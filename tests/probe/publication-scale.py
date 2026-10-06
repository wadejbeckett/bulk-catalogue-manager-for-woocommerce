"""100,000-member native publication binding on an owned disposable fixture.

No reset, implicit install, queue drain or SQL product writes. Phased seeding is
resumable by UUID SKU; private state inventories each completed chunk. Cleanup
checks UUID metadata for every object and deletes children before parents.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import time
import subprocess
import uuid
from probe import Probe, WpCliError

HERE = Path(__file__).resolve().parent


class ScaleProbe(Probe):
    def wp(self, *args, check=True):
        # Full binding traverses 100,000 WC objects repeatedly. This is an
        # explicit CLI scale timeout, not a claim about ordinary HTTP latency.
        done = subprocess.run([*self.wp_cmd, *args], capture_output=True,
                              text=True, timeout=7200)
        if check and done.returncode:
            raise WpCliError(f'WP-CLI exit {done.returncode}: {done.stderr[-2000:]}')
        return done.stdout


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('phase', choices=['seed', 'qualify', 'cleanup'])
    for key in ['base-url', 'wp-cmd', 'seed', 'evidence']:
        ap.add_argument('--' + key, required=True)
    ap.add_argument('--disposable', action='store_true', required=True)
    args = ap.parse_args()
    args.no_wp = args.verbose = False
    p = ScaleProbe(args)
    if os.environ.get('BULKCAT_FIXTURE_URL') != p.base:
        raise RuntimeError('Explicit matching fixture URL required')
    folder = Path(args.evidence)
    folder.mkdir(parents=True, exist_ok=True, mode=0o700)
    path = folder / 'state.json'
    state = json.loads(path.read_text()) if path.exists() else {'base': p.base, 'run': str(uuid.uuid4()), 'last': 0, 'chunks': []}
    if state['base'] != p.base:
        raise RuntimeError('State belongs to another fixture')
    def save():
        path.write_text(json.dumps(state, indent=2) + '\n')
        path.chmod(0o600)
    def native(phase, **extra):
        enc = lambda b: base64.b64encode(b).decode()
        data = dict(extra, phase=phase, run=state['run'], actor=state.get('actor', 0))
        code = 'putenv(base64_decode("' + enc(('BULKCAT_PROBE_SITEURL=' + p.base).encode()) + '"));'
        code += 'eval(base64_decode("' + enc((HERE / 'fixture.php').read_bytes()[5:]) + '"));'
        code += '$bulkcat_scale_args=json_decode(base64_decode("' + enc(json.dumps(data).encode()) + '"),true);'
        code += 'eval(base64_decode("' + enc((HERE / 'publication-scale.php').read_bytes()[5:]) + '"));'
        return p.wp_eval(code)
    if args.phase == 'seed':
        if 'actor' not in state:
            state['installed_start'] = native('identity')
            save()  # Persist ownership before creating the UUID actor.
            state.update(native('user'))
            save()
        while state['last'] < 22223:
            start = time.monotonic()
            result = native('seed', first=state['last'] + 1, last=min(22223, state['last'] + 250))
            state['chunks'].append(result)
            state['last'] = result['last']
            save()
            print('Seeded parents', state['last'], 'of 22223;', len(result['made']), 'new objects;', round(time.monotonic()-start, 2), 'seconds', flush=True)
        inventory = native('inventory')
        state['ids'] = inventory['ids']
        save()
        assert len(state['ids']) == 100000
    elif args.phase == 'qualify':
        hashes = lambda: {name: hashlib.sha256((HERE / name).read_bytes()).hexdigest() for name in ['publication-scale.php', 'publication-scale.py', 'fixture.php']}
        evidence = {'harness_start': hashes(), 'result': native('qualify'), 'harness_end': hashes(), 'installed_end': native('identity')}
        out = folder / (str(time.time_ns()) + '-qualify.json')
        out.write_text(json.dumps(evidence, indent=2) + '\n')
        out.chmod(0o600)
        summary = {key: evidence['result'][key] for key in ['checks', 'stages', 'memory_limit', 'peak_reset_available', 'error'] if key in evidence['result']}
        print(json.dumps(summary, indent=2), flush=True)
        print('Private qualification evidence:', out, flush=True)
        assert evidence['harness_start'] == evidence['harness_end']
        assert evidence['installed_end'] == state['installed_start']
        assert 'error' not in evidence['result'] and all(evidence['result']['checks'].values())
        state['qualified'] = str(out)
        save()
    else:
        # Descending IDs place each variation before its own parent.
        remaining = native('inventory')['ids']
        if 'ids' in state:
            assert set(remaining).issubset(state['ids']), 'Unexpected owned IDs after qualification'
        if 'cleanup_inventory' not in state:
            state['cleanup_inventory'] = remaining
        else:
            assert set(remaining).issubset(state['cleanup_inventory'])
        save()
        ids = list(reversed(remaining))
        for pos in range(0, len(ids), 500):
            result = native('cleanup', ids=ids[pos:pos+500])
            state.setdefault('cleanup_chunks', []).append({'ids': ids[pos:pos+500], **result})
            save()
            print('Remaining owned objects', result['remaining'], flush=True)
        state['cleanup'] = native('finish')
        save()
        assert state['cleanup'] == {'remaining': 0, 'actor_removed': True}


if __name__ == '__main__':
    main()
