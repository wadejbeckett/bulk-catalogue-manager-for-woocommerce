/**
 * T85, EXACT-SELECTION lifecycle group 4 and the undo of a completed change
 * through the screen: Undo from History after a product was edited since the
 * change reports that product as changed since and leaves it alone, undoing
 * the rest; when history cleanup has removed a finished change's items and
 * saved values, its request still resolves to the retained header (never a
 * new batch) and History says plainly that it cannot be undone. The
 * gone-header tombstone, its pruning and the reinstall epoch are proved
 * natively (tests/probe/exact-batch-faults.php).
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, SCREEN, openScreen, rest } = require( '../helpers' );
const {
	php,
	rows,
	write,
	prices,
	setPrices,
	idsByName,
	batchesByUuid,
	drain,
	recordWrites,
	screenParts,
	searchFor,
	checkIncrease,
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
 * Tick probe products and apply +1 through the screen; returns the batch
 * and the Apply's durable body.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {Array<number>}                   ns     Probe numbers.
 * @param {Array<Object>}                   writes recordWrites().
 * @return {Promise<Object>} `{ batchId, body, panel }`.
 */
async function applyPlusOne( page, ns, writes ) {
	const { bar, box } = screenParts( page );
	await searchFor( page, 'Probe Simple', SEED.simple( ns[ 0 ] ) );
	for ( const n of ns ) {
		await box( SEED.simple( n ) ).check();
	}
	await expect( bar ).toContainText( `${ ns.length } product` );
	const name = `Apply to ${ ns.length } product${ ns.length > 1 ? 's' : '' }`;
	const panel = await checkIncrease( page, '1', name );
	await panel.getByRole( 'button', { name } ).click();
	await expect( panel ).toContainText( 'Applying the change' );
	await expect
		.poll( () => writes.find( ( each ) => each.path === 'batches' ) )
		.toBeTruthy();
	const body = writes.find( ( each ) => each.path === 'batches' ).body;
	const [ header ] = batchesByUuid( body.uuid );
	const batchId = Number( header.batch_id );
	expect( drain( batchId ).status ).toBe( 'complete' );
	return { batchId, body, panel };
}

test( 'Undo from History reports a product changed since the change and leaves it alone, undoing the rest', async ( {
	page,
} ) => {
	test.setTimeout( 240_000 );
	const writes = recordWrites( page );
	const ids = idsByName( [ 1, 2, 3 ].map( SEED.simple ) );
	const before = prices( ids );
	try {
		await openScreen( page );
		const { batchId, panel } = await applyPlusOne(
			page,
			[ 1, 2, 3 ],
			writes
		);
		await expect( panel ).toContainText( '3 products updated.', {
			timeout: 30_000,
		} );

		// Someone edits one of them afterwards, in WooCommerce.
		setPrices( { [ ids[ 1 ] ]: 50 } );

		await panel.getByRole( 'button', { name: 'Undo this change' } ).click();
		const conflict = page.getByRole( 'region', {
			name: 'Products changed since',
		} );
		await expect( conflict ).toContainText( '1 product has changed since', {
			timeout: 30_000,
		} );
		await conflict
			.getByRole( 'button', { name: 'See the 1 that changed' } )
			.click();
		await expect( page.locator( '.bulkcat-conflict-table' ) ).toContainText(
			SEED.simple( 2 )
		);
		await conflict
			.getByRole( 'button', { name: 'Undo the other 2' } )
			.click();
		const confirm = page.getByRole( 'dialog', {
			name: 'Undo this change?',
		} );
		await confirm
			.getByRole( 'button', { name: 'Undo', exact: true } )
			.click();
		await expect( confirm ).toBeHidden();
		const undo = rows(
			'SELECT batch_id FROM {p}bulkcat_batches WHERE undo_of = %d ORDER BY batch_id DESC LIMIT 1',
			[ batchId ]
		)[ 0 ];
		expect( undo ).toBeTruthy();
		drain( Number( undo.batch_id ) );
		const after = prices( ids );
		expect( after[ ids[ 0 ] ] ).toBeCloseTo( before[ ids[ 0 ] ], 2 );
		expect( after[ ids[ 2 ] ] ).toBeCloseTo( before[ ids[ 2 ] ], 2 );
		// The later edit is kept, not overwritten.
		expect( after[ ids[ 1 ] ] ).toBeCloseTo( 50, 2 );
		await page.screenshot( {
			path: shotPath( 'history-undo-conflict-done.png' ),
		} );
	} finally {
		setPrices( { [ ids[ 1 ] ]: before[ ids[ 1 ] ] } );
	}
} );

test( 'after history cleanup removed a change’s saved values, its request still resolves to that batch and History says it cannot be undone', async ( {
	page,
} ) => {
	test.setTimeout( 240_000 );
	const writes = recordWrites( page );
	const [ four ] = idsByName( [ SEED.simple( 4 ) ] );
	const before = prices( [ four ] );
	try {
		await openScreen( page );
		const { batchId, body } = await applyPlusOne( page, [ 4 ], writes );

		// Finished longer ago than the history keeps (the cleanup's own
		// cutoff; its start stays recent, inside History's default 30-day
		// view): the daily cleanup removes its items and journal and keeps
		// the header.
		const days =
			Number(
				php(
					`echo wp_json_encode( (int) ( get_option( 'bulkcat_settings' )['history_days'] ?? 90 ) );`
				)
			) + 5;
		write(
			'UPDATE {p}bulkcat_batches SET finished_gmt = finished_gmt - INTERVAL %d DAY WHERE batch_id = %d',
			[ days, batchId ]
		);
		const runs = php(
			`global $wpdb;
			for ( $runs = 1; $runs <= 40; $runs++ ) {
				do_action( 'bulkcat_prune_history' );
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT pruned_gmt FROM {$wpdb->prefix}bulkcat_batches WHERE batch_id = %d", $in ) ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bulkcat_changes WHERE batch_id = %d", $in ) ) ) {
					break;
				}
			}
			echo wp_json_encode( $runs );`,
			batchId
		);
		expect( runs ).toBeLessThan( 40 );
		expect(
			rows(
				'SELECT (SELECT COUNT(*) FROM {p}bulkcat_batch_items WHERE batch_id = %d) AS items, (SELECT COUNT(*) FROM {p}bulkcat_changes WHERE batch_id = %d) AS changes',
				[ batchId, batchId ]
			)[ 0 ]
		).toEqual( { items: '0', changes: '0' } );

		// The same request again: the retained header, never a new batch.
		const replay = await rest( page, 'batches', {
			method: 'POST',
			data: body,
		} );
		expect( replay.status ).toBe( 200 );
		expect( replay.json ).toMatchObject( {
			batch_id: batchId,
			replayed: true,
		} );
		expect( batchesByUuid( body.uuid ) ).toHaveLength( 1 );

		// History says why it cannot be undone.
		await page.goto( `${ SCREEN }&panel=history&batch=${ batchId }` );
		await expect(
			page
				.getByText(
					'The saved values for this change were removed when old history was cleared, so it cannot be undone.'
				)
				.first()
		).toBeVisible( { timeout: 30_000 } );
		await page.screenshot( {
			path: shotPath( 'history-pruned-cannot-undo.png' ),
		} );
	} finally {
		// It cannot be undone: put the price back in WooCommerce directly.
		setPrices( before );
	}
} );

/**
 * Where a screenshot goes: BULKCAT_E2E_SHOTS, else the results folder.
 *
 * @param {string} name File name.
 * @return {string} Path.
 */
function shotPath( name ) {
	const path = require( 'path' );
	return path.join(
		process.env.BULKCAT_E2E_SHOTS ||
			path.join( __dirname, '..', '.results' ),
		name
	);
}
