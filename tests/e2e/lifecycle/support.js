/**
 * T85: what the lifecycle specs share. They drive the screen in the browser
 * and read the site through WP-CLI (`WP_CMD`, as the probe does) to see what
 * the screen cannot: the plugin's own tables, the queue and product values.
 * Nothing here writes a product except through WooCommerce's CRUD classes,
 * and every spec puts back what it changes.
 *
 * The short lease: a request carrying `X-Bulkcat-E2E-Ttl: <seconds>` gets
 * that authorization lease through the plugin's documented
 * `bulkcat_selection_ttl` filter (clamped to 60–86,400 seconds by the
 * plugin), from a throwaway must-use plugin the specs install and remove on
 * the test site only (installLeaseProbe()). It is not part of the plugin.
 */
const { execSync } = require( 'child_process' );
const { expect } = require( '@playwright/test' );
const { SCREEN, runQueue } = require( '../helpers' );
const { fixtureRefusal } = require( './guard' );

const WP = process.env.WP_CMD || '';

let refusal = null;

/**
 * Why these specs may not touch the site, or '' (guard.js): asked once.
 *
 * @return {string} The refusal, or ''.
 */
function fixtureCheck() {
	if ( null === refusal ) {
		refusal = fixtureRefusal( {
			optIn: process.env.BULKCAT_E2E_FIXTURE || '',
			wpCmd: WP,
			baseUrl:
				process.env.BULKCAT_E2E_BASE_URL || 'http://localhost:8888',
			siteUrl: () =>
				execSync( `${ WP } option get siteurl`, {
					encoding: 'utf8',
					timeout: 120_000,
				} ),
		} );
	}
	return refusal;
}

/** The must-use plugin's file name on the test site. */
const LEASE_PROBE = 'bulkcat-e2e-lease.php';

/**
 * Run PHP on the site through WP-CLI and return what it printed as JSON on
 * its last line. `input` reaches the code as `$in`.
 *
 * @param {string} code  PHP, without the opening tag.
 * @param {*}      input Data for the code.
 * @return {*} The decoded answer.
 */
function php( code, input = null ) {
	if ( fixtureCheck() ) {
		throw new Error( `Refused: ${ fixtureCheck() }` );
	}
	const payload = Buffer.from( JSON.stringify( input ) ).toString( 'base64' );
	const out = execSync( `${ WP } eval-file -`, {
		input: `<?php $in = json_decode( base64_decode( '${ payload }' ), true );\n${ code }`,
		encoding: 'utf8',
		timeout: 300_000,
		maxBuffer: 64 * 1024 * 1024,
	} );
	const lines = out.trim().split( '\n' );
	return JSON.parse( lines[ lines.length - 1 ] || 'null' );
}

/**
 * Rows of one SELECT on the site's database, `{p}` standing for the table
 * prefix; `args` are prepare() arguments.
 *
 * @param {string} query SQL.
 * @param {Array}  args  Arguments.
 * @return {Array<Object>} Rows.
 */
function rows( query, args = [] ) {
	return php(
		`global $wpdb; $q = str_replace( '{p}', $wpdb->prefix, $in['q'] );
		echo wp_json_encode( $wpdb->get_results( $in['a'] ? $wpdb->prepare( $q, $in['a'] ) : $q, ARRAY_A ) );`,
		{ q: query, a: args }
	);
}

/**
 * One statement that changes the plugin's own test data (never a product),
 * `{p}` standing for the prefix; returns the rows it touched.
 *
 * @param {string} query SQL.
 * @param {Array}  args  Arguments.
 * @return {number} Rows affected.
 */
function write( query, args = [] ) {
	return php(
		`global $wpdb; $q = str_replace( '{p}', $wpdb->prefix, $in['q'] );
		echo wp_json_encode( $wpdb->query( $in['a'] ? $wpdb->prepare( $q, $in['a'] ) : $q ) );`,
		{ q: query, a: args }
	);
}

/**
 * Install the short-lease must-use plugin on the test site.
 */
function installLeaseProbe() {
	php(
		`wp_mkdir_p( WPMU_PLUGIN_DIR );
		file_put_contents( WPMU_PLUGIN_DIR . '/' . $in, "<?php\\n/* Plugin Name: BULKCAT e2e short lease (throwaway test site only, T85) */\\nif ( isset( \\$_SERVER['HTTP_X_BULKCAT_E2E_TTL'] ) ) {\\n\\t\\$bulkcat_e2e_ttl = (int) \\$_SERVER['HTTP_X_BULKCAT_E2E_TTL'];\\n\\tadd_filter( 'bulkcat_selection_ttl', static fn() => \\$bulkcat_e2e_ttl );\\n}\\n" );
		echo wp_json_encode( file_exists( WPMU_PLUGIN_DIR . '/' . $in ) );`,
		LEASE_PROBE
	);
}

/**
 * Remove it again.
 */
function removeLeaseProbe() {
	php(
		`$f = WPMU_PLUGIN_DIR . '/' . $in; if ( file_exists( $f ) ) { unlink( $f ); } echo wp_json_encode( ! file_exists( $f ) );`,
		LEASE_PROBE
	);
}

/**
 * Make simple products through WooCommerce, published, at a price.
 *
 * @param {Array<string>} names Product names.
 * @param {string}        price Regular price.
 * @return {Array<number>} Their ids, in order.
 */
function makeProducts( names, price = '10.00' ) {
	return php(
		`$ids = array();
		foreach ( $in['names'] as $name ) {
			$p = new WC_Product_Simple();
			$p->set_name( $name );
			$p->set_status( 'publish' );
			$p->set_regular_price( $in['price'] );
			$ids[] = $p->save();
		}
		echo wp_json_encode( $ids );`,
		{ names, price }
	);
}

/**
 * Delete products for good, through WooCommerce.
 *
 * @param {Array<number>} ids Product ids.
 */
function deleteProducts( ids ) {
	php(
		`foreach ( $in as $id ) { $p = wc_get_product( $id ); if ( $p ) { $p->delete( true ); } } echo 'true';`,
		ids
	);
}

/**
 * Regular prices by id, read through WooCommerce.
 *
 * @param {Array<number>} ids Product ids.
 * @return {Object} id => price as a number.
 */
function prices( ids ) {
	return php(
		`$out = array(); foreach ( $in as $id ) { $p = wc_get_product( $id ); $out[ $id ] = $p ? (float) $p->get_regular_price() : null; } echo wp_json_encode( $out );`,
		ids
	);
}

/**
 * Set regular prices through WooCommerce (putting a spec's products back).
 *
 * @param {Object} byId id => price.
 */
function setPrices( byId ) {
	php(
		`foreach ( $in as $id => $price ) { $p = wc_get_product( $id ); if ( $p ) { $p->set_regular_price( number_format( (float) $price, 2, '.', '' ) ); $p->save(); } } echo 'true';`,
		byId
	);
}

/**
 * Product ids by exact name.
 *
 * @param {Array<string>} names Names.
 * @return {Array<number>} Ids, in the order given.
 */
function idsByName( names ) {
	return names.map( ( name ) => {
		const found = rows(
			"SELECT ID FROM {p}posts WHERE post_title = %s AND post_type = 'product' AND post_status = 'publish' ORDER BY ID LIMIT 1",
			[ name ]
		);
		return Number( found[ 0 ]?.ID || 0 );
	} );
}

/**
 * The batches made with a request id, oldest first.
 *
 * @param {string} uuid Request id.
 * @return {Array<Object>} Headers.
 */
function batchesByUuid( uuid ) {
	return rows(
		'SELECT batch_id, status, total_items, done_items, changed_items, failed_items FROM {p}bulkcat_batches WHERE uuid = %s ORDER BY batch_id',
		[ uuid ]
	);
}

/**
 * The newest batch id.
 *
 * @return {number} Id, 0 when none.
 */
function lastBatchId() {
	return Number(
		rows( 'SELECT MAX(batch_id) AS id FROM {p}bulkcat_batches' )[ 0 ]?.id ||
			0
	);
}

/**
 * Chunk actions waiting for a batch.
 *
 * @param {number} batchId Batch.
 * @return {number} Pending bulkcat_run_chunk actions for it.
 */
function pendingChunks( batchId ) {
	return Number(
		rows(
			"SELECT COUNT(*) AS n FROM {p}actionscheduler_actions WHERE hook = 'bulkcat_run_chunk' AND status IN ('pending','in-progress') AND args LIKE %s",
			[ `%${ batchId }%` ]
		)[ 0 ]?.n || 0
	);
}

/**
 * Run the queue until a batch has finished.
 *
 * @param {number} batchId Batch.
 * @param {number} rounds  Queue runs at most.
 * @return {Object} Its header.
 */
function drain( batchId, rounds = 20 ) {
	let header = null;
	for ( let i = 0; i < rounds; i++ ) {
		runQueue();
		header = rows(
			'SELECT batch_id, status, total_items, done_items, changed_items, failed_items FROM {p}bulkcat_batches WHERE batch_id = %d',
			[ batchId ]
		)[ 0 ];
		if (
			header &&
			! [ 'queued', 'running', 'undoing' ].includes( header.status )
		) {
			return header;
		}
	}
	return header;
}

/**
 * The snapshot row behind a v2 token (`v2.<id>.<epoch>.<signature>`).
 *
 * @param {string} token Token.
 * @return {Object|null} Row, without its members.
 */
function snapshotRow( token ) {
	const id = String( token || '' ).split( '.' )[ 1 ] || '';
	return (
		rows(
			'SELECT id, object_count, created_at, authorization_epoch, authorization_expires_at, retained_until, released_at FROM {p}bulkcat_selections WHERE id = %s',
			[ id ]
		)[ 0 ] || null
	);
}

/**
 * Move a snapshot's stored times back, as if the server's clock had moved
 * on: creation, lease and retention all `seconds` earlier.
 *
 * @param {string} token   Token.
 * @param {number} seconds Seconds.
 * @return {number} Rows changed.
 */
function ageSnapshot( token, seconds ) {
	const id = String( token || '' ).split( '.' )[ 1 ] || '';
	return write(
		'UPDATE {p}bulkcat_selections SET created_at = created_at - INTERVAL %d SECOND, authorization_expires_at = authorization_expires_at - INTERVAL %d SECOND, retained_until = retained_until - INTERVAL %d SECOND, released_at = released_at - INTERVAL %d SECOND WHERE id = %s',
		[ seconds, seconds, seconds, seconds, id ]
	);
}

/**
 * Run the hourly selection cleanup (the `bulkcat_prune_selections` action's
 * own hook, fired through WP-CLI) until a snapshot is gone: each run takes
 * at most 100 payloads past their retention, oldest first.
 *
 * @param {string} id Snapshot id.
 * @return {number} Runs it took.
 */
function pruneSelections( id ) {
	return php(
		`global $wpdb;
		for ( $runs = 1; $runs <= 50; $runs++ ) {
			do_action( 'bulkcat_prune_selections' );
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bulkcat_selections WHERE id = %s", $in ) ) ) {
				break;
			}
		}
		echo wp_json_encode( $runs );`,
		id
	);
}

/**
 * The tab's copy of the selection (sessionStorage), parsed.
 *
 * @param {import('@playwright/test').Page} page Page on the screen.
 * @return {Promise<Object|null>} What the tab keeps.
 */
function tabCopy( page ) {
	return page.evaluate( () => {
		const s = window.bulkcatSettings;
		const key = `bulkcat-selection:${ s.root }:${ Number( s.user?.id ) || 0 }`;
		const raw = window.sessionStorage.getItem( key );
		return raw ? JSON.parse( raw ) : null;
	} );
}

/**
 * The current snapshot's token, from the tab's copy.
 *
 * @param {import('@playwright/test').Page} page Page on the screen.
 * @return {Promise<string>} Token, '' when nothing is selected.
 */
async function currentToken( page ) {
	const copy = await tabCopy( page );
	return (
		( copy?.handles || [] ).find( ( each ) => each.key === copy.current )
			?.token || ''
	);
}

/**
 * The selection's members, read page by page through /selection/inspect.
 *
 * @param {import('@playwright/test').Page} page  Page on the screen.
 * @param {string}                          token Token (the current one by default).
 * @return {Promise<Array<number>>} Ids, ascending.
 */
async function members( page, token = '' ) {
	const use = token || ( await currentToken( page ) );
	return page.evaluate( async ( given ) => {
		const s = window.bulkcatSettings;
		const ids = [];
		for ( let n = 1; n < 2000; n++ ) {
			const response = await window.fetch( s.root + 'selection/inspect', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': s.nonce,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( {
					token: given,
					page: n,
					per_page: 100,
				} ),
			} );
			const json = await response.json();
			if ( ! response.ok ) {
				throw new Error( JSON.stringify( json ) );
			}
			( json.items || [] ).forEach( ( item ) =>
				ids.push( Number( item.id ) )
			);
			if ( n >= ( json.pagination?.totalPages || 1 ) ) {
				break;
			}
		}
		return ids.sort( ( a, b ) => a - b );
	}, use );
}

/**
 * Record every write the screen sends to the plugin, with its answer's
 * status: `{ path, body, status }` in order.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Array<Object>} The live list.
 */
function recordWrites( page ) {
	const list = [];
	page.on( 'requestfinished', async ( request ) => {
		const url = request.url().replace( /%2F/g, '/' );
		const match = /bulkcat\/v1\/([a-z/0-9]+)/.exec( url );
		if ( ! match || request.method() !== 'POST' ) {
			return;
		}
		const response = await request.response();
		list.push( {
			path: match[ 1 ],
			body: request.postDataJSON() || {},
			status: response ? response.status() : 0,
		} );
	} );
	page.on( 'requestfailed', ( request ) => {
		const url = request.url().replace( /%2F/g, '/' );
		const match = /bulkcat\/v1\/([a-z/0-9]+)/.exec( url );
		if ( match && request.method() === 'POST' ) {
			list.push( {
				path: match[ 1 ],
				body: request.postDataJSON() || {},
				status: 0,
			} );
		}
	} );
	return list;
}

/**
 * A route matcher for one of the plugin's POST routes, pretty or plain
 * permalinks alike.
 *
 * @param {string} path Route under bulkcat/v1/, such as 'batches'.
 * @return {Function} ( url ) => boolean.
 */
function routeOf( path ) {
	const exact = new RegExp(
		`bulkcat(/|%2F)v1(/|%2F)${ path.replace( /\//g, '(/|%2F)' ) }(\\?|&|$)`
	);
	return ( url ) => exact.test( url.toString() );
}

/**
 * Let the next POST to a route reach the server, then lose its answer on
 * the way back (the connection drops): the server has acted, the page
 * cannot know.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          path Route, such as 'batches'.
 * @return {Promise<Object>} Settles with `{ status, json }` of the lost answer.
 */
function loseNextAnswer( page, path ) {
	const matcher = routeOf( path );
	let used = false;
	return new Promise( ( resolve ) => {
		const handler = async ( route ) => {
			if ( used || route.request().method() !== 'POST' ) {
				return route.fallback();
			}
			used = true;
			const response = await route.fetch();
			const json = await response.json().catch( () => null );
			await route.abort( 'connectionreset' );
			await page.unroute( matcher, handler );
			resolve( {
				status: response.status(),
				json,
				body: route.request().postDataJSON(),
			} );
		};
		page.route( matcher, handler );
	} );
}

/**
 * Drop the next POST to a route before it reaches the server.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          path Route.
 * @return {Promise<Object>} Settles with the request's body.
 */
function dropNextRequest( page, path ) {
	const matcher = routeOf( path );
	let used = false;
	return new Promise( ( resolve ) => {
		const handler = async ( route ) => {
			if ( used || route.request().method() !== 'POST' ) {
				return route.fallback();
			}
			used = true;
			const body = route.request().postDataJSON();
			await route.abort( 'internetdisconnected' );
			await page.unroute( matcher, handler );
			resolve( body );
		};
		page.route( matcher, handler );
	} );
}

/**
 * The screen's search box, the selection bar and a row's checkbox.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Object} `{ search, bar, box }`.
 */
function screenParts( page ) {
	return {
		search: page.getByRole( 'searchbox', {
			name: 'Search by name or SKU',
		} ),
		bar: page.getByRole( 'region', { name: 'Selection' } ),
		box: ( name ) =>
			page.getByRole( 'checkbox', { name, exact: true } ).first(),
	};
}

/**
 * Search and wait for the rows of that search.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          text Search.
 * @param {string}                          row  A row name that must show.
 */
async function searchFor( page, text, row ) {
	const { search, box } = screenParts( page );
	await search.fill( text );
	if ( row ) {
		await expect( box( row ) ).toBeVisible();
	}
}

/**
 * Open the bulk panel on the selection and check an increase of the
 * regular price by an amount; returns the panel with Apply enabled.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {string}                          amount How much.
 * @param {string}                          apply  The Apply button's name.
 * @return {Promise<import('@playwright/test').Locator>} Panel.
 */
async function checkIncrease( page, amount, apply ) {
	const { bar } = screenParts( page );
	await bar.getByRole( 'button', { name: 'Make a change to these' } ).click();
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await panel
		.getByRole( 'radio', { name: /^Increase or decrease/ } )
		.first()
		.check();
	await panel.getByRole( 'textbox', { name: 'How much' } ).fill( amount );
	await panel.getByRole( 'button', { name: 'Check the change' } ).click();
	await expect( panel.getByRole( 'button', { name: apply } ) ).toBeEnabled( {
		timeout: 120_000,
	} );
	return panel;
}

/**
 * Undo a finished batch through the durable undo route, as History does
 * (preview, grant, body), and run the queue: puts a spec's change back.
 *
 * @param {import('@playwright/test').Page} page    Page on the screen.
 * @param {number}                          batchId Batch.
 * @param {boolean}                         force   Revert anyway.
 * @return {Promise<Object>} The undo batch's header.
 */
async function undoBatch( page, batchId, force = false ) {
	// A batch still queued (a spec stopped early) finishes first.
	drain( batchId );
	const answer = await page.evaluate(
		async ( args ) => {
			const s = window.bulkcatSettings;
			const post = async ( path, data ) => {
				const response = await window.fetch( s.root + path, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': s.nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( data ),
				} );
				return { status: response.status, json: await response.json() };
			};
			const plan = await post( `batches/${ args.id }/undo`, {
				preview: true,
				force: args.force,
			} );
			if ( plan.status !== 200 ) {
				return plan;
			}
			const grant = await post( 'requests', {
				operation: 'batch.undo',
				intent: {
					batch_id: args.id,
					plan_hash: plan.json.plan_hash,
					force: args.force,
				},
			} );
			return post( `batches/${ args.id }/undo`, {
				uuid: grant.json.uuid,
				request_proof: grant.json.request_proof,
				intent: grant.json.intent,
			} );
		},
		{ id: batchId, force }
	);
	expect( answer.status, JSON.stringify( answer.json ) ).toBeLessThan( 300 );
	return drain( Number( answer.json.batch_id ) );
}

/**
 * Undo every bulk change made since `from` that is not undone yet, through
 * the plugin's own durable undo routes (dispatched in WP-CLI as the site's
 * first administrator), then run the queue: the safety net that puts the
 * test site back when a spec stops before its own undo.
 *
 * @param {number} from The newest batch id before the spec.
 * @return {Array<number>} The batches undone.
 */
function undoLeftovers( from ) {
	const left = rows(
		"SELECT b.batch_id FROM {p}bulkcat_batches b WHERE b.batch_id > %d AND b.source = 'bulk' AND b.status NOT IN ('undone', 'undoing') AND NOT EXISTS ( SELECT 1 FROM {p}bulkcat_batches u WHERE u.undo_of = b.batch_id AND u.status IN ('queued', 'running', 'complete', 'partial') ) ORDER BY b.batch_id DESC",
		[ from ]
	).map( ( row ) => Number( row.batch_id ) );
	left.forEach( ( id ) => {
		drain( id );
		const undo = php(
			`$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
			wp_set_current_user( $admins[0]->ID );
			$post = static function ( $route, $body ) {
				$r = new WP_REST_Request( 'POST', '/bulkcat/v1/' . $route );
				$r->set_header( 'content-type', 'application/json' );
				$r->set_body( wp_json_encode( $body ) );
				$res = rest_do_request( $r );
				return array( $res->get_status(), $res->get_data() );
			};
			list( $s, $plan ) = $post( "batches/{$in}/undo", array( 'preview' => true, 'force' => false ) );
			if ( 200 !== $s ) { echo wp_json_encode( 0 ); return; }
			list( $s, $grant ) = $post( 'requests', array( 'operation' => 'batch.undo', 'intent' => array( 'batch_id' => $in, 'plan_hash' => $plan['plan_hash'], 'force' => false ) ) );
			list( $s, $done ) = $post( "batches/{$in}/undo", array( 'uuid' => $grant['uuid'], 'request_proof' => $grant['request_proof'], 'intent' => $grant['intent'] ) );
			echo wp_json_encode( (int) ( $done['batch_id'] ?? 0 ) );`,
			id
		);
		if ( undo ) {
			drain( undo );
		}
	} );
	return left;
}

module.exports = {
	fixtureCheck,
	undoLeftovers,
	SCREEN,
	WP,
	php,
	rows,
	write,
	installLeaseProbe,
	removeLeaseProbe,
	makeProducts,
	deleteProducts,
	prices,
	setPrices,
	idsByName,
	batchesByUuid,
	lastBatchId,
	pendingChunks,
	drain,
	snapshotRow,
	ageSnapshot,
	pruneSelections,
	tabCopy,
	currentToken,
	members,
	recordWrites,
	routeOf,
	loseNextAnswer,
	dropNextRequest,
	screenParts,
	searchFor,
	checkIncrease,
	undoBatch,
};
