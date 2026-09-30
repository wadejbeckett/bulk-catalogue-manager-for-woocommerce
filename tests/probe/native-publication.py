"""Scoped native publication crash/password proof, on an already-owned fixture.

Run separately from the editor matrix: each JSON is its baseline and reversal
record. No install, reset, queue drain, or changes to unowned objects.
"""
from __future__ import annotations
import argparse
import base64
import json
import os
import subprocess
import urllib.request
from pathlib import Path
from native_identity import source_manifest
import uuid
from probe import Probe

HERE = Path(__file__).resolve().parent


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--wp-cmd', required=True)
    parser.add_argument('--seed', required=True)
    parser.add_argument('--verbose', action='store_true')
    args = parser.parse_args()
    args.no_wp = False
    p = Probe(args)
    if os.environ.get('BULKCAT_FIXTURE_URL') != p.base:
        raise RuntimeError('An explicitly owned fixture URL is required')
    folder = Path(os.environ['BULKCAT_NATIVE_STATUS_EVIDENCE'])
    folder.mkdir(parents=True, exist_ok=True)
    run = str(uuid.uuid4())
    path = folder / (run + '.json')
    evidence = {'run': run, 'base': p.base, 'cases': [], 'owned_ids': [], 'owned_users': []}
    def save():
        path.write_text(json.dumps(evidence, indent=2) + '\n')
        path.chmod(0o600)
    def encoded(file):
        return base64.b64encode((HERE / file).read_bytes().removeprefix(b'<?php')).decode()
    guard, common, lifecycle = map(encoded, ['fixture.php', 'native-status.php', 'native-publication.php'])
    def native(phase, **kwargs):
        data = base64.b64encode(json.dumps(dict(run=run, phase=phase, **kwargs)).encode()).decode()
        env = base64.b64encode(('BULKCAT_PROBE_SITEURL=' + p.base).encode()).decode()
        code = f'putenv(base64_decode("{env}")); eval(base64_decode("{guard}")); $bulkcat_native_args=json_decode(base64_decode("{data}"),true); eval(base64_decode("{common}"));'
        if phase == 'library':
            code += f'$bulkcat_publication_args=$bulkcat_native_args; eval(base64_decode("{lifecycle}"));'
        return p.wp_eval(code)
    evidence['source_start'] = source_manifest()
    baseline = native('inspect')
    evidence['baseline'] = baseline
    save()
    users = {}
    failed = 0
    try:
        users = native('users', password=p.seed['password'])
        evidence['owned_users'] = [u['id'] for u in users.values()]
        p.seed['users']['admin'] = users['admin']  # No native login side effects on original users.
        save()
        actor = users['publisher']['id']
        for case in os.environ.get('BULKCAT_NATIVE_PUBLICATION_CASES', 'transport,private-undo,crash,setter-failure,final-check,hooks,binding,http').split(','):
            current = {'case': case}
            evidence['cases'].append(current)
            ids = []
            try:
                made = native('create', row={'case':case, 'date':'past', 'record':'none', 'initial':'publish' if case == 'variation-password' else 'draft', 'kind':'variation' if case.startswith('variation-') else 'simple'}, author=actor, actor=actor, fixture_admin=int(p.seed['users']['admin']['id']))
                current['before'] = made['before']
                ids = list(made['ids'].values()) + made['parents']
                evidence['owned_ids'].extend(ids)
                save()
                extra = {}
                if case == 'binding':
                    setup = native('library', id=made['ids']['manager'], actor=actor, case='scale-setup')
                    current['scale_setup'] = setup
                    ids.extend(setup['extra_ids'])
                    evidence['owned_ids'].extend(setup['extra_ids'])
                    extra['ids'] = setup['extra_ids']
                    save()  # exact inventory before any binding/date mutation
                result = native('library', **extra, id=made['ids']['manager'], other_id=made['ids']['native'], actor=actor, users=users, slug=made['before']['manager']['unrelated']['slug'], case=case)
                if case == 'binding':
                    p.seed['users']['publication_actor'] = users['publisher']
                    chosen = extra['ids']
                    body = {'ids': chosen, 'sample_size':25, 'operations':[{'action':'set_status','args':{'field':'status','value':'publish'}}]}
                    pre = p.http('publication_actor','POST','/preview',body=body).json()
                    apply = p.apply_body('publication_actor',pre,{'ids':chosen})
                    sampled = {r['object_id'] for r in pre['sample']}
                    far = next(pid for pid in reversed(chosen) if pid not in sampled)
                    native('library',id=far,actor=actor,case='date',ahead=-172800)
                    stale=p.http('publication_actor','POST','/batches',body=apply)
                    result['checks']['HTTP_far_unsampled_date_stale']=stale.status==409 and stale.json().get('code')=='bulkcat_preview_stale'
                    pre2=p.http('publication_actor','POST','/preview',body=body).json()
                    apply2=p.apply_body('publication_actor',pre2,{'ids':chosen})
                    accepted=p.http('publication_actor','POST','/batches',body=apply2)
                    bid=(accepted.json() or {}).get('batch_id')
                    result['checks']['HTTP_new_preview_accepts']=accepted.status==201 and bool(bid)
                    if bid:
                        result['batches'].append(bid)
                        native('library',id=far,actor=actor,case='date',ahead=172800)
                        replay=p.http('publication_actor','POST','/batches',body=apply2)
                        result['checks']['HTTP_receipt_before_changed_prediction']=replay.status==200 and replay.json().get('batch_id')==bid and replay.json().get('replayed') is True
                        cancelled=p.http('publication_actor','POST',f'/batches/{bid}/cancel')
                        result['checks']['owned_batch_cancelled_without_queue_drain']=cancelled.status==200
                    current['request_evidence']={'sample_count':len(pre['sample']),'outside_sample_id':far,'stale_http':stale.status,'stale':stale.json(),'accepted':accepted.json()}
                if case == 'http':
                    p.seed['users']['publication_actor'] = users['publisher']
                    pid = made['ids']['manager']
                    for target, expected in [('private','private'),('publish','future')]:
                        if target == 'publish':
                            native('library', id=pid, actor=actor, case='date', ahead=86400)
                        pre = p.http('publication_actor','POST','/preview',body={'ids':[pid],'operations':[{'action':'set_status','args':{'field':'status','value':target}}]})
                        patch = p.http('publication_actor','PATCH',f'/products/{pid}',body={'status':target})
                        fresh = p.http('publication_actor','GET',f'/products/{pid}',params=[('fields[]','status')])
                        actual = native('library',id=pid,actor=actor,case='http-read')
                        result['checks'][target+'_preview_effective_keeps_request'] = pre.status == 200 and any(r.get('field') == 'status' and r.get('new') == expected for r in pre.json().get('sample',[])) and pre.json().get('operations',[{}])[0].get('args',{}).get('value') == target
                        response = patch.json()
                        bid = response.get('batch_id')
                        result['checks'][target+'_PATCH_GET_actual_status'] = patch.status == 200 and fresh.status == 200 and response.get('item',{}).get('status') == expected and fresh.json().get('item',{}).get('status') == expected and actual['status'] == expected and any(r.get('field') == 'status' and r.get('new') == expected for r in response.get('results',[]))
                        result['checks'][target+'_preview_PATCH_GET_redacted'] = all(b'opaque' not in r.body and b'path' not in r.body for r in [pre,patch,fresh])
                        current.setdefault('http',[]).append({'target':target,'preview':pre.json(),'patch':response,'fresh':fresh.json(),'actual':actual})
                        if bid:
                            result['batches'].append(bid)
                            rows = p.http('admin','GET',f'/batches/{bid}/changes').json()
                            result['checks'][target+'_journal_actual_status'] = any(r.get('field') == 'status' and r.get('new_value') == expected for r in rows.get('items',[]))
                        if target == 'private':
                            result['checks']['HTTP_Private_password_cleared'] = actual['password_empty']
                current['result'] = result
                current['after'] = native('read', ids=made['ids'], actor=actor)
                result['checks']['unrelated_values_preserved'] = all(current['after'][side]['unrelated'] == made['before'][side]['unrelated'] for side in made['ids'])
                # Exercise every public HTTP journal/history export projection. Protected rows remain server-only.
                for bid in result['batches']:
                    for route, query in [(f'/batches/{bid}',None),(f'/batches/{bid}/changes',None),('/audit',[('batch',str(bid))]),('/export',[('source','changes'),('batch',str(bid)),('columns[]','old_value'),('columns[]','new_value')])]:
                        response=p.http('admin','GET',route,params=query)
                        key='public_'+route+'_'+str(bid)
                        result['checks'][key]=response.status==200 and b'opaque' not in response.body and b'path' not in response.body
                if case in ('private-undo', 'http') and result['batches']:
                    opener, _ = p.session('admin')
                    jar = next(handler.cookiejar for handler in opener.handlers if isinstance(handler, urllib.request.HTTPCookieProcessor))
                    cookies = [{'name':cookie.name,'value':cookie.value,'domain':cookie.domain,'path':cookie.path} for cookie in jar]
                    browser = subprocess.run(['node',str(HERE / 'native-publication-browser.cjs')],input=json.dumps({'base':p.base,'cookies':cookies,'batches':result['batches']}),text=True,capture_output=True,timeout=120)
                    # Cookie material only travels in process stdin, never into the evidence.
                    result['checks']['browser_text_redacted'] = browser.returncode == 0
                    current['browser'] = {'exit':browser.returncode,'output':browser.stdout,'error':browser.stderr[-1200:]}
                for name, ok in result['checks'].items():
                    print(('PASS' if ok else 'FAIL') + ' ' + case + ':' + name, flush=True)
                    failed += not ok
            except Exception as error:
                current['error'] = str(error)[-1500:]
                print('FAIL ' + case + ': ' + current['error'], flush=True)
                failed += 1
            finally:
                if ids:
                    cleaned = native('cleanup', ids=ids)
                    current['cleanup'] = cleaned['remaining']
                    failed += bool(cleaned['remaining'])
                save()
    finally:
        native('timezone', timezone=baseline['timezone'])
        cleanup = native('cleanup', ids=[], users=[u['id'] for u in users.values()], final=True)
        evidence['cleanup'] = cleanup
        evidence['source_end'] = source_manifest()
        evidence['source_unchanged'] = evidence['source_start'] == evidence['source_end']
        evidence['baseline_preserved'] = cleanup['baseline'] == baseline['baseline']
        failed += not evidence['source_unchanged'] or not evidence['baseline_preserved'] or bool(cleanup['remaining_users'])
        evidence['failures'] = failed
        save()
        print(f'Baseline preserved: {evidence["baseline_preserved"]}; failures: {failed}; evidence: {path}', flush=True)
    return int(failed > 0)

if __name__ == '__main__':
    raise SystemExit(main())
