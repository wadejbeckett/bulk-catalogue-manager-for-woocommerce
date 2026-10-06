/**
 * Native Status on an explicitly owned local fixture. UUID products only;
 * retained fixtures use a dedicated UUID login supplied by the caller.
 * Actual browser writes, GETs and exact batch workers; no response simulation.
 */
const { randomUUID } = require( 'crypto' );
const { writeFileSync } = require( 'fs' );
const { join } = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const { openScreen, rest, tabTo, expectAxeClean } = require( './helpers' );
const {
	php,
	rows,
	fixtureCheck,
	screenParts,
	searchFor,
	members: selectionMembers,
} = require( './lifecycle/support' );

test.setTimeout( 90_000 );
const run = randomUUID();
let fixture;

/** Keep exact ownership and settings before browser writes, when requested. */
function saveFixture() {
	if ( process.env.BULKCAT_E2E_OWNED_EVIDENCE ) {
		writeFileSync(
			join(
				process.env.BULKCAT_E2E_OWNED_EVIDENCE,
				run + '.fixture.json'
			),
			JSON.stringify( { run, fixture }, null, 2 ),
			{ mode: 0o600 }
		);
	}
}

test.beforeAll( () => {
	if ( fixtureCheck() ) {
		throw new Error(
			'Native Status requires an explicitly owned local fixture.'
		);
	}
	fixture = php(
		`
		$user = get_user_by( 'login', $in['login'] );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) { throw new RuntimeException( 'Fixture administrator required' ); }
		wp_set_current_user( $user->ID );
		$out = [ 'ids' => [], 'names' => [], 'settings' => get_option( 'bulkcat_settings', null ) ];
		foreach ( [ 'keyboard', 'scheduled', 'one', 'two', 'parent', 'outside' ] as $name ) {
			$p = 'parent' === $name ? new WC_Product_Variable() : new WC_Product_Simple();
			$p->set_name( 'Native browser ' . $in['run'] . ' ' . $name );
            if ( 'parent' === $name ) { $attribute = new WC_Product_Attribute(); $attribute->set_name( 'Size' ); $attribute->set_options( [ 'first', 'second' ] ); $attribute->set_visible( true ); $attribute->set_variation( true ); $p->set_attributes( [ $attribute ] ); }
			$p->set_status( 'keyboard' === $name ? 'private' : ( 'scheduled' === $name ? 'draft' : 'publish' ) );
			$p->set_date_created( 'scheduled' === $name ? time() + 86400 : strtotime( '2026-01-15 UTC' ) );
			$p->set_regular_price( '17.25' );
			$p->update_meta_data( '_bulkcat_native_probe', $in['run'] );
			$out['ids'][$name] = $p->save(); $out['names'][$name] = $p->get_name();
		}
		foreach ( [ 'first', 'second' ] as $name ) {
			$p = new WC_Product_Variation(); $p->set_parent_id( $out['ids']['parent'] ); $p->set_name( $out['names']['parent'] . ' - ' . $name );
			$p->set_attributes( [ 'size' => $name ] ); $p->set_status( 'publish' );
			$p->set_regular_price( '17.25' ); $p->update_meta_data( '_bulkcat_native_probe', $in['run'] );
			$out['ids'][$name] = $p->save(); $out['names'][$name] = $p->get_name();
		}
		echo wp_json_encode( $out );`,
		{ run, login: process.env.BULKCAT_E2E_USER || 'admin' }
	);
	saveFixture();
} );

test.afterAll( () => {
	if ( ! fixture ) {
		return;
	}
	const result = php(
		`
		foreach ( array_reverse( $in['ids'] ) as $id ) {
			if ( ! get_post( $id ) ) { continue; }
			if ( get_post_meta( $id, '_bulkcat_native_probe', true ) !== $in['run'] ) { throw new RuntimeException( 'Ownership changed' ); }
			wp_delete_post( $id, true );
		}
		if ( null === $in['settings'] ) { delete_option( 'bulkcat_settings' ); } else { update_option( 'bulkcat_settings', $in['settings'] ); }
		echo wp_json_encode( array_values( array_filter( $in['ids'], 'get_post' ) ) );`,
		{ ...fixture, run }
	);
	expect( result ).toEqual( [] );
	fixture.cleaned = true;
	saveFixture();
} );

const cell = ( page, name ) =>
	page.getByRole( 'button', { name: `Edit Status, ${ name }` } );

/**
 * One actual product PATCH, including api-fetch's method override.
 *
 * @param {Object} page Browser page.
 * @param {number} id   Product identity.
 */
function nextPatch( page, id ) {
	return page.waitForResponse( ( response ) => {
		const request = response.request();
		return (
			new RegExp(
				`bulkcat(\\/|%2F)v1(\\/|%2F)products(\\/|%2F)${ id }(?:[?&]|$)`
			).test( response.url() ) &&
			( request.method() === 'PATCH' ||
				request.headers()[ 'x-http-method-override' ] === 'PATCH' )
		);
	} );
}

/**
 * Read stored state through the actual REST GET after a browser write.
 *
 * @param {Object} page Browser page.
 * @param {number} id   Product identity.
 */
async function read( page, id ) {
	const response = await rest( page, `products/${ id }?fields[]=status` );
	expect( response.status ).toBe( 200 );
	return response.json.item;
}

/**
 * Reach an inline choice entirely by keyboard; one Enter commits.
 *
 * @param {Object} page    Browser page.
 * @param {string} name    Product name.
 * @param {string} target  Stored status choice.
 * @param {string} letters Native select keyboard search.
 */
async function keyboardStatus( page, name, target, letters ) {
	const button = cell( page, name );
	await tabTo( page, button, { max: 400 } );
	await page.keyboard.press( 'Enter' );
	const choice = page.getByRole( 'combobox', { name: `Status, ${ name }` } );
	await expect( choice ).toBeFocused();
	await page.keyboard.type( letters );
	await expect( choice ).toHaveValue( target );
	await page.keyboard.press( 'Enter' );
}

/**
 * Run only this batch. An optional owned-object native save hook throws to
 * exercise an actual worker failure, without installing or mocking a handler.
 *
 * @param {number} batch   Accepted batch identity.
 * @param {number} failure Exact owned object whose native save must fail.
 * @return {Object} Stored batch header.
 */
function work( batch, failure = 0 ) {
	return php(
		`
        if ( $in['failure'] ) {
            if ( get_post_meta( $in['failure'], '_bulkcat_native_probe', true ) !== $in['run'] ) { throw new RuntimeException( 'Failure object not owned' ); }
            add_action( 'woocommerce_before_product_object_save', static function ( $p ) use ( $in ) { if ( $p->get_id() === $in['failure'] ) { throw new RuntimeException( 'Owned test save failure' ); } }, 999 );
        }
        $repo = new \\BulkCatalogueManager\\Data\\BatchRepository();
        for ( $i=0; $i<10 && in_array( $repo->status( $in['batch'] ), [ 'queued', 'running' ], true ); ++$i ) {
            \\BulkCatalogueManager\\Engine\\ChunkRunner::handle( $in['batch'] );
        }
        echo wp_json_encode( $repo->find( $in['batch'] ) );`,
		{ batch, failure, run }
	);
}

/**
 * Select identities across searches, retaining earlier unrelated rows.
 *
 * @param {Object}        page  Browser page.
 * @param {Array<string>} names Names selected across searches.
 */
async function select( page, names ) {
	for ( const name of names ) {
		await searchFor( page, name, name );
		await screenParts( page ).box( name ).check();
	}
}

/**
 * Choose the registry's native action and obtain the actual server preview.
 *
 * @param {Object} page   Browser page.
 * @param {string} target Requested native status.
 */
async function preview( page, target ) {
	await screenParts( page )
		.bar.getByRole( 'button', { name: 'Make a change to these' } )
		.click();
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await panel.getByRole( 'radio', { name: /^Set Status/ } ).check();
	await panel
		.getByRole( 'combobox', { name: 'New value', exact: true } )
		.selectOption( target );
	const response = page.waitForResponse( ( r ) =>
		/bulkcat(\/|%2F)v1(\/|%2F)preview(?:[?&]|$)/.test( r.url() )
	);
	await panel.getByRole( 'button', { name: 'Check the change' } ).click();
	const result = await ( await response ).json();
	expect( result.operations[ 0 ].action ).toBe( 'set_status' );
	return { panel, result };
}

/**
 * Capture the real accepted batch response.
 *
 * @param {Object} page Browser page.
 */
function nextBatch( page ) {
	return page.waitForResponse(
		( r ) =>
			/bulkcat(\/|%2F)v1(\/|%2F)batches(?:[?&]|$)/.test( r.url() ) &&
			r.request().method() === 'POST'
	);
}

test( 'keyboard ordinary edits use one save and authoritative GET; narrow choices', async ( {
	page,
}, info ) => {
	await page.setViewportSize( { width: 720, height: 900 } );
	await openScreen( page );
	await searchFor( page, fixture.names.keyboard, fixture.names.keyboard );
	let patches = 0;
	page.on( 'request', ( request ) => {
		if (
			request.headers()[ 'x-http-method-override' ] === 'PATCH' ||
			request.method() === 'PATCH'
		) {
			patches++;
		}
	} );
	for ( const [ target, letters, label ] of [
		[ 'draft', 'Dr', 'Draft' ],
		[ 'publish', 'Pub', 'Published' ],
		[ 'pending', 'Pen', 'Pending Review' ],
	] ) {
		const response = nextPatch( page, fixture.ids.keyboard );
		await keyboardStatus( page, fixture.names.keyboard, target, letters );
		expect( ( await response ).status() ).toBe( 200 );
		await expect( cell( page, fixture.names.keyboard ) ).toContainText(
			label
		);
		expect( ( await read( page, fixture.ids.keyboard ) ).status ).toBe(
			target
		);
		await expect( page.locator( '.bulkcat-status-confirm' ) ).toHaveCount(
			0
		);
	}
	expect( patches ).toBe( 3 );
	await expectAxeClean( page, 'native Status narrow catalogue' );
	await page.screenshot( { path: info.outputPath( 'native-narrow.png' ) } );
} );

test( 'drawer one Save reports Scheduled and refresh keeps the effective state', async ( {
	page,
}, info ) => {
	await openScreen( page );
	await searchFor( page, fixture.names.scheduled, fixture.names.scheduled );
	const row = page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'link', {
			name: fixture.names.scheduled,
			exact: true,
		} ),
	} );
	await row.getByRole( 'button', { name: 'Actions' } ).click();
	await page.getByRole( 'menuitem', { name: 'Edit details' } ).click();
	const drawer = page.getByRole( 'dialog', { name: 'Details' } );
	await drawer
		.getByRole( 'combobox', { name: 'Status', exact: true } )
		.selectOption( 'publish' );
	const response = nextPatch( page, fixture.ids.scheduled );
	await drawer.getByRole( 'button', { name: 'Save', exact: true } ).click();
	const saved = await ( await response ).json();
	expect( saved.item.status ).toBe( 'future' );
	expect( ( await read( page, fixture.ids.scheduled ) ).status ).toBe(
		'future'
	);
	await page.reload();
	await expect( cell( page, fixture.names.scheduled ) ).toContainText(
		'Scheduled'
	);
	await cell( page, fixture.names.scheduled ).click();
	const choice = page.getByRole( 'combobox', {
		name: `Status, ${ fixture.names.scheduled }`,
	} );
	await expect( choice ).toHaveValue( 'future' );
	await expect( choice.locator( 'option[value="future"]' ) ).toBeDisabled();
	await page.keyboard.press( 'Escape' );
	await page.screenshot( {
		path: info.outputPath( 'native-scheduled.png' ),
	} );
} );

test( 'exact mixed selection retains unrelated rows, deduplicates direct variation and confirms at threshold', async ( {
	page,
}, info ) => {
	php(
		`$s = get_option( 'bulkcat_settings', [] ); $s['confirm_threshold'] = 5; update_option( 'bulkcat_settings', $s ); echo 'true';`
	);
	await openScreen( page );
	await select( page, [
		fixture.names.one,
		fixture.names.two,
		fixture.names.parent,
	] );
	await page
		.getByRole( 'button', {
			name: `Show the variations of ${ fixture.names.parent }`,
		} )
		.click();
	await page.getByRole( 'checkbox', { name: new RegExp( 'first' ) } ).check();
	const { panel, result } = await preview( page, 'private' );
	expect( result.totals.matched ).toBe( 5 );
	const accept = panel.getByRole( 'button', { name: /^Apply to/ } );
	await expect( accept ).toBeEnabled();
	await accept.click();
	await expect( panel.locator( '.bulkcat-confirm__ask' ) ).toBeVisible();
	expect( ( await read( page, fixture.ids.one ) ).status ).toBe( 'publish' );
	const response = nextBatch( page );
	await panel
		.locator( '.bulkcat-confirm__ask' )
		.getByRole( 'button', { name: /^Yes, change/ } )
		.click();
	const accepted = await ( await response ).json();
	const header = work( accepted.batch_id );
	expect( header.status ).toBe( 'complete' );
	const members = rows(
		'SELECT object_id, object_type FROM {p}bulkcat_batch_items WHERE batch_id=%d ORDER BY object_id',
		[ accepted.batch_id ]
	);
	expect( members.map( ( item ) => Number( item.object_id ) ) ).toEqual(
		[ 'one', 'two', 'parent', 'first', 'second' ]
			.map( ( key ) => fixture.ids[ key ] )
			.sort( ( a, b ) => a - b )
	);
	for ( const key of [ 'one', 'two', 'parent', 'first', 'second' ] ) {
		expect( ( await read( page, fixture.ids[ key ] ) ).status ).toBe(
			'private'
		);
	}
	expect( ( await read( page, fixture.ids.outside ) ).status ).toBe(
		'publish'
	);
	await info.attach( 'exact-membership', {
		body: JSON.stringify( { preview: result, accepted, header, members } ),
		contentType: 'application/json',
	} );
} );

for ( const partial of [ false, true ] ) {
	test( `${ partial ? 'partial' : 'failed' } native batch retains selection and reports actual outcomes`, async ( {
		page,
	}, info ) => {
		const label = `Native outcome ${ randomUUID() }`;
		const made = php(
			`
            $out=[];
            foreach ( [ 'healthy', 'blocked' ] as $name ) {
                $p=new WC_Product_Simple(); $p->set_name( $in['name'] . ' ' . $name ); $p->set_status( 'publish' ); $p->set_regular_price( '17.25' ); $p->update_meta_data( '_bulkcat_native_probe', $in['run'] ); $out[]=$p->save();
            }
            echo wp_json_encode( $out );`,
			{ run, name: label }
		);
		made.forEach( ( id ) => {
			fixture.ids[ `outcome-${ id }` ] = id;
		} );
		saveFixture();
		await openScreen( page );
		await select(
			page,
			partial
				? [ label + ' healthy', label + ' blocked' ]
				: [ label + ' blocked' ]
		);
		const { panel } = await preview( page, 'private' );
		const response = nextBatch( page );
		await panel.getByRole( 'button', { name: /^Apply to/ } ).click();
		const accepted = await ( await response ).json();
		const header = work( accepted.batch_id, made[ 1 ] );
		const items = rows(
			'SELECT object_id, state, message FROM {p}bulkcat_batch_items WHERE batch_id=%d ORDER BY object_id',
			[ accepted.batch_id ]
		);
		await info.attach( 'native-outcome', {
			body: JSON.stringify( { accepted, header, items } ),
			contentType: 'application/json',
		} );
		expect( header.status ).toBe( partial ? 'partial' : 'failed' );
		expect(
			items.find( ( item ) => Number( item.object_id ) === made[ 1 ] )
				.state
		).toBe( 'failed' );
		expect( ( await read( page, made[ 1 ] ) ).status ).toBe( 'publish' );
		await expect( page.locator( '#bulkcat-app' ) ).toContainText(
			'The change did not finish for every product, so your selection is kept. History shows what was not changed.',
			{ timeout: 30_000 }
		);
		await expect( page.locator( '.bulkcat-selection__count' ) ).toHaveText(
			partial ? '2 products selected' : '1 product selected'
		);
		const selectedIds = await selectionMembers( page );
		expect( selectedIds ).toEqual(
			( partial ? [ ...made ] : [ made[ 1 ] ] ).sort( ( a, b ) => a - b )
		);
		await info.attach( 'native-outcome', {
			body: JSON.stringify( { accepted, header, items, selectedIds } ),
			contentType: 'application/json',
		} );
	} );
}
