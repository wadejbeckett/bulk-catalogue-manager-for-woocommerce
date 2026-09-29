#!/usr/bin/env bash
#
# Ticket 04 (global Off/On through Status): a real upgrade from the 1.0.0
# archive to this branch, and a downgrade back, on a throwaway docker stack of
# its own (never a real shop). Unlike tests/probe/README.md's stack, the
# plugin directory is part of the WordPress volume, not a read-only bind
# mount, so `wp plugin install <zip> --force` can replace the plugin as an
# update does (an upgrade, not an uninstall).
#
# Phases (run in this order; `all` runs up, upgrade, downgrade, down):
#   up         check the 1.0.0 archive's full sha256, start the stack on
#              127.0.0.1:$PORT, install WordPress, WooCommerce 11.1.0 and the
#              1.0.0 archive, seed it with tests/probe/seed.sh
#   upgrade    through 1.0.0's own PATCH /products/{id}: draft a published
#              product (the 1.0 batch) and set another to Pending review;
#              install the branch archive (bin/build-zip.sh) with --force and
#              let the migrator run; make the first product Private and switch
#              it Off through the manager; rows
#                upgrade:guard-changed-since  the 1.0 batch's undo preview lists
#                                             it "changed since", its saved
#                                             Private intact
#                upgrade:guard-revert-anyway  the forced undo writes the 1.0
#                                             journal's status and clears the
#                                             record (SPEC §9.4), never the saved
#                                             Private
#                upgrade:1.0-undo-plain       the other 1.0 batch undoes exactly
#   downgrade  with the branch, switch Off two products that were Private;
#              rows
#                downgrade:no-work-queued        step 0: no batch queued, running
#                                                or undoing, no pending chunk
#                downgrade:off-survives          after installing 1.0.0 again the
#                                                product is still a draft and its
#                                                record is intact and ignored
#                downgrade:history-refuses       1.0.0's undo of the Off batch
#                                                refuses its items as cannot restore
#                downgrade:old-published-warning 1.0.0's Published publishes a
#                                                product saved as Private (the
#                                                rollback warning), on the second
#                                                product
#                downgrade:reinstall-restores    with the branch reinstalled, On
#                                                restores Private
#   down       remove the stack's containers, volume and network
#
# Usage (from the repository root; the 1.0.0 archive is only read):
#   ZIP10=/path/to/bulk-catalogue-manager-for-woocommerce-1.0.0.zip \
#     bash tests/probe/offon-upgrade.sh all
# Environment:
#   ZIP10               the 1.0.0 archive (required for up, upgrade, downgrade)
#   PORT                host port (default 8911)
#   SUFFIX              suffix of every docker name (default -offon)
#   WORK                where the archives, the seed file and the state go (default: a new temp dir; set it to run phases separately)
#   BULKCAT_SKIP_BUILD  1 to ship the committed assets/build in the branch archive (bin/build-zip.sh)
#
# Each row prints as PASS or FAIL; the script exits 1 on any FAIL.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
PORT=${PORT:-8911}
SUFFIX=${SUFFIX:--offon}
NET="bulkcat-upgrade$SUFFIX"
VOL="bulkcat-upgrade-wp$SUFFIX"
DB="bulkcat-upgrade-db$SUFFIX"
WPC="bulkcat-upgrade-wp-app$SUFFIX"
SHA10=3319e14f031f54d2316749ef61a93a11cca8cfca3c05ecfa239160ca4e8e865f
SLUG=bulk-catalogue-manager-for-woocommerce
WORK=${WORK:-$(mktemp -d)}
mkdir -p "$WORK/zips"

wp_wrapper() {
	cat > "$WORK/wp.sh" <<EOF
#!/usr/bin/env bash
exec docker run --rm -i --network $NET --user 33:33 -e HOME=/tmp \\
  -e WORDPRESS_DB_HOST=$DB -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress \\
  -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DEBUG=1 \\
  -v $VOL:/var/www/html -v $WORK/zips:/zips:ro wordpress:cli-php8.3 wp "\$@"
EOF
	chmod +x "$WORK/wp.sh"
}

need_zip10() {
	: "${ZIP10:?set ZIP10 to the 1.0.0 archive}"
	local got
	got=$(sha256sum "$ZIP10" | cut -d' ' -f1)
	if [ "$got" != "$SHA10" ]; then
		echo "FAIL the 1.0.0 archive's sha256 is $got, not $SHA10" >&2
		exit 1
	fi
	cp "$ZIP10" "$WORK/zips/$SLUG-1.0.0.zip"
	echo "the 1.0.0 archive: sha256 $got"
}

branch_zip() {
	local out zip
	# build-zip.sh prints npm's and webpack's output too; the archive is the line naming a .zip.
	out=$("$ROOT/bin/build-zip.sh") || { echo "bin/build-zip.sh failed" >&2; return 1; }
	zip=$(printf '%s\n' "$out" | grep -E '\.zip$' | tail -n 1)
	[ -f "$zip" ] || { echo "bin/build-zip.sh named no archive" >&2; return 1; }
	cp "$zip" "$WORK/zips/$SLUG-branch.zip"
	echo "the branch archive: $(sha256sum "$WORK/zips/$SLUG-branch.zip" | cut -d' ' -f1) from $(git -C "$ROOT" rev-parse --short HEAD)"
}

up() {
	need_zip10
	wp_wrapper
	docker network create "$NET" > /dev/null
	docker volume create "$VOL" > /dev/null
	docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress \
		-e MARIADB_USER=wordpress -e MARIADB_PASSWORD=wordpress mariadb:11 > /dev/null
	docker run -d --name "$WPC" --network "$NET" -p "127.0.0.1:$PORT:80" -e WORDPRESS_DB_HOST="$DB" \
		-e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
		-e WORDPRESS_DEBUG=1 -v "$VOL":/var/www/html wordpress:php8.3-apache > /dev/null
	sleep 20
	"$WORK/wp.sh" core install --url="http://localhost:$PORT" --title=BULKCAT-UPGRADE --admin_user=admin \
		--admin_password=password --admin_email=admin@example.com --skip-email > /dev/null
	"$WORK/wp.sh" plugin install woocommerce --version=11.1.0 --activate > /dev/null
	"$WORK/wp.sh" plugin install "/zips/$SLUG-1.0.0.zip" --activate > /dev/null
	echo "installed: $("$WORK/wp.sh" plugin get "$SLUG" --field=version) (schema $("$WORK/wp.sh" option get bulkcat_db_version))"
	(cd "$ROOT" && WP_CMD="$WORK/wp.sh" SEED_JSON="$WORK/seed.json" bash tests/probe/seed.sh > /dev/null)
	echo "WORK=$WORK"
}

checks() {
	python3 - "$1" "$PORT" "$WORK" "$ROOT" <<'PY'
import argparse, json, os, sys
phase, port, work, root = sys.argv[1:5]
sys.path.insert(0, os.path.join(root, "tests", "probe"))
import probe, offon  # noqa: E402

args = argparse.Namespace(base_url=f"http://localhost:{port}", verbose=False, no_wp=False,
                          wp_cmd=os.path.join(work, "wp.sh"), seed=os.path.join(work, "seed.json"))
p = probe.Probe(args)
state_file = os.path.join(work, "state.json")
state = json.load(open(state_file)) if os.path.exists(state_file) else {}


def save():
    json.dump(state, open(state_file, "w"))


def branch_code():
    """Whether the code running is this branch's (the 1.0.0 archive has no Off record class)."""
    return p.wp_eval("bulkcat_out( class_exists( '\\\\BulkCatalogueManager\\\\Availability\\\\OffRecord' ) );") is True


def patch(pid, status):
    reply = p.http("manager", "PATCH", f"/products/{pid}", body={"status": status})
    data = reply.json() or {}
    if reply.status != 200:
        raise AssertionError(f"1.0 PATCH {pid} {status}: {reply.brief()}")
    return data["batch_id"]


if phase == "before":
    a, c = p.simple(1)["id"], p.simple(2)["id"]
    state.update(a=a, c=c, b1=patch(a, "draft"), b1b=patch(c, "pending"))
    save()
    print(f"1.0 batches: #{state['b1']} drafted {a}, #{state['b1b']} set {c} to Pending review")
elif phase == "after":
    a, c = state["a"], state["c"]
    p._raw(p.session("admin")[0], "GET", "/wp-admin/")  # an update does not reactivate: the migrator runs on admin_init
    schema = p.wp("option", "get", "bulkcat_db_version").strip()
    upgraded = branch_code()
    offon.switch(p, [a], "private")
    off = offon.switch(p, [a], "draft")
    saved = offon.own_record(offon.record(p, a), a) or {}
    pre = p.http("manager", "POST", f"/batches/{state['b1']}/undo", body={"preview": True}).json() or {}
    item = next((i for i in pre.get("items", []) if i.get("object_id") == a), {})
    fields = [c.get("field") for c in item.get("conflicts", [])]
    offon.row(p, "upgrade:guard-changed-since", upgraded and schema == "8" and item.get("state") == "conflicted" and item.get("code") == "changed_since"
              and "availability_off" in fields and saved.get("status") == "private" and saved.get("batch") == off["batch"],
              f"branch code {upgraded}, schema {schema}; undo preview of 1.0 batch #{state['b1']}: {item.get('state')} {item.get('code')} {[(c.get('field'), c.get('current')) for c in item.get('conflicts', [])]}; saved {saved}")
    forced = offon.undo(p, state["b1"], force=True)
    final = offon.statuses(p, [a])[str(a)]
    offon.row(p, "upgrade:guard-revert-anyway", forced["report"].get("status") == "complete" and final == "publish" and offon.record(p, a) == "",
              f"forced undo {forced['report'].get('status')}: {final} (the 1.0 journal's status, never the saved Private), record {offon.record(p, a)!r}")
    plain = offon.undo(p, state["b1b"])
    got = offon.statuses(p, [c])[str(c)]
    offon.row(p, "upgrade:1.0-undo-plain", plain["report"].get("status") == "complete" and got == "publish" and offon.record(p, c) == "",
              f"undo of 1.0 batch #{state['b1b']}: {plain['report'].get('status')}, status {got}")
elif phase == "down-before":
    d, e = p.simple(3)["id"], p.simple(4)["id"]
    offon.switch(p, [d, e], "private")
    off = offon.switch(p, [d, e], "draft")
    state.update(d=d, e=e, off=off["batch"], record_d=offon.record(p, d), record_e=offon.record(p, e))
    queued = p.wp_eval("global $wpdb; bulkcat_out( array( 'batches' => (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}bulkcat_batches WHERE status IN ('queued','running','undoing')\" ), "
                       "'chunks' => (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = 'bulkcat_run_chunk' AND status IN ('pending','in-progress')\" ) ) );")
    state["queued"] = queued
    save()
    offon.row(p, "downgrade:no-work-queued", queued == {"batches": 0, "chunks": 0} and offon.own_record(state["record_d"], d) is not None,
              f"step 0: {queued}; Off batch #{off['batch']} saved {offon.own_record(state['record_d'], d)}")
elif phase == "down-after":
    d, e = state["d"], state["e"]
    old = not branch_code()
    snap = offon.statuses(p, [d, e])
    offon.row(p, "downgrade:off-survives", old and snap == {str(d): "draft", str(e): "draft"} and offon.record(p, d) == state["record_d"],
              f"1.0.0 running again: {old}; statuses {snap}; record intact {offon.record(p, d) == state['record_d']}")
    pre = p.http("manager", "POST", f"/batches/{state['off']}/undo", body={"preview": True})
    data = pre.json() or {}
    codes = sorted({i.get("code") for i in data.get("items", [])})
    offon.row(p, "downgrade:history-refuses", pre.status in (200, 409) and codes == ["cannot_restore"],
              f"1.0.0 undo preview of Off batch #{state['off']}: {pre.brief()} item codes {codes} counts {data.get('counts')}")
    batch = patch(e, "publish")
    got = offon.statuses(p, [e])[str(e)]
    offon.row(p, "downgrade:old-published-warning", got == "publish",
              f"1.0.0 Published on a product saved as Private (batch #{batch}): {got}; its saved Private was not restored (the rollback warning)")
elif phase == "down-reinstalled":
    d = state["d"]
    p._raw(p.session("admin")[0], "GET", "/wp-admin/")
    on = offon.switch(p, [d], "publish")
    got = offon.statuses(p, [d])[str(d)]
    offon.row(p, "downgrade:reinstall-restores", got == "private" and offon.record(p, d) == "" and offon.notes(on["preview"]).get(d) == "on_restores",
              f"branch reinstalled: On {offon.notes(on['preview']).get(d)} -> {got}")
failed = [r for r in p.rows if r[2] == "FAIL"]
for chapter, surface, result, detail in p.rows:
    print(f"{result:<5} {surface:<36} {detail}")
sys.exit(1 if failed else 0)
PY
}

upgrade() {
	need_zip10
	wp_wrapper
	checks before
	# Called as `upgrade || status=1`, where set -e does not stop a failed step: stop explicitly.
	branch_zip || return 1
	"$WORK/wp.sh" plugin install "/zips/$SLUG-branch.zip" --force > /dev/null || return 1
	echo "upgraded to the branch archive: $("$WORK/wp.sh" plugin get "$SLUG" --field=version), active: $("$WORK/wp.sh" plugin is-active "$SLUG" && echo yes || echo no)"
	checks after
}

downgrade() {
	need_zip10
	wp_wrapper
	[ -f "$WORK/zips/$SLUG-branch.zip" ] || branch_zip || return 1
	local status=0
	checks down-before || status=1
	"$WORK/wp.sh" plugin install "/zips/$SLUG-1.0.0.zip" --force > /dev/null
	checks down-after || status=1
	"$WORK/wp.sh" plugin install "/zips/$SLUG-branch.zip" --force > /dev/null
	checks down-reinstalled || status=1
	return $status
}

down() {
	docker rm -f -v "$WPC" "$DB" > /dev/null 2>&1 || true
	docker volume rm "$VOL" > /dev/null 2>&1 || true
	docker network rm "$NET" > /dev/null 2>&1 || true
	echo "removed $WPC, $DB, $VOL, $NET"
}

case "${1:-}" in
	up) up ;;
	upgrade) upgrade ;;
	downgrade) downgrade ;;
	down) down ;;
	all)
		trap down EXIT
		up
		status=0
		upgrade || status=1
		downgrade || status=1
		exit $status
		;;
	*) sed -n '2,52p' "$0"; exit 2 ;;
esac
