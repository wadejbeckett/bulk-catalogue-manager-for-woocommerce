/**
 * A variable product expanded and a variation edited by keyboard alone
 * (SPEC §4.2, §14; T67, T68, T75): the disclosure reports aria-expanded, the
 * expansion is announced politely through wp.a11y.speak, the parent's derived
 * price cannot be opened and says why, and a variation's own price commits
 * with Enter and reaches GET /products/{id}/variations.
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen, tabTo, rest } = require( './helpers' );

const variationPrice = async ( page, parentId, sku ) => {
	const { json } = await rest(
		page,
		`products/${ parentId }/variations?per_page=100&fields[]=regular_price&fields[]=sku`
	);
	return ( json.items || [] ).find( ( item ) => item.sku === sku )
		?.regular_price;
};

test( 'expand a variable product and edit a variation by keyboard', async ( {
	page,
} ) => {
	await openScreen( page );
	const keys = page.keyboard;
	const disclosure = page.getByRole( 'button', {
		name: `Show the variations of ${ SEED.variable }`,
	} );
	await keys.press( 'Tab' );
	await keys.press( 'Enter' ); // Skip to main content.
	await tabTo( page, disclosure );
	await expect( disclosure ).toHaveAttribute( 'aria-expanded', 'false' );
	await keys.press( 'Enter' );
	const hide = page.getByRole( 'button', {
		name: `Hide the variations of ${ SEED.variable }`,
	} );
	await expect( hide ).toHaveAttribute( 'aria-expanded', 'true' );
	await expect( page.locator( '#a11y-speak-polite' ) ).toContainText(
		'3 of 3 options shown.'
	);

	// The parent's price is worked out from its variations: Enter opens
	// nothing, and the reason is in the cell for a screen reader.
	const parentRow = page.getByRole( 'row', {
		name: new RegExp( `^${ SEED.variable } Hide the variations` ),
	} );
	await expect( parentRow ).toContainText(
		'A variable product takes its price from its variations.'
	);
	await expect( parentRow.getByRole( 'textbox' ) ).toHaveCount( 0 );

	// A variation's own price: Enter opens it, Enter commits.
	const name = `${ SEED.variable } - Small`;
	const cell = page.getByRole( 'button', {
		name: `Edit Regular price, ${ name }`,
	} );
	await tabTo( page, cell );
	const parentId = Number(
		(
			await parentRow
				.getByRole( 'link', { name: SEED.variable } )
				.getAttribute( 'href' )
		).match( /post=(\d+)/ )[ 1 ]
	);
	const sku = 'bulkcat-probe-variable-small';
	const before = await variationPrice( page, parentId, sku );
	await keys.press( 'Enter' );
	const input = page.getByRole( 'textbox', { name: /Regular price/ } );
	await expect( input ).toBeFocused();
	await keys.press( 'ControlOrMeta+a' );
	await keys.type( '33.50' );
	await keys.press( 'Enter' );
	await expect( cell ).toContainText( '33.50' );
	await expect
		.poll( () => variationPrice( page, parentId, sku ) )
		.toBe( '33.50' );

	// Put it back the same way.
	await expect( cell ).toBeFocused();
	await keys.press( 'Enter' );
	await expect( input ).toBeFocused();
	await keys.press( 'ControlOrMeta+a' );
	await keys.type( before );
	await keys.press( 'Enter' );
	await expect
		.poll( () => variationPrice( page, parentId, sku ) )
		.toBe( before );

	// Collapsing is announced state too.
	await tabTo( page, hide, { back: true } );
	await keys.press( 'Enter' );
	await expect( disclosure ).toHaveAttribute( 'aria-expanded', 'false' );
} );
