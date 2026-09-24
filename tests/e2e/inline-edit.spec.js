/**
 * Inline editing by keyboard alone (SPEC §4.1, §14; T46): Enter opens a
 * cell, Enter commits, Escape reverts, a refused value keeps the cell open
 * with the reason, and a fresh GET /products shows what was saved.
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen, tabTo, productByName } = require( './helpers' );

test( 'inline edit commits on Enter and reverts on Escape', async ( {
	page,
} ) => {
	await openScreen( page );
	const keys = page.keyboard;
	const name = SEED.simple( 3 );
	const before = ( await productByName( page, name ) ).regular_price;
	const cell = page.getByRole( 'button', {
		name: `Edit Regular price, ${ name }`,
	} );
	await keys.press( 'Tab' );
	await keys.press( 'Enter' ); // Skip to main content.
	await tabTo( page, cell );
	const input = page.getByRole( 'textbox', { name: /Regular price/ } );

	// A value the registry refuses stays in the cell with the reason.
	await keys.press( 'Enter' );
	await expect( input ).toBeFocused();
	await keys.press( 'ControlOrMeta+a' );
	await keys.type( 'twelve' );
	await keys.press( 'Enter' );
	await expect( input ).toBeFocused();
	await expect( input ).toHaveAttribute( 'aria-invalid', 'true' );

	// Escape reverts: nothing is written and focus is back on the cell.
	await keys.press( 'Escape' );
	await expect( cell ).toBeFocused();
	await expect( cell ).toContainText( before );
	expect( ( await productByName( page, name ) ).regular_price ).toBe(
		before
	);

	// Enter commits.
	await keys.press( 'Enter' );
	await keys.press( 'ControlOrMeta+a' );
	await keys.type( '99.50' );
	await keys.press( 'Enter' );
	await expect( cell ).toContainText( '99.50' );
	await expect
		.poll( async () => ( await productByName( page, name ) ).regular_price )
		.toBe( '99.50' );
	await expect( cell ).toBeFocused();

	// Put it back.
	await keys.press( 'Enter' );
	await keys.press( 'ControlOrMeta+a' );
	await keys.type( before );
	await keys.press( 'Enter' );
	await expect
		.poll( async () => ( await productByName( page, name ) ).regular_price )
		.toBe( before );
} );
