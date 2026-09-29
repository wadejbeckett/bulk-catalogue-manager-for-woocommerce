/**
 * T85, EXACT-SELECTION lifecycle groups 3 and 5 through the screen: the same
 * Apply sent three times at once (the screen's own request and two copies
 * with the same grant) makes one batch header, one set of items and one
 * queued chunk; the same request id with changed operations or targets is
 * refused and makes nothing; a request shaped by a 1.0 screen — a bare
 * request id, new or belonging to an old batch — cannot create work. The
 * rollback, lost-COMMIT and commit-to-scheduling faults of group 3 and the
 * upgrade with old jobs and duplicate old ids of group 5 are injected
 * natively (tests/probe/exact-batch-faults.php, exact-batch-race.php).
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen, rest } = require( '../helpers' );
const {
	rows,
	prices,
	idsByName,
	batchesByUuid,
	pendingChunks,
	drain,
	screenParts,
	searchFor,
	checkIncrease,
	undoBatch,
	routeOf,
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

test( 'the same Apply sent three times at once makes one batch, one set of items and one queued chunk; changed under its id it is refused', async ( {
	page,
} ) => {
	test.setTimeout( 240_000 );
	const ids = idsByName( [ 1, 2 ].map( SEED.simple ) );
	const before = prices( ids );
	let batchId = 0;
	try {
		await openScreen( page );
		const { bar, box } = screenParts( page );
		await searchFor( page, 'Probe Simple', SEED.simple( 1 ) );
		await box( SEED.simple( 1 ) ).check();
		await box( SEED.simple( 2 ) ).check();
		await expect( bar ).toContainText( '2 products selected' );
		const panel = await checkIncrease( page, '1', 'Apply to 2 products' );

		// The screen's POST /batches goes on, with two copies beside it.
		const matcher = routeOf( 'batches' );
		let used = false;
		const copies = new Promise( ( resolve ) => {
			const handler = async ( route ) => {
				const request = route.request();
				if ( request.method() !== 'POST' ) {
					return route.fallback();
				}
				if ( used ) {
					return route.fallback();
				}
				used = true;
				const headers = {
					'content-type': 'application/json',
					'x-wp-nonce': request.headers()[ 'x-wp-nonce' ],
				};
				const extra = [ 0, 1 ].map( () =>
					page.request.post( request.url(), {
						headers,
						data: request.postData(),
					} )
				);
				await route.continue();
				await page.unroute( matcher, handler );
				const answers = await Promise.all( extra );
				resolve( {
					body: request.postDataJSON(),
					answers: await Promise.all(
						answers.map( async ( answer ) => ( {
							status: answer.status(),
							json: await answer.json(),
						} ) )
					),
				} );
			};
			page.route( matcher, handler );
		} );
		await panel
			.getByRole( 'button', { name: 'Apply to 2 products' } )
			.click();
		const { body, answers } = await copies;
		await expect( panel ).toContainText( 'Applying the change' );

		const headers = batchesByUuid( body.uuid );
		expect( headers ).toHaveLength( 1 );
		batchId = Number( headers[ 0 ].batch_id );
		answers.forEach( ( answer ) => {
			expect( answer.status ).toBeLessThan( 300 );
			expect( answer.json.batch_id ).toBe( batchId );
		} );
		expect(
			answers.filter( ( answer ) => answer.json.replayed ).length
		).toBeGreaterThanOrEqual( 1 );
		expect(
			Number(
				rows(
					'SELECT COUNT(*) AS n FROM {p}bulkcat_batch_items WHERE batch_id = %d',
					[ batchId ]
				)[ 0 ].n
			)
		).toBe( 2 );
		expect( pendingChunks( batchId ) ).toBe( 1 );

		// The same request id with a changed change or a changed scope.
		const amount = JSON.parse( JSON.stringify( body ) );
		amount.intent.operations[ 0 ].args.amount = '5';
		const scope = JSON.parse( JSON.stringify( body ) );
		scope.intent.scope_hash = 'f'.repeat( scope.intent.scope_hash.length );
		for ( const changed of [ amount, scope ] ) {
			const refused = await rest( page, 'batches', {
				method: 'POST',
				data: changed,
			} );
			expect( refused.status ).toBe( 409 );
			expect( refused.json.code ).toBe( 'bulkcat_request_conflict' );
		}
		expect( batchesByUuid( body.uuid ) ).toHaveLength( 1 );

		expect( drain( batchId ).status ).toBe( 'complete' );
		const after = prices( ids );
		ids.forEach( ( id ) =>
			expect( after[ id ] ).toBeCloseTo( before[ id ] + 1, 2 )
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

test( 'a request shaped by a 1.0 screen cannot create work', async ( {
	page,
} ) => {
	await openScreen( page );
	const [ one ] = idsByName( [ SEED.simple( 1 ) ] );
	const last = Number(
		rows( 'SELECT MAX(batch_id) AS id FROM {p}bulkcat_batches' )[ 0 ].id
	);
	const legacy = {
		uuid: '11111111-2222-4333-8444-555555555555',
		operations: [
			{
				action: 'set_value',
				args: { field: 'regular_price', value: '55' },
			},
		],
		ids: [ one ],
		preview_hash: 'a'.repeat( 64 ),
	};
	const fresh = await rest( page, 'batches', {
		method: 'POST',
		data: legacy,
	} );
	expect( fresh.status, JSON.stringify( fresh.json ) ).toBe( 409 );
	expect( fresh.json.code ).toBe( 'bulkcat_legacy_request' );
	expect( fresh.json.data.batch_ids ).toEqual( [] );

	// An old batch's request id with a body that is not that batch's.
	const old = rows(
		"SELECT b.batch_id, b.uuid FROM {p}bulkcat_batches b LEFT JOIN {p}bulkcat_requests r ON r.result_kind = 'batch' AND r.result_id = b.batch_id WHERE r.uuid IS NULL AND b.source = 'bulk' AND b.uuid <> '' ORDER BY b.batch_id DESC LIMIT 1"
	)[ 0 ];
	test.skip( ! old, 'No 1.0 batch on this site.' );
	const known = await rest( page, 'batches', {
		method: 'POST',
		data: { ...legacy, uuid: old.uuid },
	} );
	expect( known.status, JSON.stringify( known.json ) ).toBe( 409 );
	expect( known.json.code ).toBe( 'bulkcat_legacy_request' );
	expect( known.json.data.batch_ids ).toContain( Number( old.batch_id ) );
	const undo = await rest( page, `batches/${ old.batch_id }/undo`, {
		method: 'POST',
		data: {
			uuid: '11111111-2222-4333-8444-666666666666',
			plan_hash: 'a'.repeat( 64 ),
		},
	} );
	expect( undo.status, JSON.stringify( undo.json ) ).toBe( 409 );
	expect( undo.json.code ).toBe( 'bulkcat_legacy_request' );
	// Nothing was made.
	expect(
		Number(
			rows( 'SELECT MAX(batch_id) AS id FROM {p}bulkcat_batches' )[ 0 ].id
		)
	).toBe( last );
} );
