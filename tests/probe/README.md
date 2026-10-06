# Seed and probe

`seed.sh` builds the probe catalogue and users on a WordPress + WooCommerce
site through WP-CLI; `probe.py` then checks every surface live and prints one
PASS/FAIL/SKIP row per surface (`python3 tests/probe/probe.py --help` lists the
chapters and how to add a surface). Both take the WP-CLI invocation in
`WP_CMD`.

## With wp-env (CI; needs Node 20 and Docker Compose v2)

```sh
npm install -g @wordpress/env@11.16.0   # the version CI pins; not a project dependency
npx wp-env start
WP_CMD="npx wp-env run cli wp" bash tests/probe/seed.sh
WP_CMD="npx wp-env run cli wp" python3 tests/probe/probe.py --base-url http://localhost:8888
```

`.wp-env.json` pins WordPress 7.1 and WooCommerce 11.1.0. CI runs each
WordPress and WooCommerce pair of its matrix by writing a gitignored
`.wp-env.override.json` with `core` and `plugins`, which wp-env merges over it;
the same file works locally.

## With a throwaway docker stack (no Node, no compose)

The local substitute for wp-env on a workstation without Node 20 or without
the Docker Compose v2 plugin (wp-env shells out to `docker compose`): the official
`wordpress:php8.3-apache`, `mariadb:11` and `wordpress:cli-php8.3` images on a
user-defined network, the repository bind-mounted read-only as the plugin.
Nothing here is committed as a compose file on purpose.

Bring it up (from the repository root):

```sh
docker network create bulkcat-probe
docker volume create bulkcat-probe-wp
docker run -d --name bulkcat-probe-db --network bulkcat-probe \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wordpress \
  -e MARIADB_USER=wordpress -e MARIADB_PASSWORD=wordpress mariadb:11
docker run -d --name bulkcat-probe-wp --network bulkcat-probe -p 127.0.0.1:8888:80 \
  -e WORDPRESS_DB_HOST=bulkcat-probe-db -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DEBUG=1 \
  -v bulkcat-probe-wp:/var/www/html \
  -v "$PWD":/var/www/html/wp-content/plugins/bulk-catalogue-manager-for-woocommerce:ro \
  wordpress:php8.3-apache
export WP_CMD="docker run --rm -i --network bulkcat-probe --user 33:33 -e HOME=/tmp \
  -e WORDPRESS_DB_HOST=bulkcat-probe-db -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DEBUG=1 \
  -v bulkcat-probe-wp:/var/www/html \
  -v $PWD:/var/www/html/wp-content/plugins/bulk-catalogue-manager-for-woocommerce:ro \
  wordpress:cli-php8.3 wp"
sleep 15   # the first start copies WordPress into the volume
$WP_CMD core install --url=http://localhost:8888 --title=BULKCAT --admin_user=admin \
  --admin_password=password --admin_email=admin@example.com --skip-email
$WP_CMD plugin install woocommerce --version=11.1.0 --activate
```

`WP_CMD` contains `$PWD`, so export it from the repository root (the path must
not contain spaces). The read-only mount matters: `wp plugin uninstall` in the
lifecycle chapter always passes `--skip-delete`, and the mount is a second
guard against deleting the working copy.

Seed and probe (the one-line invocation, after the export above):

```sh
bash tests/probe/seed.sh && python3 tests/probe/probe.py --base-url http://localhost:8888
```

`seed.sh 20000` adds the T76 scale catalogue (20,000 products, 70,000
variations) in resumable runs. The lifecycle chapter uninstalls and reactivates
the plugin; run `seed.sh` again before the next probe run.

Tear it down:

```sh
docker rm -f -v bulkcat-probe-wp bulkcat-probe-db
docker volume rm bulkcat-probe-wp
docker network rm bulkcat-probe
```

`-v` also removes the anonymous volume the `mariadb` image creates for its
data, so nothing is left behind.

## Category creation feedback (delivery 12)

`tests/e2e/category-create-feedback.spec.js` uses the native manager POST and
authoritative category GET, then reads the exact term ID, unique name and
`source=tree` batch/change rows through WP-CLI. It requires an explicit
`BULKCAT_E2E_BASE_URL`, `BULKCAT_E2E_FIXTURE=1` and a matching local `WP_CMD`;
the existing lifecycle fixture guard remains in force. Use an already owned,
seeded disposable fixture, with this checkout mounted as the plugin. No lease
probe or external provider is needed. Do not reset another fixture or overwrite
an existing `seed.json` (pass its path with `--seed` if also running `probe.py`).

After rebuilding, run the uninterrupted root/child journeys before the fault
simulations (the file declares them in that order):

```sh
npm run build
export BULKCAT_E2E_SHOTS="$(mktemp -d /tmp/bulkcat-category-evidence-XXXXXXXX)"
npm run e2e -- category-create-feedback.spec.js category-panel.spec.js
```

Keyboard root/child journeys cover 1280×900 and 900×900. The readback fault
matrix additionally covers 1440×900. A native MutationObserver also records busy
focus for Save-button activation and Enter in Name, with one POST per journey;
the pending-submit POST is never held or altered. Readback fault interception
changes **only GET**:
omission removes the newly created ID from a real list response, and failure
returns a synthetic HTTP 503. A second faulty GET precedes recovery through an
unmodified GET; no POST is replayed. Empty-state tests simulate empty pre-create
GETs without deleting seeded categories, then allow immediate or deferred native
readback, including moving focus to search before completion. These are transport
simulations, not evidence of repairing an installation's membership restriction.
The failed-GET journeys also check error disclosure contrast in normal, hover and
keyboard-focus states, then toggle Show/Hide details before retrying. The native
admin stylesheet keeps the focused underline on WordPress7.0 and removes it on7.1;
the test requires the focus ring in either case and does not suppress axe rules.

The established-region cases retain the same initially empty DOM status through
first confirmation, incomplete response, later read, dismissal and new creation
at 1280px and 900px. A MutationObserver records the exact message transitions;
an ordinary search rerender must not repeat them, and only one live region may
contain the outcome. These cases delay GET completion without changing its
payload. The explicitly labelled incomplete-response case performs a real native
POST and saves its full answer for term/journal verification, then replaces only
the response's `item` with `{}` on its way to the browser. Its read-only retry
must leave the warning unchanged. This is a response simulation, not a native
server defect or a failed write. All created UUID terms use the same exact cleanup.

Screenshots and JSON readbacks go to the evidence directory (or the test output
directory when the variable is absent). Save component/browser versions beside
them. Axe checks use the existing WCAG 2.2 conventions; they do not constitute
manual assistive-technology acceptance. Every test deletes only its exact unique
terms in `finally`, child before parent, and checks no matching terms remain.
The native audit journal is retained in the disposable fixture. Preserve failure
traces privately: they may contain the synthetic fixture's login cookies.

## Individual save reconciliation (delivery 18)

`tests/e2e/single-save-refresh.spec.js` qualifies ordinary native PATCH,
current-query reads and History Undo on an explicitly assigned local fixture.
Use a Playwright configuration with **no global setup, stored login state or
reseed**, one worker and zero automatic retries. Set `BULKCAT_E2E_FIXTURE=1`,
`BULKCAT_E2E_BASE_URL`, matching `WP_CMD`, and a fresh private
`BULKCAT_E2E_OWNED_EVIDENCE` directory. Run the spec at desktop 1440×900 and
narrow 720×900. Do not run the default suite's setup against a retained fixture.

The harness creates a UUID administrator, WC CRUD products/variations and
per-user saved views. It freezes source/build hashes and native versions,
records three Hidden-to-shown saves with exact unrelated selection, then runs
the inverse filter, keyboard/focus, Shop/Search partial display, boolean
Featured, variation applicability, native Undo and bulk-one Preview → Apply.
Each response delay or transport failure is labelled separately: query change
while a native PATCH response is held; aborted list GET after confirmed PATCH;
and a native accepted PATCH whose response is discarded. Permission refusal
uses a temporary denied capability on the UUID user only. No installed test
helper, global queue drain or site settings change is needed.

Timing starts immediately before Playwright clicks, records PATCH and list GET
request/response events and the assertion-observed draw, then observes another
1000 ms for extra requests. `BULKCAT_SINGLE_SAVE_BASELINE=1` is only for the
prechange characterization (select the three-save test with `--grep`); it
expects the stale row. Response intervals include transport/server work and
are not server profiling. Compare the same journey on the same fixture; do not
infer real-host latency or a speed improvement from corrected list membership.

Cleanup reads exact owned IDs, checks UUID ownership before deletion, removes
only their products/user and that user's auto-drafts, and compares the retained
product/metadata/terms/relationships/users/configuration baseline. Saved views
are owned user metadata. Audit/request/selection records and counters remain
as evidence, so this is not whole-database equality. Preserve every failure,
JSON manifest, screenshot and cleanup comparison in its original directory.

Historical boundary at native18 Task2 (`1beb2bc`, before the receipt follow-up):
ordinary PATCH had no durable receipt. The open catalogue blocked blind retry
and offered read-only recovery; navigation/reload did not recover mutation
identity. This limitation is superseded by the `product.inline` receipt protocol
at `8115314`/`d2e570b` and the qualification below. A matching current value alone
still cannot attribute a lost mutation. The
controller owns whole-ticket acceptance and final review. The lost-response
case preserves raw axe and computed-style evidence. If the original result has
a contrast violation, a browser-only opacity counterfactual is recorded, every
original inline style is restored, and the test still asserts the original
result. This diagnostic cannot turn a failing native assertion into a pass.

## Native single-save receipts (delivery 18, receipt Task 3)

Run `tests/e2e/inline-receipts.spec.js` only after an exclusive controller
allocation of an existing local fixture, its WP-CLI wrapper and a new private
evidence/configuration directory. Read Ops safeguards and that fixture's README.
Use one worker, zero retries, no global setup/storageState, and desktop 1440×900
plus narrow 720×900. No fixture setup, plugin installation or queue drain:

```sh
BULKCAT_E2E_FIXTURE=1 BULKCAT_E2E_BASE_URL="$ASSIGNED_URL" \
WP_CMD="$ASSIGNED_WP_CMD" BULKCAT_E2E_OWNED_EVIDENCE="$RECEIPT_EVIDENCE/run-1" \
node_modules/.bin/playwright test -c "$RECEIPT_PRIVATE_CONFIG" \
  tests/e2e/inline-receipts.spec.js --grep-invert 'real HTTP completed response dropped|native Apache observer'
```

Give **every invocation a fresh evidence subdirectory**, including failed runs,
and derive the private Playwright `outputDir` from that directory. Preserve the
original controller configuration before editing it. Run the accepted
`single-save-refresh.spec.js` separately with the same guards/configuration;
its receipt-aware transport assertions do not replace its earlier acceptance.

`inline-receipts.php` is loaded through the existing wrapper's `wp eval`. Input
is `$bulkcat_receipt_args = {run: UUID-v4, siteurl: exact assigned URL, phase}`.
The only phases are `inspect`, `before`, `assert`, `cleanup`. `before` accepts an
in-memory random password and emits the original `bulkcat_native_baseline`, then
each owned user/product ID immediately after creation. `assert` and `cleanup`
require that exact `user` and `ids` list; UUID markers are rechecked before work.
`assert` selects bounded actions: owned REST dispatch, observation, capability
revocation/restoration, one owned competing CRUD write, exact queue delivery and
History/Undo refusal checks, same-user process observation, or exact owned
receipt removal. The last action simulates missing retention data; it does not
exercise the global pruning scheduler. All product changes use native CRUD.

The browser cases send real authenticated Apache HTTP requests and discard only
a completed PATCH reply. They verify read-only automatic/reload recovery, safe
sessionStorage, native controls/keyboard/axe, exact unrelated selection, one
request/grant/batch/item and no inline queue actions. Timings begin before the
Save activation and include grant issuance, PATCH, recovery and current reads.
The browser-loaded WordPress api-fetch test uses a genuinely invalid nonce with
an ordinary concurrent request. No production latency is inferred.

The two dropped-response cases and focused lifecycle regression require **explicit controller
authorization for temporary Apache observation**. They fail without the bounded
`inline-receipts-http.py` runner. This is a separate invocation from the CLI and
other browser cases; run all native/browser work separately from heavy Jest.
Inspect the existing Apache/PHP settings first: loaded mod_php, enabled
`AllowOverride`, no conflicting prepend, and exact active-file hashes/owner/mode.
The runner accepts that reviewed JSON snapshot (`files` keyed by absolute paths,
with sha256/uid/gid/octal mode or exists=false) and refuses configuration drift.
Record the exact authorized fixture and reversal in its existing private README.

```sh
BULKCAT_E2E_FIXTURE=1 BULKCAT_E2E_BASE_URL="$ASSIGNED_URL" \
WP_CMD="$ASSIGNED_WP_CMD" python3 tests/probe/inline-receipts-http.py \
  --container "$ASSIGNED_APACHE_CONTAINER" --url "$ASSIGNED_URL" \
  --settings-snapshot "$REVIEWED_PRIVATE_SETTINGS_JSON" \
  --evidence "$RECEIPT_EVIDENCE/http-1" --config "$RECEIPT_PRIVATE_CONFIG"
```

The runner backs up the existing root `.htaccess` bytes/ownership/mode/mtime,
copies `inline-receipts-http.php` into an exclusive temporary directory **outside
the webroot**, and adds only a temporary native `php_value auto_prepend_file`.
It never edits WordPress configuration/core/vendor/plugin source or installs a
plugin, MU plugin or route. The observer uses native preinitialized hooks; only
the exact private command header, run/request UUIDs, host, unexpired lifetime,
REST path and natively verified target marker can produce a record. Records
contain run/request/target IDs, positive loaded/target-verified flags, shutdown
phase flags and the target's `woocommerce_after_product_object_save` count, without request payload
or authentication. Every forwarded request must have a record: exactly one
target save in the original PATCH, zero in all grant/read/recovery requests.
An absent observer therefore cannot pass as zero saves. Separate CLI hook
counts remain separately attributed.

The counter is active from prepend time. Its PHP finalizer is registered at
`init`, after the installed WordPress `shutdown_action_hook` registration and
WooCommerce bootstrap callbacks. It records and releases the lifetime guard
after that native dispatcher returns, including its `shutdown` actions (such
as WooCommerce deferred product synchronization) and synchronous cache close.
Each final row must report one completed shutdown action and no active shutdown
action. This boundary does not promise to observe arbitrary PHP shutdown
callbacks registered later than the finalizer. Earlier prepend-registered
finalizer evidence is an early count, not a count through native shutdown.

To test this ordering independently, run the same bounded command with a fresh
evidence directory and `--lifecycle-only`. It selects one desktop regression and
enables a private, exact-header/UUID/target-guarded probe. During the real native
WordPress shutdown action, the probe dispatches one **synthetic save-hook event**
for the owned product; it does not call CRUD save or change business data. A
separate `lifecycle.jsonl` witness must show the event counted while shutdown is
active and the lease remains held. The final observation must include that
event after shutdown returns. This proves lifecycle ordering, not an additional
actual product save. The normal four HTTP journeys (two cases at two widths)
disable this injection and count actual native saves. Preserve pre-fix RED and
post-fix GREEN alongside their observer source identities.

The test command has a 540-second outer bound, private matching expires after
600 seconds, and signal/finally cleanup restores and verifies the original
configuration, waits boundedly for matched observer requests to release their
file-lifetime guards, and captures/removes only its exact temporary files,
including both observation and lifecycle JSONL files. That file
guard protects observer cleanup only; it is not a product/receipt lock. Normal
unmatched login HTTP is checked before, during and after. Retain the lifecycle,
backup, observations and all failures privately. Verify actual CLI/browser
children, exact UUID cleanup and protected baselines afterward; parent exit
alone does not prove cleanup. Failed restoration is an explicit failure with
retained recovery evidence, never permission to restart a shared service.

Separate instrumented WP-CLI processes call actual registered REST controllers,
repositories, SQL and WooCommerce hooks. They are **not Apache HTTP requests**.
They count `woocommerce_after_product_object_save` and `post_updated` for exact
owned IDs and qualify concurrent unique claims, committed acceptance before
execution, mid-write death after native save/before journal settlement, delayed
completion, partial/pre-effect outcomes and receipt/capability/payload fences.
The partial regression lowers regular price through a second native CRUD process
after acceptance; the variation regression changes only its current parent tax
status. They qualify per-object pre-Writer cache refresh, not serializable edits
or protection against a later concurrent write. No global cache flush is used.
The CLI evidence must not be relabelled as an HTTP hook counter; the temporary
Apache observer above supplies that separate native proof.

Barriers emit process/container/connection IDs and use checked nonblocking
STDIN, `stream_select` and a monotonic 45-second deadline. Only the bounded
`release\n` token releases execution; EOF, invalid/oversized input and timeout
fail closed. A native `barrier_check` assertion qualifies unreleased and partial
input deadlines, ordinary release and an oversized token, without replacing the
separate actual-death evidence. A death case sends SIGKILL only after inspecting the exact
auto-removing CLI container created by the assigned wrapper; shared WordPress
and database services are never killed. Same-user `SHOW PROCESSLIST` is projected
to the two owned IDs, command, state, seconds and observation time, without SQL.
Keep its actual server state (for example `Update`); do not translate elapsed
time into an unobserved `LOCK WAIT`. The separate claim statement timing and
barriers establish additional ordering, not extra database privileges.

The COMMIT fault uses a single-client TCP proxy child inside that same ephemeral
CLI container, listening only on its private loopback with an OS-assigned port.
Only the original process's database connection is redirected. It forwards the
real MySQL protocol, observes the server's COMMIT OK packet, then closes both
sockets **without forwarding that acknowledgement to mysqli**. It records only
packet size/sequence/time through an inherited observation descriptor, never
wire/SQL/credential payloads. Native wpdb can exit before later PHP shutdown
handlers execute: record that original exit (observed 1), do not invent a child
exit status, and prove the exact auto-removing container is gone.
No invalid SQL, substituted database return or thrown save exception stands in
for this boundary. Failure to observe the real packet leaves that gate unproved.
No new retained service, host listener or database setting is needed.

Private JSON is the ownership and reversal record: source/build/native hashes,
versions, UUID IDs, phase events, command/process exits, outcomes, timings and
cleanup. Signed grants may occur there; no private JSON, login material or
browser artifacts belong in Git or a public report. Credentials remain in test
memory. Cleanup waits for/reaps or terminates every exact owned process before
removing products and the account/sessions/views/auto-drafts. It compares the
original protected baseline and source identity; plugin audit/receipt records
remain evidence, so this is not whole-database equality. On interrupted cleanup,
use the recorded UUID and exact IDs, preserve its failed record, then save the
recovered comparison separately. Do not repair a mismatch by resetting the site.

Finish all native/browser processes and cleanup **before** a genuine no-vendor
run. Check actual owned CLI/proxy children through their exact host cgroups and
owned browser descendants through the inherited evidence-directory marker; a
Docker/Playwright parent exit alone is not the cleanup proof. Preserve the
process audit, UUID cleanup and protected baseline comparison before isolation. `composer test:standalone` otherwise delegates to installed PHPUnit. Use an
external dependency backup, a restoration trap and a before/after file manifest;
never place dependency copies in an unignored repository directory. Preserve the
expected unavailable-POT-tooling skip separately from passing assertions.

## Performance (T76)

`perf.py` measures SPEC §12.5 on the scale catalogue and exits 1 when a
budget is missed; `PERF.md` records the last run. It is slow (about 17
minutes, most of it two 5,000-product batches), so it is not in CI; run it by
hand before a release. It needs MySQL 8 and no persistent object cache, and
it changes data (it applies and undoes a 10 % price increase on 5,000
products, adds 200 history rows and prunes 50,000 journal rows): only ever on
a throwaway stack.

The stack is the docker one above with `mysql:8` in place of `mariadb:11`
and its own names, so it can run beside a probe stack:

```sh
docker network create bulkcat-perf
docker volume create bulkcat-perf-wp
docker run -d --name bulkcat-perf-db --network bulkcat-perf \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress \
  -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress mysql:8
docker run -d --name bulkcat-perf-wp --network bulkcat-perf -p 127.0.0.1:8896:80 \
  -e WORDPRESS_DB_HOST=bulkcat-perf-db -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
  -v bulkcat-perf-wp:/var/www/html \
  -v "$PWD":/var/www/html/wp-content/plugins/bulk-catalogue-manager-for-woocommerce:ro \
  wordpress:php8.3-apache
export WP_CMD="docker run --rm -i --network bulkcat-perf --user 33:33 -e HOME=/tmp \
  -e WORDPRESS_DB_HOST=bulkcat-perf-db -e WORDPRESS_DB_USER=wordpress \
  -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
  -v bulkcat-perf-wp:/var/www/html \
  -v $PWD:/var/www/html/wp-content/plugins/bulk-catalogue-manager-for-woocommerce:ro \
  wordpress:cli-php8.3 wp"
sleep 20
$WP_CMD core install --url=http://localhost:8896 --title=BULKCAT --admin_user=admin \
  --admin_password=password --admin_email=admin@example.com --skip-email
$WP_CMD plugin install woocommerce --version=11.1.0 --activate
$WP_CMD config set DISABLE_WP_CRON true --raw     # only perf.py drives Action Scheduler
$WP_CMD config set SAVEQUERIES "isset( \$_SERVER['HTTP_X_BULKCAT_PERF_SQL'] )" --raw   # SQL on demand
bash tests/probe/seed.sh 20000                  # about two minutes; see seed.sh
```

Then drop in the measurement mu-plugin. It is not part of the plugin and is
never committed: it only adds the numbers `perf.py` reads. Save this as
`bulkcat-perf-probe.php` and copy it in with
`docker exec bulkcat-perf-wp mkdir -p /var/www/html/wp-content/mu-plugins &&
docker cp bulkcat-perf-probe.php bulkcat-perf-wp:/var/www/html/wp-content/mu-plugins/`:

```php
<?php
/* Plugin Name: BULKCAT perf probe (throwaway) */
$GLOBALS['bulkcat_perf'] = array( 't0' => (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) );
add_filter( 'rest_pre_dispatch', function ( $r ) {
	$GLOBALS['bulkcat_perf']['q0'] = $GLOBALS['wpdb']->num_queries;
	$GLOBALS['bulkcat_perf']['d0'] = microtime( true );
	return $r;
}, -1000 );
add_filter( 'rest_post_dispatch', function ( $response ) {
	global $wpdb;
	if ( $response instanceof WP_HTTP_Response && isset( $GLOBALS['bulkcat_perf']['q0'] ) ) {
		$now = microtime( true );
		$response->header( 'X-BULKCAT-Perf', wp_json_encode( array(
			'dispatch_queries' => $wpdb->num_queries - $GLOBALS['bulkcat_perf']['q0'],
			'queries'          => $wpdb->num_queries,
			'dispatch_ms'      => round( ( $now - $GLOBALS['bulkcat_perf']['d0'] ) * 1000, 1 ),
			'server_ms'        => round( ( $now - $GLOBALS['bulkcat_perf']['t0'] ) * 1000, 1 ),
			'peak_mb'          => round( memory_get_peak_usage() / 1048576, 2 ),
			'memory_limit'     => ini_get( 'memory_limit' ),
		) ) );
	}
	return $response;
}, PHP_INT_MAX );
$bulkcat_perf_log = function ( array $row ) {
	file_put_contents( WP_CONTENT_DIR . '/bulkcat-perf.log', wp_json_encode( $row ) . "\n", FILE_APPEND | LOCK_EX );
};
if ( ! empty( $_SERVER['HTTP_X_BULKCAT_PERF_LOG'] ) ) {
	$id = substr( preg_replace( '/[^A-Za-z0-9_.-]/', '', $_SERVER['HTTP_X_BULKCAT_PERF_LOG'] ), 0, 64 );
	register_shutdown_function( function () use ( $id, $bulkcat_perf_log ) {
		global $wpdb;
		$row = array( 'kind' => 'request', 'id' => $id, 'total_ms' => round( ( microtime( true ) - $GLOBALS['bulkcat_perf']['t0'] ) * 1000, 1 ),
			'queries' => $wpdb->num_queries, 'peak_mb' => round( memory_get_peak_usage() / 1048576, 2 ) );
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$row['sql'] = array_map( fn( $q ) => array( round( $q[1] * 1000, 2 ), $q[0] ), $wpdb->queries );
		}
		$bulkcat_perf_log( $row );
	} );
}
// perf.py's undo_scale group: run Action Scheduler's queue inside a plain web request, as its
// WP-Cron and async runners do, when asked with the key perf.py stores for the run in the
// bulkcat_perf_queue_key option. The runner raises the limit to WP_MAX_MEMORY_LIMIT; the stack
// sets that to 128M for the group, and the answer reports the limit the run had.
add_action( 'wp_loaded', function () {
	$key = isset( $_SERVER['HTTP_X_BULKCAT_PERF_QUEUE'] ) ? (string) $_SERVER['HTTP_X_BULKCAT_PERF_QUEUE'] : '';
	if ( '' === $key || ! hash_equals( (string) get_option( 'bulkcat_perf_queue_key', '' ), $key ) ) {
		return;
	}
	// Two minutes a request, not Action Scheduler's 30 seconds: many chunks in one request, fewer requests.
	add_filter( 'action_scheduler_queue_runner_time_limit', fn() => 120 );
	$ran = ActionScheduler_QueueRunner::instance()->run( 'bulkcat perf web runner' );
	wp_send_json( array( 'ran' => $ran, 'memory_limit' => ini_get( 'memory_limit' ), 'peak_mb' => round( memory_get_peak_usage() / 1048576, 2 ) ) );
} );
foreach ( array( 'bulkcat_run_chunk', 'bulkcat_prune_history' ) as $hook ) {
	add_action( $hook, function () {
		gc_collect_cycles();
		memory_reset_peak_usage();
		$GLOBALS['bulkcat_perf']['a0'] = array( microtime( true ), $GLOBALS['wpdb']->num_queries, memory_get_usage() );
	}, PHP_INT_MIN );
	add_action( $hook, function ( ...$args ) use ( $hook, $bulkcat_perf_log ) {
		list( $t, $q, $m ) = $GLOBALS['bulkcat_perf']['a0'];
		$bulkcat_perf_log( array( 'kind' => $hook, 'args' => $args, 'ms' => round( ( microtime( true ) - $t ) * 1000, 1 ),
			'queries' => $GLOBALS['wpdb']->num_queries - $q, 'start_mb' => round( $m / 1048576, 2 ),
			'peak_mb' => round( memory_get_peak_usage() / 1048576, 2 ), 'end_mb' => round( memory_get_usage() / 1048576, 2 ) ) );
	}, PHP_INT_MAX, 5 );
}
```

Query counts are `$wpdb->num_queries`, which counts exactly the statements
SAVEQUERIES would list. A request sent with the header `X-BULKCAT-Perf-Sql: 1`
and `X-BULKCAT-Perf-Log: <id>` also logs its statements with their times, for
profiling a missed budget (`EXPLAIN ANALYZE` the slow one in the database
container).

Run it, all groups or some (`--only grid,export`; `--help` lists them), and
write the numbers down:

```sh
bash tests/probe/seed.sh 20000   # also writes seed.json with the probe users
python3 tests/probe/perf.py --base-url http://localhost:8896 --json /tmp/performance.json
```

The `undo_scale` group (T111) runs only when named: it makes a
100,000-object change (Weight set to 0.777 on the scale category after
`seed.sh 22223`) and undoes it, or undoes one already made
(`--undo-batch N`). Its web requests and queue run must stay at 128 MB, so
give the stack `WP_MAX_MEMORY_LIMIT` 128M for the run (Action Scheduler's
runners raise the limit to it) and remove it after:

```sh
$WP_CMD config set WP_MAX_MEMORY_LIMIT 128M
python3 tests/probe/perf.py --base-url http://localhost:8896 --only undo_scale [--undo-batch N]
$WP_CMD config delete WP_MAX_MEMORY_LIMIT
```

It takes hours: the change and its undo each run 100,000 product saves, and
each of the undo's web requests (preview, last page, conflict count,
confirm) plans all 100,000 objects, about two minutes apiece.

What each number means, and why: server time is the request from PHP's start
to the end of the REST dispatch as the mu-plugin sees it (WordPress and
WooCommerce's own bootstrap, about 60 queries, included); queries are those
inside the dispatch, the plugin's own; a chunk's memory is what it adds to the
memory it started with, on top of a light REST request's peak, because under
WP-CLI WooCommerce's commands alone hold about 110 MB. `PERF.md` has the
last run and the reasoning behind each budget.

Tear it down, the mu-plugin with it:

```sh
docker rm -f -v bulkcat-perf-wp bulkcat-perf-db
docker volume rm bulkcat-perf-wp
docker network rm bulkcat-perf
```

## Exact selection and durable requests (T81–T83)

The PHP probes named `exact-*.php` and `selection-*.php` run inside WordPress
through WP-CLI on a disposable stack whose plugin directory is this checkout,
never on a real shop: each refuses to run (`fixture.php`,
`bulkcat_probe_fixture()`) unless `siteurl` is the owned fixture's
(`http://localhost:18925`) or the WP-CLI process is given
`BULKCAT_PROBE_SITEURL` naming this site's URL on a local development host
(`localhost`, `127.0.0.1`, `[::1]`, `*.localhost`, `*.test`; with the
docker stacks above, add `-e BULKCAT_PROBE_SITEURL=http://localhost:8888`
to `WP_CMD`), and its header gives its command, for example

```sh
wp eval 'require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/exact-batch.php";'
```

(`eval` with `require`, not `eval-file`, so the file's `strict_types` holds).
T83's are `exact-batch.php` (the service journey: capture, exact preview,
durable Apply and its retries, the plugin's queue, journal, undo),
`exact-batch-routes.php` (the same through the REST routes, with their
refusals), `exact-batch-faults.php` (rollback, lost COMMIT, scheduling gaps,
expiry, conflicts, tombstones, changed kinds, unavailable members, epochs,
pruning and 1.0 request ids) and `exact-batch-race.php`, whose phases two
overlapping WP-CLI processes run (`$phase="prepare_apply"; require …`, then
`hold_apply` and `peer_apply` at once, then `verify_apply`; the same for
`undo`). They print their checks as JSON and exit 1 on the first failure,
cleaning up the products they made. `probe.py`'s write and variations
chapters take selections and changes through `POST /requests` grants.

The plugin's cleanup is bounded (at most 100 payloads or receipts a run,
oldest first), so a probe that checks its own row is gone drains it
(`bulkcat_probe_drain()`: runs until one takes less than a full run, at most
100 runs) rather than calling it once; a backlog left by earlier runs, such
as the e2e lifecycle specs, cannot hide its row. `stale-selections.php` makes
such a backlog (`$stale = 500; require …/stale-selections.php`: selections
captured with the clock nine days back, so their payloads and receipts all
await cleanup); `exact-selection-cleanup.php` and
`exact-selection-capacity.php` still call the cleanup once, since they check
its bound.

## Historical `offon` chapter (tickets 04 and 14)

`probe.py --chapters offon` retains its stable row identifiers while checking
current native Status writes through the manager’s own routes. New Draft does
not capture a record; new Publish does not restore a remembered status. Explicit
Undo, native scheduling, buyer reachability, foreign/malformed records, and exact
legacy predicates remain covered. Rows that need a legacy record create labelled
synthetic metadata; they do not claim to prove an archive upgrade. Its checks live in
`tests/probe/offon.py`; its fixtures are products it upserts through WP-CLI
(SKUs `bulkcat-offon-*`, category "Bulkcat Offon"), so it runs on the stack
above after `seed.sh`, before the destructive lifecycle chapter. One row waits
75 seconds for a scheduled product's time to pass.

Its buyer rows (`offon:a*`, `offon:b*`, `offon:c*`) visit the storefront as an
anonymous visitor and as a logged-in customer with cookie jars of their own:
the product page, the id link, the category archive, search, the Store API,
the WordPress REST product, the sitemap, the feed, oEmbed, add to cart, and
carts filled while On (Store API and classic checkout, a cart read, a
persistent cart after its session is deleted, Order again). For a
billing-only checkout the chapter turns on Check payments, turns shipping
off and makes a `[woocommerce_checkout]` page (`/bulkcat-offon-checkout/`);
it records the three WooCommerce options it changes and puts them back at
the end (`offon:shop-options-restored`), and removes the page if it made it.
Its customers (`bulkcat_offon_*`, the seed's password) and the orders it
places are the throwaway stack's. `offon:e7-*` checks that WooCommerce and
this plugin are the only active plugins, with no must-use plugin.

`perf.py --only offon` measures the switch at scale on the performance stack
below: Off, the undo of that Off, Off again and On over about 5,000 simple
products through the durable routes, each against the write budget, and the
grid with the Status column and the Off filter while they are Off.

**Historical procedure, not the native Status14 release gate:**
`tests/probe/offon-upgrade.sh` was written to prove an upgrade from the 1.0.0 archive to
this branch and a downgrade back on a throwaway stack of its own (default
port 8911, every docker name suffixed `-offon`), whose plugin directory is
part of the WordPress volume rather than a read-only bind mount so that
`wp plugin install <zip> --force` can replace the plugin as an update does.
It checks the 1.0.0 archive's full sha256 first and builds the branch archive
with `bin/build-zip.sh` (from HEAD, so commit first):

```sh
ZIP10=/path/to/bulk-catalogue-manager-for-woocommerce-1.0.0.zip bash tests/probe/offon-upgrade.sh all
```

`all` runs `up`, `upgrade`, `downgrade` and `down` (the teardown runs even
after a failure); set `WORK` to a directory to run the phases one by one. The
script's header lists every row.

## External actions (T104–T109)

The generic external action contract (`docs/EXTENSIONS.md`) is proven on a
real site with the neutral test provider "Sample labels", which lives only in
`tests/Support/Extension/` and is never shipped. On a throwaway stack (the one
above, or one of its own with every docker name suffixed and another host
port, as T109 ran it) copy its must-use plugin into place after `seed.sh`:

```sh
docker exec bulkcat-probe-wp mkdir -p /var/www/html/wp-content/mu-plugins
docker cp tests/fixtures/mu-plugins/bulkcat-sample-provider.php \
  bulkcat-probe-wp:/var/www/html/wp-content/mu-plugins/
docker exec bulkcat-probe-wp chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
```

It registers only when the core is active and loads the provider from the
bind-mounted checkout. Remove it again before `probe.py` (`offon:e7-*` checks
that no must-use plugin is present):

```sh
docker exec bulkcat-probe-wp rm /var/www/html/wp-content/mu-plugins/bulkcat-sample-provider.php
```

With it in place and `BULKCAT_PROBE_SITEURL` added to `WP_CMD`:

- `provider-journey.php` walks E5–E12 through the plugin's own services,
  REST routes, queue, journal and undo, with every provider-missing case,
  and cleans up what it made. Its uninstall phases prove E16 around a real
  uninstall with *Remove all data* on (then reactivate and seed again):

  ```sh
  $WP_CMD eval '$phase = "journey"; require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/provider-journey.php";'
  $WP_CMD eval '$phase = "uninstall_before"; require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/provider-journey.php";'
  $WP_CMD plugin uninstall bulk-catalogue-manager-for-woocommerce --deactivate --skip-delete
  $WP_CMD eval '$phase = "uninstall_after"; require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/provider-journey.php";'
  $WP_CMD plugin activate bulk-catalogue-manager-for-woocommerce
  ```

- `provider-scale.php` (E15), after `seed.sh 10000`: `/fields` with and
  without one provider, the test provider's state token over 10,000 products,
  a 10,000-object preview of Add labels next to a native preview of the same
  capture, a 1,000-object Apply through the queue (at least 300 products a
  minute, under 96 MB a chunk) and its undo. It prints the figures as JSON.

- `tests/e2e/provider-labels.spec.js` (E14) is the browser journey by
  keyboard with axe-core: two SKU searches, Add labels, preview, Apply,
  History, Undo, the labels read back through `WP_CMD`. It skips itself on a
  site without the must-use plugin, so the rest of the browser suite runs
  unchanged elsewhere.

- `tests/e2e/provider-actions.spec.js` (contract version 2, 1.4.x) drives the
  version 2 journeys in the browser with the neutral "Sample picks" provider
  (`bulkcat-picks-provider.php`) and the category provider "Sample shelves"
  (`bulkcat-shelves-provider.php`, Put on shelves / Take off shelves on
  product categories only): a row action on one product through its Actions
  menu and the add-on's group (search picker, Apply, View in History, Undo,
  a retained selection of another product untouched), a bulk action on a
  retained selection of two products, and a category action on two
  categories with Include subcategories (five categories changed, a sibling
  left alone, History, Undo). Its products and categories are its own (UUID
  names, deleted afterwards), and stored values are read back through
  `WP_CMD`. With the fixture opt-in (`BULKCAT_E2E_FIXTURE=1`) it copies the two
  must-use plugins in itself and removes only what it copied, as
  `provider-labels.spec.js` now does with "Sample labels"; the site's plugin
  must carry this checkout's `tests/` (wp-env, or the stack above).

## Native release gate in one command (TESTS-8)

`tests/probe/gate.sh all` runs the native probes a release gate needs on a
throwaway stack of its own (every docker name suffixed `$SUFFIX`, default
`-gate`, port `$PORT`, default 8960) and removes the stack afterwards, even
after a failure:

```sh
RELEASED=/path/to/bulk-catalogue-manager-for-woocommerce-1.1.1.zip \
RELEASED_SHA256=<its sha256> \
CANDIDATE=dist/bulk-catalogue-manager-for-woocommerce-<version>.zip \
WC_ZIP=/path/to/woocommerce.11.1.2.zip \
  bash tests/probe/gate.sh all
```

1. **up**: WordPress, WooCommerce and the released archive, the plugin inside
   the WordPress volume (an upgrade is a real `wp plugin install --force`).
2. **upgrade**: `seed.sh`, then `native-status-upgrade.sh` seed, upgrade,
   verify and cleanup against the two archives (the released archive must be
   from before native Status, 1.1.x: the probe checks that release's own
   `set_value` work carries over).
3. **mount**: the web container again, with this checkout's `tests/`
   read-only inside the installed plugin; the plugin itself stays the archive.
4. **native**: the fault probes (`exact-selection`, `exact-selection-routes`,
   `exact-selection-cleanup`, `exact-selection-capacity`, `selection-lifecycle`,
   `selection-lifecycle-routes`, `exact-batch`, `exact-batch-routes`,
   `exact-batch-faults`, `stock-undo`), the two-process races
   (`exact-selection-race`; `exact-batch-race` apply and undo;
   `selection-lifecycle-race` dual renew, renew against prune, prune against
   renew, and compose), and the add-on journeys (`provider-journey`,
   `provider-v2-journey`) with the "Sample labels" and "Sample picks" must-use
   plugins copied in for them and removed after.
5. **down**.

Each step prints PASS or FAIL with its time to `$WORK/summary.txt` and keeps
its output in `$WORK/logs/<step>.log`; the script exits 1 if any step failed.
Set `WORK` to run the phases one by one (`up`, `upgrade`, `mount`, `native`,
`down`) or to keep the evidence; `ONLY="exact-batch race-exact-batch-apply"`
narrows `native` to named steps. `WORK` holds signed test grants: keep it
private. `probe.py` and the Playwright suite then run on any seeded stack as
above.

## Probes clean up after themselves

Every chapter that makes products removes them at its end through WooCommerce
CRUD, so the grid's first page shows the seed again for whatever runs next
(the browser specs): `read:export:cleanup` (the export round trip's fixtures,
keyed `_bulkcat_probe_key`, and their terms and page), `variations:cleanup`
(Probe Sixty) and `offon:cleanup` (SKUs `bulkcat-offon-*`, the chapter's
categories and tag; its orders and customers stay with the throwaway stack).
`read:products:live-schema` makes and deletes its own Scheduled product and
variation, so a scheduled row is always validated against the schema.

## Native Status oracle and publication lifecycle (plan 14)

Use an **existing, explicitly assigned** synthetic candidate fixture. The
comparator is the actual native product editor on that fixture, not a previous
manager release. Keep any separate released-archive upgrade fixture untouched;
do not create a stack, reseed, or run the lifecycle/offon chapters as setup.
Supply the assigned fixture’s seed file; never overwrite `seed.json`:

```sh
BULKCAT_FIXTURE_URL="$BULKCAT_E2E_BASE_URL" \
BULKCAT_NATIVE_STATUS_EVIDENCE=/private/owned-fixture/native-status-evidence \
python3 tests/probe/probe.py --base-url "$BULKCAT_E2E_BASE_URL" \
  --wp-cmd "$WP_CMD" --seed "$SEED_JSON" --chapters native-status
```

`native-status.py` follows the existing chapter interface. It evaluates the
repository's `fixture.php` guard and `native-status.php` setup/readback helper
inside ephemeral WP-CLI processes: no installed helper, MU plugin, new fixture,
or engine substitution. Guarding requires the explicitly named local URL to
match the actual site's `siteurl`. Never point `WP_CMD` at a different site.
The chapter is opt-in (`--chapters native-status`), excluded from the default
chapter list; it fails closed without the matching guard and private evidence directory. An optional comma-separated
`BULKCAT_NATIVE_STATUS_CASES` limits a covering rerun by exact fixture case name.

The native leg submits the classic product editor's successful form controls
to `wp-admin/post.php` using its real session/nonce, or submits the native
`woocommerce_save_variations` AJAX request using the parent editor's nonce.
A user denied the parent editor does not get an invented nonce: editor denial
and the separately inspected coarse AJAX permission gate are reported distinctly.
The manager leg uses real HTTP preview/grants/acceptance, then calls the
installed `ChunkRunner::handle` for that exact batch (never drains other work).
A raw new `set_value` status request intentionally exercises old-client
normalisation at the candidate’s new-write boundary. Accepted legacy execution remains in
the unchanged `status-switch.json` tests, not redefined by this chapter.

Every case uses unique run-owned products, paired initial state and dedicated
capability actors. Fixture creation sets an administrator only in its short-lived
setup process, to retain explicit slugs for native draft/pending records. Worker
execution uses its normal recorded-actor path. Variation menu order starts at
the native one-child value; the probe still fingerprints it. The private JSON records native submissions, manager
preview/batch reports, status, local/GMT/CRUD dates, password fingerprints,
legacy metadata, unrelated fields, modification time and the exact native
`publish_future_post` hook/arguments/timestamp. It never stores cookies or
password values. Source-derived ±minute boundaries use the injected-clock unit
fixture; the live probe uses distant dates. Zero GMT is deliberately seeded on
an owned product only; a missing GMT submission is distinct from an absent SQL
column (WordPress has no such post row).

The JSON is also the ownership/reversal record: original timezone and baseline
are saved before writes, each pair's IDs before submissions, and actor IDs
before requests. `finally` deletes only exact UUID-marked products (children
before parents) and users and restores the original timezone. Native authentication uses a
UUID-owned administrator, and its auto-draft dashboard posts are removed before
deleting that user. Existing users, their sessions and preferences are untouched.
Native audit batches/receipts are retained as candidate evidence in the fixture;
no queue-wide pruning, uninstall or reset is performed. Compare the saved hashes of original products/variations and their metadata,
terms/taxonomy/relationships, users, non-session usermeta and selected
configuration after cleanup. These hashes do not claim to cover the whole database. On interruption, use this record and verify
each object's `_bulkcat_native_probe` UUID before cleanup; do not use broad name
or SKU matching. Task 5 must seed its accepted-but-unfinished upgrade cases
against the preserved release ZIP through its own guarded workflow; completed
Task 1 history is not proof of queued upgrade recovery.

Each run records start/end SHA-256 manifests of the candidate PHP sources and
built assets and probe/fixture files, plus Git HEAD and the runtime version labels.
A changing manifest fails the chapter. A version label alone
does not establish an archive identity. A changed manifest means the run cannot
be claimed as one run of the final source. The native chapter also counts exact
object CRUD saves and native post updates: equal second-resolution timestamps
are not proof of no save. Stored GMT and observed scheduled event timestamps
are recorded separately, including site-timezone/DST cases; the probe does not
claim that cron eventually published anything.

The companion lifecycle probe exercises exact native password transport and
Undo, conflicts/public redaction, interrupted coupled saves and recorded-actor
revocation, final checks after setters, hook intent/cleanup, and complete
publication binding over 1,001 owned objects. It also checks preview/PATCH/GET
and renders History with the existing browser build, without changing the UI:

```sh
BULKCAT_FIXTURE_URL="$BULKCAT_E2E_BASE_URL" \
BULKCAT_NATIVE_STATUS_EVIDENCE=/private/owned-fixture/publication-evidence \
python3 tests/probe/native-publication.py --base-url "$BULKCAT_E2E_BASE_URL" \
  --wp-cmd "$WP_CMD" --seed "$SEED_JSON"
```

`BULKCAT_NATIVE_PUBLICATION_CASES` selects exact comma-separated cases from
`transport,private-undo,crash,setter-failure,final-check,hooks,binding,http,scheduled-replay,variation-password` for
repair reruns. The browser checks use installed Playwright and the exact owned
HTTP session in process memory; cookies/passwords are not saved in evidence.
The binding measurement is bounded cost evidence, not the release scale gate.
Native-oracle, harness and cleanup failures must remain in the evidence beside
any corrected rerun. Investigate a baseline discrepancy before acceptance;
never overwrite original user preferences or remove unrelated sessions to make
a hash match. Never report a source-only concern as a demonstrated exploit.

`scheduled-replay` crosses the real minute boundary after an interrupted Scheduled
save and distinguishes it from an unwritten Future→Publish intent, with retained
and revoked publication rights. `variation-password` qualifies the native
variation datastore’s omitted password load/update, then proves safe refusal of
password-bearing Private changes, internal restoration and pending replay. Empty
password variation edits remain supported; no variation password writer is added.
The Status chapter includes future-dated variation Preview/Apply/native readback.


## Native archive qualification and full publication binding (plan 14, Task 5)

Use `native-status-upgrade.sh` with explicit immutable release and candidate ZIPs.
It never builds a package, creates/resets a stack, or uninstalls the plugin. Read
the assigned fixture's ownership/runbook first. Its web container must have no
source bind mount; every installed archive file is hashed before and after the
native installer upgrade. Version labels alone are insufficient.

Supply every argument in every phase (values below come from the owning fixture):

```sh
bash tests/probe/native-status-upgrade.sh seed \
  --base-url "$BULKCAT_FIXTURE_URL" --wp-cmd "$WP_CMD" --seed "$SEED_JSON" \
  --evidence "$PRIVATE_UPGRADE_EVIDENCE" --container "$OWNED_WEB_CONTAINER" \
  --released "$RELEASED_ZIP" --released-sha256 "$RELEASED_SHA256" \
  --candidate "$CANDIDATE_ZIP" --candidate-sha256 "$CANDIDATE_SHA256" \
  --candidate-install-path "$CANDIDATE_PATH_INSIDE_CLI"
```

Run `seed` while the actual released archive is installed, then `upgrade`,
`verify`, and `cleanup` with the same arguments. Released RequestService creates
accepted pending work, work interrupted after its native save but before journal
completion, an unaccepted signed grant, completed Draft and restore history,
and saved legacy views. UUID product/account IDs, fingerprints, mapped permissions,
and exact CRUD/native-post save counts are recorded before upgrading. Revocation
removes the capability that actually maps to the owned object's `edit_post`, and
asserts denial before executing the release-created batch. A permission skip is
a truthful partial outcome, with no save; receipt lookup still obeys route access.

Never reseed over a failure. Each phase writes a new immutable observation file;
state remains the ownership/reversal record. `verify-history` is a scoped
continuation for history/new-write checks after a preserved worker failure.
`verify-denied` checks a preserved denied-worker observation and its unchanged
snapshot, then covers replay under denied/restored permissions. Neither phase
substitutes candidate-created work for release-created work or converts a failed
run into a full-green run. Report the original failure and covering evidence.
Cleanup deletes only the recorded UUID's products, users, sessions and auto-drafts,
and compares the exact original baseline. Audit history/receipts remain evidence.
Signed grants and fixture state belong outside Git in a private evidence folder.

`tests/e2e/native-status.spec.js` adds native keyboard/one-Save, narrow-screen,
Scheduled GET/refresh, exact mixed parent/direct-variation selection, threshold
confirmation, and real failed/partial outcomes. Supply a UUID-owned native login
via `BULKCAT_E2E_USER`/`BULKCAT_E2E_PASSWORD` on retained fixtures.
The normal disposable CI job opts into the same local fixture guard and runs
the complete browser suite with its fixture administrator. The paging lifecycle cases create and remove their own99 UUID-named products through WooCommerce. `status-off-on.spec.js` opens the saved legacy views in the actual grid, checks their labels and exact membership, and checks
new native normalization, including explicit obsolete-intent refusal. Optional
provider/browser contract checks still run separately on a disposable fixture.

The scale probe requires a separate explicitly disposable fixture. Inventory its
resources, ownership and reversal before setup; do not enlarge a retained native
fixture as incidental preparation. It creates 22,223 products and 77,777 variations
through WooCommerce CRUD and records resumable UUID-owned chunks. Setup uses
WooCommerce's native transaction API per bounded chunk, defers term counting and
product transient cleanup, then flushes those and parent synchronization before
commit. It never wraps the measured publication operations in a setup transaction.
No business-data SQL seeding or database configuration changes are involved.
Each chunk records the native setup mode and exact newly created IDs; existing
UUID SKUs are checked for ownership and reused on resume. Preserve the private
state and inspect UUID inventory after any uncertain commit before resuming:

```sh
python3 tests/probe/publication-scale.py seed --disposable \
  --base-url "$BULKCAT_FIXTURE_URL" --wp-cmd "$WP_CMD" --seed "$SEED_JSON" \
  --evidence "$PRIVATE_SCALE_EVIDENCE"
# Then qualify, then cleanup, with identical arguments.
```

Qualification captures all 100,000 identities, previews a native Status change,
changes the last unsampled object's date, and requires stale-preview refusal.
Fresh acceptance must materialize exactly those identities without saving them;
a later date change must still replay the original receipt. Only this batch is
cancelled before execution: this is functional full-selection CLI proof, not a
100,000-product write-throughput run. Measured CLI time/query/peak-memory results are evidence, not inferred
HTTP budgets: PHP 8.1 cannot reset its process peak. Installed runtime and harness
hashes bracket the run. Read the reported effective `memory_limit`; a CLI wrapper
startup setting does not prove that WordPress/WP-CLI kept that setting. Cleanup
records descending chunks of at most500 owned IDs, checks each ownership marker,
and uses WooCommerce deletion with the same native setup deferrals. It verifies
zero remaining UUID products before deleting the exact UUID account. Audit
batches/receipts remain as evidence; do not run unrelated pruning to remove them.
The 1,001-object native-publication measurement remains a
smaller lifecycle check and cannot substitute for this complete-selection gate.

Run destructive uninstall/data-removal chapters only on the separately disposable
fixture, after the upgrade and browser evidence is preserved. Never file-downgrade
queued `set_status` work to 1.1.0: drain or explicitly handle it first, and follow
`docs/RELEASE.md` for data/history compatibility and controller release gates.
