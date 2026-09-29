/**
 * T85, EXACT-SELECTION lifecycle group 6 through the screen, on the real
 * server clock:
 *
 * - an open page renews its lease and keeps the same token, so a preview
 *   made before stays good (a 60-second test lease, waited out for real);
 * - a page whose timers were frozen past its lease (a suspended tab: the
 *   page's clock paused with Playwright while the server's lease lapses for
 *   real) comes back with the same products under a new epoch, says so, and
 *   asks for the change to be checked again; a product that started to match
 *   meanwhile is not included;
 * - overnight, simulated: the same, with the snapshot's stored times moved
 *   back twelve hours through WP-CLI (the plugin has no settable clock for a
 *   web request) and the page's clock moved forward twelve hours;
 * - a user who loses the right to use the screen mid-selection is refused in
 *   plain words and nothing is written; the selection is kept, and once the
 *   right is back it carries on;
 * - with BULKCAT_E2E_REAL_SUSPENSION=1, the same at the default 15-minute
 *   lease over a real 30-minute wait: one page left open, one frozen.
 *
 * The short lease comes from the throwaway must-use plugin in support.js,
 * for requests carrying X-Bulkcat-E2E-Ttl only.
 */
const { execSync } = require( 'child_process' );
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen } = require( '../helpers' );
const {
	WP,
	rows,
	makeProducts,
	deleteProducts,
	prices,
	idsByName,
	batchesByUuid,
	drain,
	snapshotRow,
	ageSnapshot,
	currentToken,
	members,
	recordWrites,
	screenParts,
	searchFor,
	checkIncrease,
	installLeaseProbe,
	removeLeaseProbe,
	lastBatchId,
	undoLeftovers,
	fixtureCheck,
} = require( './support' );

test.skip( !! fixtureCheck(), fixtureCheck() );

const DRIFT =
	'This page was idle for a while, so your selection was checked again.';

/**
 * Select every product matching a search (tick the first, then Select all
 * matching), as a user building an A/B set would.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          text  Search.
 * @param {Array<string>}                   names The matching names.
 */
async function selectMatching( page, text, names ) {
	const { bar, box } = screenParts( page );
	await searchFor( page, text, names[ 0 ] );
	await box( names[ 0 ] ).check();
	await expect( bar ).toContainText( '1 product selected' );
	await bar
		.getByRole( 'button', {
			name: new RegExp(
				`^Select all ${ names.length } products matching`
			),
		} )
		.click();
	await expect( bar ).toContainText( `${ names.length } products selected` );
}

/**
 * Tell the page it is visible again, as a tab brought back to the front.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
function wakeUp( page ) {
	return page.evaluate( () =>
		document.dispatchEvent( new Event( 'visibilitychange' ) )
	);
}

/**
 * Apply the checked change and run it; returns its batch id.
 *
 * @param {import('@playwright/test').Page}    page   Page.
 * @param {import('@playwright/test').Locator} panel  Bulk panel.
 * @param {string}                             name   Apply button's name.
 * @param {Array<Object>}                      writes recordWrites().
 * @return {Promise<number>} Batch id.
 */
async function applyAndRun( page, panel, name, writes ) {
	const count = writes.filter( ( each ) => each.path === 'batches' ).length;
	await panel.getByRole( 'button', { name } ).click();
	await expect( panel ).toContainText( 'Applying the change' );
	await expect
		.poll(
			() => writes.filter( ( each ) => each.path === 'batches' ).length
		)
		.toBeGreaterThan( count );
	const sent = writes.filter( ( each ) => each.path === 'batches' );
	const body = sent[ sent.length - 1 ].body;
	const [ header ] = batchesByUuid( body.uuid );
	expect( drain( Number( header.batch_id ) ).status ).toBe( 'complete' );
	return Number( header.batch_id );
}

test.describe( 'with a 60-second lease', () => {
	test.use( { extraHTTPHeaders: { 'X-Bulkcat-E2E-Ttl': '60' } } );
	let mark = 0;
	test.beforeAll( () => installLeaseProbe() );
	test.afterAll( () => removeLeaseProbe() );
	test.beforeEach( () => {
		mark = lastBatchId();
	} );
	test.afterEach( () => {
		undoLeftovers( mark );
	} );

	test( 'an open page renews its lease and keeps its token, so its preview stays good', async ( {
		page,
	} ) => {
		test.setTimeout( 300_000 );
		const writes = recordWrites( page );
		const ids = idsByName( [ 5, 6 ].map( SEED.simple ) );
		const before = prices( ids );
		await openScreen( page );
		const { bar, box } = screenParts( page );
		await searchFor( page, 'Probe Simple', SEED.simple( 5 ) );
		await box( SEED.simple( 5 ) ).check();
		await box( SEED.simple( 6 ) ).check();
		await expect( bar ).toContainText( '2 products selected' );
		const token = await currentToken( page );
		const row = snapshotRow( token );
		// The test lease is honoured: sixty seconds.
		expect(
			( Date.parse( row.authorization_expires_at + 'Z' ) -
				Date.parse( row.created_at + 'Z' ) ) /
				1000
		).toBeLessThanOrEqual( 61 );
		const panel = await checkIncrease( page, '1', 'Apply to 2 products' );

		// Two and a half leases, the page open and its timers running.
		const started = Date.now();
		await page.waitForTimeout( 150_000 );
		const renewals = writes.filter(
			( each ) => each.path === 'selection/renew'
		);
		expect( renewals.length ).toBeGreaterThanOrEqual( 3 );
		expect( await currentToken( page ) ).toBe( token );
		const after = snapshotRow( token );
		expect( after.authorization_epoch ).toBe( '1' );
		expect(
			Date.parse( after.authorization_expires_at + 'Z' )
		).toBeGreaterThan( started + 60_000 );
		await expect( page.locator( '.bulkcat-selection' ) ).not.toContainText(
			DRIFT
		);

		// The preview made before the wait still applies.
		const batchId = await applyAndRun(
			page,
			panel,
			'Apply to 2 products',
			writes
		);
		expect(
			writes.filter( ( each ) => each.path === 'preview' )
		).toHaveLength( 1 );
		const now = prices( ids );
		ids.forEach( ( id ) =>
			expect( now[ id ] ).toBeCloseTo( before[ id ] + 1, 2 )
		);
		expect( batchId ).toBeGreaterThan( 0 );
	} );

	test( 'a page frozen past its lease comes back with the same products under a new epoch, checks the change again, and never takes a new match', async ( {
		page,
	} ) => {
		test.setTimeout( 300_000 );
		const writes = recordWrites( page );
		const names = [ 'Lifecycle Six A', 'Lifecycle Six B' ];
		const made = makeProducts( names, '10.00' );
		try {
			await page.clock.install();
			await openScreen( page );
			await selectMatching( page, 'Lifecycle Six', names );
			const token = await currentToken( page );
			const panel = await checkIncrease(
				page,
				'1',
				'Apply to 2 products'
			);

			// The tab is suspended: its timers stop; the server's clock
			// does not.
			await page.clock.pauseAt( Date.now() + 1000 );
			const renewsBefore = writes.filter(
				( each ) => each.path === 'selection/renew'
			).length;
			await new Promise( ( resolve ) => setTimeout( resolve, 75_000 ) );
			expect(
				writes.filter( ( each ) => each.path === 'selection/renew' )
					.length
			).toBe( renewsBefore );
			expect(
				Date.parse(
					snapshotRow( token ).authorization_expires_at + 'Z'
				)
			).toBeLessThan( Date.now() );
			// Meanwhile a third product starts to match the search.
			made.push( ...makeProducts( [ 'Lifecycle Six C' ], '10.00' ) );

			// Back to the front.
			await page.clock.fastForward( 75_000 );
			await page.clock.resume();
			await wakeUp( page );
			await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
				DRIFT
			);
			const { bar } = screenParts( page );
			await expect( bar ).toContainText( '2 products selected' );
			const renewed = await currentToken( page );
			expect( renewed ).not.toBe( token );
			expect( renewed.split( '.' )[ 1 ] ).toBe( token.split( '.' )[ 1 ] );
			expect( snapshotRow( renewed ).authorization_epoch ).toBe( '2' );
			expect( await members( page ) ).toEqual(
				made.slice( 0, 2 ).sort( ( a, b ) => a - b )
			);

			// The earlier preview no longer applies: check it again.
			await expect(
				panel.getByRole( 'button', { name: 'Apply to 2 products' } )
			).toHaveCount( 0, { timeout: 30_000 } );
			await page.screenshot( {
				path: shot( 'suspended-drift-preview-again.png' ),
			} );
			const check = panel.getByRole( 'button', {
				name: /^(Check the change|Preview again)$/,
			} );
			await check.first().click();
			await expect(
				panel.getByRole( 'button', { name: 'Apply to 2 products' } )
			).toBeEnabled( { timeout: 60_000 } );
			await applyAndRun( page, panel, 'Apply to 2 products', writes );
			const now = prices( made );
			expect( now[ made[ 0 ] ] ).toBeCloseTo( 11, 2 );
			expect( now[ made[ 1 ] ] ).toBeCloseTo( 11, 2 );
			expect( now[ made[ 2 ] ] ).toBeCloseTo( 10, 2 );
		} finally {
			undoLeftovers( mark );
			deleteProducts( made );
		}
	} );
} );

test.describe( 'at the default lease', () => {
	let mark = 0;
	test.beforeEach( () => {
		mark = lastBatchId();
	} );
	test.afterEach( () => {
		undoLeftovers( mark );
	} );

	test( 'overnight, simulated: the same products under a new epoch, kept another seven days, never a new match', async ( {
		page,
	} ) => {
		test.setTimeout( 240_000 );
		const writes = recordWrites( page );
		const names = [ 'Lifecycle Night A', 'Lifecycle Night B' ];
		const made = makeProducts( names, '10.00' );
		try {
			await page.clock.install();
			await openScreen( page );
			await selectMatching( page, 'Lifecycle Night', names );
			const token = await currentToken( page );
			const panel = await checkIncrease(
				page,
				'1',
				'Apply to 2 products'
			);
			await page.clock.pauseAt( Date.now() + 1000 );
			// Twelve hours pass: the server's stored times move back (the
			// snapshot's creation, lease and retention), the page's clock on.
			expect( ageSnapshot( token, 12 * 3600 ) ).toBe( 1 );
			made.push( ...makeProducts( [ 'Lifecycle Night C' ], '10.00' ) );
			await page.clock.fastForward( 12 * 3600 * 1000 );
			await page.clock.resume();
			await wakeUp( page );
			await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
				DRIFT
			);
			const renewed = await currentToken( page );
			const row = snapshotRow( renewed );
			expect( row.authorization_epoch ).toBe( '2' );
			const now = Date.parse(
				rows( 'SELECT UTC_TIMESTAMP() AS n' )[ 0 ].n + 'Z'
			);
			expect(
				( Date.parse( row.retained_until + 'Z' ) - now ) / 86400000
			).toBeGreaterThan( 6.99 );
			expect( await members( page ) ).toEqual(
				made.slice( 0, 2 ).sort( ( a, b ) => a - b )
			);
			await expect(
				panel.getByRole( 'button', { name: 'Apply to 2 products' } )
			).toHaveCount( 0, { timeout: 30_000 } );
			await panel
				.getByRole( 'button', {
					name: /^(Check the change|Preview again)$/,
				} )
				.first()
				.click();
			await applyAndRun( page, panel, 'Apply to 2 products', writes );
			const after = prices( made );
			expect( after[ made[ 2 ] ] ).toBeCloseTo( 10, 2 );
			expect( after[ made[ 0 ] ] ).toBeCloseTo( 11, 2 );
		} finally {
			undoLeftovers( mark );
			deleteProducts( made );
		}
	} );

	test( 'a user who loses the right mid-selection is refused in plain words and nothing is written; once it is back the same selection carries on', async ( {
		browser,
	} ) => {
		test.setTimeout( 240_000 );
		const login = 'bulkcat_probe_manager';
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
			viewport: { width: 1440, height: 900 },
		} );
		const page = await context.newPage();
		const ids = idsByName( [ 1, 2, 3 ].map( SEED.simple ) );
		const before = prices( ids );
		try {
			await page.goto( '/wp-login.php' );
			await page.locator( '#user_login' ).fill( login );
			await page
				.locator( '#user_pass' )
				.fill(
					process.env.BULKCAT_PROBE_PASSWORD ||
						'bulkcat-probe-password'
				);
			await page.locator( '#wp-submit' ).click();
			await page.waitForURL( /wp-admin/ );
			await openScreen( page );
			const { bar, box } = screenParts( page );
			await searchFor( page, 'Probe Simple', SEED.simple( 1 ) );
			await box( SEED.simple( 1 ) ).check();
			await box( SEED.simple( 2 ) ).check();
			await expect( bar ).toContainText( '2 products selected' );
			const token = await currentToken( page );
			const last = lastBatchId();

			// The page stays open; the account is demoted.
			execSync( `${ WP } user set-role ${ login } subscriber`, {
				stdio: 'ignore',
			} );
			await box( SEED.simple( 3 ) ).check();
			const refusal =
				'Your account does not have permission to use Bulk Catalogue Manager. Ask an administrator for access.';
			await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
				refusal
			);
			await expect( bar ).toContainText( '2 products selected' );
			await bar
				.getByRole( 'button', { name: 'Make a change to these' } )
				.click();
			const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
			await panel
				.getByRole( 'radio', { name: /^Increase or decrease/ } )
				.first()
				.check();
			await panel
				.getByRole( 'textbox', { name: 'How much' } )
				.fill( '1' );
			await panel
				.getByRole( 'button', { name: 'Check the change' } )
				.click();
			await expect( panel ).toContainText( refusal );
			await expect(
				panel.getByRole( 'button', { name: /^Apply to/ } )
			).toHaveCount( 0 );
			await page.screenshot( {
				path: shot( 'permission-lost-refusal.png' ),
			} );
			// Nothing written; the selection is still kept on the server.
			expect( lastBatchId() ).toBe( last );
			const now = prices( ids );
			ids.forEach( ( id ) => expect( now[ id ] ).toBe( before[ id ] ) );
			expect( snapshotRow( token ) ).not.toBeNull();
			await panel
				.getByRole( 'button', { name: 'Close', exact: true } )
				.click();
			await expect( panel ).toBeHidden();

			// The right comes back: the same selection carries on.
			execSync( `${ WP } user set-role ${ login } shop_manager`, {
				stdio: 'ignore',
			} );
			await box( SEED.simple( 3 ) )
				.uncheck()
				.catch( () => {} );
			await box( SEED.simple( 3 ) ).check();
			await expect( bar ).toContainText( '3 products selected' );
			expect( await members( page ) ).toEqual(
				[ ...ids ].sort( ( a, b ) => a - b )
			);
			await bar
				.getByRole( 'button', { name: 'Clear selection' } )
				.click();
		} finally {
			execSync( `${ WP } user set-role ${ login } shop_manager`, {
				stdio: 'ignore',
			} );
			await context.close();
		}
	} );
} );

test.describe( 'a real 30-minute suspension (BULKCAT_E2E_REAL_SUSPENSION=1)', () => {
	test.skip(
		! process.env.BULKCAT_E2E_REAL_SUSPENSION,
		'Takes 30 minutes of wall time.'
	);
	let mark = 0;
	test.beforeEach( () => {
		mark = lastBatchId();
	} );
	test.afterEach( () => {
		undoLeftovers( mark );
	} );

	test( 'thirty minutes at the default lease: the open page keeps its token and preview, the frozen page comes back under a new epoch without the new match', async ( {
		context,
		browser,
	} ) => {
		test.setTimeout( 45 * 60 * 1000 );
		const names = [ 'Lifecycle Real A', 'Lifecycle Real B' ];
		const made = makeProducts( names, '10.00' );
		const [ seven ] = makeProducts( [ 'Lifecycle Open Page' ], '20.00' );
		const before = prices( [ seven ] );
		const open = await context.newPage();
		// Playwright's clock belongs to the browser context, so the frozen
		// page gets a context of its own (the second real run froze both
		// pages' timers through the shared clock: one renewal in 30 minutes).
		const frozenContext = await browser.newContext( {
			storageState: require( 'path' ).join(
				__dirname,
				'..',
				'.results',
				'admin.json'
			),
			viewport: { width: 1440, height: 900 },
		} );
		const frozen = await frozenContext.newPage();
		const openWrites = recordWrites( open );
		const frozenWrites = recordWrites( frozen );
		const log = [];
		try {
			// The open page: one product, previewed.
			await openScreen( open );
			const parts = screenParts( open );
			await searchFor(
				open,
				'Lifecycle Open Page',
				'Lifecycle Open Page'
			);
			await parts.box( 'Lifecycle Open Page' ).check();
			await expect( parts.bar ).toContainText( '1 product selected' );
			const openToken = await currentToken( open );
			const openPanel = await checkIncrease(
				open,
				'1',
				'Apply to 1 product'
			);

			// The frozen page: A and B, previewed, then its timers stop.
			await frozen.clock.install();
			await openScreen( frozen );
			await selectMatching( frozen, 'Lifecycle Real', names );
			const frozenToken = await currentToken( frozen );
			const frozenPanel = await checkIncrease(
				frozen,
				'1',
				'Apply to 2 products'
			);
			await frozen.clock.pauseAt( Date.now() + 1000 );
			// The open page is the tab in front, a page left open in view.
			await open.bringToFront();

			const started = Date.now();
			log.push( `wait started ${ new Date( started ).toISOString() }` );
			for ( let minute = 1; minute <= 30; minute++ ) {
				await open.waitForTimeout( 60_000 );
				if ( minute === 20 ) {
					made.push(
						...makeProducts( [ 'Lifecycle Real C' ], '10.00' )
					);
				}
			}
			const wall = ( Date.now() - started ) / 1000;
			log.push(
				`wait ended ${ new Date().toISOString() }, ${ wall.toFixed(
					1
				) } s`
			);

			// The open page renewed on its own and kept its token.
			const renewals = openWrites.filter(
				( each ) => each.path === 'selection/renew'
			).length;
			log.push( `open page renewals: ${ renewals }` );
			expect( renewals ).toBeGreaterThanOrEqual( 5 );
			expect( await currentToken( open ) ).toBe( openToken );
			expect( snapshotRow( openToken ).authorization_epoch ).toBe( '1' );
			await expect(
				open.locator( '.bulkcat-selection' )
			).not.toContainText( DRIFT );
			await applyAndRun(
				open,
				openPanel,
				'Apply to 1 product',
				openWrites
			);
			expect(
				openWrites.filter( ( each ) => each.path === 'preview' )
			).toHaveLength( 1 );
			expect( prices( [ seven ] )[ seven ] ).toBeCloseTo(
				before[ seven ] + 1,
				2
			);

			// The frozen page's lease lapsed thirty minutes ago, for real.
			expect(
				frozenWrites.filter(
					( each ) => each.path === 'selection/renew'
				).length
			).toBe( 0 );
			expect(
				Date.parse(
					snapshotRow( frozenToken ).authorization_expires_at + 'Z'
				)
			).toBeLessThan( Date.now() - 14 * 60 * 1000 );
			await frozen.clock.fastForward( wall * 1000 );
			await frozen.clock.resume();
			await wakeUp( frozen );
			await expect(
				frozen.locator( '.bulkcat-selection' )
			).toContainText( DRIFT );
			const renewed = await currentToken( frozen );
			expect( snapshotRow( renewed ).authorization_epoch ).toBe( '2' );
			expect( await members( frozen ) ).toEqual(
				made.slice( 0, 2 ).sort( ( a, b ) => a - b )
			);
			await frozen.screenshot( {
				path: shot( 'real-30-minute-frozen-return.png' ),
			} );
			await expect(
				frozenPanel.getByRole( 'button', {
					name: 'Apply to 2 products',
				} )
			).toHaveCount( 0, { timeout: 30_000 } );
			await frozenPanel
				.getByRole( 'button', {
					name: /^(Check the change|Preview again)$/,
				} )
				.first()
				.click();
			await applyAndRun(
				frozen,
				frozenPanel,
				'Apply to 2 products',
				frozenWrites
			);
			const after = prices( made );
			expect( after[ made[ 2 ] ] ).toBeCloseTo( 10, 2 );
			expect( after[ made[ 0 ] ] ).toBeCloseTo( 11, 2 );
		} finally {
			// eslint-disable-next-line no-console
			console.log( log.join( '\n' ) );
			await frozenContext.close();
			undoLeftovers( mark );
			deleteProducts( [ ...made, seven ] );
		}
	} );
} );

/**
 * Where a screenshot goes: BULKCAT_E2E_SHOTS, else the results folder.
 *
 * @param {string} name File name.
 * @return {string} Path.
 */
function shot( name ) {
	const path = require( 'path' );
	return path.join(
		process.env.BULKCAT_E2E_SHOTS ||
			path.join( __dirname, '..', '.results' ),
		name
	);
}
