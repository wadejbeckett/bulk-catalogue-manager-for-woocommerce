"""Preserve released receipts/work, install a supplied archive, then qualify recovery.

Explicit phases prevent a failed verification from reseeding or replacing evidence.
State contains signed synthetic grants; keep --evidence outside Git, mode 0700.
"""
import argparse
import base64
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import subprocess
import time
import urllib.parse
import uuid
import zipfile

from probe import Probe

HERE = Path(__file__).resolve().parent
SLUG = 'bulk-catalogue-manager-for-woocommerce'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('phase', choices=['inspect', 'seed', 'upgrade', 'verify', 'verify-history', 'verify-native', 'verify-denied', 'cleanup'])
    for key in ['base-url', 'wp-cmd', 'seed', 'evidence', 'released', 'released-sha256',
                'candidate', 'candidate-sha256', 'candidate-install-path', 'container']:
        parser.add_argument('--' + key, required=True)
    args = parser.parse_args()
    args.verbose = args.no_wp = False
    p = Probe(args)
    if os.environ.get('BULKCAT_FIXTURE_URL') != p.base:
        raise RuntimeError('Explicit BULKCAT_FIXTURE_URL must match --base-url')
    folder = Path(args.evidence)
    folder.mkdir(parents=True, exist_ok=True, mode=0o700)
    folder.chmod(0o700)
    state_path = folder / 'state.json'
    state = json.loads(state_path.read_text()) if state_path.exists() else {'run': str(uuid.uuid4()), 'base': p.base, 'cases': {}}
    if state['base'] != p.base:
        raise RuntimeError('State belongs to another fixture')
    harness = lambda: {name: hashlib.sha256((HERE / name).read_bytes()).hexdigest() for name in ['native-status-upgrade.sh', 'native-status-upgrade.py', 'native-status-upgrade.php', 'native-status.php', 'fixture.php', 'probe.py']}
    output = {'phase': args.phase, 'run': state['run'], 'checks': {}, 'observations': {}, 'harness_start': harness()}
    log = folder / (str(time.time_ns()) + '-' + args.phase + '.json')

    def save():
        for path, data in [(state_path, state), (log, output)]:
            path.write_text(json.dumps(data, indent=2) + '\n')
            path.chmod(0o600)

    def check(name, condition):
        output['checks'][name] = bool(condition)
        save()
        print(('PASS ' if condition else 'FAIL ') + name, flush=True)
        if not condition:
            raise AssertionError(name)

    def native(phase, **data):
        data = dict(data, phase=phase, run=state['run'])
        enc = lambda b: base64.b64encode(b).decode()
        env = enc(('BULKCAT_PROBE_SITEURL=' + p.base).encode())
        code = f'putenv(base64_decode("{env}"));'
        code += 'eval(base64_decode("' + enc((HERE / 'fixture.php').read_bytes()[5:]) + '"));'
        common = dict(data, phase=phase if phase in ['users', 'cleanup'] else 'library')
        code += '$bulkcat_native_args=json_decode(base64_decode("' + enc(json.dumps(common).encode()) + '"),true);'
        code += 'eval(base64_decode("' + enc((HERE / 'native-status.php').read_bytes()[5:]) + '"));'
        if phase not in ['users', 'cleanup']:
            code += '$bulkcat_upgrade_args=json_decode(base64_decode("' + enc(json.dumps(data).encode()) + '"),true);'
            code += 'eval(base64_decode("' + enc((HERE / 'native-status-upgrade.php').read_bytes()[5:]) + '"));'
        return p.wp_eval(code)

    archives = {}
    for label in ['released', 'candidate']:
        path = Path(getattr(args, label))
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        if digest != getattr(args, label + '_sha256'):
            raise RuntimeError(label + ' archive hash mismatch')
        with zipfile.ZipFile(path) as z:
            archives[label] = {name[len(SLUG) + 1:]: hashlib.sha256(z.read(name)).hexdigest()
                               for name in z.namelist() if name.startswith(SLUG + '/') and not name.endswith('/')}
        output[label] = {'sha256': digest, 'files': archives[label]}
    mounts = json.loads(subprocess.check_output(['docker', 'inspect', args.container, '--format', '{{json .Mounts}}'], text=True))
    output['mounts'] = mounts
    # A parent bind or plugin bind would make an apparent upgrade misleading.
    check('no_source_bind_mount', all(m['Type'] != 'bind' for m in mounts))
    before = native('identity')
    output['identity_before'] = before
    save()
    label = 'released' if args.phase in ['inspect', 'seed', 'upgrade'] else 'candidate'
    check('installed_' + label + '_all_files_exact', before['files'] == archives[label])

    def run(case, phase, **extra):
        return native(phase, ids=[case['id']], actor=case['actor'], **extra)

    def grant(case, target):
        return run(case, 'grant', target=target)

    def accept(case, issued):
        return run(case, 'accept', request=issued['request'])

    def work(case, batch, **extra):
        return run(case, 'worker', batch=batch, **extra)

    def inline(case, values):
        issued = run(case, 'route', method='POST', route='requests', data={
            'operation': 'product.inline',
            'intent': {'object_id': case['id'], 'source': 'inline', 'values': values},
        })
        check('inline_grant_issued_without_save_' + str(len(output['observations'].get('inline_grants', []))),
              issued['status'] == 200 and issued['counts'][str(case['id'])] == {'crud': 0, 'post': 0})
        held = issued['data']
        output['observations'].setdefault('inline_grants', []).append(issued)
        save()
        body = dict(values, source='inline', uuid=held['uuid'],
                    request_proof=held['request_proof'], intent=held['intent'])
        return run(case, 'route', method='PATCH', route=f"products/{case['id']}", data=body)

    try:
        if args.phase == 'inspect':
            check('actual_release_has_no_native_status_class', not before['native'])
        elif args.phase == 'seed':
            check('state_not_previously_seeded', not state['cases'] and 'users' not in state)
            state['baseline'] = before['baseline']
            state['users'] = native('users', password=p.seed['password'])
            save()
            actor = state['users']['admin']['id']
            for name in ['completed', 'restored', 'pending', 'crashed', 'revoked', 'unaccepted', 'native', 'untouched']:
                owner = state['users']['publisher']['id'] if name == 'revoked' else actor
                made = native('create', actor=owner, name=name, status='private')
                case = state['cases'][name] = dict(made, actor=owner)
                save()
                issued = grant(case, 'draft')
                case['issued'] = issued
                check(name + '_release_action_set_value', issued['preview']['operations'][0]['action'] == 'set_value')
                check(name + '_preview_no_saves', issued['counts'][str(case['id'])] == {'crud': 0, 'post': 0})
                if name in ['unaccepted', 'native']:
                    continue
                case['accepted'] = accept(case, issued)
                check(name + '_accepted', 'result' in case['accepted'])
                batch = case['batch'] = case['accepted']['result']['batch_id']
                save()
                if name in ['pending', 'revoked']:
                    continue
                if name == 'crashed':
                    case['crash'] = run(case, 'crash', batch=batch)
                    case['interrupted'] = run(case, 'snapshot', batch=batch)
                    check('crash_really_working_after_save_before_journal', case['interrupted']['items'][0]['state'] == 'working' and all(r['result'] == 'pending' for r in case['interrupted']['changes']) and case['interrupted']['objects'][0]['status'] == 'draft')
                    check('crash_one_save', case['crash']['counts'][str(case['id'])] == {'crud': 1, 'post': 1})
                    continue
                case['worked'] = work(case, batch)
                check(name + '_draft_completed_one_save', case['worked']['result'] == 'complete' and case['worked']['counts'][str(case['id'])] == {'crud': 1, 'post': 1})
                if name == 'restored':
                    case['restore_issued'] = grant(case, 'publish')
                    case['restore_accepted'] = accept(case, case['restore_issued'])
                    case['restore_batch'] = case['restore_accepted']['result']['batch_id']
                    case['restore_worked'] = work(case, case['restore_batch'])
                    check('release_publish_restored_private', case['restore_worked']['snapshot']['objects'][0]['status'] == 'private')
                save()
            state['views'] = []
            for predicate in ['off', 'changed_outside']:
                reply = native('route', actor=actor, method='POST', route='views', data={'name': 'Upgrade ' + predicate, 'view': {'type': 'table', 'perPage': 25, 'filters': [{'field': 'status', 'operator': 'isAny', 'value': [predicate]}]}})
                state['views'].append(reply)
                check('released_saved_view_' + predicate, reply['status'] == 201)
            state['seeded'] = True
            save()
        elif args.phase == 'upgrade':
            check('all_release_scenarios_saved_before_upgrade', state.get('seeded') is True)
            # The supplied path is visible to the existing fixture's WP-CLI wrapper.
            output['installer'] = p.wp('plugin', 'install', args.candidate_install_path, '--force')
            after = native('identity')
            check('installed_candidate_all_files_exact', after['files'] == archives['candidate'])
            check('upgrade_preserves_all_existing_data', before['baseline'] == after['baseline'])
            # An update's tables migrate on the first admin request after it (src/Data/Migrator.php,
            # Plugin::migrate() on admin_init), as on a site updated from its Plugins screen; until
            # then the request routes refuse (bulkcat_request_storage). It is made as the run's own
            # administrator, whose user rows cleanup removes, so the baseline can still be exact.
            schema = 'bulkcat_out( array( get_option( "bulkcat_db_version" ), ( new \\BulkCatalogueManager\\Data\\Migrator( \\BulkCatalogueManager\\Data\\Registry::plugin() ) )->is_current() ) );'
            output['schema_before_admin_request'] = p.wp_eval(schema)
            jar = http.cookiejar.CookieJar()
            opener = p._opener(jar)
            p._raw(opener, 'GET', '/wp-login.php')
            form = urllib.parse.urlencode({'log': state['users']['admin']['login'], 'pwd': p.seed['password'],
                                           'wp-submit': 'Log In', 'testcookie': '1'}).encode()
            p._raw(opener, 'POST', '/wp-login.php', form, {'Content-Type': 'application/x-www-form-urlencoded'})
            output['first_admin_request'] = p._raw(opener, 'GET', '/wp-admin/').status
            output['schema_after_admin_request'] = p.wp_eval(schema)
            check('first_admin_request_migrates_to_current_schema',
                  output['first_admin_request'] == 200 and output['schema_after_admin_request'][1] is True)
            migrated = native('identity')
            output['identity_migrated'] = migrated
            check('migration_preserves_catalogue_and_configuration',
                  all(migrated['baseline'][k] == before['baseline'][k] for k in before['baseline'] if k != 'usermeta_hash'))
            state['upgraded'] = True
            save()
        elif args.phase in ['verify', 'verify-history', 'verify-native']:
            check('archive_upgrade_recorded', state.get('upgraded') is True)
            check('plain_woocommerce_no_companion', len(before['plugins']) == 2 and before['mu'] == [])
            if args.phase == 'verify-native':
                # Continue only the exact old-harness refusal, retaining the passed legacy work.
                prior_paths = sorted(folder.glob('*-verify.json'))
                prior_path = prior_paths[-1]
                previous = json.loads(prior_path.read_text())
                refused = previous['observations']['new_edits']
                check('prior_failure_is_grant_free_zero_write',
                      previous['run'] == state['run'] and previous['candidate'] == output['candidate']
                      and {k for k, v in previous['checks'].items() if not v} == {'new_1_draft'}
                      and len(refused) == 1 and refused[0]['status'] == 409
                      and refused[0]['data']['code'] == 'bulkcat_invalid_request'
                      and refused[0]['counts'][str(state['cases']['native']['id'])] == {'crud': 0, 'post': 0})
                output['prior_legacy_evidence'] = {'path': str(prior_path), 'sha256': hashlib.sha256(prior_path.read_bytes()).hexdigest()}
                for name, key in [('completed', 'batch'), ('restored', 'restore_batch')]:
                    case = state['cases'][name]
                    observed = previous['observations'][name + '_undo']['worker']
                    current = run(case, 'snapshot', batch=case[key])
                    check(name + '_prior_undo_still_exact',
                          previous['checks'][name + '_historical_undo_exact']
                          and current['objects'] == observed['snapshot']['objects'])
            for name in (['pending', 'crashed', 'revoked'] if args.phase == 'verify' else []):
                case = state['cases'][name]
                if name == 'revoked':
                    output['observations']['revoked_caps'] = run(case, 'revoke')
                    check('effective_mapped_edit_permission_denied_before_worker', all(not row['allowed'] for row in output['observations']['revoked_caps']))
                result = work(case, case['batch'], lease_advance=301 if name == 'crashed' else 0)
                output['observations'][name] = result
                count = result['counts'][str(case['id'])]
                check(name + '_exact_saves', count == {'crud': 1 if name == 'pending' else 0, 'post': 1 if name == 'pending' else 0})
                check(name + '_outcome', result['result'] == ('partial' if name == 'revoked' else 'complete'))
                check(name + '_accepted_operations_unchanged', result['snapshot']['header']['operations'] == case['accepted']['snapshot']['header']['operations'])
                if name == 'revoked':
                    check('denied_legacy_object_skipped_unchanged', result['snapshot']['items'][0]['state'] == 'skipped' and result['snapshot']['objects'][0]['status'] == case['before']['status'] and result['snapshot']['objects'][0]['record'] == case['before']['record'])
                if name != 'revoked':
                    obj = result['snapshot']['objects'][0]
                    check(name + '_original_draft_remembered_private', obj['status'] == 'draft' and json.loads(obj['record'])['status'] == 'private')
                replay = accept(case, case['issued'])
                if name == 'revoked':
                    output['observations']['replay_without_write_permission'] = replay
                    check('replay_respects_current_route_permission', replay.get('error') == 'bulkcat_forbidden')
                    run(case, 'restore-edit')
                    replay = accept(case, case['issued'])
                output['observations'][name + '_replay'] = replay
                check(name + '_receipt_replay_same_batch', replay.get('result', {}).get('batch_id') == case['batch'] and replay['result']['replayed'] and replay['counts'][str(case['id'])] == {'crud': 0, 'post': 0})
            case = state['cases']['unaccepted']
            refused = accept(case, case['issued'])
            output['observations']['unaccepted'] = refused
            check('old_unaccepted_grant_refused_without_save', refused.get('error') == 'bulkcat_preview_stale' and refused['counts'][str(case['id'])] == {'crud': 0, 'post': 0})
            new = grant(case, 'draft')
            check('old_client_fresh_preview_normalizes_native_action', new['preview']['operations'][0]['action'] == 'set_status')
            for name, key in ([] if args.phase == 'verify-native' else [('completed', 'batch'), ('restored', 'restore_batch')]):
                case = state['cases'][name]
                undone = run(case, 'undo', batch=case[key])
                result = work(case, undone['result']['batch_id'])
                output['observations'][name + '_undo'] = {'undo': undone, 'worker': result}
                check(name + '_historical_undo_exact', result['result'] == 'complete' and result['snapshot']['objects'][0]['status'] == ('private' if name == 'completed' else 'draft'))
            case = state['cases']['native']
            for target in ['draft', 'publish', 'pending', 'draft', 'publish']:
                result = inline(case, {'status': target})
                output['observations'].setdefault('new_edits', []).append(result)
                check('new_' + str(len(output['observations']['new_edits'])) + '_' + target, result['status'] == 200 and result['data']['receipt_state'] == 'closed' and result['data']['item']['status'] == target and result['counts'][str(case['id'])] == {'crud': 1, 'post': 1} and not result['data']['item'].get('off_state'))
            last = result['data']['batch_id']
            undone = run(case, 'undo', batch=last)
            result = work(case, undone['result']['batch_id'])
            output['observations']['native_undo'] = result
            check('new_undo_restores_draft', result['result'] == 'complete' and result['snapshot']['objects'][0]['status'] == 'draft')
            changed = inline(case, {'status': 'private'})
            output['observations']['conflict_edit'] = changed
            check('conflict_edit_one_closed_save', changed['status'] == 200 and changed['data']['receipt_state'] == 'closed' and changed['data']['item']['status'] == 'private' and changed['counts'][str(case['id'])] == {'crud': 1, 'post': 1})
            conflicts = run(case, 'route', method='POST', route=f"batches/{undone['result']['batch_id']}/undo", data={'preview': True})
            output['observations']['conflicts'] = conflicts
            check('explicit_undo_conflict_detected', conflicts['status'] == 200 and conflicts['data']['counts']['conflicted'] > 0)
            views = native('route', actor=state['users']['admin']['id'], method='GET', route='views')
            output['observations']['views'] = views
            check('both_saved_predicates_unchanged', [v['view'] for v in views['data']['items']] == [v['data']['item']['view'] for v in state['views']])
            untouched = state['cases']['untouched']
            snap = run(untouched, 'snapshot', batch=untouched['batch'])
            check('no_legacy_metadata_sweep', snap['objects'] == untouched['worked']['snapshot']['objects'])
            state[{'verify': 'verified', 'verify-history': 'history_verified', 'verify-native': 'native_verified'}[args.phase]] = True
            save()
        elif args.phase == 'verify-denied':
            # Cover a corrected outcome assertion without rerunning already completed work.
            candidates = [json.loads(path.read_text()) for path in sorted(folder.glob('*-verify.json'))]
            previous = next(row for row in reversed(candidates) if 'revoked' in row['observations'])
            output['prior_worker_evidence'] = previous
            case = state['cases']['revoked']
            observed = previous['observations']['revoked']
            check('recorded_effective_permission_was_denied_before_worker', previous['checks']['effective_mapped_edit_permission_denied_before_worker'])
            check('recorded_worker_zero_saves', observed['counts'][str(case['id'])] == {'crud': 0, 'post': 0})
            current = run(case, 'snapshot', batch=case['batch'])
            output['observations']['current'] = current
            check('worker_snapshot_still_exact', current == observed['snapshot'])
            check('denied_legacy_work_is_truthful_partial_skip', observed['result'] == 'partial' and current['items'][0]['state'] == 'skipped' and current['objects'][0]['status'] == case['before']['status'] and current['objects'][0]['record'] == case['before']['record'] and 'permission' in current['items'][0]['message'])
            refused = accept(case, case['issued'])
            output['observations']['denied_replay'] = refused
            check('replay_respects_current_route_permission', refused.get('error') == 'bulkcat_forbidden')
            run(case, 'restore-edit')
            replay = accept(case, case['issued'])
            output['observations']['restored_replay'] = replay
            check('original_receipt_after_permission_restored', replay['result']['replayed'] and replay['result']['batch_id'] == case['batch'] and replay['snapshot']['receipt']['fingerprint'] == case['accepted']['snapshot']['receipt']['fingerprint'] and replay['counts'][str(case['id'])] == {'crud': 0, 'post': 0})
            state['denied_verified'] = True
            save()
        elif args.phase == 'cleanup':
            result = native('cleanup', ids=[], final=True)
            output['cleanup'] = result
            check('owned_records_removed', not result['remaining'] and not result['remaining_users'])
            check('exact_original_baseline_restored', result['baseline'] == state['baseline'])
            state['cleaned'] = True
            save()
    except Exception as error:
        output['error'] = str(error)
        save()
        raise
    finally:
        output['harness_end'] = harness()
        save()
    check('harness_stable', output['harness_start'] == output['harness_end'])
    print('Evidence: ' + str(log))


if __name__ == '__main__':
    main()
