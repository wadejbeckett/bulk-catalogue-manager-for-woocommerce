"""Native Status comparison against the actual editor on an existing guarded fixture.

This probe does not mount or install an engine; it records the installed source/build identity. PHP helper text is evaluated in a short-lived
WP-CLI process. Native writes use actual authenticated post.php / Woo AJAX;
manager writes use preview, durable grants and the installed engine's worker.
All owned IDs/users and configuration reversal are recorded before cleanup.
"""
from __future__ import annotations

import base64
from datetime import datetime, timezone
import time
from html.parser import HTMLParser
import json
import os
from pathlib import Path
from native_identity import source_manifest
import re
import urllib.parse
import uuid

HERE = Path(__file__).resolve().parent


class EditorForm(HTMLParser):
    """Successful controls from the native classic product form, no JS stand-in."""
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.active = False
        self.fields = {}
        self.capture = None
        self.select = None
        self.option = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form' and a.get('id') == 'post':
            self.active = True
        if not self.active or 'disabled' in a:
            return
        name = a.get('name')
        if tag == 'input' and name:
            kind = a.get('type', 'text')
            if kind in ('submit', 'button', 'file', 'reset'):
                return
            if kind in ('checkbox', 'radio') and 'checked' not in a:
                return
            # These fixtures have no multi-valued categories/attributes.
            self.fields[name] = a.get('value', '')
        if tag == 'textarea' and name:
            self.capture = name
            self.fields[name] = ''
        if tag == 'select' and name:
            self.select = name
        if tag == 'option' and self.select:
            self.option = (a.get('value', ''), 'selected' in a)
            if self.select not in self.fields or self.option[1]:
                self.fields[self.select] = self.option[0]

    def handle_data(self, data):
        if self.capture:
            self.fields[self.capture] += data

    def handle_endtag(self, tag):
        if tag == 'form':
            self.active = False
        if tag == 'textarea':
            self.capture = None
        if tag == 'select':
            self.select = None


def check_native_status(p):
    expected = os.environ.get('BULKCAT_FIXTURE_URL', '').rstrip('/')
    host = urllib.parse.urlsplit(p.base).hostname or ''
    if expected != p.base or not (host in ('localhost', '127.0.0.1', '::1') or host.endswith(('.localhost', '.test'))):
        raise RuntimeError('BULKCAT_FIXTURE_URL must match the explicitly owned local fixture')
    out = os.environ.get('BULKCAT_NATIVE_STATUS_EVIDENCE')
    if not out:
        raise RuntimeError('Set BULKCAT_NATIVE_STATUS_EVIDENCE to a private evidence directory')
    folder = Path(out)
    folder.mkdir(parents=True, exist_ok=True)
    run = str(uuid.uuid4())
    path = folder / (run + '.json')
    fixture = json.loads((HERE.parent / 'fixtures/native-status.json').read_text())
    helper = base64.b64encode((HERE / 'native-status.php').read_bytes().removeprefix(b'<?php')).decode()
    guard = base64.b64encode((HERE / 'fixture.php').read_bytes().removeprefix(b'<?php')).decode()
    evidence = {'run': run, 'base': p.base, 'cases': [], 'owned_ids': [], 'owned_users': [], 'baseline': None}

    def save():
        path.write_text(json.dumps(evidence, indent=2) + '\n')
        path.chmod(0o600)

    def native(phase, **args):
        payload = base64.b64encode(json.dumps(dict(run=run, phase=phase, **args)).encode()).decode()
        env = base64.b64encode(('BULKCAT_PROBE_SITEURL=' + p.base).encode()).decode()
        return p.wp_eval(f'putenv(base64_decode("{env}")); eval(base64_decode("{guard}")); $bulkcat_native_args=json_decode(base64_decode("{payload}"),true); eval(base64_decode("{helper}"));')

    def result(surface, ok, detail):
        p.row('native-status', surface, bool(ok), detail)
        if surface.endswith(':oracle') or surface.endswith(':harness'):
            print(f'{surface}: {detail}', flush=True)
        save()

    def form(user, pid):
        opener, _ = p.session(user)
        response = p._raw(opener, 'GET', f'/wp-admin/post.php?post={pid}&action=edit')
        parsed = EditorForm()
        parsed.feed(response.body.decode('utf-8', 'replace'))
        return response, parsed.fields

    def editor(user, pid, requested, fields=None, gmt=None):
        response, fields = form(user, pid) if fields is None else (None, fields.copy())
        if not fields.get('_wpnonce'):
            return {'http': response.status, 'editor_denied': True}
        if gmt == 'zero':
            fields['post_date_gmt'] = '0000-00-00 00:00:00'
        elif gmt == 'missing':
            fields.pop('post_date_gmt', None)
        fields['post_status'] = requested
        fields.pop('save', None)
        fields.pop('publish', None)
        fields.pop('saveasdraft', None)
        fields.pop('original_publish', None)
        # Visibility is a separate native editor control. Requesting private is
        # its native visibility path; leaving private explicitly chooses public.
        if requested == 'private':
            fields['visibility'] = 'private'
        elif fields.get('visibility') == 'private':
            fields['visibility'] = 'public'
        opener, _ = p.session(user)
        response = p._raw(opener, 'POST', '/wp-admin/post.php', urllib.parse.urlencode(fields).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})
        return {'http': response.status, 'location': response.headers.get('Location', ''), 'submitted_status': requested}

    def variation(user, parent, pid, requested):
        response, _ = form(user, parent)
        html = response.body.decode('utf-8', 'replace')
        nonce = re.search(r'"save_variations_nonce"\s*:\s*"([a-f0-9]+)"', html)
        if not nonce:
            return {'http': response.status, 'editor_denied': True, 'ajax_submitted': False}
        fields = {'action': 'woocommerce_save_variations', 'security': nonce[1], 'product_id': parent, 'variable_post_id[0]': pid, 'variable_regular_price[0]': '17.25', 'variable_weight[0]': '1.25', 'variation_menu_order[0]': '1', 'variable_description[0]': 'Unrelated description'}
        if requested == 'publish':
            fields['variable_enabled[0]'] = 'on'
        opener, _ = p.session(user)
        reply = p._raw(opener, 'POST', '/wp-admin/admin-ajax.php', urllib.parse.urlencode(fields).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})
        return {'http': reply.status, 'body': reply.body.decode('utf-8', 'replace')[:500], 'ajax_submitted': True}

    def manager(user, pid, target, revoke=None, delete_actor=False):
        body = {'ids': [pid], 'operations': [{'action': 'set_value', 'args': {'field': 'status', 'value': target, 'apply_to_variations': False}}], 'sample_size': 'all'}
        response = p.http(user, 'POST', '/preview', body=body)
        pre = response.json() or {}
        record = {'preview_http': response.status, 'preview': pre}
        if response.status != 200:
            return record
        apply = p.apply_body(user, pre, {'ids': [pid]})
        # Remove access after preview/grant, before any accepted write.
        if revoke:
            native('revoke', actor=revoke, delete=delete_actor, fixture_admin=int(p.seed['users']['admin']['id']))
        reply = p.http(user, 'POST', '/batches', body=apply)
        record['apply_http'] = reply.status
        record['batch'] = reply.json()
        bid = (reply.json() or {}).get('batch_id')
        if bid:
            # Drive this exact batch, not the fixture's whole queue.
            record['worker'] = native('worker', batch=int(bid), id=pid)
            record['report'] = p.http('admin', 'GET', f'/batches/{bid}').json()
        return record

    evidence['source_start'] = source_manifest()
    baseline = native('inspect')
    evidence['baseline'] = baseline
    save()  # configuration backup exists before any timezone write.
    result('pinned-runtime', baseline['wp'] == fixture['oracle']['wordpress'] and baseline['wc'] == fixture['oracle']['woocommerce'] and baseline['manager'] == fixture['oracle']['manager'], f"WP {baseline['wp']} / WC {baseline['wc']} / manager {baseline['manager']}")
    if baseline['wp'] != fixture['oracle']['wordpress'] or baseline['wc'] != fixture['oracle']['woocommerce'] or baseline['manager'] != fixture['oracle']['manager']:
        raise RuntimeError('Pinned runtime mismatch; stopped before creating any test objects')
    users = {}
    try:
        users = native('users', password=p.seed['password'])
        evidence['owned_users'] = [u['id'] for u in users.values()]
        p.seed['users']['admin'] = users['admin']  # No native login side effects on original users.
        save()
        for name, user in users.items():
            p.seed['users']['native_' + name] = user
        for row in fixture['rows']:
            selected = os.environ.get('BULKCAT_NATIVE_STATUS_CASES', '')
            if selected and row['case'] not in selected.split(','):
                continue
            actor_name = row.get('actor', 'admin')
            user = 'admin' if actor_name == 'admin' else 'native_' + actor_name
            actor = int(p.seed['users'][user]['id'])
            author = int(p.seed['users']['admin']['id']) if actor_name == 'other' else actor
            current = {'case': row['case'], 'definition': row, 'steps': []}
            evidence['cases'].append(current)
            ids = []
            try:
                if row.get('timezone', baseline['timezone']) != baseline['timezone']:
                    native('timezone', timezone=row['timezone'])
                made = native('create', row=row, author=author, actor=actor, fixture_admin=int(p.seed['users']['admin']['id']))
                current.update(made)
                ids = list(made['ids'].values()) + made['parents']
                evidence['owned_ids'].extend(ids)
                save()
                for target in row['requested']:
                    windows = {}
                    if actor_name in ('removed', 'deleted'):
                        # Capture a valid native form before revocation as well.
                        _, saved_form = form(user, made['ids']['native'])
                        windows['manager'] = [int(time.time())]
                        managed = manager(user, made['ids']['manager'], target, revoke=actor, delete_actor=actor_name == 'deleted')
                        windows['manager'].append(int(time.time()))
                        windows['native'] = [int(time.time())]
                        nat = editor(user, made['ids']['native'], target, saved_form)
                        windows['native'].append(int(time.time()))
                    else:
                        windows['native'] = [int(time.time())]
                        nat = variation(user, made['parents'][0], made['ids']['native'], target) if row.get('kind') == 'variation' else editor(user, made['ids']['native'], target, gmt=row.get('gmt'))
                        windows['native'].append(int(time.time()))
                        windows['manager'] = [int(time.time())]
                        managed = manager(user, made['ids']['manager'], target)
                        windows['manager'].append(int(time.time()))
                    states = native('read', ids=made['ids'], actor=actor)
                    current['steps'].append({'requested': target, 'save_windows': windows, 'native_submission': nat, 'manager_request': managed, 'after': states})
                    n, m = states['native'], states['manager']
                    detail = f"native {n['status']} / manager {m['status']}; native HTTP {nat['http']}; preview {managed['preview_http']} apply {managed.get('apply_http')}"
                    allowed = row.get('allowed', True)
                    # A manager refusal is allowed when native editor coerces an
                    # unauthorised publish to pending/private to previous status.
                    equal = n['status'] == m['status'] if allowed else m['status'] == made['before']['manager']['status']
                    result(row['case'] + ':' + target + ':manager', equal, detail)
                    if allowed:
                        dates_match = n['date_gmt'] == m['date_gmt']
                        if row.get('gmt'):
                            dates_match = all(windows[side][0] <= int(datetime.fromisoformat(states[side]['date_gmt']).replace(tzinfo=timezone.utc).timestamp()) <= windows[side][1] for side in ('native', 'manager'))
                        result(row['case'] + ':' + target + ':effects', dates_match and n['password_sha256'] == m['password_sha256'], 'native date rule/password; floating dates checked against each save window')
                        sample = [r for r in managed.get('preview', {}).get('sample', []) if r.get('field') == 'status']
                        prediction = (sample[0].get('context') or {}).get('effective_status', sample[0].get('new')) if sample else None
                        result(row['case'] + ':' + target + ':prediction', prediction == n['status'], f"preview {prediction}; native stored {n['status']}")
                        result(row['case'] + ':' + target + ':event', n['event']['timestamp'] == m['event']['timestamp'], 'publish_future_post timestamp; exact object args recorded separately')
                        before = made['before']['manager'] if len(current['steps']) == 1 else current['steps'][-2]['after']['manager']
                        raw = before['record']
                        try:
                            decoded = json.loads(raw)
                        except (TypeError, ValueError):
                            decoded = None
                        own = isinstance(decoded, dict) and decoded.get('id') == m['id'] and decoded.get('status') in ('publish', 'future', 'private', 'pending')
                        expected_record = '' if own and before['status'] != n['status'] else raw
                        result(row['case'] + ':' + target + ':legacy-record', m['record'] == expected_record, 'only a changed native status clears a valid own record; no new record or foreign cleanup')
                        if before['status'] == n['status']:
                            result(row['case'] + ':' + target + ':no-save', managed.get('worker') == {'crud_saves': 0, 'post_updates': 0}, 'exact-object CRUD and native post-update observers detect same-second writes')
                    for side in ('native','manager'):
                        before_fields = made['before'][side]['unrelated']
                        after_fields = states[side]['unrelated']
                        changed = {k: [v, after_fields[k]] for k, v in before_fields.items() if v != after_fields[k]}
                        # An unauthorised native publish is coerced to Pending;
                        # core also clears its slug. Retain the full fingerprint
                        # and explicitly assert that exact native effect, never
                        # make it a permanently failing 'unchanged' requirement.
                        if side == 'native' and not allowed and n['status'] == 'pending' and not n['caps']['publish_products'] and before_fields['slug']:
                            current['steps'][-1]['native_coercion_effects'] = changed
                            result(row['case'] + ':' + target + ':native-coercion-effects', changed == {'slug': [before_fields['slug'], '']}, 'native denial coerces Pending and clears only its slug; full before/after fingerprints retained')
                        else:
                            result(row['case'] + ':' + target + ':' + side + '-unrelated', not changed, 'price, stock, terms, listing, descriptions, slug, attributes and dimensions; differences=' + json.dumps(changed))
                last = current['steps'][-1]['after']['native']
                expected_status = row['effective']
                result(row['case'] + ':oracle', last['status'] == expected_status, f"native stored {last['status']}; expected {expected_status}")
            except Exception as error:
                current['error'] = f'{type(error).__name__}: {str(error)[-700:]}'
                result(row['case'] + ':harness', False, current['error'])
            finally:
                if ids:
                    cleaned = native('cleanup', ids=ids)
                    current['cleanup'] = cleaned['remaining']
                    result(row['case'] + ':cleanup', not cleaned['remaining'], 'exact UUID products/parents removed; native audit retained')
                if row.get('timezone', baseline['timezone']) != baseline['timezone']:
                    native('timezone', timezone=baseline['timezone'])
                save()
    finally:
        native('timezone', timezone=baseline['timezone'])
        cleanup = native('cleanup', ids=[], users=[u['id'] for u in users.values()], final=True)
        evidence['cleanup'] = cleanup
        evidence['source_end'] = source_manifest()
        evidence['source_unchanged'] = evidence['source_start'] == evidence['source_end']
        result('source-unchanged', evidence['source_unchanged'], 'runtime, build and probe file manifests match at start/end')
        result('baseline-preserved', cleanup['baseline'] == baseline['baseline'], 'full original product/meta/term/user/config hashes retained (login session_tokens excluded); owned users removed; timezone restored')
        result('owned-users-removed', not cleanup['remaining_users'], 'exact UUID-owned user IDs no longer exist')
        evidence['rows'] = [r for r in p.rows if r[0] == 'native-status']
        save()
        print(f'Native Status evidence: {path}', flush=True)


CHECKS = [check_native_status]
