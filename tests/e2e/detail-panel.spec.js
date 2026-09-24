/* eslint-disable @wordpress/no-global-active-element -- this code runs in the page, through evaluate(). */
/**
 * The detail drawer by keyboard alone (SPEC §4.1, §14; T47, T75): the row
 * menu opens it, focus moves into it and stays there, Escape closes it and
 * focus returns to the row's menu button; an edit saves as a batch of one
 * that GET /products shows, and categories read as names, not ids.
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen, tabTo, productByName } = require( './helpers' );

/**
 * Open a row's Edit details from its menu by keyboard.
 *
 * @param {import('@playwright/test').Page}    page Page.
 * @param {import('@playwright/test').Locator} menu The row's Actions button.
 * @return {Promise<import('@playwright/test').Locator>} The drawer.
 */
async function openDetails( page, menu ) {
	await tabTo( page, menu );
	await page.keyboard.press( 'Enter' );
	const item = page.getByRole( 'menuitem', { name: 'Edit details' } );
	await expect( item ).toBeVisible();
	for (
		let i = 0;
		i < 5 &&
		! ( await item.evaluate( ( n ) => n === document.activeElement ) );
		i++
	) {
		await page.keyboard.press( 'ArrowDown' );
	}
	await page.keyboard.press( 'Enter' );
	const drawer = page.getByRole( 'dialog', { name: 'Details' } );
	await expect( drawer ).toBeVisible();
	return drawer;
}

test( 'detail drawer: open, trap, Escape, focus return, save', async ( {
	page,
} ) => {
	await openScreen( page );
	const keys = page.keyboard;
	const name = SEED.simple( 4 );
	const before = await productByName( page, name, [ 'stock_quantity' ] );
	const row = page.getByRole( 'row', { name: new RegExp( `^${ name }` ) } );
	const menu = row.getByRole( 'button', { name: 'Actions' } );
	await keys.press( 'Tab' );
	await keys.press( 'Enter' ); // Skip to main content.

	let drawer = await openDetails( page, menu );
	await expect(
		drawer.getByRole( 'button', { name: 'Close details' } )
	).toBeFocused();
	await expect( drawer ).toContainText( SEED.hats );
	for ( let i = 0; i < 60; i++ ) {
		await keys.press( 'Tab' );
		expect(
			await drawer.evaluate( ( node ) =>
				node.contains( document.activeElement )
			),
			'focus stays in the drawer'
		).toBe( true );
	}
	await keys.press( 'Escape' );
	await expect( drawer ).toBeHidden();
	await expect( menu ).toBeFocused();

	// Edit the stock quantity and save.
	drawer = await openDetails( page, menu );
	const quantity = drawer.getByRole( 'spinbutton', {
		name: 'Stock quantity',
	} );
	await tabTo( page, quantity );
	await keys.press( 'ControlOrMeta+a' );
	await keys.type( String( Number( before.stock_quantity ) + 5 ) );
	const save = drawer.getByRole( 'button', { name: 'Save' } );
	await tabTo( page, save );
	await keys.press( 'Enter' );
	await expect
		.poll( async () =>
			Number(
				( await productByName( page, name, [ 'stock_quantity' ] ) )
					.stock_quantity
			)
		)
		.toBe( Number( before.stock_quantity ) + 5 );

	// Put it back the same way.
	if ( ! ( await drawer.isVisible() ) ) {
		drawer = await openDetails( page, menu );
	}
	await tabTo( page, quantity );
	await keys.press( 'ControlOrMeta+a' );
	await keys.type( String( before.stock_quantity ) );
	await tabTo( page, save );
	await keys.press( 'Enter' );
	await expect
		.poll( async () =>
			Number(
				( await productByName( page, name, [ 'stock_quantity' ] ) )
					.stock_quantity
			)
		)
		.toBe( Number( before.stock_quantity ) );
} );
