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
admin stylesheet replaces the focused link's underline with its focus ring; the
test preserves that behavior and does not suppress axe rules.

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

## Global Off/On (ticket 04, T89–T94)

`probe.py --chapters offon` proves Status as the global Off/On switch
(SPEC §5.5) through the manager's own routes. Its checks live in
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

`tests/probe/offon-upgrade.sh` proves an upgrade from the 1.0.0 archive to
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

## Native Status oracle (plan 14, deliberately RED before engine changes)

Use an **existing** owned synthetic fixture with the actual released archive
installed through the native plugin installer. Do not mount the candidate over
it, reseed another fixture, or run the lifecycle/offon chapters as setup. Supply
its own seed file; never overwrite the default `seed.json`:

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
normalisation after the future upgrade. Accepted legacy execution remains in
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
before parents) and users and restores the original timezone. Native audit
batches/receipts are retained as release evidence in the disposable fixture;
no queue-wide pruning, uninstall or reset is performed. Compare exact baseline
product/category rows after cleanup. On interruption, use this record and verify
each object's `_bulkcat_native_probe` UUID before cleanup; do not use broad name
or SKU matching. Task 5 must seed its accepted-but-unfinished upgrade cases
against the preserved release ZIP through its own guarded workflow; completed
Task 1 history is not proof of queued upgrade recovery.

A nonzero result on released 1.1.0 is expected for its obsolete restoration,
record, prediction or permission behaviour. Native-oracle, harness and cleanup
failures are **not** expected RED: inspect and resolve or report those separately.
Never report a source-only permission concern as a demonstrated exploit.
