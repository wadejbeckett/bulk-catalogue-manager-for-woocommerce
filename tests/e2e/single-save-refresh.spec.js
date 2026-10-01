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
const { openScreen, rest } = require( './helpers' );
const { php, fixtureCheck, members } = require( './lifecycle/support' );

const run = randomUUID();
const login = `single-${ run }`;
const password = randomUUID();
const baseline = process.env.BULKCAT_SINGLE_SAVE_BASELINE === '1';
const evidence = process.env.BULKCAT_E2E_OWNED_EVIDENCE;
const state = { run, baseline, measurements: [] };

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
            foreach (array_reverse($in['products']) as $item) {
                $p = wc_get_product($item['id']); if (!$p) { continue; }
                if ($p->get_meta('_bulkcat_native_probe') !== $in['run']) { throw new RuntimeException('Object ownership changed'); }
                $p->delete(true);
            }
            if (get_user_meta($in['user'], '_bulkcat_native_probe', true) !== $in['run']) { throw new RuntimeException('User ownership changed'); }
            foreach (get_posts(['author' => $in['user'], 'post_type' => 'any', 'post_status' => 'auto-draft', 'numberposts' => -1]) as $post) { wp_delete_post($post->ID, true); }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($in['user']);
            echo wp_json_encode(['remaining' => array_values(array_filter(array_column($in['products'], 'id'), 'get_post')), 'userRemoved' => !get_user_by('id', $in['user'])]);`,
			{ run, user: state.user, products: state.products || [] }
		);
	}
	state.after = snapshot();
	state.identityAfter = identity();
	record();
	expect( state.cleanup ).toEqual( { remaining: [], userRemoved: true } );
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
