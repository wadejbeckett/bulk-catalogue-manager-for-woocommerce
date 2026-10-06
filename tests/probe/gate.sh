#!/usr/bin/env bash
#
# The native release gate (TESTS-8) in one command: the archive upgrade, the
# fault probes, the cross-process races and the add-on journeys, on a
# throwaway docker stack of its own (never a real shop), then the stack is
# removed. Every probe it runs is described in tests/probe/README.md; this
# script only puts them in order, gives each a log and a PASS or FAIL line,
# and exits 1 when any failed.
#
# Phases (`all` runs up, upgrade, mount, native, then down even after a failure):
#   up       start the stack on 127.0.0.1:$PORT (mariadb:11, wordpress:php8.3-apache,
#            WP-CLI in wordpress:cli-php8.3; every docker name ends in $SUFFIX),
#            install WordPress, WooCommerce ($WC_ZIP) and the released archive
#            ($RELEASED), the plugin inside the WordPress volume so an upgrade is
#            a real `wp plugin install --force`
#   upgrade  seed.sh, then native-status-upgrade.sh seed, upgrade (to $CANDIDATE),
#            verify and cleanup: release-created work, receipts, history and views
#            carried over the native installer, exact files hashed before and after
#            (the web container has no bind mount yet, as that probe requires)
#   mount    recreate the web container with this checkout's tests/ mounted
#            read-only inside the installed plugin (the native probes and the test
#            providers load from there); the plugin itself stays the archive
#   native   the fault probes (exact-selection*, selection-lifecycle*,
#            exact-batch*, stock-undo), the races (exact-selection-race,
#            exact-batch-race apply and undo, selection-lifecycle-race dual, keep,
#            prune and compose: two overlapping WP-CLI processes each), and the
#            add-on journeys (provider-journey, provider-v2-journey) with the test
#            providers' must-use plugins copied in for them and removed after
#   down     remove the stack's containers, volume and network
#
# Usage (from anywhere; the archives are only read):
#   RELEASED=/path/to/bulk-catalogue-manager-for-woocommerce-1.1.1.zip \
#   RELEASED_SHA256=<its sha256> \
#   CANDIDATE=dist/bulk-catalogue-manager-for-woocommerce-<version>.zip \
#   WC_ZIP=/path/to/woocommerce.11.1.2.zip \
#     bash tests/probe/gate.sh all
#
# Environment:
#   RELEASED         a released archive from before native Status (1.1.x): the
#                    upgrade probe checks that release's own set_value Status work
#   RELEASED_SHA256  its full sha256 (checked before anything is installed)
#   CANDIDATE        the archive under test (its sha256 is printed and recorded)
#   WC_ZIP           a WooCommerce archive (default: woocommerce from wordpress.org at $WC_VERSION)
#   WC_VERSION       default 11.1.2, used only without WC_ZIP
#   PORT             host port (default 8960)
#   SUFFIX           suffix of every docker name (default -gate)
#   WORK             where the archives, wrappers, seed file, logs and private
#                    evidence go (default: a new temp dir; set it to run phases
#                    one by one). Keep it private: it holds signed test grants.
#   ONLY             with `native`, a space-separated subset of step names
#
# A step's log is $WORK/logs/<step>.log; the summary is $WORK/summary.txt.
set -uo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
SLUG=bulk-catalogue-manager-for-woocommerce
PORT=${PORT:-8960}
SUFFIX=${SUFFIX:--gate}
WC_VERSION=${WC_VERSION:-11.1.2}
NET="bulkcat-net$SUFFIX"
VOL="bulkcat-wp$SUFFIX"
DB="bulkcat-db$SUFFIX"
WEB="bulkcat-web$SUFFIX"
URL="http://localhost:$PORT"
WORK=${WORK:-$(mktemp -d)}
mkdir -p "$WORK/zips" "$WORK/logs"
chmod 700 "$WORK"
FAILED=0

say() { printf '%s\n' "$*" | tee -a "$WORK/summary.txt"; }

# The WP-CLI wrapper probe.py, seed.sh and the upgrade probe take as WP_CMD.
# With $1 = tests, this checkout's tests/ is also mounted inside the plugin.
wrapper() {
	local mount=''
	if [ "${1:-}" = tests ]; then
		mount="-v $ROOT/tests:/var/www/html/wp-content/plugins/$SLUG/tests:ro"
	fi
	cat > "$WORK/wp.sh" <<EOF
#!/usr/bin/env bash
exec docker run --rm -i --network $NET --user 33:33 -e HOME=/tmp \\
  -e WORDPRESS_DB_HOST=$DB -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress \\
  -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DEBUG=1 -e BULKCAT_PROBE_SITEURL=$URL \\
  -v $VOL:/var/www/html -v $WORK/zips:/zips:ro $mount wordpress:cli-php8.3 wp "\$@"
EOF
	chmod +x "$WORK/wp.sh"
}
WP="$WORK/wp.sh"

web() {
	local mount=()
	if [ "${1:-}" = tests ]; then
		mount=(-v "$ROOT/tests:/var/www/html/wp-content/plugins/$SLUG/tests:ro")
	fi
	docker rm -f "$WEB" > /dev/null 2>&1 || true
	docker run -d --name "$WEB" --network "$NET" -p "127.0.0.1:$PORT:80" -e WORDPRESS_DB_HOST="$DB" \
		-e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
		-e WORDPRESS_DEBUG=1 -v "$VOL":/var/www/html "${mount[@]}" wordpress:php8.3-apache > /dev/null
	# The first start copies WordPress into the volume; wait for it and for an answer.
	for _ in $(seq 1 90); do
		if docker exec "$WEB" test -f /var/www/html/wp-includes/version.php &&
			curl -s -o /dev/null "$URL/wp-login.php"; then
			return 0
		fi
		sleep 1
	done
	echo "the web container did not answer on $URL" >&2
	return 1
}

# mariadb:11 takes a while to accept connections on its first start.
database() {
	for _ in $(seq 1 90); do
		docker exec "$DB" mariadb-admin ping -h 127.0.0.1 -uwordpress -pwordpress --silent > /dev/null 2>&1 && return 0
		sleep 1
	done
	echo "the database container did not accept connections" >&2
	return 1
}

archives() {
	: "${RELEASED:?set RELEASED to a released archive from before native Status (1.1.x)}"
	: "${RELEASED_SHA256:?set RELEASED_SHA256 to its sha256}"
	: "${CANDIDATE:?set CANDIDATE to the archive under test}"
	local got
	got=$(sha256sum "$RELEASED" | cut -d' ' -f1)
	if [ "$got" != "$RELEASED_SHA256" ]; then
		say "FAIL released archive sha256 is $got, not $RELEASED_SHA256"
		exit 1
	fi
	cp "$RELEASED" "$WORK/zips/released.zip"
	cp "$CANDIDATE" "$WORK/zips/candidate.zip"
	sha256sum "$WORK/zips/released.zip" | cut -d' ' -f1 > "$WORK/released.sha256"
	sha256sum "$WORK/zips/candidate.zip" | cut -d' ' -f1 > "$WORK/candidate.sha256"
	say "released  $(cat "$WORK/released.sha256")  $RELEASED"
	say "candidate $(cat "$WORK/candidate.sha256")  $CANDIDATE"
	if [ -n "${WC_ZIP:-}" ]; then
		cp "$WC_ZIP" "$WORK/zips/woocommerce.zip"
	fi
}

up() {
	if docker network inspect "$NET" > /dev/null 2>&1 || docker volume inspect "$VOL" > /dev/null 2>&1; then
		say "FAIL up: $NET or $VOL already exists; run down first (or choose another SUFFIX)"
		return 1
	fi
	archives
	wrapper
	docker network create "$NET" > /dev/null
	docker volume create "$VOL" > /dev/null
	docker run -d --name "$DB" --network "$NET" -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress \
		-e MARIADB_USER=wordpress -e MARIADB_PASSWORD=wordpress mariadb:11 > /dev/null
	database || return 1
	web || return 1
	"$WP" core install --url="$URL" --title=BULKCAT-GATE --admin_user=admin --admin_password=password \
		--admin_email=admin@example.com --skip-email > /dev/null || return 1
	if [ -f "$WORK/zips/woocommerce.zip" ]; then
		"$WP" plugin install /zips/woocommerce.zip --activate > /dev/null || return 1
	else
		"$WP" plugin install woocommerce --version="$WC_VERSION" --activate > /dev/null || return 1
	fi
	"$WP" plugin install /zips/released.zip --activate > /dev/null || return 1
	say "up: WordPress $("$WP" core version), WooCommerce $("$WP" plugin get woocommerce --field=version), released $SLUG $("$WP" plugin get "$SLUG" --field=version) on $URL; WORK=$WORK"
}

# One gate step: its command's output to its log, PASS or FAIL in the summary.
step() {
	local name=$1
	shift
	if [ -n "${ONLY:-}" ] && ! [[ " $ONLY " == *" $name "* ]]; then
		return 0
	fi
	local start=$SECONDS
	if "$@" > "$WORK/logs/$name.log" 2>&1; then
		say "PASS $name ($((SECONDS - start)) s)"
	else
		say "FAIL $name ($((SECONDS - start)) s): $(tail -n 3 "$WORK/logs/$name.log" | tr '\n' ' ' | cut -c1-300)"
		FAILED=1
	fi
}

upgrade_phase() {
	local phase=$1
	BULKCAT_FIXTURE_URL="$URL" bash "$ROOT/tests/probe/native-status-upgrade.sh" "$phase" \
		--base-url "$URL" --wp-cmd "$WP" --seed "$WORK/seed.json" --evidence "$WORK/upgrade-evidence" \
		--container "$WEB" --released "$WORK/zips/released.zip" --released-sha256 "$(cat "$WORK/released.sha256")" \
		--candidate "$WORK/zips/candidate.zip" --candidate-sha256 "$(cat "$WORK/candidate.sha256")" \
		--candidate-install-path /zips/candidate.zip
}

upgrade() {
	wrapper
	if ! "$WP" core is-installed > /dev/null 2>&1; then
		say "FAIL upgrade: no installed site on $URL (run up first)"
		FAILED=1
		return
	fi
	step seed env WP_CMD="$WP" SEED_JSON="$WORK/seed.json" bash "$ROOT/tests/probe/seed.sh"
	for phase in seed upgrade verify cleanup; do
		step "upgrade-$phase" upgrade_phase "$phase"
	done
	say "upgrade: installed $SLUG $("$WP" plugin get "$SLUG" --field=version)"
}

mount_tests() {
	wrapper tests
	web tests || return 1
	say "mount: $ROOT/tests read-only in the installed plugin"
}

# A native probe through `wp eval`, with optional PHP set before the require.
probe() {
	local file=$1 pre=${2:-}
	"$WP" eval "$pre require WP_PLUGIN_DIR . '/$SLUG/tests/probe/$file';"
}

stock_undo() {
	probe stock-undo.php | tee /dev/stderr | grep -qx 'ALL PASS'
}

# Two overlapping processes: the holder starts first and waits inside its
# transaction for the peer (the probes' own GET_LOCK barriers), then verify.
race() {
	local file=$1 prepare=$2 hold=$3 peer=$4 verify=$5
	probe "$file" "\$phase = '$prepare';" || return 1
	probe "$file" "\$phase = '$hold';" > "$WORK/logs/race-$hold.log" 2>&1 &
	local holder=$!
	sleep 2
	probe "$file" "\$phase = '$peer';" > "$WORK/logs/race-$peer.log" 2>&1
	local peer_status=$?
	wait "$holder"
	local hold_status=$?
	cat "$WORK/logs/race-$hold.log" "$WORK/logs/race-$peer.log"
	[ "$hold_status" -eq 0 ] && [ "$peer_status" -eq 0 ] || return 1
	probe "$file" "\$phase = '$verify';"
}

providers() {
	local action=$1
	for name in bulkcat-sample-provider.php bulkcat-picks-provider.php; do
		if [ "$action" = add ]; then
			# Owned by the web user, as WordPress would make it: probes and specs that copy
			# a must-use plugin of their own run as www-data (probe.py's time-limit observer).
			docker exec "$WEB" mkdir -p /var/www/html/wp-content/mu-plugins
			docker exec "$WEB" chown www-data:www-data /var/www/html/wp-content/mu-plugins
			docker cp "$ROOT/tests/fixtures/mu-plugins/$name" "$WEB:/var/www/html/wp-content/mu-plugins/$name"
			docker exec "$WEB" chown www-data:www-data "/var/www/html/wp-content/mu-plugins/$name"
		else
			docker exec "$WEB" rm -f "/var/www/html/wp-content/mu-plugins/$name"
		fi
	done
}

native() {
	wrapper tests
	if ! "$WP" core is-installed > /dev/null 2>&1; then
		say "FAIL native: no installed site on $URL (run up, upgrade and mount first)"
		FAILED=1
		return
	fi
	for file in exact-selection exact-selection-routes exact-selection-cleanup exact-selection-capacity \
		selection-lifecycle selection-lifecycle-routes exact-batch exact-batch-routes exact-batch-faults; do
		step "$file" probe "$file.php"
	done
	step stock-undo stock_undo
	step race-exact-selection race exact-selection-race.php prepare a b verify
	step race-exact-batch-apply race exact-batch-race.php prepare_apply hold_apply peer_apply verify_apply
	step race-exact-batch-undo race exact-batch-race.php prepare_undo hold_undo peer_undo verify_undo
	step race-lifecycle-dual race selection-lifecycle-race.php prepare renew_hold renew verify_dual
	step race-lifecycle-keep race selection-lifecycle-race.php prepare renew_hold prune verify_keep
	step race-lifecycle-prune race selection-lifecycle-race.php prepare prune_hold renew verify_prune
	step race-lifecycle-compose race selection-lifecycle-race.php prepare_compose compose_hold compose verify_compose
	providers add
	step provider-journey probe provider-journey.php "\$phase = 'journey';"
	step provider-v2-journey probe provider-v2-journey.php
	providers remove
}

down() {
	# Any WP-CLI container still attached (an interrupted step) first, so the network can go.
	for container in $(docker ps -aq --filter "network=$NET" 2> /dev/null); do
		docker rm -f -v "$container" > /dev/null 2>&1 || true
	done
	docker rm -f -v "$WEB" "$DB" > /dev/null 2>&1 || true
	docker volume rm "$VOL" > /dev/null 2>&1 || true
	docker network rm "$NET" > /dev/null 2>&1 || true
	say "down: removed $WEB, $DB, $VOL and $NET"
}

case "${1:-}" in
	up) up || FAILED=1 ;;
	upgrade) upgrade ;;
	mount) mount_tests || FAILED=1 ;;
	native) native ;;
	down) down ;;
	all)
		trap down EXIT
		if up; then
			upgrade
			if mount_tests; then
				native
			else
				FAILED=1
			fi
		else
			FAILED=1
		fi
		;;
	*)
		echo "usage: $0 up|upgrade|mount|native|down|all (see the header)" >&2
		exit 2
		;;
esac
if [ "$FAILED" -ne 0 ]; then
	say "gate: FAILED (logs in $WORK/logs)"
	exit 1
fi
say "gate: all passed"
