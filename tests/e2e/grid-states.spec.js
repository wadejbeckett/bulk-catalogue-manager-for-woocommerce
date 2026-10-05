/**
 * The grid's five states (SPEC §4.1; T43, T44, T75): loading, no products,
 * no matches, a failed read that keeps the rows, and a change in progress
 * whose rows are pending and read-only with Cancel and View in History, then
 * read again when it finishes. Loading, no products and the failure are made
 * by answering GET /products in the browser; the rest is the real site.
 */
const { test, expect } = require( '@playwright/test' );
const {
	SCREEN,
	SEED,
	openScreen,
	runQueue,
	untilVisible,
	productByName,
	tabTo,
	tabFromContent,
} = require( './helpers' );

const PRODUCTS = /\/bulkcat\/v1\/products(\?|%3F|$)/;

test( 'loading shows skeleton rows at row height and says so once', async ( {
	page,
} ) => {
	let release;
	const held = new Promise( ( resolve ) => ( release = resolve ) );
	await page.route( PRODUCTS, async ( route ) => {
		await held;
		await route.continue();
	} );
	await page.goto( SCREEN );
	const status = page.getByRole( 'status' ).filter( {
		hasText: 'Loading products…',
	} );
	await expect( status ).toBeVisible();
	await expect( page.locator( '.bulkcat-skeleton__table' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
	const heights = await page
		.locator( '.bulkcat-skeleton__row' )
		.evaluateAll( ( rows ) => rows.map( ( row ) => row.offsetHeight ) );
	expect( heights.length ).toBeGreaterThan( 0 );
	release();
	await expect(
		page.locator( '.dataviews-view-table tbody tr' ).first()
	).toBeVisible();
	const rowHeight = await page
		.locator( '.dataviews-view-table tbody tr' )
		.first()
		.evaluate( ( row ) => row.offsetHeight );
	expect( Math.abs( rowHeight - heights[ 0 ] ) ).toBeLessThanOrEqual( 1 );
} );

test( 'no products at all shows one sentence and one link', async ( {
	page,
} ) => {
	await page.route( PRODUCTS, async ( route ) => {
		const response = await route.fetch();
		const json = await response.json();
		json.items = [];
		json.pagination = {
			...( json.pagination || {} ),
			total: 0,
			totalPages: 0,
		};
		await route.fulfill( { response, json } );
	} );
	await page.goto( SCREEN );
	await expect(
		page.getByText( 'There are no products yet.' )
	).toBeVisible();
	const link = page.getByRole( 'link', { name: 'Add a new product' } );
	await expect( link ).toHaveAttribute(
		'href',
		/post-new\.php\?post_type=product/
	);
	await expect( page.locator( '.dataviews-view-table' ) ).toHaveCount( 0 );
} );

test( 'no matches lists the filters and clears them', async ( { page } ) => {
	await openScreen( page );
	const search = page.getByRole( 'searchbox', {
		name: 'Search by name or SKU',
	} );
	await tabFromContent( page, search );
	await page.keyboard.type( 'zzz-no-such-product' );
	await expect(
		page.getByText( 'No products match these filters.' )
	).toBeVisible();
	await expect(
		page.getByText( /zzz-no-such-product/ ).first()
	).toBeVisible();
	const clear = page
		.locator( '.bulkcat-state' )
		.getByRole( 'button', { name: 'Clear filters' } );
	await tabTo( page, clear );
	await page.keyboard.press( 'Enter' );
	await expect(
		page.getByRole( 'checkbox', { name: SEED.simple( 1 ) } )
	).toBeVisible();
} );

test( 'a failed read says what failed and keeps the rows', async ( {
	page,
} ) => {
	await openScreen( page );
	await page.route( PRODUCTS, ( route ) =>
		route.fulfill( {
			status: 500,
			json: { code: 'bulkcat_e2e_failure', message: 'Made to fail.' },
		} )
	);
	const search = page.getByRole( 'searchbox', {
		name: 'Search by name or SKU',
	} );
	await tabFromContent( page, search );
	await page.keyboard.type( 'Probe' );
	await expect(
		page
			.locator( '.bulkcat-error' )
			.getByText( 'The products could not be loaded.' )
	).toBeVisible();
	// The rows loaded before stay behind the notice.
	await expect(
		page.getByRole( 'checkbox', { name: SEED.simple( 1 ) } )
	).toBeVisible();
	await page.unroute( PRODUCTS );
	const retry = page.getByRole( 'button', { name: 'Try again' } );
	await tabTo( page, retry, { back: true } );
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '.bulkcat-error' ) ).toHaveCount( 0 );
} );

test( 'a change in progress: pending rows, Cancel and View in History, then read again', async ( {
	page,
} ) => {
	test.skip(
		! process.env.WP_CMD,
		'Needs WP_CMD to hold and then run the queue.'
	);
	runQueue();
	await openScreen( page );
	const name = SEED.simple( 5 );
	const before = ( await productByName( page, name ) ).regular_price;
	await page.getByRole( 'checkbox', { name } ).press( 'Space' );
	// T84: the server holds the selection before the change is checked.
	await expect(
		page.getByRole( 'region', { name: 'Selection' } )
	).toContainText( '1 product selected' );
	await page
		.getByRole( 'button', { name: 'Make a change to these' } )
		.press( 'Enter' );
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await panel
		.getByRole( 'radio', { name: /^Increase or decrease/ } )
		.first()
		.press( 'Space' );
	await panel.getByRole( 'textbox', { name: 'How much' } ).fill( '2' );
	await panel
		.getByRole( 'button', { name: 'Check the change' } )
		.press( 'Enter' );
	await panel
		.getByRole( 'button', { name: 'Apply to 1 product' } )
		.press( 'Enter' );
	await expect( panel.getByText( /0 of 1 done/ ) ).toBeVisible();
	await panel
		.getByRole( 'button', { name: 'Back to products' } )
		.press( 'Enter' );

	// Nothing runs the queue yet, so the change waits: the banner and pending row.
	const banner = page.getByRole( 'group', { name: 'Change in progress' } );
	await expect( banner ).toBeVisible();
	await expect(
		banner.getByRole( 'button', { name: 'Stop this change' } )
	).toBeVisible();
	await expect(
		banner.getByRole( 'button', { name: 'View in History' } )
	).toBeVisible();
	const row = page.getByRole( 'row', { name: new RegExp( `^${ name }` ) } );
	await expect( row.locator( '[aria-busy="true"]' ).first() ).toBeVisible();

	// The queue runs; the banner goes and the row reads the new price.
	await untilVisible(
		page.locator( '.bulkcat-catalogue__progress' ).first(),
		1
	).catch( () => {} );
	for ( let i = 0; i < 10 && ( await banner.isVisible() ); i++ ) {
		runQueue();
		await page.waitForTimeout( 2500 );
	}
	await expect( banner ).toBeHidden();
	await expect( row.locator( '[aria-busy="true"]' ) ).toHaveCount( 0 );
	const after = ( await productByName( page, name ) ).regular_price;
	expect( Number( after ) ).toBeCloseTo( Number( before ) + 2, 2 );

	// Put it back directly, as the bulk journey's undo is covered elsewhere.
	await page.evaluate(
		async ( args ) => {
			const s = window.bulkcatSettings;
			const found = await (
				await window.fetch(
					`${ s.root }products?search=${ encodeURIComponent( args.name ) }&fields[]=name`,
					{ headers: { 'X-WP-Nonce': s.nonce } }
				)
			).json();
			const id = found.items.find(
				( item ) => item.name === args.name
			).id;
			await window.fetch( `${ s.root }products/${ id }`, {
				method: 'PATCH',
				headers: {
					'X-WP-Nonce': s.nonce,
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( { regular_price: args.price } ),
			} );
		},
		{ name, price: before }
	);
	expect( ( await productByName( page, name ) ).regular_price ).toBe(
		before
	);
} );

test( 'Cancel stops a queued change', async ( { page } ) => {
	test.skip( ! process.env.WP_CMD, 'Needs WP_CMD to hold the queue.' );
	runQueue();
	await openScreen( page );
	const name = SEED.simple( 6 );
	const before = ( await productByName( page, name ) ).regular_price;
	await page.getByRole( 'checkbox', { name } ).press( 'Space' );
	// T84: the server holds the selection before the change is checked.
	await expect(
		page.getByRole( 'region', { name: 'Selection' } )
	).toContainText( '1 product selected' );
	await page
		.getByRole( 'button', { name: 'Make a change to these' } )
		.press( 'Enter' );
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await panel
		.getByRole( 'radio', { name: /^Increase or decrease/ } )
		.first()
		.press( 'Space' );
	await panel.getByRole( 'textbox', { name: 'How much' } ).fill( '3' );
	await panel
		.getByRole( 'button', { name: 'Check the change' } )
		.press( 'Enter' );
	await panel
		.getByRole( 'button', { name: 'Apply to 1 product' } )
		.press( 'Enter' );
	await expect( panel.getByText( /0 of 1 done/ ) ).toBeVisible();
	await panel
		.getByRole( 'button', { name: 'Back to products' } )
		.press( 'Enter' );
	const banner = page.getByRole( 'group', { name: 'Change in progress' } );
	// FRONTEND-13: stopping a change is asked about first.
	await banner
		.getByRole( 'button', { name: 'Stop this change' } )
		.press( 'Enter' );
	await banner
		.getByRole( 'button', { name: 'Yes, stop it' } )
		.press( 'Enter' );
	await expect( banner ).toBeHidden( { timeout: 20_000 } );
	runQueue();
	expect( ( await productByName( page, name ) ).regular_price ).toBe(
		before
	);
} );
