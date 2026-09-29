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
