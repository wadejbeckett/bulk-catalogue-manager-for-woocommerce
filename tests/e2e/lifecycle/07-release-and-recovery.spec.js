/**
 * T85, EXACT-SELECTION lifecycle group 7 and the tab's recovery through the
 * screen: an intermediate snapshot is released once its successor is
 * acknowledged (kept an hour at most), a release whose request is lost
 * leaves the seven-day inactivity bound in place, and the hourly cleanup
 * removes what those bounds allow; a reload comes back to the same
 * selection, a closed tab does not (sessionStorage ends with the tab; the
 * server keeps the snapshot until its own bounds); a tab returning after
 * the retention gets a plain "no longer kept" with the count it had, never
 * an empty or finished selection. The keepalive-against-prune race is
 * proved natively (tests/probe/selection-lifecycle-race.php).
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen } = require( '../helpers' );
const {
	rows,
	write,
	idsByName,
	snapshotRow,
	ageSnapshot,
	pruneSelections,
	tabCopy,
	currentToken,
	members,
	recordWrites,
	dropNextRequest,
	screenParts,
	searchFor,
	lastBatchId,
	undoLeftovers,
	fixtureCheck,
} = require( './support' );

test.skip( !! fixtureCheck(), fixtureCheck() );

// The safety net: a spec that stops before its own undo leaves nothing.
let mark = 0;
test.beforeEach( () => {
	mark = lastBatchId();
} );
test.afterEach( () => {
	undoLeftovers( mark );
} );

/**
 * Seconds between two of a row's UTC times.
 *
 * @param {string} from Earlier.
 * @param {string} to   Later.
 * @return {number} Seconds.
 */
const seconds = ( from, to ) =>
	( Date.parse( to + 'Z' ) - Date.parse( from + 'Z' ) ) / 1000;

test( 'an intermediate snapshot is released once replaced; a lost release leaves the seven-day bound; the cleanup honours both', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	const writes = recordWrites( page );
	await openScreen( page );
	const { bar, box } = screenParts( page );
	await searchFor( page, 'Probe Simple', SEED.simple( 1 ) );
	await box( SEED.simple( 1 ) ).check();
	await expect( bar ).toContainText( '1 product selected' );
	const first = await currentToken( page );
	await box( SEED.simple( 2 ) ).check();
	await expect( bar ).toContainText( '2 products selected' );
	await expect
		.poll( () =>
			writes.some(
				( each ) =>
					each.path === 'selection/release' &&
					each.body.tokens?.includes( first )
			)
		)
		.toBe( true );
	const second = await currentToken( page );
	const released = snapshotRow( first );
	expect( released.released_at ).toBeTruthy();
	expect(
		seconds( released.released_at, released.retained_until )
	).toBeLessThanOrEqual( 3600 );

	// The release of the second is lost on the way.
	const dropped = dropNextRequest( page, 'selection/release' );
	await box( SEED.simple( 3 ) ).check();
	await expect( bar ).toContainText( '3 products selected' );
	expect( ( await dropped ).tokens ).toContain( second );
	const kept = snapshotRow( second );
	expect( kept.released_at ).toBeNull();
	expect(
		seconds(
			rows( 'SELECT UTC_TIMESTAMP() AS n' )[ 0 ].n,
			kept.retained_until
		)
	).toBeGreaterThan( 6.9 * 86400 );
	// Nothing on screen: a lost release is not the user's problem.
	await expect( page.locator( '.bulkcat-selection' ) ).not.toContainText(
		'could not'
	);

	// An hour later the released one is cleaned up; the others stay.
	ageSnapshot( first, 3700 );
	expect( pruneSelections( first.split( '.' )[ 1 ] ) ).toBeLessThan( 50 );
	expect( snapshotRow( first ) ).toBeNull();
	expect( snapshotRow( second ) ).not.toBeNull();
	expect( snapshotRow( await currentToken( page ) ) ).not.toBeNull();
	expect( await members( page ) ).toEqual(
		idsByName( [ 1, 2, 3 ].map( SEED.simple ) ).sort( ( a, b ) => a - b )
	);
	await bar.getByRole( 'button', { name: 'Clear selection' } ).click();
} );

test( 'a reload comes back to the same selection; a closed tab does not', async ( {
	page,
	context,
} ) => {
	test.setTimeout( 120_000 );
	await openScreen( page );
	const { bar, box } = screenParts( page );
	await searchFor( page, 'Probe Simple', SEED.simple( 4 ) );
	await box( SEED.simple( 4 ) ).check();
	await box( SEED.simple( 5 ) ).check();
	await expect( bar ).toContainText( '2 products selected' );
	const token = await currentToken( page );

	await page.reload();
	await expect( bar ).toContainText( '2 products selected' );
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'Your selection is still here: 2 products.'
	);
	expect( await currentToken( page ) ).toBe( token );
	expect( await members( page ) ).toEqual(
		idsByName( [ 4, 5 ].map( SEED.simple ) ).sort( ( a, b ) => a - b )
	);

	// A new tab: the closed tab's copy went with it.
	const next = await context.newPage();
	await page.close();
	await openScreen( next );
	await expect(
		next.getByRole( 'region', { name: 'Selection' } )
	).toHaveCount( 0 );
	await expect( next.locator( '.bulkcat-selection' ) ).toHaveCount( 0 );
	expect( await tabCopy( next ) ).toBeNull();
	// The server still keeps it, within its own bounds, for no one to load.
	expect( snapshotRow( token ) ).not.toBeNull();
} );

test( 'a tab returning after the retention is told the work is no longer kept, with the count it had', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	await openScreen( page );
	const { bar, box } = screenParts( page );
	await searchFor( page, 'Probe Simple', SEED.simple( 6 ) );
	for ( const n of [ 6, 7, 8 ] ) {
		await box( SEED.simple( n ) ).check();
	}
	await expect( bar ).toContainText( '3 products selected' );
	const token = await currentToken( page );
	// More than seven days without a keepalive (not yet pruned).
	write(
		'UPDATE {p}bulkcat_selections SET authorization_expires_at = UTC_TIMESTAMP() - INTERVAL 8 DAY, retained_until = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id = %s',
		[ token.split( '.' )[ 1 ] ]
	);
	await page.reload();
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'You had 3 products selected.'
	);
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'no longer'
	);
	await expect( bar ).toHaveCount( 0 );
	await expect( page.locator( '.bulkcat-selection' ) ).not.toContainText(
		'The change finished'
	);
	await page.screenshot( {
		path: require( 'path' ).join(
			process.env.BULKCAT_E2E_SHOTS ||
				require( 'path' ).join( __dirname, '..', '.results' ),
			'lost-work-notice.png'
		),
	} );
} );
