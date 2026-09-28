/**
 * T84: the exact selection on a real site. Search, tick, search again, tick,
 * Select all matching (added, not replacing), untick one, edit the search
 * through empty text (kept), Review selected products by keyboard (remove
 * one, focus back), reload (the same selection, said so), preview, Apply,
 * the background queue, done (the selection cleared only now), Undo from
 * History, and the values read back through GET /products at every stage.
 * Every capture, composition, Apply and undo the page sends is a durable
 * request with a server grant; none is a 1.0 body.
 *
 * With BULKCAT_E2E_SHOTS set to a folder, the selection bar, the review
 * drawer and the done state are saved there as screenshots.
 */
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const {
	SEED,
	openScreen,
	runQueue,
	untilVisible,
	tabTo,
	productByName,
} = require( './helpers' );

const SHOTS = process.env.BULKCAT_E2E_SHOTS || '';

/**
 * Save a screenshot when asked to.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          name File name.
 */
async function shot( page, name ) {
	if ( SHOTS ) {
		await page.screenshot( { path: path.join( SHOTS, name ) } );
	}
}

test( 'a selection kept across searches, reviewed, reloaded, applied, cleared on success and undone', async ( {
	page,
} ) => {
	test.skip( ! process.env.WP_CMD, 'Needs WP_CMD to run the queue.' );
	test.setTimeout( 240_000 );
	runQueue();
	// With SCRIPT_DEBUG on, React's own warnings (hook order, keys) show here.
	const failures = [];
	page.on( 'pageerror', ( error ) => failures.push( error.message ) );
	page.on( 'console', ( message ) => {
		if (
			message.type() === 'error' &&
			/Warning: |React|Hooks/.test( message.text() )
		) {
			failures.push( message.text().slice( 0, 300 ) );
		}
	} );
	const writes = [];
	page.on( 'request', ( request ) => {
		const url = request.url();
		if (
			request.method() === 'POST' &&
			/bulkcat\/v1\/(selection|selection\/compose|batches|batches\/\d+\/undo)(\?|$)/.test(
				url.replace( /%2F/g, '/' )
			)
		) {
			writes.push( request.postDataJSON() || {} );
		}
	} );
	await openScreen( page );
	const numbers = [ 1, 2, 3, 4, 5, 6, 7, 8, 9 ];
	const before = {};
	for ( const n of numbers ) {
		before[ n ] = Number(
			( await productByName( page, SEED.simple( n ) ) ).regular_price
		);
	}
	const search = page.getByRole( 'searchbox', {
		name: 'Search by name or SKU',
	} );
	const bar = page.getByRole( 'region', { name: 'Selection' } );
	const box = ( n ) =>
		page.getByRole( 'checkbox', { name: SEED.simple( n ), exact: true } );

	// Search, tick; search again, tick: both kept.
	await search.fill( SEED.simple( 1 ) );
	await expect( box( 2 ) ).toHaveCount( 0 );
	await box( 1 ).check();
	await expect( bar ).toContainText( '1 product selected' );
	await search.fill( SEED.simple( 2 ) );
	await expect( box( 1 ) ).toHaveCount( 0 );
	await expect( bar ).toContainText( '1 product selected' );
	await box( 2 ).check();
	await expect( bar ).toContainText( '2 products selected' );

	// Select all matching adds the nine matches to the two.
	await search.fill( 'Probe Simple 0' );
	await expect( box( 9 ) ).toBeVisible();
	await expect( box( 1 ) ).toBeChecked();
	await expect( box( 3 ) ).not.toBeChecked();
	await bar
		.getByRole( 'button', { name: /^Select all 9 products matching/ } )
		.click();
	await expect( bar ).toContainText( '9 products selected' );
	await expect( box( 3 ) ).toBeChecked();

	// Unticking one removes only that one.
	await box( 3 ).uncheck();
	await expect( bar ).toContainText( '8 products selected' );

	// Editing the search through empty text keeps the selection.
	const unfiltered = page.waitForResponse(
		( response ) =>
			/bulkcat(\/|%2F)v1(\/|%2F)products/.test( response.url() ) &&
			! /[?&]search=/.test( response.url() )
	);
	await search.fill( '' );
	await unfiltered;
	await expect( bar ).toContainText( '8 products selected' );
	await shot( page, 'selection-bar.png' );

	// Review selected products, by keyboard: remove one, focus comes back.
	const review = bar.getByRole( 'button', {
		name: 'Review selected products',
	} );
	await tabTo( page, review, { back: true } );
	await page.keyboard.press( 'Enter' );
	const drawer = page.getByRole( 'dialog', { name: 'Selected products' } );
	await expect( drawer ).toBeVisible();
	await expect( drawer ).toContainText( '8 products selected' );
	await expect( drawer ).toContainText( SEED.simple( 9 ) );
	await expect( drawer ).not.toContainText( SEED.simple( 3 ) );
	await shot( page, 'review-drawer.png' );
	const remove = drawer.getByRole( 'button', {
		name: `Remove ${ SEED.simple( 9 ) } from the selection`,
	} );
	await tabTo( page, remove );
	await page.keyboard.press( 'Enter' );
	await expect( drawer ).toContainText( '7 products selected' );
	await expect( drawer ).not.toContainText( SEED.simple( 9 ) );
	await page.keyboard.press( 'Escape' );
	await expect( drawer ).toBeHidden();
	await expect( review ).toBeFocused();
	await expect( bar ).toContainText( '7 products selected' );

	// A reload in the middle comes back to the same selection, and says so.
	await page.reload();
	await expect(
		page.locator( '.dataviews-view-table tbody tr' ).first()
	).toBeVisible();
	await expect( bar ).toContainText( '7 products selected' );
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'Your selection is still here: 7 products.'
	);

	// Preview and Apply: increase the regular price by 1.
	await bar.getByRole( 'button', { name: 'Make a change to these' } ).click();
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await expect( panel ).toContainText( '7 selected products' );
	await panel
		.getByRole( 'radio', { name: /^Increase or decrease/ } )
		.first()
		.check();
	await panel.getByRole( 'textbox', { name: 'How much' } ).fill( '1' );
	await panel.getByRole( 'button', { name: 'Check the change' } ).click();
	const apply = panel.getByRole( 'button', { name: 'Apply to 7 products' } );
	await expect( apply ).toBeEnabled( { timeout: 60_000 } );
	await apply.click();

	// Done: only now is the selection cleared.
	const undo = panel.getByRole( 'button', { name: 'Undo this change' } );
	await untilVisible( undo );
	await expect( panel ).toContainText( '7 products updated.' );
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'The change finished, so your selection was cleared.'
	);
	await shot( page, 'done-panel.png' );
	for ( const n of numbers ) {
		const now = Number(
			( await productByName( page, SEED.simple( n ) ) ).regular_price
		);
		expect( now, SEED.simple( n ) ).toBeCloseTo(
			before[ n ] + ( [ 3, 9 ].includes( n ) ? 0 : 1 ),
			2
		);
	}
	await panel.getByRole( 'button', { name: 'Back to products' } ).click();
	await expect( bar ).toHaveCount( 0 );
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'The change finished, so your selection was cleared.'
	);
	await shot( page, 'done-catalogue.png' );

	// Undo from History, then every price is back.
	await page
		.locator( '.bulkcat-selection' )
		.getByRole( 'button', { name: 'Undo this change' } )
		.click();
	const confirm = page.getByRole( 'dialog', { name: 'Undo this change?' } );
	await expect( confirm ).toBeVisible( { timeout: 30_000 } );
	await confirm.getByRole( 'button', { name: 'Undo', exact: true } ).click();
	await expect( confirm ).toBeHidden();
	await expect
		.poll(
			async () => {
				runQueue();
				return Number(
					( await productByName( page, SEED.simple( 1 ) ) )
						.regular_price
				);
			},
			{ timeout: 90_000 }
		)
		.toBeCloseTo( before[ 1 ], 2 );
	for ( const n of numbers ) {
		expect(
			Number(
				( await productByName( page, SEED.simple( n ) ) ).regular_price
			),
			SEED.simple( n )
		).toBeCloseTo( before[ n ], 2 );
	}

	// No error on the page, and nothing it sent was a 1.0 body.
	expect( failures ).toEqual( [] );
	expect( writes.length ).toBeGreaterThan( 5 );
	writes.forEach( ( body ) => {
		if ( body.preview ) {
			return;
		}
		expect( Object.keys( body ) ).toEqual(
			expect.arrayContaining( [ 'uuid', 'request_proof', 'intent' ] )
		);
		[
			'operations',
			'ids',
			'source',
			'preview_count',
			'confirm_changed',
		].forEach( ( key ) => expect( body[ key ] ).toBeUndefined() );
	} );
} );
