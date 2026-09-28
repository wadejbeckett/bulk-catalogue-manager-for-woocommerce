/**
 * T85, EXACT-SELECTION lifecycle group 1 through the screen, with the
 * composition it rests on: a selection built across searches and pages,
 * subtraction on and off the page, the paged review; then a Select all
 * matching whose answer is lost while a new product starts to match, retried
 * with the same grant (the original set, never the new match), and a lost
 * capture whose payload is pruned before the retry (a definite "no longer
 * kept", never an empty or re-run selection). The receipt-pruning and
 * signed-deadline half of group 1 needs an eight-day clock and is proved
 * natively (tests/probe/exact-selection.php).
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen } = require( '../helpers' );
const {
	WP,
	rows,
	write,
	makeProducts,
	deleteProducts,
	idsByName,
	members,
	pruneSelections,
	currentToken,
	recordWrites,
	loseNextAnswer,
	screenParts,
	searchFor,
	lastBatchId,
	undoLeftovers,
} = require( './support' );

test.skip( ! WP, 'Needs WP_CMD to read the site.' );

// The safety net: a spec that stops before its own undo leaves nothing.
let mark = 0;
test.beforeEach( () => {
	mark = lastBatchId();
} );
test.afterEach( () => {
	undoLeftovers( mark );
} );

/**
 * The names of the product rows on the grid's current page.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<Array<string>>} Names.
 */
function rowNames( page ) {
	return page
		.locator( '.dataviews-view-table tbody tr input[type="checkbox"]' )
		.evaluateAll( ( inputs ) =>
			inputs.map( ( input ) => input.getAttribute( 'aria-label' ) )
		);
}

test( 'a selection composed across searches and pages, subtracted on and off the page, and reviewed a page at a time', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	await openScreen( page );
	const { bar, box } = screenParts( page );
	const [ one, two ] = idsByName( [ SEED.simple( 1 ), SEED.simple( 2 ) ] );
	const scale = rows(
		"SELECT ID FROM {p}posts WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE 'Scale Product 000%' ORDER BY ID"
	).map( ( row ) => Number( row.ID ) );
	expect( scale ).toHaveLength( 99 );

	// Two searches, one tick each.
	await searchFor( page, SEED.simple( 1 ), SEED.simple( 1 ) );
	await box( SEED.simple( 1 ) ).check();
	await expect( bar ).toContainText( '1 product selected' );
	await searchFor( page, SEED.simple( 2 ), SEED.simple( 2 ) );
	await box( SEED.simple( 2 ) ).check();
	await expect( bar ).toContainText( '2 products selected' );

	// A third search over four pages: a tick on page 1 and on page 2.
	await searchFor( page, 'Scale Product 000' );
	await expect(
		box( 'Scale Product 00099' ).or( box( 'Scale Product 00001' ) )
	).toBeVisible();
	const first = ( await rowNames( page ) )[ 0 ];
	await box( first ).check();
	await expect( bar ).toContainText( '3 products selected' );
	await page.getByRole( 'button', { name: 'Next page' } ).click();
	await expect( box( first ) ).toHaveCount( 0 );
	const second = ( await rowNames( page ) )[ 0 ];
	await box( second ).check();
	await expect( bar ).toContainText( '4 products selected' );

	// Select all matching adds the 99, the two ticked ones among them.
	await bar
		.getByRole( 'button', { name: /^Select all 99 products matching/ } )
		.click();
	await expect( bar ).toContainText( '101 products selected' );
	await expect( box( second ) ).toBeChecked();

	// Untick one on this page: only that one goes.
	const onPage = await rowNames( page );
	await box( onPage[ 1 ] ).uncheck();
	await expect( bar ).toContainText( '100 products selected' );
	const [ unticked ] = idsByName( [ onPage[ 1 ] ] );

	// Review, a page at a time; remove one that is not on the grid's page.
	await bar
		.getByRole( 'button', { name: 'Review selected products' } )
		.click();
	const drawer = page.getByRole( 'dialog', { name: 'Selected products' } );
	await expect( drawer ).toContainText( '100 products selected' );
	await expect( drawer ).toContainText( 'Page 1 of 4' );
	await expect( drawer.getByRole( 'listitem' ) ).toHaveCount( 25 );
	await drawer.getByRole( 'button', { name: 'Next', exact: true } ).click();
	await drawer.getByRole( 'button', { name: 'Next', exact: true } ).click();
	await expect( drawer ).toContainText( 'Page 3 of 4' );
	const removeButtons = drawer.getByRole( 'button', { name: /^Remove / } );
	const label = await removeButtons.first().getAttribute( 'aria-label' );
	const removedName = /^Remove (.*) from the selection$/.exec( label )[ 1 ];
	expect( onPage ).not.toContain( removedName );
	await removeButtons.first().click();
	await expect( drawer ).toContainText( '99 products selected' );
	await page.keyboard.press( 'Escape' );
	await expect( drawer ).toBeHidden();
	const [ removed ] = idsByName( [ removedName ] );

	// Another search: nothing is lost, membership shows on the rows.
	await searchFor( page, SEED.simple( 1 ), SEED.simple( 1 ) );
	await expect( bar ).toContainText( '99 products selected' );
	await expect( box( SEED.simple( 1 ) ) ).toBeChecked();

	// The exact set: the two, the 99, less the unticked and the removed.
	const expected = [ one, two, ...scale ]
		.filter( ( id ) => id !== unticked && id !== removed )
		.sort( ( a, b ) => a - b );
	expect( await members( page ) ).toEqual( expected );
	await bar.getByRole( 'button', { name: 'Clear selection' } ).click();
	await expect( bar ).toHaveCount( 0 );
} );

test( 'a lost Select all matching answer is retried with the same grant: the set it captured, never a product that matched later; pruned first, it is gone', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const writes = recordWrites( page );
	const late = [];
	try {
		await openScreen( page );
		const { bar, box } = screenParts( page );
		const matches = rows(
			"SELECT ID FROM {p}posts WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE 'Scale Product 0001%' ORDER BY ID"
		).map( ( row ) => Number( row.ID ) );
		expect( matches ).toHaveLength( 10 );
		await searchFor( page, 'Scale Product 0001', 'Scale Product 00010' );
		await box( 'Scale Product 00010' ).check();
		await expect( bar ).toContainText( '1 product selected' );

		// The capture reaches the server; its answer does not come back.
		const lost = loseNextAnswer( page, 'selection' );
		await bar
			.getByRole( 'button', {
				name: /^Select all 10 products matching/,
			} )
			.click();
		const answer = await lost;
		expect( answer.status ).toBe( 200 );
		expect( answer.json.count ).toBe( 10 );
		await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
			'Your selection could not be saved just now.'
		);
		await expect( bar ).toContainText( '1 product selected' );

		// Meanwhile a new product starts to match the search.
		late.push( ...makeProducts( [ 'Scale Product 0001 late' ] ) );

		// Try again: the same grant, the set captured before the new match.
		await page
			.locator( '.bulkcat-selection' )
			.getByRole( 'button', { name: 'Try again' } )
			.click();
		await expect( bar ).toContainText( '10 products selected' );
		expect( await members( page ) ).toEqual( matches );
		const captures = writes.filter(
			( each ) => each.path === 'selection' && each.body.intent?.version
		);
		const queried = captures.filter(
			( each ) => each.body.intent.conditions
		);
		expect( queried ).toHaveLength( 2 );
		expect( queried[ 1 ].body.uuid ).toBe( queried[ 0 ].body.uuid );
		expect( queried[ 1 ].body.request_proof ).toBe(
			queried[ 0 ].body.request_proof
		);
		expect( queried[ 1 ].status ).toBe( 200 );

		// Again, but the captured payload is pruned before the retry.
		await bar.getByRole( 'button', { name: 'Clear selection' } ).click();
		await box( 'Scale Product 00011' ).check();
		await expect( bar ).toContainText( '1 product selected' );
		const base = await currentToken( page );
		const lostAgain = loseNextAnswer( page, 'selection' );
		// The page's count is from before the new match; the capture is now.
		await bar
			.getByRole( 'button', {
				name: /^Select all \d+ products matching/,
			} )
			.click();
		const second = await lostAgain;
		expect( second.json.count ).toBe( 11 );
		const id = second.json.token.split( '.' )[ 1 ];
		expect(
			write(
				'UPDATE {p}bulkcat_selections SET retained_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = %s',
				[ id ]
			)
		).toBe( 1 );
		expect( pruneSelections( id ) ).toBeLessThan( 50 );
		expect(
			rows( 'SELECT id FROM {p}bulkcat_selections WHERE id = %s', [ id ] )
		).toEqual( [] );
		const receipt = rows(
			"SELECT state FROM {p}bulkcat_requests WHERE result_kind = 'snapshot' AND result_id = %s",
			[ id ]
		);
		expect( receipt ).toEqual( [ { state: 'gone' } ] );
		await page
			.locator( '.bulkcat-selection' )
			.getByRole( 'button', { name: 'Try again' } )
			.click();
		await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
			'no longer'
		);
		// The last confirmed selection is kept; nothing was captured again.
		await expect( bar ).toContainText( '1 product selected' );
		expect( await currentToken( page ) ).toBe( base );
		const last = writes
			.filter( ( each ) => each.path === 'selection' )
			.slice( -1 )[ 0 ];
		expect( last.status ).toBe( 410 );
		await bar.getByRole( 'button', { name: 'Clear selection' } ).click();
	} finally {
		deleteProducts( late );
	}
} );
