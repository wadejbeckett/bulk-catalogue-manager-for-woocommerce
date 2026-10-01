/**
 * One native save on an explicitly assigned fixture. No reseed or installed
 * helpers: only UUID-owned products, login and saved view. Run with a config
 * without globalSetup/storageState; this spec authenticates its own user.
 * BULKCAT_SINGLE_SAVE_BASELINE=1 records the pre-repair stale-list behaviour.
 * Evidence contains identities/timings/hashes, never authentication material.
 */
const { randomUUID, createHash } = require( 'crypto' );
const { readFileSync, writeFileSync, mkdirSync } = require( 'fs' );
const { execFileSync } = require( 'child_process' );
const { join } = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { openScreen, rest, tabTo, expectAxeClean } = require( './helpers' );
const { php, fixtureCheck, members } = require( './lifecycle/support' );

const run = randomUUID();
const login = `single-${ run }`;
const password = randomUUID();
const baseline = process.env.BULKCAT_SINGLE_SAVE_BASELINE === '1';
const evidence = process.env.BULKCAT_E2E_OWNED_EVIDENCE;
const state = { run, baseline, measurements: [], journeys: [], views: [] };

function record() {
	if ( ! evidence ) {
		throw new Error( 'An owned evidence directory is required.' );
	}
	mkdirSync( evidence, { recursive: true } );
	writeFileSync(
		join( evidence, `${ run }.json` ),
		JSON.stringify( state, null, 2 ),
		{
			mode: 0o600,
		}
	);
}

function identity() {
	const paths = execFileSync(
		'git',
		[
			'ls-files',
			'--cached',
			'--others',
			'--exclude-standard',
			'src',
			'assets/src',
			'assets/build',
			'tests/e2e/single-save-refresh.spec.js',
			'tests/probe/native-status.php',
			'tests/probe/fixture.php',
		],
		{ encoding: 'utf8' }
	)
		.trim()
		.split( '\n' );
	paths.push( 'tests/e2e/single-save-refresh.spec.js' );
	return {
		head: execFileSync( 'git', [ 'rev-parse', 'HEAD' ], {
			encoding: 'utf8',
		} ).trim(),
		files: Object.fromEntries(
			[ ...new Set( paths ) ].map( ( path ) => [
				path,
				createHash( 'sha256' )
					.update( readFileSync( path ) )
					.digest( 'hex' ),
			] )
		),
	};
}

function snapshot() {
	return php(
		`
        putenv( 'BULKCAT_PROBE_SITEURL=' . $in['url'] );
        require 'wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/fixture.php';
        $bulkcat_native_args = [ 'run' => $in['run'], 'phase' => 'library' ];
        require 'wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/native-status.php';
        echo wp_json_encode( [ 'baseline' => bulkcat_native_baseline(), 'versions' => [ 'wp' => get_bloginfo('version'), 'wc' => WC_VERSION, 'php' => PHP_VERSION, 'manager' => \\BulkCatalogueManager\\Plugin::VERSION ] ] );`,
		{ run, url: process.env.BULKCAT_E2E_BASE_URL }
	);
}

test.beforeAll( () => {
	if ( fixtureCheck() ) {
		throw new Error( fixtureCheck() );
	}
	state.before = snapshot();
	state.identityBefore = identity();
	record();
	state.user = php(
		`
        $id = wp_insert_user( [ 'user_login' => $in['login'], 'user_pass' => $in['password'], 'user_email' => $in['login'] . '@example.test', 'role' => 'administrator' ] );
        if ( is_wp_error($id) ) { throw new RuntimeException($id->get_error_message()); }
        update_user_meta($id, '_bulkcat_native_probe', $in['run']);
        echo wp_json_encode($id);`,
		{ run, login, password }
	);
	record();
	state.products = [];
	for ( const kind of [
		'simple',
		'simple',
		'simple',
		'variable',
		'variation',
	] ) {
		const product = php(
			`
            wp_set_current_user($in['user']);
            $p = 'variable' === $in['kind'] ? new WC_Product_Variable() : ('variation' === $in['kind'] ? new WC_Product_Variation() : new WC_Product_Simple());
            $p->set_name('Single save ' . $in['run'] . ' ' . $in['index']);
            $p->set_status('publish'); $p->set_regular_price('17.25');
            if ('variation' === $in['kind']) { $p->set_parent_id($in['parent']); }
            else { $p->set_catalog_visibility('hidden'); }
            $p->update_meta_data('_bulkcat_native_probe', $in['run']);
            echo wp_json_encode(['id' => $p->save(), 'name' => $p->get_name(), 'kind' => $in['kind']]);`,
			{
				run,
				kind,
				user: state.user,
				index: state.products.length,
				parent: state.products[ 3 ]?.id,
			}
		);
		state.products.push( product );
		record();
	}
} );

test.afterAll( () => {
	if ( state.user ) {
		state.cleanup = php(
			`
            $readback = []; $drafts = [];
            foreach (array_reverse($in['products']) as $item) {
                $p = wc_get_product($item['id']); if (!$p) { continue; }
                if ($p->get_meta('_bulkcat_native_probe') !== $in['run']) { throw new RuntimeException('Object ownership changed'); }
                $readback[] = ['id' => $p->get_id(), 'type' => $p->get_type(), 'visibility' => $p->get_catalog_visibility(), 'featured' => $p->get_featured(), 'price' => $p->get_regular_price(), 'status' => $p->get_status()];
                $p->delete(true);
            }
            if (get_user_meta($in['user'], '_bulkcat_native_probe', true) !== $in['run']) { throw new RuntimeException('User ownership changed'); }
            foreach (get_posts(['author' => $in['user'], 'post_type' => 'any', 'post_status' => 'auto-draft', 'numberposts' => -1]) as $post) { $drafts[] = $post->ID; wp_delete_post($post->ID, true); }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($in['user']);
            echo wp_json_encode(['remaining' => array_values(array_filter(array_column($in['products'], 'id'), 'get_post')), 'userRemoved' => !get_user_by('id', $in['user']), 'readback' => $readback, 'removedDrafts' => $drafts, 'remainingDrafts' => array_values(array_filter($drafts, 'get_post'))]);`,
			{ run, user: state.user, products: state.products || [] }
		);
	}
	state.after = snapshot();
	state.identityAfter = identity();
	record();
	expect( state.cleanup ).toMatchObject( {
		remaining: [],
		userRemoved: true,
		remainingDrafts: [],
	} );
	expect( state.after ).toEqual( state.before );
	expect( state.identityAfter ).toEqual( state.identityBefore );
} );

test( 'three native Hidden-to-shown saves retain unrelated exact selection', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( login );
	await page.getByLabel( 'Password', { exact: true } ).fill( password );
	await page.getByRole( 'button', { name: 'Log In', exact: true } ).click();
	await expect( page ).toHaveURL( /\/wp-admin\// );
	await openScreen( page );
	const saved = await rest( page, 'views', {
		method: 'POST',
		data: {
			name: run,
			view: {
				type: 'table',
				perPage: 25,
				search: run,
				fields: [ 'name', 'status', 'visible_on_site' ],
				filters: [
					{
						field: 'catalog_visibility',
						operator: 'isAny',
						value: [ 'hidden' ],
					},
				],
			},
		},
	} );
	expect( saved.status ).toBe( 201 );
	state.view = saved.json.item.id;
	state.views.push( state.view );
	record();
	try {
		await page.reload();
		await page
			.getByRole( 'group', { name: 'Saved views' } )
			.getByRole( 'button', { name: run, exact: true } )
			.click();
		await page
			.getByRole( 'checkbox', {
				name: state.products[ 3 ].name,
				exact: true,
			} )
			.check();
		await expect
			.poll( () => members( page ).catch( () => [] ) )
			.toEqual( [ state.products[ 3 ].id ] );
		for ( const product of state.products.slice( 0, 3 ) ) {
			const requests = [];
			const starts = new Map();
			const onRequest = ( request ) => {
				if (
					/bulkcat(\/|%2F)v1(\/|%2F)products(?:[/?&]|%2F)/.test(
						request.url()
					)
				) {
					starts.set( request, performance.now() );
				}
			};
			const onResponse = ( response ) => {
				const request = response.request();
				if ( ! starts.has( request ) ) {
					return;
				}
				requests.push( {
					method:
						request.headers()[ 'x-http-method-override' ] ||
						request.method(),
					route: decodeURIComponent( request.url() ).split(
						'bulkcat/v1/'
					)[ 1 ],
					startMs: starts.get( request ) - click,
					responseMs: performance.now() - click,
					status: response.status(),
				} );
			};
			page.on( 'request', onRequest );
			page.on( 'response', onResponse );
			const control = page.getByRole( 'switch', {
				name: `Shown in shop and search, ${ product.name }`,
			} );
			const patch = page.waitForResponse(
				( response ) =>
					decodeURIComponent( response.url() ).includes(
						`products/${ product.id }?`
					) &&
					( response.request().headers()[
						'x-http-method-override'
					] === 'PATCH' ||
						response.request().method() === 'PATCH' )
			);
			const click = performance.now();
			await control.click();
			const response = await patch;
			const answer = await response.json();
			expect( response.status() ).toBe( 200 );
			expect( answer.status ).toBe( 'complete' );
			if ( baseline ) {
				await expect( control ).toHaveAttribute(
					'aria-checked',
					'true'
				);
			} else {
				await expect( control ).toHaveCount( 0 );
			}
			const displayedMs = performance.now() - click;
			// Bounded observation to distinguish missing reconciliation from a late read.
			await page.waitForTimeout( 1000 );
			page.off( 'request', onRequest );
			page.off( 'response', onResponse );
			const measurement = {
				product,
				requests,
				displayedMs,
				observationMs: performance.now() - click,
				rowRemains: await control.count(),
				batch: answer.batch_id,
				firstListGet:
					requests.find(
						( entry ) =>
							entry.method === 'GET' &&
							/^products[?&]/.test( entry.route )
					) || null,
			};
			state.measurements.push( measurement );
			record();
			expect(
				requests.filter( ( entry ) => entry.method === 'PATCH' )
			).toHaveLength( 1 );
			expect( measurement.rowRemains ).toBe( baseline ? 1 : 0 );
			const current = await rest( page, `products/${ product.id }` );
			expect( current.json.item.catalog_visibility ).toBe( 'visible' );
			expect( await members( page ) ).toEqual( [
				state.products[ 3 ].id,
			] );
		}
		await page.screenshot( {
			path: join( evidence, `${ run }.png` ),
			fullPage: true,
		} );
	} finally {
		await rest( page, `views/${ state.view }`, { method: 'DELETE' } );
	}
} );

/** Native route identity, including api-fetch's POST method override. */
const productRoute = /bulkcat(\/|%2F)v1(\/|%2F)products/;
const methodOf = ( request ) =>
	request.headers()[ 'x-http-method-override' ] || request.method();
const routeOf = ( request ) =>
	decodeURIComponent( request.url() ).split( 'bulkcat/v1/' )[ 1 ];
const isList = ( request ) =>
	methodOf( request ) === 'GET' &&
	/^products[?&]/.test( routeOf( request ) || '' );
const switchOf = ( page, product ) =>
	page.getByRole( 'switch', {
		name: `Shown in shop and search, ${ product.name }`,
		exact: true,
	} );
const rowOf = ( page, product ) =>
	page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'checkbox', { name: product.name, exact: true } ),
	} );
const searchOf = ( page ) =>
	page.getByRole( 'searchbox', { name: 'Search by name or SKU' } );

/**
 * Wait for exactly this native product PATCH, never an unrelated read.
 * @param {Object} page Browser page.
 * @param {number} id   Product identity.
 */
function nextNativePatch( page, id ) {
	return page.waitForResponse(
		( response ) =>
			methodOf( response.request() ) === 'PATCH' &&
			new RegExp( `^products/${ id }(?:[?&]|$)` ).test(
				routeOf( response.request() ) || ''
			)
	);
}

/**
 * Add only UUID-owned native products; ownership is recorded before UI work.
 * @param {Object} values Owned product properties.
 */
function makeOwned( values = {} ) {
	const product = php(
		`
        wp_set_current_user($in['user']);
        $p = $in['parent'] ? new WC_Product_Variation() : ($in['kind'] === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple());
        $p->set_name('Single save ' . $in['run'] . ' ' . $in['index']);
        $p->set_status('publish'); $p->set_regular_price('17.25');
        if ($in['parent']) { $p->set_parent_id($in['parent']); $p->set_attributes(['size' => 'owned']); }
        else { $p->set_catalog_visibility($in['visibility']); }
        $p->update_meta_data('_bulkcat_native_probe', $in['run']);
        $id = $p->save(); $p = wc_get_product($id);
        echo wp_json_encode(['id' => $id, 'name' => $p->get_name(), 'kind' => $p->get_type()]);`,
		{
			run,
			user: state.user,
			index: state.products.length,
			parent: 0,
			kind: 'simple',
			visibility: 'hidden',
			...values,
		}
	);
	state.products.push( product );
	record();
	return product;
}

/**
 * Authenticate the UUID user and use an owned saved view; never edit site defaults.
 * @param {Object} page    Browser page.
 * @param {Array}  filters Saved view filters.
 * @param {Array}  fields  Visible native columns.
 */
async function openOwned(
	page,
	filters = [],
	fields = [ 'status', 'visible_on_site', 'featured', 'regular_price' ]
) {
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( login );
	await page.getByLabel( 'Password', { exact: true } ).fill( password );
	await page.getByRole( 'button', { name: 'Log In', exact: true } ).click();
	await expect( page ).toHaveURL( /\/wp-admin\// );
	await openScreen( page );
	const name = `${ run } view ${ state.views.length }`;
	const saved = await rest( page, 'views', {
		method: 'POST',
		data: {
			name,
			view: { type: 'table', perPage: 100, search: run, fields, filters },
		},
	} );
	expect( saved.status ).toBe( 201 );
	state.views.push( saved.json.item.id );
	record();
	await page.reload();
	await page
		.getByRole( 'group', { name: 'Saved views' } )
		.getByRole( 'button', { name, exact: true } )
		.click();
	await expect( searchOf( page ) ).toHaveValue( run );
	return name;
}

/**
 * Retain the exact real responses, read values and diagnostics privately.
 * @param {string} label Evidence label.
 * @param {Object} data  Evidence payload.
 */
function saveJourney( label, data ) {
	state.journeys.push( { label, ...data } );
	record();
}

/**
 * Select an unrelated owned identity and wait for its capture, as in the baseline.
 * @param {Object} page    Browser page.
 * @param {Object} product Exact owned product.
 */
async function selectOther( page, product ) {
	await page
		.getByRole( 'checkbox', { name: product.name, exact: true } )
		.check();
	await expect
		.poll( () => members( page ).catch( () => [] ) )
		.toEqual( [ product.id ] );
}

/**
 * Run only a returned owned-user batch; no global queue processing.
 * @param {number} batch Accepted batch identity.
 */
function workOwned( batch ) {
	return php(
		`
        $repo = new \\BulkCatalogueManager\\Data\\BatchRepository();
        $row = $repo->find($in['batch']);
        if (!$row || (int)$row['user_id'] !== $in['user']) { throw new RuntimeException('Batch not owned'); }
        for ($i=0; $i<10 && in_array($repo->status($in['batch']), ['queued','running'], true); ++$i) { \\BulkCatalogueManager\\Engine\\ChunkRunner::handle($in['batch']); }
        echo wp_json_encode($repo->find($in['batch']));`,
		{ batch, user: state.user }
	);
}

test.afterEach( async ( { page }, info ) => {
	if ( ! state.user || page.isClosed() ) {
		return;
	}
	await page
		.screenshot( {
			path: info.outputPath( 'final-state.png' ),
			fullPage: true,
		} )
		.catch( () => {} );
	saveJourney( info.title, {
		status: info.status,
		errors: info.errors.map( ( error ) => error.message ),
		viewport: page.viewportSize(),
	} );
} );

test( 'native shown-to-Hidden keyboard removal, exact selection, notice and supported Undo', async ( {
	page,
}, info ) => {
	const product = makeOwned( { kind: 'variable', visibility: 'visible' } );
	const other = makeOwned( { visibility: 'visible' } );
	const view = await openOwned( page, [
		{
			field: 'catalog_visibility',
			operator: 'isAny',
			value: [ 'visible' ],
		},
	] );
	await selectOther( page, other );
	await tabTo( page, switchOf( page, product ), { max: 500 } );
	const patch = nextNativePatch( page, product.id );
	await page.keyboard.press( 'Space' );
	const response = await patch;
	const answer = await response.json();
	expect( response.status() ).toBe( 200 );
	expect( answer.status ).toBe( 'complete' );
	await expect( rowOf( page, product ) ).toHaveCount( 0 );
	await expect( searchOf( page ) ).toBeFocused();
	expect( await members( page ) ).toEqual( [ other.id ] );
	const current = await rest( page, `products/${ product.id }` );
	expect( current.json.item.catalog_visibility ).toBe( 'hidden' );
	const axe = await expectAxeClean(
		page,
		'single save notice and vanished-row focus'
	);
	const history = page.getByRole( 'link', {
		name: 'View change in History',
		exact: true,
	} );
	await expect( history ).toHaveAttribute(
		'href',
		new RegExp( `batch=${ answer.batch_id }(?:&|$)` )
	);
	await tabTo( page, history, { max: 500 } );
	await page.keyboard.press( 'Enter' );
	const card = page.locator(
		`article[aria-labelledby="bulkcat-batch-${ answer.batch_id }"]`
	);
	await tabTo(
		page,
		card.getByRole( 'button', { name: 'Undo', exact: true } ),
		{
			max: 500,
		}
	);
	await page.keyboard.press( 'Enter' );
	const dialog = page.getByRole( 'dialog', { name: 'Undo this change?' } );
	await expect( dialog ).toBeVisible();
	const undone = page.waitForResponse(
		( r ) =>
			routeOf( r.request() )?.startsWith(
				`batches/${ answer.batch_id }/undo`
			) && r.request().postDataJSON()?.preview !== true
	);
	await tabTo(
		page,
		dialog.getByRole( 'button', { name: 'Undo', exact: true } )
	);
	await page.keyboard.press( 'Enter' );
	const undoResponse = await undone;
	expect( undoResponse.status() ).toBe( 201 );
	const undo = await undoResponse.json();
	const header = workOwned( undo.batch_id );
	expect( header.status ).toBe( 'complete' );
	expect(
		( await rest( page, `products/${ product.id }` ) ).json.item
			.catalog_visibility
	).toBe( 'visible' );
	await openScreen( page );
	await page
		.getByRole( 'group', { name: 'Saved views' } )
		.getByRole( 'button', { name: view, exact: true } )
		.click();
	await expect( rowOf( page, product ) ).toHaveCount( 1 );
	expect( await members( page ) ).toEqual( [ other.id ] );
	saveJourney( info.title, { answer, current, undo, header, axe } );
} );

test( 'native Shop/Search partial states, boolean Featured and variation applicability', async ( {
	page,
}, info ) => {
	const shop = makeOwned( { visibility: 'catalog' } );
	const search = makeOwned( { visibility: 'search' } );
	const parent = makeOwned( { kind: 'variable' } );
	const child = makeOwned( { parent: parent.id } );
	await openOwned( page );
	for ( const [ product, value, label ] of [
		[ shop, 'catalog', 'Shop only' ],
		[ search, 'search', 'Search results only' ],
	] ) {
		const partly = page.getByRole( 'button', {
			name: `Shown in shop and search, ${ product.name }`,
			exact: true,
		} );
		await expect( partly ).toHaveText( 'Partly' );
		await expect( partly ).toHaveAccessibleDescription(
			`Partly shown: ${ label }. Choose on or off to change it.`
		);
		await page
			.getByRole( 'button', {
				name: `Edit Featured, ${ product.name }`,
				exact: true,
			} )
			.click();
		const input = page.getByRole( 'combobox', {
			name: `Featured, ${ product.name }`,
			exact: true,
		} );
		await input.selectOption( '1' );
		const patch = nextNativePatch( page, product.id );
		await input.press( 'Enter' );
		const response = await patch;
		const answer = await response.json();
		expect( response.status() ).toBe( 200 );
		expect( response.request().postDataJSON().featured ).toBe( true );
		expect( answer.item.featured ).toBe( true );
		const current = await rest(
			page,
			`products/${ product.id }?fields[]=featured&fields[]=catalog_visibility`
		);
		expect( current.json.item.featured ).toBe( true );
		expect( current.json.item.catalog_visibility ).toBe( value );
		await expect( partly ).toHaveText( 'Partly' );
		saveJourney( info.title, { product, answer, current } );
	}
	await page
		.getByRole( 'button', {
			name: `Show the variations of ${ parent.name }`,
		} )
		.click();
	// Variations can share the parent's native name. Bind the row to its
	// exact object ID, not the parent's same-name selection checkbox.
	const row = page.getByRole( 'row' ).filter( {
		has: page.locator( `[data-bulkcat-row="${ child.id }"]` ),
	} );
	await expect( row ).toBeVisible();
	await expect( row.getByRole( 'switch' ) ).toHaveCount( 0 );
	await expect(
		row.getByRole( 'button', {
			name: `Edit Featured, ${ child.name }`,
			exact: true,
		} )
	).toHaveCount( 0 );
	await expect(
		row.getByTitle(
			'Featured belongs to the product, not to each variation.',
			{ exact: true }
		)
	).toHaveAttribute( 'data-bulkcat-editable', 'false' );
	await row
		.getByRole( 'button', {
			name: `Edit Regular price, ${ child.name }`,
			exact: true,
		} )
		.click();
	const price = page.getByRole( 'textbox', {
		name: `Regular price, ${ child.name }`,
		exact: true,
	} );
	await price.fill( '21.75' );
	const patch = nextNativePatch( page, child.id );
	await price.press( 'Enter' );
	const response = await patch;
	expect( response.status() ).toBe( 200 );
	const answer = await response.json();
	expect(
		( await rest( page, `products/${ child.id }?fields[]=regular_price` ) )
			.json.item.regular_price
	).toBe( '21.75' );
	await expect(
		row.getByRole( 'button', {
			name: `Edit Regular price, ${ child.name }`,
			exact: true,
		} )
	).toContainText( '21.75' );
	expect(
		( await rest( page, `products/${ parent.id }` ) ).json.item
			.catalog_visibility
	).toBe( 'hidden' );
	saveJourney( info.title, { child, answer } );
} );

test( 'query switch during native save reconciles the current query', async ( {
	page,
}, info ) => {
	const product = makeOwned();
	const other = makeOwned();
	await openOwned( page );
	await selectOther( page, other );
	let release;
	let accepted;
	const held = new Promise( ( resolve ) => {
		accepted = resolve;
	} );
	await page.route( productRoute, async ( route ) => {
		if ( methodOf( route.request() ) !== 'PATCH' ) {
			await route.continue();
			return;
		}
		const response = await route.fetch();
		accepted( {
			status: response.status(),
			answer: await response.json(),
		} );
		await new Promise( ( resolve ) => {
			release = resolve;
		} );
		await route.fulfill( { response } );
	} );
	const patch = nextNativePatch( page, product.id );
	await switchOf( page, product ).click();
	const actual = await held;
	expect( actual.status ).toBe( 200 );
	await searchOf( page ).fill( other.name );
	await expect( rowOf( page, product ) ).toHaveCount( 0 );
	await expect( rowOf( page, other ) ).toHaveCount( 1 );
	const read = page.waitForResponse( ( r ) => isList( r.request() ) );
	release();
	expect( ( await patch ).status() ).toBe( 200 );
	const current = await ( await read ).json();
	expect( current.items.map( ( item ) => item.id ) ).toEqual( [ other.id ] );
	await expect( searchOf( page ) ).toHaveValue( other.name );
	expect( await members( page ) ).toEqual( [ other.id ] );
	saveJourney( info.title, {
		actual,
		current,
		simulation: 'native PATCH response held across query switch',
	} );
} );

test( 'confirmed native PATCH then failed list read recovers with GET only', async ( {
	page,
}, info ) => {
	const product = makeOwned();
	const other = makeOwned();
	await openOwned( page, [
		{ field: 'catalog_visibility', operator: 'isAny', value: [ 'hidden' ] },
	] );
	await selectOther( page, other );
	const requests = [];
	let fail = true;
	let patched = false;
	await page.route( productRoute, async ( route ) => {
		requests.push( {
			method: methodOf( route.request() ),
			route: routeOf( route.request() ),
		} );
		if ( methodOf( route.request() ) === 'PATCH' ) {
			patched = true;
		}
		if ( patched && fail && isList( route.request() ) ) {
			await route.abort( 'failed' );
			return;
		}
		await route.continue();
	} );
	const patch = nextNativePatch( page, product.id );
	await switchOf( page, product ).click();
	const response = await patch;
	const answer = await response.json();
	expect( response.status() ).toBe( 200 );
	await expect( page.locator( '#bulkcat-app' ) ).toContainText(
		`Saved changes to ${ product.name }, but could not refresh the results.`
	);
	const recovery = page.getByRole( 'button', {
		name: 'Refresh results',
		exact: true,
	} );
	await expect( recovery ).toBeVisible();
	const axe = await expectAxeClean(
		page,
		'confirmed save with failed read recovery'
	);
	await page.screenshot( {
		path: info.outputPath( 'failed-read.png' ),
		fullPage: true,
	} );
	fail = false;
	const retryStart = requests.length;
	await tabTo( page, recovery, { max: 500 } );
	await page.keyboard.press( 'Enter' );
	await expect( rowOf( page, product ) ).toHaveCount( 0 );
	await expect( recovery ).toHaveCount( 0 );
	expect(
		requests.filter( ( request ) => request.method === 'PATCH' )
	).toHaveLength( 1 );
	expect(
		requests
			.slice( retryStart )
			.every( ( request ) => request.method === 'GET' )
	).toBe( true );
	expect(
		( await rest( page, `products/${ product.id }` ) ).json.item
			.catalog_visibility
	).toBe( 'visible' );
	expect( await members( page ) ).toEqual( [ other.id ] );
	saveJourney( info.title, {
		answer,
		requests,
		axe,
		simulation: 'aborted list GET after native confirmed PATCH',
	} );
} );

test( 'lost native PATCH response remains unconfirmed after matching readback, no blind retry', async ( {
	page,
}, info ) => {
	const product = makeOwned();
	await openOwned( page );
	const requests = [];
	let native;
	await page.route( productRoute, async ( route ) => {
		requests.push( {
			method: methodOf( route.request() ),
			route: routeOf( route.request() ),
		} );
		if ( methodOf( route.request() ) === 'PATCH' ) {
			const response = await route.fetch();
			native = {
				status: response.status(),
				answer: await response.json(),
			};
			await route.abort( 'failed' );
			return;
		}
		await route.continue();
	} );
	await switchOf( page, product ).click();
	await expect( page.locator( '#bulkcat-app' ) ).toContainText(
		`The save outcome for ${ product.name } is unconfirmed.`
	);
	expect( native.status ).toBe( 200 );
	const recovery = page.getByRole( 'button', {
		name: 'Read current product',
		exact: true,
	} );
	// Run the unchanged accessibility assertion after collecting recovery evidence.
	await tabTo( page, recovery, { max: 500 } );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#bulkcat-app' ) ).toContainText(
		'Current values were read. This does not confirm the outcome of the earlier save.'
	);
	await expect( switchOf( page, product ) ).toHaveAttribute(
		'aria-disabled',
		'true'
	);
	await switchOf( page, product ).click( { force: true } );
	expect(
		requests.filter( ( request ) => request.method === 'PATCH' )
	).toHaveLength( 1 );
	expect(
		( await rest( page, `products/${ product.id }` ) ).json.item
			.catalog_visibility
	).toBe( 'visible' );
	await expect(
		page.getByRole( 'link', {
			name: 'Review product History',
			exact: true,
		} )
	).toBeVisible();
	saveJourney( info.title, {
		native,
		requests,
		simulation:
			'real native PATCH accepted, transport response discarded; matching read does not attribute mutation',
	} );
	await qualifyHeldRow( page, product, info );
} );

test( 'native bulk-one still requires Preview then Apply', async ( {
	page,
}, info ) => {
	const product = makeOwned();
	await openOwned( page );
	await selectOther( page, product );
	const requests = [];
	page.on( 'request', ( request ) => {
		if ( request.url().includes( 'bulkcat' ) ) {
			requests.push( {
				method: methodOf( request ),
				route: routeOf( request ),
			} );
		}
	} );
	await page
		.getByRole( 'button', { name: 'Make a change to these' } )
		.click();
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await panel.getByRole( 'radio', { name: /^Set Status/ } ).check();
	await panel
		.getByRole( 'combobox', { name: 'New value', exact: true } )
		.selectOption( 'private' );
	await expect(
		panel.getByRole( 'button', { name: /^Apply to/ } )
	).toHaveCount( 0 );
	const previewResponse = page.waitForResponse( ( r ) =>
		/^preview[?&]/.test( routeOf( r.request() ) || '' )
	);
	await panel.getByRole( 'button', { name: 'Check the change' } ).click();
	const preview = await ( await previewResponse ).json();
	expect( preview.totals.matched ).toBe( 1 );
	expect(
		( await rest( page, `products/${ product.id }` ) ).json.item.status
	).toBe( 'publish' );
	expect(
		requests.filter(
			( request ) =>
				request.method === 'POST' &&
				/^batches[?&]/.test( request.route || '' )
		)
	).toHaveLength( 0 );
	const batchResponse = page.waitForResponse(
		( r ) =>
			r.request().method() === 'POST' &&
			/^batches[?&]/.test( routeOf( r.request() ) || '' )
	);
	await panel
		.getByRole( 'button', { name: 'Apply to 1 product', exact: true } )
		.click();
	const accepted = await ( await batchResponse ).json();
	const header = workOwned( accepted.batch_id );
	expect( header.status ).toBe( 'complete' );
	expect(
		( await rest( page, `products/${ product.id }` ) ).json.item.status
	).toBe( 'private' );
	expect(
		requests.filter( ( request ) => request.method === 'PATCH' )
	).toHaveLength( 0 );
	saveJourney( info.title, { preview, accepted, header, requests } );
} );

test( 'native permission revocation denies edit without changing the product', async ( {
	page,
}, info ) => {
	const product = makeOwned();
	await openOwned( page );
	php(
		`if (get_user_meta($in['user'], '_bulkcat_native_probe', true) !== $in['run']) { throw new RuntimeException('User not owned'); } $u = new WP_User($in['user']); $u->add_cap('edit_products', false); echo 'true';`,
		{ run, user: state.user }
	);
	try {
		const patch = nextNativePatch( page, product.id );
		await switchOf( page, product ).click();
		const response = await patch;
		const refusal = await response.json();
		expect( response.status() ).toBe( 403 );
		await expect( page.locator( '#bulkcat-app' ) ).toContainText(
			`We could not change whether ${ product.name } is shown in the shop and search, so it is as it was.`
		);
		await expect( switchOf( page, product ) ).toHaveAttribute(
			'aria-checked',
			'false'
		);
		const current = await rest( page, `products/${ product.id }` );
		expect( current.json.item.catalog_visibility ).toBe( 'hidden' );
		saveJourney( info.title, {
			refusal,
			current,
			fault: 'actual native edit_products capability denied on UUID user after screen load',
		} );
	} finally {
		php(
			`$u = new WP_User($in['user']); $u->remove_cap('edit_products'); echo 'true';`,
			{ user: state.user }
		);
	}
} );

/**
 * Preserve the original axe failure. The opacity experiment is diagnostic only:
 * restore the browser styles, then assert the ORIGINAL result (never the experiment).
 *
 * @param {Object} page    Browser page.
 * @param {Object} product Exact owned row.
 * @param {Object} info    Playwright test identity.
 */
async function qualifyHeldRow( page, product, info ) {
	const analyze = async () => {
		let builder = new AxeBuilder( { page } ).withTags( [
			'wcag2a',
			'wcag2aa',
			'wcag21a',
			'wcag21aa',
			'wcag22aa',
		] );
		for ( const selector of [
			'#wpbody-content .wrap',
			'.bulkcat-bulk',
			'.components-modal__screen-overlay',
			'.bulkcat-drawer',
		] ) {
			if ( await page.locator( selector ).count() ) {
				builder = builder.include( selector );
			}
		}
		return builder
			.exclude( '.update-nag' )
			.exclude( '.notice:not([class*="bulkcat"])' )
			.analyze();
	};
	const original = await analyze();
	const blocking = original.violations.filter( ( violation ) =>
		[ 'serious', 'critical' ].includes( violation.impact )
	);
	const cells = rowOf( page, product ).locator( '.bulkcat-cell.is-pending' );
	const computed = await cells.evaluateAll( ( nodes ) =>
		nodes.map( ( node ) => ( {
			text: node.textContent,
			style: node.getAttribute( 'style' ),
			opacity: window.getComputedStyle( node ).opacity,
			pointerEvents: window.getComputedStyle( node ).pointerEvents,
			links: [ ...node.querySelectorAll( 'a' ) ].map( ( link ) => ( {
				color: window.getComputedStyle( link ).color,
				fontSize: window.getComputedStyle( link ).fontSize,
				href: link.getAttribute( 'href' ),
				tabIndex: link.tabIndex,
			} ) ),
		} ) )
	);
	let counterfactual = null;
	if ( blocking.some( ( violation ) => violation.id === 'color-contrast' ) ) {
		try {
			await cells.evaluateAll( ( nodes ) =>
				nodes.forEach( ( node ) => {
					node.style.opacity = '1';
				} )
			);
			counterfactual = ( await analyze() ).violations;
		} finally {
			await cells.evaluateAll(
				( nodes, before ) =>
					nodes.forEach( ( node, index ) => {
						if ( before[ index ].style === null ) {
							node.removeAttribute( 'style' );
						} else {
							node.setAttribute( 'style', before[ index ].style );
						}
					} ),
				computed
			);
		}
	}
	const restored = await cells.evaluateAll( ( nodes ) =>
		nodes.map( ( node ) => window.getComputedStyle( node ).opacity )
	);
	const diagnostic = {
		product,
		original: original.violations,
		computed,
		counterfactual,
		restored,
	};
	saveJourney(
		`${ info.title } — original axe and opacity-only counterfactual`,
		diagnostic
	);
	await info.attach( 'held-row-axe', {
		body: JSON.stringify( diagnostic, null, 2 ),
		contentType: 'application/json',
	} );
	expect( restored ).toEqual( computed.map( ( cell ) => cell.opacity ) );
	expect(
		blocking,
		'Original native held-row accessibility, before diagnostic CSS changes'
	).toEqual( [] );
}
