#!/usr/bin/env python3
"""One explicitly assigned local Apache observation; restore before returning."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import signal
import subprocess
import time
from urllib.parse import urlparse
from urllib.request import urlopen
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--container', required=True)
parser.add_argument('--url', required=True)
parser.add_argument('--settings-snapshot', required=True)
parser.add_argument('--evidence', required=True)
parser.add_argument('--config', required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
out = Path(args.evidence).resolve()
out.mkdir(parents=True, exist_ok=False)
url = urlparse(args.url)
assert url.scheme == 'http' and url.hostname in ('localhost', '127.0.0.1') and url.port
assert os.environ.get('BULKCAT_E2E_FIXTURE') == '1'
assert os.environ.get('BULKCAT_E2E_BASE_URL') == args.url and os.environ.get('WP_CMD')
container = json.loads(subprocess.check_output(['docker', 'inspect', args.container]))[0]
assert container['State']['Running']
assert any(p['HostIp'] == '127.0.0.1' and p['HostPort'] == str(url.port)
           for p in container['NetworkSettings']['Ports']['80/tcp'])
assert any(m['Source'] == str(root) and not m['RW'] for m in container['Mounts'])
cid = container['Id']
command = str(uuid.uuid4())
private = '/tmp/bulkcat-receipt-observer-' + command
config_path = '/var/www/html/.htaccess'
report = {'container': cid, 'command': command, 'private': private, 'restored': False}
child = None
installed = False
created = False
cleanup_errors = []


def php(code, data=None, user=None):
    argv = ['docker', 'exec', '-i']
    if user is not None:
        argv += ['--user', user]
    argv += [cid, 'php', '-r', code]
    result = subprocess.run(argv, input=json.dumps(data or {}).encode(),
                            capture_output=True, timeout=25)
    if result.returncode or result.stderr:
        raise RuntimeError(f'Owned PHP exit {result.returncode}: {result.stderr.decode()}')
    return json.loads(result.stdout) if result.stdout else None


def health():
    with urlopen(args.url + '/wp-login.php', timeout=20) as response:
        body = response.read()
        assert response.status == 200 and b'id="loginform"' in body
        return {'status': response.status, 'login_form': True}


def interrupted(signum, _frame):
    raise InterruptedError(f'Observer command received signal {signum}')


def verify_snapshot(snapshot):
    php(r'''$d=json_decode(stream_get_contents(STDIN),true);
$paths=array_merge(['/etc/apache2/apache2.conf','/var/.htaccess','/var/www/.htaccess','/var/www/html/.htaccess'],glob('/etc/apache2/{mods,conf,sites}-enabled/*',GLOB_BRACE),glob('/usr/local/etc/php/conf.d/*.ini'));
sort($paths);$expected=array_keys($d);sort($expected);
if($paths!==$expected||is_file('/usr/local/etc/php/php.ini'))exit(2);
foreach($d as $p=>$v){if(($v['exists']??true)===false){if(file_exists($p))exit(3);continue;}
$s=stat($p);if(hash_file('sha256',$p)!==$v['sha256']||$s['uid']!==$v['uid']||$s['gid']!==$v['gid']||decoct($s['mode']&07777)!==$v['mode'])exit(4);}
''', snapshot)


for sig in (signal.SIGTERM, signal.SIGINT):
    signal.signal(sig, interrupted)

try:
    # Refuse drift or added active overrides since the explicitly reviewed snapshot.
    snapshot = json.loads(Path(args.settings_snapshot).read_text())['files']
    verify_snapshot(snapshot)
    original = php(r'''$p='/var/www/html/.htaccess';if(!is_file($p)||is_link($p))exit(2);
$s=stat($p);echo json_encode(['bytes'=>base64_encode(file_get_contents($p)),'sha256'=>hash_file('sha256',$p),'uid'=>$s['uid'],'gid'=>$s['gid'],'mode'=>$s['mode']&07777,'mtime'=>$s['mtime']]);''')
    original_bytes = base64.b64decode(original['bytes'])
    assert b'auto_prepend_file' not in original_bytes
    (out / 'htaccess-original.json').write_text(json.dumps(original, indent=2))
    os.chmod(out / 'htaccess-original.json', 0o600)
    user = f"{original['uid']}:{original['gid']}"
    report['before'] = {k: v for k, v in original.items() if k != 'bytes'}
    report['http_before'] = health()
    observer = Path(__file__).with_suffix('.php').read_bytes()
    settings = {'host': url.netloc, 'command': command, 'expires': int(time.time()) + 600}
    php("$d=json_decode(stream_get_contents(STDIN),true);if(file_exists($d['dir']))exit(2);", {'dir': private}, user)
    created = True  # Finally also handles an interrupted or partial setup.
    php(r'''$d=json_decode(stream_get_contents(STDIN),true);if(file_exists($d['dir'])||!mkdir($d['dir'],0700))exit(2);
foreach(['observer.php'=>$d['source'],'config.json'=>base64_encode(json_encode($d['settings'])),'observations.jsonl'=>'','active.lock'=>''] as $f=>$bytes){$v=base64_decode($bytes);if(file_put_contents($d['dir'].'/'.$f,$v)!==strlen($v)||!chmod($d['dir'].'/'.$f,0600))exit(3);}''',
        {'dir': private, 'source': base64.b64encode(observer).decode(), 'settings': settings}, user)
    deployed = original_bytes + f'\n# Owned receipt observation {command}\n<IfModule php_module>\nphp_value auto_prepend_file {private}/observer.php\n</IfModule>\n'.encode()
    report['observer_sha256'] = hashlib.sha256(observer).hexdigest()
    report['deployed_sha256'] = hashlib.sha256(deployed).hexdigest()
    # Mark before mutation so finally also restores after an uncertain write acknowledgement.
    installed = True
    php(r'''$d=json_decode(stream_get_contents(STDIN),true);$p='/var/www/html/.htaccess';if(hash_file('sha256',$p)!==$d['before'])exit(2);$v=base64_decode($d['bytes']);if(file_put_contents($p,$v,LOCK_EX)!==strlen($v))exit(3);''',
        {'before': original['sha256'], 'bytes': base64.b64encode(deployed).decode()}, user)
    report['http_unmatched_during'] = health()
    assert php("$d=json_decode(stream_get_contents(STDIN),true);echo json_encode(filesize($d['dir'].'/observations.jsonl'));", {'dir': private}, user) == 0
    env = dict(os.environ, BULKCAT_E2E_OWNED_EVIDENCE=str(out),
               BULKCAT_RECEIPT_OBSERVER_CONTAINER=cid, BULKCAT_RECEIPT_OBSERVER_DIR=private,
               BULKCAT_RECEIPT_OBSERVER_COMMAND=command)
    argv = ['node_modules/.bin/playwright', 'test', '-c', args.config,
            'tests/e2e/inline-receipts.spec.js', '--grep', 'real HTTP completed response dropped']
    report['test_command'] = argv
    child = subprocess.Popen(argv, cwd=root, env=env, start_new_session=True)
    report['test_exit'] = child.wait(timeout=540)
finally:
    # A second INT/TERM cannot interrupt restoration; all cleanup calls are bounded.
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, signal.SIG_IGN)
    # Only this spawned test process group. Never stop Apache, DB or unrelated jobs.
    try:
        if child is not None and child.poll() is None:
            assert os.getpgid(child.pid) == child.pid
            os.killpg(child.pid, signal.SIGINT)
            try:
                child.wait(timeout=25)
            except subprocess.TimeoutExpired:
                os.killpg(child.pid, signal.SIGKILL)
                child.wait(timeout=10)
            report['interrupted_test_exit'] = child.returncode
    except Exception as error:
        cleanup_errors.append('test termination: ' + repr(error))
    if installed:
        try:
            report['after'] = php(r'''$d=json_decode(stream_get_contents(STDIN),true);$p='/var/www/html/.htaccess';$v=base64_decode($d['bytes']);if(file_put_contents($p,$v,LOCK_EX)!==strlen($v)||!chmod($p,$d['mode'])||!touch($p,$d['mtime']))exit(2);clearstatcache(true,$p);$s=stat($p);if(hash_file('sha256',$p)!==$d['sha256']||$s['uid']!==$d['uid']||$s['gid']!==$d['gid']||($s['mode']&07777)!==$d['mode'])exit(3);echo json_encode(['sha256'=>hash_file('sha256',$p),'uid'=>$s['uid'],'gid'=>$s['gid'],'mode'=>$s['mode']&07777]);''', original, user)
            report['restored'] = True
            verify_snapshot(snapshot)
            report['all_settings_restored'] = len(snapshot)
        except Exception as error:
            cleanup_errors.append('configuration restoration: ' + repr(error))
    # Keep the observer if restoring its reference failed; report rather than hide it.
    if created and (not installed or report['restored']):
        try:
            observations = php(r'''$d=json_decode(stream_get_contents(STDIN),true);$dir=$d['dir'];
if(!is_dir($dir)){echo json_encode('');return;}
$lease=null;if(is_file($dir.'/active.lock')){$lease=fopen($dir.'/active.lock','r');if(false===$lease)exit(2);
$end=hrtime(true)+20000000000;while(!flock($lease,LOCK_EX|LOCK_NB)){if(hrtime(true)>=$end)exit(3);usleep(20000);}}
$rows=is_file($dir.'/observations.jsonl')?file_get_contents($dir.'/observations.jsonl'):'';
foreach(['observer.php','config.json','observations.jsonl','active.lock'] as $f){if(file_exists($dir.'/'.$f)&&!unlink($dir.'/'.$f))exit(4);}
if(!rmdir($dir))exit(5);if(null!==$lease)fclose($lease);echo json_encode($rows);''', {'dir': private}, user)
            (out / 'apache-observations.jsonl').write_text(observations)
            report['observer_removed'] = True
            report['matched_requests_released'] = True
        except Exception as error:
            cleanup_errors.append('observer removal: ' + repr(error))
    try:
        report['http_after'] = health()
    except Exception as error:
        cleanup_errors.append('restored HTTP check: ' + repr(error))
    report['cleanup_errors'] = cleanup_errors
    (out / 'observer-lifecycle.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report), flush=True)
if cleanup_errors:
    raise RuntimeError('; '.join(cleanup_errors))
raise SystemExit(report['test_exit'])
