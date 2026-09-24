/**
 * The category panel's two forms and its keyboard (SPEC §4.1, §4.7, §14;
 * T61, T66): a panel beside the grid at 1280 pixels, a button opening the
 * same tree as a sheet at 900, and every node action reachable from the
 * keyboard through Shift+F10, with Escape back to the node.
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen } = require( './helpers' );

test.describe( 'at 1280 pixels', () => {
	test.use( { viewport: { width: 1280, height: 900 } } );

	test( 'the tree is a panel beside the grid, and collapses to a control', async ( {
		page,
	} ) => {
		await openScreen( page );
		const panel = page.getByRole( 'complementary', {
			name: 'Product categories',
		} );
		await expect( panel ).toBeVisible();
		await expect(
			panel.getByRole( 'tree', { name: 'Product categories' } )
		).toBeVisible();
		const hide = panel.getByRole( 'button', { name: 'Hide categories' } );
		await hide.press( 'Enter' );
		await expect(
			page.getByRole( 'tree', { name: 'Product categories' } )
		).toBeHidden();
		const show = page.getByRole( 'button', { name: 'Show categories' } );
		await expect( show ).toBeVisible();
		await show.press( 'Enter' );
		await expect(
			page.getByRole( 'tree', { name: 'Product categories' } )
		).toBeVisible();
	} );

	test( 'every node action is reachable from the keyboard', async ( {
		page,
	} ) => {
		await openScreen( page, 'categories' );
		const tree = page.getByRole( 'tree', { name: 'Product categories' } );
		const root = tree.getByRole( 'treeitem', {
			name: new RegExp( `^${ SEED.root }` ),
		} );
		await root.focus();
		await page.keyboard.press( 'Shift+F10' );
		const items = page
			.getByRole( 'menu' )
			.filter( {
				has: page.getByRole( 'menuitem', { name: /^Move to/ } ),
			} )
			.getByRole( 'menuitem' );
		await expect( items.first() ).toBeFocused();
		const names = await items.allTextContents();
		expect( names.join( ' | ' ) ).toMatch( /Move to/ );
		expect( names.join( ' | ' ) ).toMatch( /Rename/ );
		await page.keyboard.press( 'Escape' );
		await expect( root ).toBeFocused();
		// Home and End move along the tree.
		await page.keyboard.press( 'End' );
		await expect( tree.getByRole( 'treeitem' ).last() ).toBeFocused();
		await page.keyboard.press( 'Home' );
		await expect( tree.getByRole( 'treeitem' ).first() ).toBeFocused();
	} );
} );

test.describe( 'at 900 pixels', () => {
	test.use( { viewport: { width: 900, height: 900 } } );

	test( 'the tree opens as a sheet from a button', async ( { page } ) => {
		await openScreen( page );
		const button = page.locator( 'button[aria-haspopup="dialog"]', {
			hasText: 'Categories',
		} );
		await expect( button ).toBeVisible();
		await expect(
			page.getByRole( 'tree', { name: 'Product categories' } )
		).toBeHidden();
		await button.press( 'Enter' );
		const sheet = page.getByRole( 'dialog', { name: 'Categories' } );
		await expect( sheet ).toBeVisible();
		await expect(
			sheet.getByRole( 'tree', { name: 'Product categories' } )
		).toBeVisible();
		await page.keyboard.press( 'Escape' );
		await expect( sheet ).toBeHidden();
		await expect( button ).toBeFocused();
	} );
} );
