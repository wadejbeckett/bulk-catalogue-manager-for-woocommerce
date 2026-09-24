/* eslint-disable @wordpress/no-global-active-element -- this code runs in the page, through evaluate(). */
/**
 * A category moved through the Move to… dialog with the keyboard alone
 * (SPEC §4.7, §14; T64, T66, T75): the tree's type-ahead and arrows reach the
 * node, Shift+F10 opens its actions, the dialog moves it, the tree shows the
 * new place, and the same path puts it back.
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen, tabTo } = require( './helpers' );

/**
 * Focus a node of the tree by typing its first letters.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          name Category name.
 */
async function reach( page, name ) {
	const item = page.getByRole( 'treeitem', {
		name: new RegExp( `^${ name }` ),
	} );
	for ( let i = 0; i < 12; i++ ) {
		if (
			( await item.count() ) &&
			( await item.evaluate(
				( node ) => node === document.activeElement
			) )
		) {
			return item;
		}
		await page.keyboard.press( 'ArrowDown' );
	}
	const at = await page.evaluate(
		() =>
			document.activeElement.tagName +
			' ' +
			( document.activeElement.getAttribute( 'aria-label' ) ||
				document.activeElement.textContent.slice( 0, 40 ) )
	);
	throw new Error(
		`The arrows did not reach ${ name }; focus is on ${ at }`
	);
}

/**
 * Open a node's Move to… dialog and move it into a parent.
 *
 * @param {import('@playwright/test').Page} page     Page.
 * @param {string}                          name     Category name.
 * @param {string}                          into     The "Move into" option's words.
 * @param {string}                          position The "Position" option's words, or ''.
 */
async function moveBy( page, name, into, position = '' ) {
	const keys = page.keyboard;
	await keys.press( 'Shift+F10' );
	const move = page.getByRole( 'menuitem', { name: /^Move to/ } );
	await expect( move ).toBeVisible();
	for (
		let i = 0;
		i < 10 &&
		! ( await move.evaluate( ( n ) => n === document.activeElement ) );
		i++
	) {
		await keys.press( 'ArrowDown' );
	}
	await keys.press( 'Enter' );
	const dialog = page.getByRole( 'dialog', { name: `Move “${ name }”` } );
	await expect( dialog ).toBeVisible();
	const select = dialog.getByRole( 'combobox', { name: 'Move into' } );
	await tabTo( page, select );
	const options = await select.locator( 'option' ).allTextContents();
	const want = options.indexOf( into );
	expect( want, `"${ into }" is offered` ).toBeGreaterThanOrEqual( 0 );
	for ( let i = 0; i < options.length; i++ ) {
		const at = await select.evaluate( ( node ) => node.selectedIndex );
		if ( at === want ) {
			break;
		}
		await keys.press( at < want ? 'ArrowDown' : 'ArrowUp' );
	}
	await expect( select ).toHaveValue(
		await select.locator( 'option' ).nth( want ).getAttribute( 'value' )
	);
	if ( position ) {
		const where = dialog.getByRole( 'combobox', { name: 'Position' } );
		await tabTo( page, where );
		const places = await where.locator( 'option' ).allTextContents();
		const target = places.indexOf( position );
		for ( let i = 0; i < places.length; i++ ) {
			const at = await where.evaluate( ( node ) => node.selectedIndex );
			if ( at === target ) {
				break;
			}
			await keys.press( at < target ? 'ArrowDown' : 'ArrowUp' );
		}
	}
	await expect( dialog ).toContainText(
		`New place: ${ into === 'Top level' ? '' : into + ' › ' }${ name }`
	);
	await tabTo(
		page,
		dialog.getByRole( 'button', { name: 'Move', exact: true } )
	);
	await keys.press( 'Enter' );
	await expect( dialog ).toBeHidden();
}

test( 'move a category through the dialog by keyboard', async ( { page } ) => {
	await openScreen( page, 'categories' );
	const keys = page.keyboard;
	await keys.press( 'Tab' );
	await keys.press( 'Enter' ); // Skip to main content.
	const tree = page.getByRole( 'tree', { name: 'Product categories' } );
	await tabTo( page, tree.getByRole( 'treeitem' ).first() );

	// Open the probe branch and remember where Hats sits among its siblings.
	const root = await reach( page, SEED.root );
	await keys.press( 'ArrowRight' );
	await expect( root ).toHaveAttribute( 'aria-expanded', 'true' );
	const children = await tree
		.locator( '[role="treeitem"][aria-level="2"]' )
		.evaluateAll( ( nodes ) =>
			nodes.map(
				( node ) =>
					node.getAttribute( 'aria-label' ) || node.textContent
			)
		);
	const hatsFirst =
		children.findIndex( ( label ) => label.startsWith( SEED.hats ) ) <
		children.findIndex( ( label ) => label.startsWith( SEED.shirts ) );
	await reach( page, SEED.hats );

	// Into Shirts.
	await moveBy( page, SEED.hats, `${ SEED.root } › ${ SEED.shirts }` );
	await expect(
		page.locator( '#a11y-speak-polite, [data-bulkcat-announcer]' ).first()
	).toContainText( SEED.hats );
	const hats = tree.getByRole( 'treeitem', {
		name: new RegExp( `^${ SEED.hats }` ),
	} );
	// The tree is read again; Hats has left the second level.
	await expect(
		tree.locator( '[role="treeitem"][aria-level="2"]', {
			hasText: SEED.hats,
		} )
	).toHaveCount( 0 );
	const shirts = await reach( page, SEED.shirts );
	if ( ( await shirts.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await keys.press( 'ArrowRight' );
	}
	await expect( hats ).toHaveAttribute( 'aria-level', '3' );

	// And back, to the place it had.
	await reach( page, SEED.hats );
	await moveBy(
		page,
		SEED.hats,
		SEED.root,
		hatsFirst ? `Before “${ SEED.shirts }”` : ''
	);
	await expect( hats ).toHaveAttribute( 'aria-level', '2' );
} );
