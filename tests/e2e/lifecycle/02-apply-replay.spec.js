/**
 * T85, EXACT-SELECTION lifecycle group 2 through the screen, with the
 * completion rules: an Apply whose answer is lost is retried after more than
 * the old 60-second window with the same grant (one batch, one relative
 * change); an Apply accepted before its selection's lease lapsed and its
 * payload was pruned still resolves to that batch; an Apply grant survives a
 * reload while its answer is unknown (T84's "left for T85"), and the server
 * refuses a second application of it; a change that finished in full clears
 * what it applied and keeps what was selected since; one that ended in part
 * keeps the selection. Every change is undone at the end.
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen, rest } = require( '../helpers' );
const {
	WP,
	php,
	write,
	makeProducts,
	deleteProducts,
	prices,
	idsByName,
	batchesByUuid,
	drain,
	members,
	pruneSelections,
	currentToken,
	recordWrites,
	loseNextAnswer,
	dropNextRequest,
	screenParts,
	searchFor,
	checkIncrease,
	undoBatch,
	tabCopy,
	lastBatchId,
	undoLeftovers,
} = require( './support' );

test.skip( ! WP, 'Needs WP_CMD to run the queue and read the site.' );

// The safety net: a spec that stops before its own undo leaves nothing.
let mark = 0;
test.beforeEach( () => {
	mark = lastBatchId();
} );
test.afterEach( () => {
	undoLeftovers( mark );
} );

/**
 * Select probe products by ticking them in one search.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {Array<number>}                   ns   Probe product numbers.
 */
async function tickProbes( page, ns ) {
	const { bar, box } = screenParts( page );
	await searchFor( page, 'Probe Simple', SEED.simple( ns[ 0 ] ) );
	for ( const n of ns ) {
		await box( SEED.simple( n ) ).check();
	}
	await expect( bar ).toContainText(
		`${ ns.length } product${ ns.length > 1 ? 's' : '' } selected`
	);
}

/**
 * Expect each price to have gone up by exactly one.
 *
 * @param {Object} before id => price before.
 */
function expectPlusOne( before ) {
	const now = prices( Object.keys( before ).map( Number ) );
	Object.entries( before ).forEach( ( [ id, price ] ) =>
		expect( now[ id ], `product ${ id }` ).toBeCloseTo( price + 1, 2 )
	);
}

test( 'a lost Apply answer, retried after more than a minute with the same grant: one batch, one application, and the selection clears once it finished in full', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const ids = idsByName( [ 1, 2, 3 ].map( SEED.simple ) );
	const before = prices( ids );
	let batchId = 0;
	try {
		await openScreen( page );
		await tickProbes( page, [ 1, 2, 3 ] );
		const panel = await checkIncrease( page, '1', 'Apply to 3 products' );
		const lost = loseNextAnswer( page, 'batches' );
		await panel
			.getByRole( 'button', { name: 'Apply to 3 products' } )
			.click();
		const answer = await lost;
		batchId = Number( answer.json.batch_id );
		expect( batchId ).toBeGreaterThan( 0 );
		await expect( panel ).toContainText(
			'We could not confirm whether the change was applied'
		);
		expect( batchesByUuid( answer.body.uuid ) ).toHaveLength( 1 );

		// Longer than the 1.0 screen's 60-second replay window.
		await page.waitForTimeout( 65_000 );
		const retried = page.waitForRequest(
			( request ) =>
				request.method() === 'POST' &&
				/bulkcat(\/|%2F)v1(\/|%2F)batches(\?|&|$)/.test( request.url() )
		);
		await panel.getByRole( 'button', { name: 'Try again' } ).click();
		const again = ( await retried ).postDataJSON();
		expect( again.uuid ).toBe( answer.body.uuid );
		expect( again.request_proof ).toBe( answer.body.request_proof );
		await expect( panel ).toContainText(
			'This change had already been accepted, so it was not made again.'
		);
		expect( batchesByUuid( answer.body.uuid ) ).toEqual( [
			expect.objectContaining( { batch_id: String( batchId ) } ),
		] );
		expect( drain( batchId ).status ).toBe( 'complete' );
		expectPlusOne( before );
		await expect( panel ).toContainText( '3 products updated.', {
			timeout: 30_000,
		} );
		await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
			'The change finished, so your selection was cleared.'
		);
	} finally {
		if ( batchId ) {
			await undoBatch( page, batchId );
		}
	}
	const back = prices( ids );
	ids.forEach( ( id ) =>
		expect( back[ id ] ).toBeCloseTo( before[ id ], 2 )
	);
} );

test( 'an accepted Apply still resolves to its batch after the selection’s lease lapsed and its payload was pruned', async ( {
	page,
} ) => {
	test.setTimeout( 240_000 );
	const ids = idsByName( [ 4, 5 ].map( SEED.simple ) );
	const before = prices( ids );
	let batchId = 0;
	try {
		await openScreen( page );
		await tickProbes( page, [ 4, 5 ] );
		const token = await currentToken( page );
		const panel = await checkIncrease( page, '1', 'Apply to 2 products' );
		const lost = loseNextAnswer( page, 'batches' );
		await panel
			.getByRole( 'button', { name: 'Apply to 2 products' } )
			.click();
		const answer = await lost;
		batchId = Number( answer.json.batch_id );
		await expect( panel ).toContainText(
			'We could not confirm whether the change was applied'
		);

		// The lease lapses and the payload is pruned before the retry.
		const snapshot = token.split( '.' )[ 1 ];
		expect(
			write(
				'UPDATE {p}bulkcat_selections SET authorization_expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR, retained_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = %s',
				[ snapshot ]
			)
		).toBe( 1 );
		expect( pruneSelections( snapshot ) ).toBeLessThan( 50 );
		expect(
			php(
				`global $wpdb; echo wp_json_encode( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bulkcat_selections WHERE id = %s", $in ) ) );`,
				snapshot
			)
		).toBe( 0 );

		await panel.getByRole( 'button', { name: 'Try again' } ).click();
		await expect( panel ).toContainText(
			'This change had already been accepted, so it was not made again.'
		);
		expect( batchesByUuid( answer.body.uuid ) ).toHaveLength( 1 );
		expect( drain( batchId ).status ).toBe( 'complete' );
		expectPlusOne( before );
	} finally {
		if ( batchId ) {
			await undoBatch( page, batchId );
		}
	}
} );

test( 'an Apply grant survives a reload while its answer is unknown, and the server refuses a second application', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const writes = recordWrites( page );
	const accepted = idsByName( [ 6, 7 ].map( SEED.simple ) );
	const [ unsent ] = idsByName( [ SEED.simple( 8 ) ] );
	const before = prices( [ ...accepted, unsent ] );
	const made = [];
	try {
		// 1. Accepted by the server, the answer lost, then a reload.
		await openScreen( page );
		await tickProbes( page, [ 6, 7 ] );
		let panel = await checkIncrease( page, '1', 'Apply to 2 products' );
		const lost = loseNextAnswer( page, 'batches' );
		await panel
			.getByRole( 'button', { name: 'Apply to 2 products' } )
			.click();
		const answer = await lost;
		const batchId = Number( answer.json.batch_id );
		made.push( batchId );
		await expect( panel ).toContainText(
			'We could not confirm whether the change was applied'
		);
		const held = await page.evaluate( () => {
			const s = window.bulkcatSettings;
			return JSON.parse(
				window.sessionStorage.getItem(
					`bulkcat-selection:${ s.root }:${ s.user.id }:apply`
				)
			);
		} );
		expect( held.grant.uuid ).toBe( answer.body.uuid );

		await page.reload();
		const banner = page.getByRole( 'group', {
			name: 'Change in progress',
		} );
		await expect( banner ).toContainText(
			'This change had already been accepted, so it was not sent again.'
		);
		const resolved = writes.find(
			( each ) => each.path === 'requests/resolve'
		);
		expect( resolved.body.uuid ).toBe( answer.body.uuid );
		expect( drain( batchId ).status ).toBe( 'complete' );
		await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
			'The change finished, so your selection was cleared.',
			{ timeout: 30_000 }
		);

		// The same grant again: the same batch. Changed under its id: 409.
		const replay = await rest( page, 'batches', {
			method: 'POST',
			data: answer.body,
		} );
		expect( replay.status ).toBe( 200 );
		expect( replay.json ).toMatchObject( {
			batch_id: batchId,
			replayed: true,
		} );
		const changed = JSON.parse( JSON.stringify( answer.body ) );
		changed.intent.operations[ 0 ].args.amount = '2';
		const conflict = await rest( page, 'batches', {
			method: 'POST',
			data: changed,
		} );
		expect( conflict.status ).toBe( 409 );
		expect( conflict.json.code ).toBe( 'bulkcat_request_conflict' );
		expect( batchesByUuid( answer.body.uuid ) ).toHaveLength( 1 );
		expectPlusOne( {
			[ accepted[ 0 ] ]: before[ accepted[ 0 ] ],
			[ accepted[ 1 ] ]: before[ accepted[ 1 ] ],
		} );

		// 2. Never received by the server, then a reload: nothing changed,
		// and the same change sends the same grant.
		await tickProbes( page, [ 8 ] );
		panel = await checkIncrease( page, '1', 'Apply to 1 product' );
		const dropped = dropNextRequest( page, 'batches' );
		await panel
			.getByRole( 'button', { name: 'Apply to 1 product' } )
			.click();
		const body = await dropped;
		await expect( panel ).toContainText(
			'We could not confirm whether the change was applied'
		);
		expect( batchesByUuid( body.uuid ) ).toHaveLength( 0 );
		await page.reload();
		await expect(
			page.locator( '.dataviews-view-table tbody tr' ).first()
		).toBeVisible();
		await expect
			.poll(
				() =>
					writes.filter(
						( each ) =>
							each.path === 'requests/resolve' &&
							each.body.uuid === body.uuid
					).length
			)
			.toBe( 1 );
		await expect(
			page.getByRole( 'group', { name: 'Change in progress' } )
		).toHaveCount( 0 );
		expect( ( await tabCopy( page ) ).current ).toBeTruthy();
		panel = await checkIncrease( page, '1', 'Apply to 1 product' );
		const sent = page.waitForRequest(
			( request ) =>
				request.method() === 'POST' &&
				/bulkcat(\/|%2F)v1(\/|%2F)batches(\?|&|$)/.test( request.url() )
		);
		await panel
			.getByRole( 'button', { name: 'Apply to 1 product' } )
			.click();
		expect( ( await sent ).postDataJSON().uuid ).toBe( body.uuid );
		await expect( panel ).toContainText( 'Applying the change' );
		const [ second ] = batchesByUuid( body.uuid );
		made.push( Number( second.batch_id ) );
		expect( drain( Number( second.batch_id ) ).status ).toBe( 'complete' );
		expectPlusOne( { [ unsent ]: before[ unsent ] } );
		expect(
			writes.filter(
				( each ) =>
					each.path === 'requests' &&
					each.body.operation === 'batch.apply'
			)
		).toHaveLength( 2 );
	} finally {
		for ( const id of made ) {
			await undoBatch( page, id );
		}
	}
	const back = prices( [ ...accepted, unsent ] );
	Object.keys( before ).forEach( ( id ) =>
		expect( back[ id ] ).toBeCloseTo( before[ id ], 2 )
	);
} );

test( 'a change that finished in full clears what it applied and keeps what was selected since; one that ended in part keeps the selection', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const [ nine, ten ] = idsByName( [ 9, 10 ].map( SEED.simple ) );
	const [ variable ] = idsByName( [ SEED.variable ] );
	const before = prices( [ nine, ten ] );
	const made = [];
	const temp = makeProducts( [ 'Lifecycle Partial' ], '5.00' );
	try {
		await openScreen( page );
		const { bar, box } = screenParts( page );
		await tickProbes( page, [ 9, 10 ] );
		let panel = await checkIncrease( page, '1', 'Apply to 2 products' );
		await panel
			.getByRole( 'button', { name: 'Apply to 2 products' } )
			.click();
		await expect( panel ).toContainText( 'Applying the change' );
		made.push( Number( ( await currentBatch( page ) ) || 0 ) );
		await panel.getByRole( 'button', { name: 'Back to products' } ).click();
		// Selected while the change waits in the queue.
		await searchFor( page, SEED.variable, SEED.variable );
		await box( SEED.variable ).check();
		await expect( bar ).toContainText( '3 products selected' );
		expect( drain( made[ 0 ] ).status ).toBe( 'complete' );
		await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
			'Anything you selected after applying it is still selected.',
			{ timeout: 30_000 }
		);
		await expect( bar ).toContainText( '1 product selected' );
		expect( await members( page ) ).toEqual( [ variable ] );
		expectPlusOne( before );

		// A change that ends in part keeps the selection.
		await bar.getByRole( 'button', { name: 'Clear selection' } ).click();
		await searchFor( page, 'Lifecycle Partial', 'Lifecycle Partial' );
		await box( 'Lifecycle Partial' ).check();
		await tickProbes( page, [ 9 ] );
		await expect( bar ).toContainText( '2 products selected' );
		php( `wp_trash_post( (int) $in ); echo 'true';`, temp[ 0 ] );
		panel = await checkIncrease( page, '1', 'Apply to 1 product' );
		await panel
			.getByRole( 'button', { name: 'Apply to 1 product' } )
			.click();
		await expect( panel ).toContainText( 'Applying the change' );
		made.push( Number( ( await currentBatch( page ) ) || 0 ) );
		expect( drain( made[ 1 ] ).status ).toBe( 'partial' );
		await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
			'The change did not finish for every product, so your selection is kept.',
			{ timeout: 30_000 }
		);
		await expect( bar ).toContainText( '2 products selected' );
	} finally {
		for ( const id of made.filter( Boolean ).reverse() ) {
			await undoBatch( page, id );
		}
		deleteProducts( temp );
	}
	const back = prices( [ nine, ten ] );
	expect( back[ nine ] ).toBeCloseTo( before[ nine ], 2 );
	expect( back[ ten ] ).toBeCloseTo( before[ ten ], 2 );
} );

/**
 * The batch the screen is following, from the tab's copy.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<number>} Batch id.
 */
async function currentBatch( page ) {
	await expect
		.poll( async () => ( await tabCopy( page ) )?.submitted?.batchId || 0 )
		.toBeGreaterThan( 0 );
	return ( await tabCopy( page ) ).submitted.batchId;
}
