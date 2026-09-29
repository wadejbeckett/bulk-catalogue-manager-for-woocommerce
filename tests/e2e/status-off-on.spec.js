/* eslint-disable @wordpress/no-global-active-element -- this code runs in the page, through evaluate(). */
/**
 * Status as the global Off/On switch, by keyboard alone (SPEC §5.5, §4.5,
 * §14; T92): an inline Draft shows the confirm and the cell then reads
 * "Off (was Published)"; the Off filter finds the product and the Draft
 * filter does not; an inline Published switches it back On; a product that
 * was Private before Off comes back Private and the confirm said so; the
 * drawer's Off gives the same journal rows as the cell's; the drawer's Off
 * is undone from History; and axe-core finds nothing on the confirm.
 *
 * The UI is driven by keys only; the plugin's own REST routes read the
 * catalogue and the journal, and put every product back afterwards.
 */
const { test, expect } = require( '@playwright/test' );
const {
	SEED,
	openScreen,
	rest,
	runQueue,
	tabTo,
	productByName,
	expectAxeClean,
} = require( './helpers' );

const INLINE = SEED.simple( 7 );
const PRIVATE = SEED.simple( 8 );
const DRAWER = SEED.simple( 10 );

/**
 * A product's status and Off state.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          name Product name.
 * @return {Promise<Object>} { id, status, off_state }.
 */
function statusOf( page, name ) {
	return productByName( page, name, [ 'status' ] );
}

/**
 * The ids GET /products lists for a Status filter.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {Array<string>}                   value Filter values.
 * @return {Promise<Array<number>>} Ids.
 */
async function filtered( page, value ) {
	const query = new URLSearchParams( {
		search: 'Probe Simple',
		per_page: '100',
		'status[operator]': 'isAny',
	} );
	value.forEach( ( each ) => query.append( 'status[value][]', each ) );
	query.append( 'fields[]', 'name' );
	const { status, json } = await rest( page, `products?${ query }` );
	expect( status ).toBe( 200 );
	return ( json.items || [] ).map( ( item ) => item.id );
}

/**
 * Put a product back to Published with no Off record, through the routes.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          name Product name.
 */
async function putBack( page, name ) {
	for ( let i = 0; i < 3; i++ ) {
		const row = await statusOf( page, name );
		if ( row.status === 'publish' && ! row.off_state ) {
			return;
		}
		await rest( page, `products/${ row.id }`, {
			method: 'PATCH',
			data: {
				status: 'publish',
				keep_changed_status: row.off_state?.state === 'changed_outside',
			},
		} );
	}
	const row = await statusOf( page, name );
	expect( [ row.status, row.off_state ] ).toEqual( [ 'publish', null ] );
}

/**
 * Reach a control by Tab or Shift+Tab, whichever way it lies from the focus
 * (the grid lists the newest products first, so rows can be either side).
 *
 * @param {import('@playwright/test').Page}    page   Page.
 * @param {import('@playwright/test').Locator} target Control to reach.
 */
async function reach( page, target ) {
	await expect( target ).toBeVisible();
	const back = await target.evaluate( ( node ) => {
		const active = document.activeElement;
		if ( ! active || active === document.body || active === node ) {
			return false;
		}
		// DOCUMENT_POSITION_PRECEDING (2): the target comes before the focus.
		return (
			Math.floor( active.compareDocumentPosition( node ) / 2 ) % 2 === 1
		);
	} );
	await tabTo( page, target, { back, max: 400 } );
}

/**
 * The status cell's trigger for a product.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          name Product name.
 * @return {import('@playwright/test').Locator} Its button.
 */
const statusCell = ( page, name ) =>
	page.getByRole( 'button', { name: `Edit Status, ${ name }` } );

/**
 * Open a status cell by keyboard and choose a status by typing its name.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {string}                          name   Product name.
 * @param {string}                          typed  The start of the status's name.
 * @param {string}                          chosen The value it selects.
 */
async function chooseStatus( page, name, typed, chosen ) {
	await reach( page, statusCell( page, name ) );
	await page.keyboard.press( 'Enter' );
	const select = page.getByRole( 'combobox', { name: `Status, ${ name }` } );
	await expect( select ).toBeFocused();
	await page.keyboard.type( typed );
	await expect( select ).toHaveValue( chosen );
	await page.keyboard.press( 'Enter' );
}

/**
 * The journal rows of a batch, as the History feed gives them.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {number}                          batch Batch id.
 * @return {Promise<Array<Object>>} { field, old, new } rows, the record's time made generic.
 */
async function changes( page, batch ) {
	const { status, json } = await rest( page, `batches/${ batch }/changes` );
	expect( status ).toBe( 200 );
	return ( json.items || [] )
		.map( ( row ) => ( {
			field: row.field,
			old: String( row.old_value ).replace( /, saved .*$/, ', saved …' ),
			new: String( row.new_value ).replace( /, saved .*$/, ', saved …' ),
			result: row.result,
		} ) )
		.sort( ( a, b ) => a.field.localeCompare( b.field ) );
}

/**
 * The batch id of the next PATCH /products/{id} answer (the screen sends it
 * as a POST carrying X-HTTP-Method-Override, as apiFetch does).
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<number>} Batch id.
 */
async function nextPatch( page ) {
	const response = await page.waitForResponse(
		( each ) =>
			/bulkcat(\/|%2F)v1(\/|%2F)products(\/|%2F)\d+/.test( each.url() ) &&
			( each.request().method() === 'PATCH' ||
				( each.request().method() === 'POST' &&
					each.request().headers()[ 'x-http-method-override' ] ===
						'PATCH' ) )
	);
	expect( response.status() ).toBe( 200 );
	return ( await response.json() ).batch_id;
}

test.describe( 'Status as the Off/On switch', () => {
	test.afterEach( async ( { page } ) => {
		for ( const name of [ INLINE, PRIVATE, DRAWER ] ) {
			await putBack( page, name );
		}
	} );

	test( 'switch Off and On in the cell, the filter, a Private product, the drawer and undo', async ( {
		page,
	} ) => {
		runQueue();
		await openScreen( page );
		for ( const name of [ INLINE, PRIVATE, DRAWER ] ) {
			await putBack( page, name );
		}
		await page.reload();
		await expect(
			page.locator( '.dataviews-view-table tbody tr' ).first()
		).toBeVisible();
		const keys = page.keyboard;
		const inline = await statusOf( page, INLINE );

		// Inline Draft asks first; nothing is written until it is confirmed.
		await keys.press( 'Tab' );
		await expect(
			page.getByRole( 'link', { name: 'Skip to main content' } )
		).toBeFocused();
		await keys.press( 'Enter' );
		await chooseStatus( page, INLINE, 'Dr', 'draft' );
		const confirm = page.locator( '.bulkcat-status-confirm' );
		await expect( confirm ).toContainText(
			`Switch ${ INLINE } Off? It leaves the site for every buyer. Its status, Published, is saved`
		);
		await expect(
			confirm.getByRole( 'button', { name: 'Switch Off' } )
		).toBeFocused();
		await expectAxeClean( page, 'the Off/On confirm in the cell' );
		expect( ( await statusOf( page, INLINE ) ).status ).toBe( 'publish' );

		// Escape goes back to the select; Enter there asks again.
		await keys.press( 'Escape' );
		await expect( confirm ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'combobox', { name: `Status, ${ INLINE }` } )
		).toBeFocused();
		await keys.press( 'Enter' );
		await expect(
			confirm.getByRole( 'button', { name: 'Switch Off' } )
		).toBeFocused();
		const inlineBatch = nextPatch( page );
		await keys.press( 'Enter' );
		const offBatch = await inlineBatch;
		await expect( statusCell( page, INLINE ) ).toContainText(
			'Off (was Published)'
		);
		await expect( statusCell( page, INLINE ) ).toBeFocused();
		const off = await statusOf( page, INLINE );
		expect( off.status ).toBe( 'draft' );
		expect( off.off_state ).toMatchObject( {
			state: 'off',
			saved: 'publish',
			saved_label: 'Published',
			batch_id: offBatch,
		} );

		// The Off filter finds it; the Draft filter (a genuine draft) does not.
		expect( await filtered( page, [ 'off' ] ) ).toContain( inline.id );
		expect( await filtered( page, [ 'draft' ] ) ).not.toContain(
			inline.id
		);
		expect( await filtered( page, [ 'changed_outside' ] ) ).not.toContain(
			inline.id
		);

		// Inline Published restores Published with no confirm.
		await chooseStatus( page, INLINE, 'Pub', 'publish' );
		await expect( confirm ).toHaveCount( 0 );
		await expect( statusCell( page, INLINE ) ).toContainText( 'Published' );
		await expect
			.poll( async () => ( await statusOf( page, INLINE ) ).status )
			.toBe( 'publish' );
		expect( ( await statusOf( page, INLINE ) ).off_state ).toBeNull();

		// A product Private before Off comes back Private, and the confirm says so.
		await chooseStatus( page, PRIVATE, 'Pri', 'private' );
		await expect( confirm ).toHaveCount( 0 );
		await expect
			.poll( async () => ( await statusOf( page, PRIVATE ) ).status )
			.toBe( 'private' );
		await expect( statusCell( page, PRIVATE ) ).toBeFocused();
		await chooseStatus( page, PRIVATE, 'Dr', 'draft' );
		await expect( confirm ).toContainText(
			'Its status, Private, is saved'
		);
		await keys.press( 'Enter' );
		await expect( statusCell( page, PRIVATE ) ).toContainText(
			'Off (was Private)'
		);
		await chooseStatus( page, PRIVATE, 'Pub', 'publish' );
		await expect( confirm ).toContainText(
			`${ PRIVATE } was Private before it was switched Off, so it comes back Private, not Published.`
		);
		await expect(
			confirm.getByRole( 'button', { name: 'Switch On' } )
		).toBeFocused();
		await keys.press( 'Enter' );
		await expect( statusCell( page, PRIVATE ) ).toContainText( 'Private' );
		await expect
			.poll( async () => ( await statusOf( page, PRIVATE ) ).status )
			.toBe( 'private' );
		expect( ( await statusOf( page, PRIVATE ) ).off_state ).toBeNull();

		// The drawer's Off: the same confirm above Save, the same journal rows.
		const drawerRow = page.getByRole( 'row', {
			name: new RegExp( `^${ DRAWER }` ),
		} );
		const menu = drawerRow.getByRole( 'button', { name: 'Actions' } );
		await reach( page, menu );
		await keys.press( 'Enter' );
		const item = page.getByRole( 'menuitem', { name: 'Edit details' } );
		await expect( item ).toBeVisible();
		for (
			let i = 0;
			i < 5 &&
			! ( await item.evaluate(
				( node ) => node === document.activeElement
			) );
			i++
		) {
			await keys.press( 'ArrowDown' );
		}
		await keys.press( 'Enter' );
		const drawer = page.getByRole( 'dialog', { name: 'Details' } );
		await expect( drawer ).toBeVisible();
		const select = drawer.getByRole( 'combobox', {
			name: 'Status',
			exact: true,
		} );
		await tabTo( page, select );
		await keys.type( 'Dr' );
		await expect( select ).toHaveValue( 'draft' );
		await tabTo( page, drawer.getByRole( 'button', { name: 'Save' } ) );
		await keys.press( 'Enter' );
		const drawerConfirm = drawer.locator( '.bulkcat-status-confirm' );
		await expect( drawerConfirm ).toContainText(
			`Switch ${ DRAWER } Off?`
		);
		await expect(
			drawerConfirm.getByRole( 'button', { name: 'Switch Off' } )
		).toBeFocused();
		await expectAxeClean( page, 'the Off/On confirm in the drawer' );
		const drawerPatch = nextPatch( page );
		await keys.press( 'Enter' );
		const drawerBatch = await drawerPatch;
		await expect( drawer ).toContainText(
			'Saved: Status. Switched Off (was Published).'
		);
		expect( ( await statusOf( page, DRAWER ) ).off_state ).toMatchObject( {
			state: 'off',
			saved: 'publish',
		} );
		const inlineRows = await changes( page, offBatch );
		expect( inlineRows.map( ( row ) => row.field ) ).toEqual( [
			'availability_off',
			'status',
		] );
		expect( await changes( page, drawerBatch ) ).toEqual( inlineRows );
		await keys.press( 'Escape' );
		await expect( drawer ).toBeHidden();

		// Undo the drawer's Off from History: Published again, no record.
		await openScreen( page, 'history', `&batch=${ drawerBatch }` );
		const card = page.locator(
			`[aria-labelledby="bulkcat-batch-${ drawerBatch }"]`
		);
		await expect( card ).toBeVisible();
		// The linked batch takes focus; its Undo is reached from there.
		await reach(
			page,
			card.getByRole( 'button', { name: 'Undo', exact: true } )
		);
		await keys.press( 'Enter' );
		const undo = page.getByRole( 'dialog', { name: 'Undo this change?' } );
		await expect( undo ).toBeVisible( { timeout: 30_000 } );
		await tabTo(
			page,
			undo.getByRole( 'button', { name: 'Undo', exact: true } )
		);
		await keys.press( 'Enter' );
		await expect( undo ).toBeHidden();
		await expect
			.poll(
				async () => {
					runQueue();
					const row = await statusOf( page, DRAWER );
					return [ row.status, row.off_state ];
				},
				{ timeout: 90_000 }
			)
			.toEqual( [ 'publish', null ] );
	} );
} );
