/* eslint-disable @wordpress/no-global-active-element -- this code runs in the page, through evaluate(). */
/**
 * Shared steps for the browser specs: open the screen, move by keyboard
 * alone, read the catalogue through the plugin's own REST routes, and run
 * axe-core on the plugin's part of the page.
 */
const { execSync } = require( 'child_process' );
const { expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const SCREEN =
	'/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce';

/** The seeded probe catalogue (tests/probe/seed.sh). */
const SEED = {
	simple: ( n ) => `Probe Simple ${ String( n ).padStart( 2, '0' ) }`,
	variable: 'Probe Variable',
	root: 'Probe Catalogue',
	shirts: 'Probe Shirts',
	hats: 'Probe Hats',
};

/**
 * Open a panel of the screen and wait for it to settle.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          panel Panel key, '' for the catalogue.
 * @param {string}                          extra Further query arguments.
 */
async function openScreen( page, panel = '', extra = '' ) {
	await page.goto(
		SCREEN + ( panel ? `&panel=${ panel }` : '' ) + ( extra || '' )
	);
	await expect( page.locator( '#bulkcat-app .bulkcat-app' ) ).toBeVisible();
	if ( ! panel ) {
		await expect(
			page.locator( '.dataviews-view-table tbody tr' ).first()
		).toBeVisible();
	}
}

/**
 * Press Tab (or Shift+Tab) until the locator has focus: the keyboard's own
 * way of reaching a control, with no pointer event.
 *
 * @param {import('@playwright/test').Page}    page         Page.
 * @param {import('@playwright/test').Locator} target       Control to reach.
 * @param {Object}                             options      Options.
 * @param {boolean}                            options.back Press Shift+Tab instead.
 * @param {number}                             options.max  Presses at most.
 */
async function tabTo( page, target, { back = false, max = 120 } = {} ) {
	for ( let i = 0; i < max; i++ ) {
		if (
			( await target.count() ) &&
			( await target.evaluate(
				( node ) => node === document.activeElement
			) )
		) {
			return;
		}
		await page.keyboard.press( back ? 'Shift+Tab' : 'Tab' );
	}
	throw new Error( `Tab did not reach ${ target }` );
}

/**
 * The element with focus, described for assertions and messages.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<Object>} { tag, name, role }.
 */
function focused( page ) {
	return page.evaluate( () => {
		const node = document.activeElement;
		return {
			tag: node?.tagName,
			role: node?.getAttribute( 'role' ),
			name:
				node?.getAttribute( 'aria-label' ) ||
				( node?.textContent || '' ).trim().slice( 0, 80 ),
		};
	} );
}

/**
 * A request to the plugin's REST namespace as the logged-in user, with the
 * nonce the screen was given.
 *
 * @param {import('@playwright/test').Page} page           Page on the screen.
 * @param {string}                          path           Route under bulkcat/v1/.
 * @param {Object}                          options        Options.
 * @param {string}                          options.method HTTP method.
 * @param {Object}                          options.data   JSON body.
 * @return {Promise<Object>} { status, json }.
 */
function rest( page, path, { method = 'GET', data } = {} ) {
	return page.evaluate(
		async ( args ) => {
			const settings = window.bulkcatSettings;
			const response = await window.fetch( settings.root + args.path, {
				method: args.method,
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': settings.nonce,
					'Content-Type': 'application/json',
				},
				body: args.data ? JSON.stringify( args.data ) : undefined,
			} );
			return { status: response.status, json: await response.json() };
		},
		{ path, method, data }
	);
}

/**
 * One product row from GET /products, found by its exact name.
 *
 * @param {import('@playwright/test').Page} page   Page on the screen.
 * @param {string}                          name   Product name.
 * @param {Array<string>}                   fields Field keys to read.
 * @return {Promise<Object>} Row.
 */
async function productByName( page, name, fields = [ 'regular_price' ] ) {
	const query = new URLSearchParams( { search: name, per_page: '100' } );
	[ 'name', ...fields ].forEach( ( field ) =>
		query.append( 'fields[]', field )
	);
	const { status, json } = await rest( page, `products?${ query }` );
	expect( status ).toBe( 200 );
	const row = ( json.items || [] ).find( ( item ) => item.name === name );
	expect( row, `GET /products has ${ name }` ).toBeTruthy();
	return row;
}

/**
 * axe-core over the plugin's part of the page (the admin's own menu and
 * toolbar are WordPress's, not this plugin's), WCAG 2.2 A and AA rules.
 * Fails on any serious or critical violation and returns them all.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {string}                          label   Screen name, for the message.
 * @param {Array<string>}                   include Selectors to check.
 * @return {Promise<Array<Object>>} Every violation, any impact.
 */
async function expectAxeClean(
	page,
	label,
	include = [
		'#wpbody-content .wrap',
		'.bulkcat-bulk',
		'.components-modal__screen-overlay',
		'.bulkcat-drawer',
	]
) {
	let builder = new AxeBuilder( { page } ).withTags( [
		'wcag2a',
		'wcag2aa',
		'wcag21a',
		'wcag21aa',
		'wcag22aa',
	] );
	for ( const selector of include ) {
		if ( await page.locator( selector ).count() ) {
			builder = builder.include( selector );
		}
	}
	// WordPress's own update nag sits inside .wrap on an out-of-date site.
	builder = builder
		.exclude( '.update-nag' )
		.exclude( '.notice:not([class*="bulkcat"])' );
	const { violations } = await builder.analyze();
	const blocking = violations.filter( ( violation ) =>
		[ 'serious', 'critical' ].includes( violation.impact )
	);
	const summary = blocking.map(
		( violation ) =>
			`${ violation.impact } ${ violation.id }: ${ violation.help } — ${ violation.nodes
				.slice( 0, 3 )
				.map( ( node ) => node.target.join( ' ' ) )
				.join( ' | ' ) }`
	);
	expect( summary, `axe-core on ${ label }` ).toEqual( [] );
	return violations;
}

/**
 * Run the plugin's background chunks, as the probe does: a container's
 * WP-Cron cannot reach its own public URL, so nothing runs by itself on the
 * docker stack or wp-env. WP_CMD is the WP-CLI command line
 * (tests/probe/README.md); without it the site's own cron is relied on.
 */
function runQueue() {
	if ( ! process.env.WP_CMD ) {
		return;
	}
	execSync(
		`${ process.env.WP_CMD } action-scheduler run --hooks=bulkcat_run_chunk --batch-size=25`,
		{ stdio: 'ignore', timeout: 60_000 }
	);
}

/**
 * Wait for a locator to show, running the background chunks meanwhile.
 *
 * @param {import('@playwright/test').Locator} target What the finished work shows.
 * @param {number}                             rounds Queue runs at most.
 */
async function untilVisible( target, rounds = 15 ) {
	for ( let i = 0; i < rounds; i++ ) {
		if ( await target.isVisible() ) {
			return;
		}
		runQueue();
		try {
			await target.waitFor( { state: 'visible', timeout: 4000 } );
			return;
		} catch {}
	}
	await expect( target ).toBeVisible();
}

module.exports = {
	runQueue,
	untilVisible,
	SCREEN,
	SEED,
	openScreen,
	tabTo,
	focused,
	rest,
	productByName,
	expectAxeClean,
};
