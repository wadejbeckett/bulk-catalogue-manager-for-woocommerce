/**
 * E14 (T109, docs/EXTENSIONS.md §15.2): an add-on's action on a real site,
 * by keyboard alone. Two SKU searches build one selection, the "Sample
 * labels" test provider's Add labels is chosen and checked, the preview shows
 * the add-on's own text, Apply runs through the plugin's queue, completion
 * clears the selection, History lists the change with its values, and Undo
 * puts the labels back. The labels are read back from the site through WP-CLI
 * at every stage, and axe-core runs on each state.
 *
 * Needs the test provider's must-use plugin on the fixture site
 * (tests/probe/README.md, "External actions"), and WP_CMD to run the queue
 * and read the values; skipped otherwise.
 */
const { execSync } = require( 'child_process' );
const { test, expect } = require( '@playwright/test' );
const {
	SEED,
	openScreen,
	runQueue,
	untilVisible,
	tabTo,
	tabFromContent,
	rest,
	expectAxeClean,
} = require( './helpers' );

/**
 * A seeded simple product's SKU (tests/probe/seed.sh).
 *
 * @param {number} n Its number.
 * @return {string} SKU.
 */
const sku = ( n ) => `bulkcat-probe-simple-${ String( n ).padStart( 2, '0' ) }`;

/**
 * Run PHP on the fixture site through WP-CLI.
 *
 * @param {string} code PHP, with double quotes only.
 * @return {string} What it printed.
 */
function wp( code ) {
	return execSync( `${ process.env.WP_CMD } eval '${ code }'`, {
		encoding: 'utf8',
		timeout: 60_000,
	} ).trim();
}

/**
 * The labels a seeded product carries, as the test provider stores them.
 *
 * @param {number} n Product number.
 * @return {string} The stored JSON list, or '' for none.
 */
const labelsOf = ( n ) =>
	wp(
		`echo (string) get_post_meta( wc_get_product_id_by_sku( "${ sku(
			n
		) }" ), "_sample_labels", true );`
	);

test( 'an add-on action by keyboard: two SKU searches, Add labels, preview, Apply, History and Undo', async ( {
	page,
} ) => {
	test.skip( ! process.env.WP_CMD, 'Needs WP_CMD to run the queue.' );
	test.setTimeout( 240_000 );
	runQueue();
	const failures = [];
	page.on( 'pageerror', ( error ) => failures.push( error.message ) );
	await openScreen( page );
	const { json: registry } = await rest( page, 'fields' );
	test.skip(
		! ( registry.providers || [] ).some(
			( provider ) => provider.id === 'sample'
		),
		"Needs the test provider's must-use plugin."
	);

	// Known values: 01 carries Blue, 02 carries nothing.
	wp(
		`update_post_meta( wc_get_product_id_by_sku( "${ sku(
			1
		) }" ), "_sample_labels", wp_json_encode( array( "blue" ) ) );`
	);
	wp(
		`delete_post_meta( wc_get_product_id_by_sku( "${ sku(
			2
		) }" ), "_sample_labels" );`
	);
	expect( labelsOf( 1 ) ).toBe( '["blue"]' );
	expect( labelsOf( 2 ) ).toBe( '' );

	const keys = page.keyboard;
	const search = page.getByRole( 'searchbox', {
		name: 'Search by name or SKU',
	} );
	const bar = page.getByRole( 'region', { name: 'Selection' } );
	const box = ( n ) =>
		page.getByRole( 'checkbox', { name: SEED.simple( n ), exact: true } );

	// Two SKU searches build one selection.
	await tabFromContent( page, search );
	await keys.type( sku( 1 ) );
	await expect( box( 2 ) ).toHaveCount( 0 );
	await tabTo( page, box( 1 ) );
	await keys.press( 'Space' );
	await expect( bar ).toContainText( '1 product selected' );
	await tabTo( page, search, { back: true } );
	await keys.press( 'ControlOrMeta+a' );
	await keys.type( sku( 2 ) );
	await expect( box( 1 ) ).toHaveCount( 0 );
	await tabTo( page, box( 2 ) );
	await keys.press( 'Space' );
	await expect( bar ).toContainText( '2 products selected' );

	// Make a change: Sample labels › Add labels, chosen with the arrows.
	const makeChange = bar.getByRole( 'button', {
		name: 'Make a change to these',
	} );
	await tabTo( page, makeChange, { back: true } );
	await keys.press( 'Enter' );
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await expect( panel ).toContainText( '2 selected products' );
	const group = panel.locator( '.bulkcat-bulk__group', {
		has: page.getByRole( 'heading', { name: 'Sample labels' } ),
	} );
	const add = group.getByRole( 'radio', { name: /^Add labels/ } );
	await tabTo( page, panel.getByRole( 'radio' ).first() );
	for ( let i = 0; i < 120 && ! ( await add.isChecked() ); i++ ) {
		await keys.press( 'ArrowDown' );
	}
	await expect( add ).toBeChecked();
	const labels = panel.getByRole( 'group', { name: 'Labels' } );
	const green = labels.getByRole( 'checkbox', { name: 'Green' } );
	await tabTo( page, green );
	await keys.press( 'Space' );
	await expect( green ).toBeChecked();
	await expectAxeClean( page, 'the bulk panel with an add-on action' );

	// Check the change: the preview shows the add-on's own text.
	await tabTo(
		page,
		panel.getByRole( 'button', { name: 'Check the change' } )
	);
	await keys.press( 'Enter' );
	const apply = panel.getByRole( 'button', { name: 'Apply to 2 products' } );
	await expect( apply ).toBeEnabled( { timeout: 60_000 } );
	await expect( panel ).toContainText(
		'Sample labels will change on 2 products.'
	);
	const table = panel.getByRole( 'table' );
	await expect( table ).toContainText( 'Blue, Green' );
	await expect( table ).toContainText( 'Keeps 1 existing label.' );
	await expectAxeClean( page, 'the preview of an add-on action' );

	// Apply, through the plugin's queue.
	const queued = page.waitForResponse(
		( response ) =>
			response.request().method() === 'POST' &&
			/bulkcat(\/|%2F)v1(\/|%2F)batches(\?|$)/.test( response.url() )
	);
	await tabTo( page, apply );
	await keys.press( 'Enter' );
	const batchId = ( await ( await queued ).json() ).batch_id;
	expect( batchId ).toBeGreaterThan( 0 );
	const undoHere = panel.getByRole( 'button', { name: 'Undo this change' } );
	await untilVisible( undoHere );
	await expect( panel ).toContainText( '2 products updated.' );
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'The change finished, so your selection was cleared.'
	);
	expect( labelsOf( 1 ) ).toBe( '["blue","green"]' );
	expect( labelsOf( 2 ) ).toBe( '["green"]' );
	await expectAxeClean( page, 'the finished add-on change' );

	// History lists it, with the add-on's values as text.
	await openScreen( page, 'history' );
	const card = page.locator( '.bulkcat-batch', {
		has: page.locator( `#bulkcat-batch-${ batchId }` ),
	} );
	await expect( card ).toBeVisible();
	await expect(
		card.getByRole( 'heading', { name: /^Add labels/ } )
	).toBeVisible();
	await tabFromContent(
		page,
		card.getByRole( 'button', { name: 'See every change' } )
	);
	await keys.press( 'Enter' );
	const changes = card.locator( '.bulkcat-changes' );
	await expect( changes ).toContainText( SEED.simple( 1 ) );
	await expect( changes ).toContainText( 'Blue, Green' );
	await expect( changes ).toContainText( SEED.simple( 2 ) );
	await expectAxeClean( page, 'History of an add-on change' );

	// Undo, and every label is back.
	await tabTo(
		page,
		card.getByRole( 'button', { name: 'Undo', exact: true } )
	);
	await keys.press( 'Enter' );
	const confirm = page.getByRole( 'dialog', { name: 'Undo this change?' } );
	await expect( confirm ).toBeVisible( { timeout: 30_000 } );
	await expectAxeClean( page, 'the undo of an add-on change' );
	await tabTo(
		page,
		confirm.getByRole( 'button', { name: 'Undo', exact: true } )
	);
	await keys.press( 'Enter' );
	await expect( confirm ).toBeHidden();
	await expect
		.poll(
			() => {
				runQueue();
				return labelsOf( 1 );
			},
			{ timeout: 90_000 }
		)
		.toBe( '["blue"]' );
	expect( labelsOf( 2 ) ).toBe( '' );

	// Leave the fixture as the seed made it.
	wp(
		`delete_post_meta( wc_get_product_id_by_sku( "${ sku(
			1
		) }" ), "_sample_labels" );`
	);
	expect( failures ).toEqual( [] );
} );
