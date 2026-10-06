/**
 * Contract version 2 add-on actions in the browser (1.4.x journeys, until now
 * checked by hand at each release gate), with the neutral test providers of
 * tests/fixtures/mu-plugins: "Sample picks" (a searchable lookup of 5,000
 * recipients, row entries) and "Sample shelves" (category actions only).
 *
 * - a row action on one product: the row's Actions menu, the add-on's group,
 *   Add recipients opened at its search picker, Apply, View in History, Undo;
 *   a retained selection of another product is left as it was;
 * - a bulk action on a retained selection of two products;
 * - a category action on two categories with Include subcategories: every
 *   category below them changed once, History, Undo.
 *
 * Every product and category is the spec's own (UUID names, made through
 * WooCommerce and WordPress, deleted in `finally`), so the seed's size and
 * what earlier specs left behind do not matter. Stored values are read back
 * through WP-CLI at each stage. The spec copies the two must-use plugins in
 * when the site lacks them and removes only what it copied; it needs the
 * plugin's tests/ directory on the site (wp-env, or the docker stack of
 * tests/probe/README.md) and the fixture opt-in (BULKCAT_E2E_FIXTURE=1,
 * WP_CMD, BULKCAT_E2E_BASE_URL).
 */
const { randomUUID } = require( 'crypto' );
const { test, expect } = require( '@playwright/test' );
const {
	SCREEN,
	runQueue,
	untilVisible,
	tabTo,
	expectAxeClean,
} = require( './helpers' );
const {
	fixtureCheck,
	php,
	installMuPlugins,
	removeMuPlugins,
} = require( './lifecycle/support' );

const PLUGINS = [
	'bulkcat-picks-provider.php',
	'bulkcat-shelves-provider.php',
];

test.describe.configure( { mode: 'serial' } );

let copied = [];

test.beforeAll( () => {
	if ( ! fixtureCheck() ) {
		copied = installMuPlugins( PLUGINS );
	}
} );

test.afterAll( () => {
	expect( removeMuPlugins( copied ) ).toEqual( [] );
} );

test.beforeEach( () => {
	test.skip(
		!! fixtureCheck(),
		`Needs the disposable fixture opt-in: ${ fixtureCheck() }`
	);
	const ready = php(
		`$fields = rest_do_request( ( function () { wp_set_current_user( 1 ); return new WP_REST_Request( 'GET', '/bulkcat/v1/fields' ); } )() )->get_data();
		$ids = array();
		foreach ( (array) ( $fields['actions'] ?? array() ) as $action ) { $ids[] = $action['id'] ?? ''; }
		echo wp_json_encode( array_values( array_intersect( array( 'x_picks_add', 'x_shelves_add' ), $ids ) ) );`
	);
	expect(
		ready,
		'both test providers registered (their must-use plugins need the plugin tests/ directory on the site)'
	).toEqual( [ 'x_picks_add', 'x_shelves_add' ] );
} );

/**
 * Two simple products of this run, through WooCommerce.
 *
 * @param {string} run Run id.
 * @return {Object} { name: id }.
 */
function makeOwnProducts( run ) {
	return php(
		`$out = array();
		foreach ( array( 'One', 'Two', 'Three' ) as $n ) {
			$p = new WC_Product_Simple();
			$p->set_name( 'Picks ' . $n . ' ' . $in );
			$p->set_status( 'publish' );
			$p->set_regular_price( '10.00' );
			$p->update_meta_data( '_bulkcat_e2e_run', $in );
			$out[ 'Picks ' . $n . ' ' . $in ] = $p->save();
		}
		echo wp_json_encode( $out );`,
		run
	);
}

/**
 * Delete this run's products, checking their marker first.
 *
 * @param {string} run Run id.
 */
function deleteOwnProducts( run ) {
	const left = php(
		`global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bulkcat_e2e_run' AND meta_value = %s", $in ) );
		foreach ( $ids as $id ) { $p = wc_get_product( (int) $id ); if ( $p ) { $p->delete( true ); } }
		echo wp_json_encode( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bulkcat_e2e_run' AND meta_value = %s", $in ) ) );`,
		run
	);
	expect( left ).toBe( 0 );
}

/**
 * The recipients a product holds, as the test provider stores them.
 *
 * @param {Array<number>} ids Product ids.
 * @return {Object} id => list of recipient ids.
 */
function picksOf( ids ) {
	return php(
		`$out = array();
		foreach ( $in as $id ) { clean_post_cache( $id ); $raw = get_post_meta( $id, '_sample_picks', true ); $out[ $id ] = '' === $raw ? array() : json_decode( $raw, true ); }
		echo wp_json_encode( $out );`,
		ids
	);
}

/**
 * Open the catalogue searched for this run, its rows only.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          run  Run id.
 * @param {number}                          rows Rows expected.
 */
async function openRun( page, run, rows ) {
	await page.goto( SCREEN );
	await expect( page.locator( '#bulkcat-app .bulkcat-app' ) ).toBeVisible();
	const search = page.getByRole( 'searchbox', {
		name: 'Search by name or SKU',
		exact: true,
	} );
	await search.focus();
	await page.keyboard.type( run );
	await expect(
		page.locator( '.dataviews-view-table tbody tr' )
	).toHaveCount( rows );
}

/**
 * Choose recipients in an open search picker by typing and arrows.
 *
 * @param {import('@playwright/test').Page}    page    Page.
 * @param {import('@playwright/test').Locator} scope   Dialog holding the picker.
 * @param {string}                             typed   What to type.
 * @param {Array<string>}                      choices Labels to choose, in order.
 */
async function choose( page, scope, typed, choices ) {
	const picker = scope.getByRole( 'combobox', { name: 'Recipients' } );
	await expect( picker ).toBeFocused();
	await page.keyboard.type( typed );
	for ( const label of choices ) {
		await expect(
			scope.getByRole( 'option', { name: new RegExp( label ) } )
		).toBeVisible();
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'Enter' );
		await expect(
			scope.getByRole( 'button', { name: `Remove ${ label }` } )
		).toBeVisible();
	}
	// Escape closes the list only, never the panel.
	await page.keyboard.press( 'Escape' );
	await expect( scope ).toBeVisible();
}

/**
 * Undo the History entry named by `heading` and wait for it to finish.
 *
 * @param {import('@playwright/test').Page} page    Page on History.
 * @param {RegExp}                          heading The entry's accessible name.
 */
async function undoFromHistory( page, heading ) {
	const entry = page.getByRole( 'article', { name: heading } ).first();
	await expect( entry ).toBeVisible();
	const undo = entry.getByRole( 'button', { name: 'Undo', exact: true } );
	await undo.focus();
	await page.keyboard.press( 'Enter' );
	const ask = page.getByRole( 'dialog', { name: 'Undo this change?' } );
	await expect( ask ).toBeVisible();
	await expectAxeClean( page, 'the undo confirmation' );
	await tabTo(
		page,
		ask.getByRole( 'button', { name: 'Undo', exact: true } )
	);
	await page.keyboard.press( 'Enter' );
	await expect( ask ).toBeHidden();
	await untilVisible(
		page
			.getByRole( 'article', { name: heading } )
			.first()
			.getByText( /^Undone/ )
			.first(),
		20
	).catch( async () => {
		// The card reads its new state on the next History read.
		runQueue();
		await page.reload();
	} );
}

test( 'a row action on one product leaves a retained selection alone, then History and Undo', async ( {
	page,
} ) => {
	const run = randomUUID();
	const ids = makeOwnProducts( run );
	const [ one, two ] = Object.keys( ids );
	try {
		await openRun( page, run, 3 );
		const kept = page.getByRole( 'checkbox', { name: two, exact: true } );
		await kept.focus();
		await page.keyboard.press( 'Space' );
		const selection = page.getByRole( 'region', { name: 'Selection' } );
		await expect( selection ).toContainText( '1 product selected' );

		const row = page
			.getByRole( 'row' )
			.filter( { has: page.getByText( one, { exact: true } ) } );
		const actions = row.getByRole( 'button', {
			name: 'Actions',
			exact: true,
		} );
		await actions.focus();
		await page.keyboard.press( 'Enter' );
		const group = page.getByRole( 'menuitem', { name: 'Sample picks…' } );
		await expect( group ).toBeVisible();
		for (
			let i = 0;
			i < 20 &&
			! ( await group.evaluate(
				( n ) => n === n.ownerDocument.activeElement
			) );
			i++
		) {
			await page.keyboard.press( 'ArrowDown' );
		}
		await page.keyboard.press( 'Enter' );
		const menu = page.getByRole( 'menu', { name: 'Sample picks' } );
		const add = menu.getByRole( 'menuitem', { name: /^Add recipients/ } );
		await expect( add ).toBeFocused();
		await page.keyboard.press( 'Enter' );

		const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
		await expect( panel ).toBeVisible();
		// The row entry opens at the action's arguments, on this product alone.
		await choose( page, panel, 'Recipient 012', [
			'Recipient 0120',
			'Recipient 0121',
		] );
		await expectAxeClean( page, 'a row entry with recipients chosen' );
		await tabTo(
			page,
			panel.getByRole( 'button', { name: 'Check the change' } )
		);
		await page.keyboard.press( 'Enter' );
		const apply = panel.getByRole( 'button', {
			name: 'Apply to 1 product',
		} );
		await expect( apply ).toBeVisible();
		await expect( panel.getByRole( 'table' ) ).toContainText( one );
		await expect( panel.getByRole( 'table' ) ).not.toContainText( two );
		expect( picksOf( [ ids[ one ], ids[ two ] ] ) ).toEqual( {
			[ ids[ one ] ]: [],
			[ ids[ two ] ]: [],
		} );
		await tabTo( page, apply );
		await page.keyboard.press( 'Enter' );
		const history = panel.getByRole( 'button', {
			name: 'View in History',
		} );
		await untilVisible( history, 20 );
		const stored = picksOf( [ ids[ one ], ids[ two ] ] );
		expect( stored[ ids[ one ] ] ).toHaveLength( 2 );
		expect( stored[ ids[ two ] ] ).toEqual( [] );
		// The retained selection is still the other product.
		await expect( selection ).toContainText( '1 product selected' );

		await tabTo( page, history );
		await page.keyboard.press( 'Enter' );
		const heading = /^Add recipients: Recipient 0120, Recipient 0121/;
		await expect(
			page.getByRole( 'article', { name: heading } ).first()
		).toBeVisible();
		await expectAxeClean( page, 'History after a row action' );
		await undoFromHistory( page, heading );
		await expect
			.poll( () => {
				runQueue();
				return picksOf( [ ids[ one ] ] )[ ids[ one ] ];
			} )
			.toEqual( [] );
	} finally {
		deleteOwnProducts( run );
	}
} );

test( 'a bulk action on a retained selection of two products, then History and Undo', async ( {
	page,
} ) => {
	const run = randomUUID();
	const ids = makeOwnProducts( run );
	const [ one, two, three ] = Object.keys( ids );
	try {
		await openRun( page, run, 3 );
		for ( const name of [ one, three ] ) {
			const box = page.getByRole( 'checkbox', { name, exact: true } );
			await box.focus();
			await page.keyboard.press( 'Space' );
		}
		const selection = page.getByRole( 'region', { name: 'Selection' } );
		await expect( selection ).toContainText( '2 products selected' );
		// A new search keeps the selection (it is retained on the server).
		const search = page.getByRole( 'searchbox', {
			name: 'Search by name or SKU',
			exact: true,
		} );
		await search.focus();
		await page.keyboard.press( 'ControlOrMeta+A' );
		await page.keyboard.type( `Picks Two ${ run }` );
		await expect(
			page.locator( '.dataviews-view-table tbody tr' )
		).toHaveCount( 1 );
		await expect( selection ).toContainText( '2 products selected' );

		const make = page.getByRole( 'button', {
			name: 'Make a change to these',
		} );
		await make.focus();
		await page.keyboard.press( 'Enter' );
		const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
		await expect( panel ).toBeVisible();
		const radio = panel.getByRole( 'radio', { name: /^Add recipients/ } );
		await radio.focus();
		await page.keyboard.press( 'Space' );
		await expect( radio ).toBeChecked();
		await tabTo(
			page,
			panel.getByRole( 'combobox', { name: 'Recipients' } )
		);
		await choose( page, panel, 'Recipient 004', [
			'Recipient 0040',
			'Recipient 0041',
		] );
		await tabTo(
			page,
			panel.getByRole( 'button', { name: 'Check the change' } )
		);
		await page.keyboard.press( 'Enter' );
		const apply = panel.getByRole( 'button', {
			name: 'Apply to 2 products',
		} );
		await expect( apply ).toBeVisible();
		await expectAxeClean( page, 'a provider preview of two products' );
		await tabTo( page, apply );
		await page.keyboard.press( 'Enter' );
		const history = panel.getByRole( 'button', {
			name: 'View in History',
		} );
		await untilVisible( history, 20 );
		const stored = picksOf( [ ids[ one ], ids[ two ], ids[ three ] ] );
		expect( stored[ ids[ one ] ] ).toHaveLength( 2 );
		expect( stored[ ids[ three ] ] ).toEqual( stored[ ids[ one ] ] );
		expect( stored[ ids[ two ] ] ).toEqual( [] );

		await tabTo( page, history );
		await page.keyboard.press( 'Enter' );
		const heading = /^Add recipients: Recipient 0040, Recipient 0041/;
		await undoFromHistory( page, heading );
		await expect
			.poll( () => {
				runQueue();
				return picksOf( [ ids[ one ], ids[ three ] ] );
			} )
			.toEqual( { [ ids[ one ] ]: [], [ ids[ three ] ]: [] } );
	} finally {
		deleteOwnProducts( run );
	}
} );

test( 'a category action on two categories with Include subcategories, then History and Undo', async ( {
	page,
} ) => {
	const run = randomUUID().slice( 0, 8 );
	// Two branches of this run: A (A1, A1a) and B (B1); C beside them is never chosen.
	const terms = php(
		`$make = function ( $name, $parent ) { $t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) ); if ( is_wp_error( $t ) ) { throw new RuntimeException( $t->get_error_message() ); } update_term_meta( $t['term_id'], '_bulkcat_e2e_run', $GLOBALS['bulkcat_run'] ); return (int) $t['term_id']; };
		$GLOBALS['bulkcat_run'] = $in;
		$out = array();
		$out['A'] = $make( "Shelf A $in", 0 );
		$out['A1'] = $make( "Shelf A1 $in", $out['A'] );
		$out['A1a'] = $make( "Shelf A1a $in", $out['A1'] );
		$out['B'] = $make( "Shelf B $in", 0 );
		$out['B1'] = $make( "Shelf B1 $in", $out['B'] );
		$out['C'] = $make( "Shelf C $in", 0 );
		echo wp_json_encode( $out );`,
		run
	);
	const shelves = () =>
		php(
			`$out = array(); foreach ( $in as $key => $id ) { $raw = get_term_meta( $id, '_sample_shelves', true ); $out[ $key ] = '' === $raw ? array() : json_decode( $raw, true ); } echo wp_json_encode( $out );`,
			terms
		);
	try {
		await page.goto( `${ SCREEN }&panel=categories` );
		const tree = page.getByRole( 'tree' );
		await expect( tree ).toBeVisible();
		const search = page.getByRole( 'searchbox', {
			name: 'Search categories',
		} );
		await search.focus();
		await page.keyboard.type( run );
		const a = page.getByRole( 'treeitem', {
			name: new RegExp( `^Shelf A ${ run },` ),
		} );
		const b = page.getByRole( 'treeitem', {
			name: new RegExp( `^Shelf B ${ run },` ),
		} );
		await expect( a ).toBeVisible();
		await expect( b ).toBeVisible();
		// Keyboard: into the tree, Enter chooses A, Space adds B.
		await tabTo( page, tree.locator( '[role="treeitem"][tabindex="0"]' ) );
		for (
			let i = 0;
			i < 20 &&
			! ( await a.evaluate(
				( n ) => n === n.ownerDocument.activeElement
			) );
			i++
		) {
			await page.keyboard.press( 'ArrowDown' );
		}
		await page.keyboard.press( 'Enter' );
		for (
			let i = 0;
			i < 20 &&
			! ( await b.evaluate(
				( n ) => n === n.ownerDocument.activeElement
			) );
			i++
		) {
			await page.keyboard.press( 'ArrowDown' );
		}
		await page.keyboard.press( 'Space' );
		const pane = page.getByRole( 'region', {
			name: '2 categories selected',
		} );
		await expect( pane ).toBeVisible();

		const group = pane.getByRole( 'button', { name: 'Sample shelves…' } );
		await tabTo( page, group );
		await page.keyboard.press( 'Enter' );
		const menu = page.getByRole( 'menu', { name: 'Sample shelves…' } );
		const put = menu.getByRole( 'menuitem', { name: 'Put on shelves' } );
		await expect( put ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		const dialog = page.getByRole( 'dialog', { name: 'Put on shelves' } );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText( 'For 2 chosen categories:' );
		for ( const wall of [ 'North wall', 'East wall' ] ) {
			const box = dialog.getByRole( 'checkbox', { name: wall } );
			await tabTo( page, box );
			await page.keyboard.press( 'Space' );
			await expect( box ).toBeChecked();
		}
		const sub = dialog.getByRole( 'checkbox', {
			name: 'Include subcategories',
		} );
		await tabTo( page, sub );
		await page.keyboard.press( 'Space' );
		await expect( sub ).toBeChecked();
		await tabTo( page, dialog.getByRole( 'button', { name: 'Preview' } ) );
		await page.keyboard.press( 'Enter' );
		const records = dialog.getByRole( 'list', {
			name: 'Categories in this change',
		} );
		await expect( records.getByRole( 'listitem' ) ).toHaveCount( 5 );
		await expect( records ).not.toContainText( `Shelf C ${ run }` );
		await expectAxeClean( page, 'a category action preview' );
		expect( shelves() ).toEqual( {
			A: [],
			A1: [],
			A1a: [],
			B: [],
			B1: [],
			C: [],
		} );
		const apply = dialog.getByRole( 'button', {
			name: 'Apply to 5 categories',
		} );
		await tabTo( page, apply );
		await page.keyboard.press( 'Enter' );
		const confirm = dialog.getByRole( 'button', {
			name: 'Yes, change 5 categories',
		} );
		if ( await confirm.isVisible().catch( () => false ) ) {
			await tabTo( page, confirm );
			await page.keyboard.press( 'Enter' );
		}
		await untilVisible(
			dialog.locator( '.bulkcat-cat-provider__progress [role="status"]' ),
			20
		);
		const both = [ 'east', 'north' ];
		expect( shelves() ).toEqual( {
			A: both,
			A1: both,
			A1a: both,
			B: both,
			B1: both,
			C: [],
		} );
		await tabTo(
			page,
			dialog
				.locator( '.bulkcat-cat-confirm__buttons' )
				.getByRole( 'button', { name: 'Close', exact: true } )
		);
		await page.keyboard.press( 'Enter' );
		await expect( dialog ).toBeHidden();

		await page.goto( `${ SCREEN }&panel=history` );
		// The newest entry is this change, named by the add-on's action (a
		// list of choices has no chosen-item labels to add, unlike a lookup).
		const heading = /^Put on shelves$/;
		await expect(
			page.getByRole( 'article' ).first()
		).toHaveAccessibleName( heading );
		await expectAxeClean( page, 'History after a category action' );
		await undoFromHistory( page, heading );
		await expect
			.poll( () => {
				runQueue();
				return shelves();
			} )
			.toEqual( { A: [], A1: [], A1a: [], B: [], B1: [], C: [] } );
	} finally {
		const left = php(
			`foreach ( array_reverse( $in ) as $id ) { wp_delete_term( $id, 'product_cat' ); }
			echo wp_json_encode( count( array_filter( $in, fn( $id ) => (bool) get_term( $id, 'product_cat' ) ) ) );`,
			Object.values( terms )
		);
		expect( left ).toBe( 0 );
	}
} );
