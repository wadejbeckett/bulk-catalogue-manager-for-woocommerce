/** Real native admin navigation on an explicitly owned local fixture. */
const fs = require( 'fs' );
const { randomUUID } = require( 'crypto' );
const { test, expect } = require( '@playwright/test' );
const {
	openScreen,
	rest,
	expectAxeClean,
	tabTo,
	focused,
} = require( './helpers' );
const {
	php,
	fixtureCheck,
	currentToken,
	members,
} = require( './lifecycle/support' );

test.setTimeout( 120_000 );
const run = process.env.BULKCAT_ROW_RUN || randomUUID();
let fixture;
let armed = false;

/**
 * Helpers only exist in the source-mounted disposable fixture, never a release ZIP.
 * @param {string} code  Fixture PHP.
 * @param {Object} input Fixture arguments.
 */
function probe( code, input = {} ) {
	return php(
		`require WP_PLUGIN_DIR . '/bulk-catalogue-manager-for-woocommerce/tests/probe/native-product-row-actions.php'; ${ code }`,
		{ ...input, run }
	);
}

test.beforeAll( () => {
	if ( fixtureCheck() ) {
		throw new Error(
			'Native row actions require an explicitly owned local fixture.'
		);
	}
	armed = true;
	fixture = probe(
		'echo wp_json_encode( bulkcat_row_probe_setup( $in["run"] ) );'
	);
} );

test.afterAll( () => {
	if ( armed ) {
		probe(
			'echo wp_json_encode( bulkcat_row_probe_cleanup( $in["run"] ) );'
		);
	}
} );

/**
 * Locate only this run's exact native product name.
 * @param {import('@playwright/test').Page} page Browser page.
 * @param {string}                          key  Owned source name.
 */
async function findRow( page, key ) {
	await openScreen( page );
	await page
		.getByRole( 'searchbox', {
			name: 'Search by name or SKU',
			exact: true,
		} )
		.fill( fixture.names[ key ] );
	const row = page.getByRole( 'row' ).filter( {
		has: page.getByText( fixture.names[ key ], { exact: true } ),
	} );
	await expect( row ).toHaveCount( 1 );
	return row;
}

async function menu( page, key ) {
	const row = await findRow( page, key );
	await row.getByRole( 'button', { name: 'Actions', exact: true } ).click();
	return row;
}

function read( id ) {
	return probe(
		'echo wp_json_encode( bulkcat_row_probe_read( (int) $in["id"], $in["run"] ) );',
		{ id }
	);
}

function events( source ) {
	return probe(
		'echo wp_json_encode( array_values( array_filter( get_option( "bulkcat_row_probe_log_" . $in["run"], [] ), static fn( $e ) => $e["source"] === $in["source"] ) ) );',
		{ source }
	);
}

/**
 * Actual native redirect identifies the new copy, then ownership/native data is checked.
 * @param {import('@playwright/test').Page} page    Browser page.
 * @param {string}                          key     Owned source name.
 * @param {boolean}                         manager Use the manager menu.
 */
async function duplicate( page, key, manager ) {
	if ( manager ) {
		await menu( page, key );
		await page
			.getByRole( 'menuitem', { name: 'Duplicate', exact: true } )
			.click();
	} else {
		await page.goto(
			`/wp-admin/edit.php?post_type=product&s=${ encodeURIComponent( fixture.names[ key ] ) }`
		);
		const row = page.locator( `#post-${ fixture.ids[ key ] }` );
		await row.hover();
		await row
			.getByRole( 'link', {
				name: 'Make a duplicate from this product',
				exact: true,
			} )
			.click();
	}
	await expect( page ).toHaveURL( /post\.php\?.*post=\d+/ );
	const id = Number( new URL( page.url() ).searchParams.get( 'post' ) );
	expect( id ).not.toBe( fixture.ids[ key ] );
	const copy = read( id );
	expect( copy.status ).toBe( 'draft' );
	expect( copy.sku ).not.toBe( read( fixture.ids[ key ] ).sku );
	expect( copy.excluded ).toBe( '' );
	expect( events( fixture.ids[ key ] ).map( ( e ) => e.event ) ).toEqual( [
		'duplicate_before_save',
		'duplicate_complete',
	] );
	return copy;
}

test( 'View uses an isolated native tab and leaves exact selection intact', async ( {
	page,
} ) => {
	const outside = await findRow( page, 'outside' );
	await outside.getByRole( 'checkbox' ).first().check();
	// Capture is asynchronous: do not inspect an empty, not-yet-persisted handle.
	await expect.poll( () => currentToken( page ) ).not.toBe( '' );
	await expect
		.poll( () => members( page ) )
		.toEqual( [ fixture.ids.outside ] );
	const token = await currentToken( page );
	// Search in the same mounted grid, preserving the exact selection.
	await page
		.getByRole( 'searchbox', {
			name: 'Search by name or SKU',
			exact: true,
		} )
		.fill( fixture.names[ 'manager-simple' ] );
	const row = page.getByRole( 'row' ).filter( {
		has: page.getByText( fixture.names[ 'manager-simple' ], {
			exact: true,
		} ),
	} );
	await row.getByRole( 'button', { name: 'Actions', exact: true } ).click();
	const labels = await page.getByRole( 'menuitem' ).allTextContents();
	expect(
		labels.filter( ( label ) =>
			/^(Edit details|Open product editor|View product|Duplicate|Move to Trash)/.test(
				label
			)
		)
	).toEqual( [
		'Edit details',
		'Open product editor',
		'View product (new tab)',
		'Duplicate',
		'Move to Trash',
	] );
	const popupReady = page.waitForEvent( 'popup' );
	await page.getByRole( 'menuitem', { name: /^View product/ } ).click();
	const popup = await popupReady;
	await expect( popup ).toHaveURL(
		read( fixture.ids[ 'manager-simple' ] ).permalink
	);
	expect( await popup.evaluate( () => window.opener ) ).toBeNull();
	await popup.close();
	await page.bringToFront();
	expect( await currentToken( page ) ).toBe( token );
	expect( await members( page ) ).toEqual( [ fixture.ids.outside ] );
	await expectAxeClean( page, 'native row actions' );
} );

for ( const type of [ 'simple', 'variable', 'grouped', 'external' ] ) {
	test( `Duplicate ${ type } follows native product and child behavior`, async ( {
		page,
	} ) => {
		const manager = await duplicate( page, `manager-${ type }`, true );
		const native = await duplicate( page, `native-${ type }`, false );
		for ( const key of [
			'type',
			'status',
			'global_id',
			'price',
			'parent',
		] ) {
			expect( manager[ key ] ).toEqual( native[ key ] );
		}
		expect( manager.children.length ).toBe( native.children.length );
		if ( type === 'variable' ) {
			expect( manager.children ).toHaveLength( 2 );
			for ( let i = 0; i < 2; i++ ) {
				expect( manager.children[ i ].parent ).toBe( manager.id );
				expect( manager.children[ i ].attributes ).toEqual(
					native.children[ i ].attributes
				);
				const source = read( fixture.ids[ `manager-${ type }` ] )
					.children[ i ];
				expect( events( source.id ).map( ( e ) => e.event ) ).toEqual( [
					'duplicate_before_save',
				] );
			}
		}
	} );
}

test( 'sold-product Cancel does not mutate; native Trash and Undo restore the same row', async ( {
	page,
} ) => {
	await menu( page, 'sold' );
	page.once( 'dialog', ( dialog ) => dialog.dismiss() );
	await page
		.getByRole( 'menuitem', { name: 'Move to Trash', exact: true } )
		.click();
	expect( read( fixture.ids.sold ).status ).toBe( 'publish' );
	await menu( page, 'sold' );
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page
		.getByRole( 'menuitem', { name: 'Move to Trash', exact: true } )
		.click();
	await expect(
		page
			.getByText( '1 product moved to the Trash.', { exact: false } )
			.first()
	).toBeVisible();
	await expect(
		page.getByRole( 'link', { name: 'Undo', exact: true } )
	).toBeVisible();
	expect( read( fixture.ids.sold ).status ).toBe( 'trash' );
	await page.getByRole( 'link', { name: 'Undo', exact: true } ).click();
	await expect
		.poll( () => read( fixture.ids.sold ).status )
		.not.toBe( 'trash' );
} );

test( 'fresh native authorization rejects revoked edit rights and another actor nonce', async ( {
	page,
	browser,
} ) => {
	await openScreen( page );
	const id = fixture.ids.outside;
	const original = await rest( page, `products/${ id }` );
	const password = randomUUID();
	const actor = probe(
		'echo wp_json_encode( bulkcat_row_probe_actor( $in["run"], "restricted", $in["password"] ) );',
		{ password }
	);
	const context = await browser.newContext( {
		storageState: { cookies: [], origins: [] },
	} );
	try {
		const other = await context.newPage();
		await other.goto( new URL( '/wp-login.php', page.url() ).href );
		await other.locator( '#user_login' ).fill( actor.login );
		await other.locator( '#user_pass' ).fill( password );
		await other.locator( '#wp-submit' ).click();
		await other.waitForURL( /\/wp-admin\// );
		expect(
			(
				await other.request.get(
					original.json.item.native_actions.duplicate.url,
					{ maxRedirects: 0 }
				)
			).status()
		).toBe( 403 );
		await other.goto(
			new URL(
				'/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce',
				page.url()
			).href
		);
		await expect(
			other.locator( '#bulkcat-app .bulkcat-app' )
		).toBeVisible();
		const own = await rest( other, `products/${ id }` );
		expect( own.status ).toBe( 200 );
		probe(
			'if ( get_user_meta( $in["id"], "_bulkcat_row_probe", true ) !== $in["run"] ) { throw new RuntimeException( "Unowned actor" ); } $u = new WP_User( $in["id"] ); $u->add_cap( "edit_products", false ); $u->add_cap( "edit_published_products", false ); echo "true";',
			{ id: actor.id }
		);
		expect(
			(
				await other.request.get(
					own.json.item.native_actions.duplicate.url,
					{ maxRedirects: 0 }
				)
			).status()
		).toBe( 403 );
		expect( events( id ) ).toEqual( [] );
		expect( read( id ).status ).toBe( 'publish' );
	} finally {
		await context.close();
	}
} );

test( 'native lock and trash-refusal hook preserve the source product', async ( {
	page,
} ) => {
	await openScreen( page );
	const id = fixture.ids.outside;
	const answer = await rest( page, `products/${ id }` );
	const locker = probe(
		'echo wp_json_encode( bulkcat_row_probe_actor( $in["run"], "locker", $in["password"] ) );',
		{ password: randomUUID() }
	);
	probe(
		'$p = bulkcat_row_probe_owned( $in["id"], $in["run"] ); update_post_meta( $p->get_id(), "_edit_lock", time() . ":" . $in["user"] ); echo "true";',
		{ id, user: locker.id }
	);
	try {
		expect(
			(
				await page.request.get(
					answer.json.item.native_actions.trash.url,
					{ maxRedirects: 0 }
				)
			).status()
		).toBe( 409 );
		expect( read( id ).status ).toBe( 'publish' );
	} finally {
		probe(
			'$p = bulkcat_row_probe_owned( $in["id"], $in["run"] ); delete_post_meta( $p->get_id(), "_edit_lock" ); $p->update_meta_data( "_bulkcat_row_probe_refuse_trash", 1 ); $p->save(); echo "true";',
			{ id }
		);
	}
	try {
		const response = await page.request.get(
			answer.json.item.native_actions.trash.url,
			{ maxRedirects: 0 }
		);
		expect( response.status() ).toBeGreaterThanOrEqual( 400 );
		expect( read( id ).status ).toBe( 'publish' );
	} finally {
		probe(
			'$p = bulkcat_row_probe_owned( $in["id"], $in["run"] ); $p->delete_meta_data( "_bulkcat_row_probe_refuse_trash" ); $p->save(); echo "true";',
			{ id }
		);
	}
} );

test( 'draft and future have no live View; direct variations have no parent-native destinations', async ( {
	page,
} ) => {
	await openScreen( page );
	for ( const key of [ 'draft', 'future', 'manager-variable-Small' ] ) {
		const answer = await rest( page, `products/${ fixture.ids[ key ] }` );
		expect( answer.status ).toBe( 200 );
		expect( answer.json.item.native_actions.view ).toBeNull();
		if ( key.endsWith( '-Small' ) ) {
			for ( const kind of [ 'editor', 'duplicate', 'trash' ] ) {
				expect( answer.json.item.native_actions[ kind ] ).toBeNull();
			}
			expect( answer.json.item.native_actions.details ).toBe( true );
		}
	}
} );

test( 'expired native nonce and a new sales warning refuse without native mutation', async ( {
	page,
} ) => {
	await openScreen( page );
	const id = fixture.ids[ 'manager-simple' ];
	const answer = await rest( page, `products/${ id }` );
	const bad = new URL( answer.json.item.native_actions.duplicate.url );
	bad.searchParams.set( '_wpnonce', 'invalid-fixture-nonce' );
	expect(
		( await page.request.get( bad.href, { maxRedirects: 0 } ) ).status()
	).toBe( 403 );
	const trash = answer.json.item.native_actions.trash.url;
	probe(
		'$p = bulkcat_row_probe_owned( (int) $in["id"], $in["run"] ); $p->set_total_sales( 4 ); $p->save(); echo "true";',
		{ id }
	);
	expect(
		( await page.request.get( trash, { maxRedirects: 0 } ) ).status()
	).toBe( 409 );
	expect( read( id ).status ).toBe( 'publish' );
} );

/** New native gates run independently with --grep 'remaining:'. */
test.describe( 'remaining:', () => {
	test.beforeEach( () => {
		// Only this UUID's hook log: previous paired duplicates must not inflate it.
		probe(
			'bulkcat_row_probe_guard( $in["run"] ); delete_option( "bulkcat_row_probe_log_" . $in["run"] ); echo "true";'
		);
	} );

	test( 'populated grouped references, global identities and child-exclusion follow native Duplicate', async ( {
		page,
	} ) => {
		probe(
			`foreach ( $in['ids'] as $id ) {
			$p = bulkcat_row_probe_owned( $id, $in['run'] );
			$digits = str_pad( (string) $id, 12, '0', STR_PAD_LEFT );
			$sum = 0; for ( $i = 0; $i < 12; ++$i ) { $sum += (int) $digits[$i] * ( $i % 2 ? 3 : 1 ); }
			$p->set_global_unique_id( $digits . ( ( 10 - $sum % 10 ) % 10 ) ); $p->save();
		}
		foreach ( $in['groups'] as $id ) {
			$p = bulkcat_row_probe_owned( $id, $in['run'] );
			bulkcat_row_probe_owned( $in['child'], $in['run'] );
			$p->set_children( [ $in['child'] ] ); $p->save();
		} echo 'true';`,
			{
				ids: Object.entries( fixture.ids )
					.filter( ( [ key ] ) =>
						/^(manager|native)-variable/.test( key )
					)
					.map( ( [ , id ] ) => id ),
				groups: [
					fixture.ids[ 'manager-grouped' ],
					fixture.ids[ 'native-grouped' ],
				],
				child: fixture.ids.outside,
			}
		);
		for ( const type of [ 'variable', 'grouped' ] ) {
			const copies = [];
			for ( const entry of [ 'manager', 'native' ] ) {
				const key = `${ entry }-${ type }`;
				const source = read( fixture.ids[ key ] );
				const copy = await duplicate( page, key, entry === 'manager' );
				copies.push( copy );
				if ( type === 'variable' ) {
					expect( source.global_id ).not.toBe( '' );
					expect( copy.global_id ).toBe( '' );
					expect( copy.children ).toHaveLength( 2 );
					for ( const child of copy.children ) {
						const original = source.children.find(
							( c ) =>
								JSON.stringify( c.attributes ) ===
								JSON.stringify( child.attributes )
						);
						expect( original.global_id ).not.toBe( '' );
						expect( child.global_id ).toBe( '' );
						expect( child.sku ).not.toBe( original.sku );
						expect( child.parent ).toBe( copy.id );
					}
				} else {
					expect( copy.children.map( ( c ) => c.id ) ).toEqual( [
						fixture.ids.outside,
					] );
				}
			}
			expect( copies[ 0 ].children.length ).toBe(
				copies[ 1 ].children.length
			);
		}
		probe(
			`delete_option( 'bulkcat_row_probe_log_' . $in['run'] );
		foreach ( $in['ids'] as $id ) { $p = bulkcat_row_probe_owned( $id, $in['run'] ); $p->update_meta_data( '_bulkcat_row_probe_no_children', 1 ); $p->save(); } echo 'true';`,
			{
				ids: [
					fixture.ids[ 'manager-variable' ],
					fixture.ids[ 'native-variable' ],
				],
			}
		);
		for ( const entry of [ 'manager', 'native' ] ) {
			const copy = await duplicate(
				page,
				`${ entry }-variable`,
				entry === 'manager'
			);
			expect( copy.children ).toEqual( [] );
		}
	} );

	test( 'variable Trash and native Undo preserve native child-status parity', async ( {
		page,
	} ) => {
		const outcomes = [];
		for ( const entry of [ 'manager', 'native' ] ) {
			const key = `${ entry }-variable`;
			const id = fixture.ids[ key ];
			const childIds = read( id ).children.map( ( child ) => child.id );
			const statuses = () =>
				[ id, ...childIds ].map(
					( productId ) => read( productId ).status
				);
			const before = statuses();
			if ( entry === 'manager' ) {
				await menu( page, key );
				await page
					.getByRole( 'menuitem', {
						name: 'Move to Trash',
						exact: true,
					} )
					.click();
			} else {
				await page.goto(
					`/wp-admin/edit.php?post_type=product&s=${ encodeURIComponent( fixture.names[ key ] ) }`
				);
				const row = page.locator( `#post-${ id }` );
				await row.hover();
				await row.locator( '.trash a' ).click();
			}
			const undo = page.getByRole( 'link', {
				name: 'Undo',
				exact: true,
			} );
			await expect( undo ).toBeVisible();
			expect( read( id ).status ).toBe( 'trash' );
			const trashed = statuses();
			await undo.click();
			await expect.poll( () => read( id ).status ).not.toBe( 'trash' );
			outcomes.push( { before, trashed, restored: statuses() } );
		}
		expect( outcomes[ 0 ] ).toEqual( outcomes[ 1 ] );
	} );

	test( 'editor Duplicate Trash and Back retain exact off-page selection and honest CSV', async ( {
		page,
	} ) => {
		for ( const key of [ 'outside', 'manager-external' ] ) {
			const row = await findRow( page, key );
			await row.getByRole( 'checkbox' ).first().check();
			await expect.poll( () => currentToken( page ) ).not.toBe( '' );
		}
		const ids = [
			fixture.ids.outside,
			fixture.ids[ 'manager-external' ],
		].sort( ( a, b ) => a - b );
		await expect.poll( () => members( page ) ).toEqual( ids );
		const token = await currentToken( page );
		await menu( page, 'manager-external' );
		await page
			.getByRole( 'menuitem', {
				name: 'Open product editor',
				exact: true,
			} )
			.click();
		await expect( page ).toHaveURL( /post\.php\?.*post=\d+/ );
		expect(
			Number( new URL( page.url() ).searchParams.get( 'post' ) )
		).toBe( fixture.ids[ 'manager-external' ] );
		await page.goBack();
		await expect(
			page.locator( '#bulkcat-app .bulkcat-app' )
		).toBeVisible();
		expect( await currentToken( page ) ).toBe( token );
		expect( await members( page ) ).toEqual( ids );
		const copy = await duplicate( page, 'manager-external', true );
		await page.goBack();
		await expect(
			page.locator( '#bulkcat-app .bulkcat-app' )
		).toBeVisible();
		expect( await currentToken( page ) ).toBe( token );
		expect( await members( page ) ).toEqual( ids );
		expect( ids ).not.toContain( copy.id );
		await menu( page, 'outside' );
		const beforeTrash = await rest(
			page,
			`products/${ fixture.ids.outside }`
		);
		await page
			.getByRole( 'menuitem', { name: 'Move to Trash', exact: true } )
			.click();
		const undo = page.getByRole( 'link', { name: 'Undo', exact: true } );
		await expect( undo ).toBeVisible();
		const undoUrl = new URL( await undo.getAttribute( 'href' ), page.url() )
			.href;
		expect( read( fixture.ids.outside ).status ).toBe( 'trash' );
		for ( const kind of [ 'duplicate', 'trash' ] ) {
			expect(
				(
					await page.request.get(
						beforeTrash.json.item.native_actions[ kind ].url,
						{ maxRedirects: 0 }
					)
				).status()
			).toBe( 404 );
		}
		try {
			await page.goBack();
			await expect(
				page.locator( '#bulkcat-app .bulkcat-app' )
			).toBeVisible();
			expect( await currentToken( page ) ).toBe( token );
			expect( await members( page ) ).toEqual( ids );
			await page
				.getByRole( 'button', { name: 'Export CSV', exact: true } )
				.click();
			const dialog = page.getByRole( 'dialog', {
				name: 'Export CSV',
				exact: true,
			} );
			await expect(
				dialog.getByText(
					'The file will have 1 row. 1 selected item will be left out.',
					{ exact: true }
				)
			).toBeVisible();
			await dialog
				.getByRole( 'checkbox', {
					name: 'Include identifiers for re-import',
					exact: true,
				} )
				.check();
			const ready = page.waitForEvent( 'download' );
			await dialog
				.getByRole( 'button', { name: 'Download', exact: true } )
				.click();
			const download = await ready;
			const csv = fs
				.readFileSync( await download.path(), 'utf8' )
				.replace( /^\uFEFF/, '' );
			// These synthetic names contain no line breaks; identifiers are first.
			const lines = csv.trim().split( /\r?\n/ );
			expect( lines ).toHaveLength( 2 );
			expect(
				Number( lines[ 1 ].split( ',' )[ 0 ].replace( /"/g, '' ) )
			).toBe( fixture.ids[ 'manager-external' ] );
		} finally {
			await page.goto( undoUrl );
			await expect
				.poll( () => read( fixture.ids.outside ).status )
				.not.toBe( 'trash' );
		}
	} );

	test( 'private/password View and revoked delete or filtered Duplicate capability retain native authority', async ( {
		page,
		browser,
	} ) => {
		await openScreen( page );
		const privateRow = await rest(
			page,
			`products/${ fixture.ids.private }`
		);
		const passwordRow = await rest(
			page,
			`products/${ fixture.ids.password }`
		);
		// Private permalinks depend on read_post; compare native controls as the
		// same authenticated browser actor, not get_permalink() as CLI user 0.
		for ( const [ key, answer ] of [
			[ 'private', privateRow ],
			[ 'password', passwordRow ],
		] ) {
			expect( answer.status ).toBe( 200 );
			await page.goto(
				`/wp-admin/edit.php?post_type=product&s=${ encodeURIComponent( fixture.names[ key ] ) }`
			);
			const row = page.locator( `#post-${ fixture.ids[ key ] }` );
			await row.hover();
			const nativeView = row.locator( '.view a' );
			await expect( nativeView ).toBeVisible();
			const nativeUrl = new URL(
				await nativeView.getAttribute( 'href' ),
				page.url()
			).href;
			expect( answer.json.item.native_actions.view.url ).toBe(
				nativeUrl
			);
		}
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		try {
			const other = await context.newPage();
			expect(
				(
					await other.goto(
						privateRow.json.item.native_actions.view.url
					)
				).status()
			).toBe( 404 );
			await other.goto( passwordRow.json.item.native_actions.view.url );
			await expect(
				other.locator( 'input[name="post_password"]' )
			).toBeVisible();
			const password = randomUUID();
			const actor = probe(
				'echo wp_json_encode( bulkcat_row_probe_actor( $in["run"], "remainingrights", $in["password"] ) );',
				{ password }
			);
			await other.goto( new URL( '/wp-login.php', page.url() ).href );
			await other.locator( '#user_login' ).fill( actor.login );
			await other.locator( '#user_pass' ).fill( password );
			await other.locator( '#wp-submit' ).click();
			await other.waitForURL( /\/wp-admin\// );
			await other.goto(
				new URL(
					'/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce',
					page.url()
				).href
			);
			await expect(
				other.locator( '#bulkcat-app .bulkcat-app' )
			).toBeVisible();
			const own = await rest(
				other,
				`products/${ fixture.ids.password }`
			);
			expect( own.status ).toBe( 200 );
			probe(
				`if ( get_user_meta( $in['id'], '_bulkcat_row_probe', true ) !== $in['run'] ) { throw new RuntimeException( 'Unowned actor' ); }
			$u = new WP_User( $in['id'] ); $u->add_cap( 'delete_published_products', false );
			update_user_meta( $in['id'], '_bulkcat_row_probe_duplicate_cap', 'manage_options' ); echo 'true';`,
				{ id: actor.id }
			);
			for ( const kind of [ 'trash', 'duplicate' ] ) {
				expect(
					(
						await other.request.get(
							own.json.item.native_actions[ kind ].url,
							{ maxRedirects: 0 }
						)
					).status()
				).toBe( 403 );
			}
			const fresh = await rest(
				other,
				`products/${ fixture.ids.password }`
			);
			expect( fresh.json.item.native_actions.trash ).toBeNull();
			expect( fresh.json.item.native_actions.duplicate ).toBeNull();
			expect( read( fixture.ids.password ).status ).toBe( 'publish' );
			expect( events( fixture.ids.password ) ).toEqual( [] );
		} finally {
			await context.close();
		}
	} );

	for ( const width of [ 390, 900 ] ) {
		test( `keyboard native-menu Cancel and accessibility at ${ width }px`, async ( {
			page,
		} ) => {
			probe(
				'$p = bulkcat_row_probe_owned( $in["id"], $in["run"] ); $p->set_status( "publish" ); $p->set_total_sales( 2 ); $p->save(); echo "true";',
				{ id: fixture.ids.sold }
			);
			await page.setViewportSize( { width, height: 900 } );
			const row = await findRow( page, 'sold' );
			const opener = row.getByRole( 'button', {
				name: 'Actions',
				exact: true,
			} );
			await tabTo( page, opener );
			await page.keyboard.press( 'Enter' );
			const trash = page.getByRole( 'menuitem', {
				name: 'Move to Trash',
				exact: true,
			} );
			for (
				let i = 0;
				i < 10 &&
				! ( await trash.evaluate(
					( node ) => node === node.ownerDocument.activeElement
				) );
				i++
			) {
				await page.keyboard.press( 'ArrowDown' );
			}
			await expect( trash ).toBeFocused();
			await expectAxeClean( page, `native menu ${ width }` );
			const before = read( fixture.ids.sold );
			page.once( 'dialog', ( dialog ) => dialog.dismiss() );
			await page.keyboard.press( 'Enter' );
			expect( read( fixture.ids.sold ) ).toEqual( before );
			expect( ( await focused( page ) ).tag ).not.toBe( 'BODY' );
		} );
	}
} );
