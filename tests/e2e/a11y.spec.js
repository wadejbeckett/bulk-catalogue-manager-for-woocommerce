/**
 * axe-core on every screen T75 lists, with zero serious or critical
 * violations (SPEC §14, §16.4): the grid, expanded variations, the bulk
 * panel and its preview, the detail drawer, History and its expanded batch,
 * the audit log, the category manager with its Move to… dialog, and
 * Settings. Then the 320-pixel and 400 per cent runs and reduced motion.
 */
const { test, expect } = require( '@playwright/test' );
const { SEED, openScreen, expectAxeClean } = require( './helpers' );

test.describe( 'axe-core on each screen', () => {
	test( 'catalogue grid', async ( { page } ) => {
		await openScreen( page );
		await expectAxeClean( page, 'the catalogue' );
	} );

	test( 'expanded variations', async ( { page } ) => {
		await openScreen( page );
		await page
			.getByRole( 'button', {
				name: `Show the variations of ${ SEED.variable }`,
			} )
			.press( 'Enter' );
		await expect(
			page.locator( '.bulkcat-vrow.is-child' ).first()
		).toBeVisible();
		await expectAxeClean( page, 'the expanded variations' );
	} );

	test( 'bulk panel and preview', async ( { page } ) => {
		await openScreen( page );
		await page.getByRole( 'checkbox', { name: SEED.simple( 1 ) } ).check();
		// T84: the server holds the selection before the change is checked.
		await expect(
			page.getByRole( 'region', { name: 'Selection' } )
		).toContainText( '1 product selected' );
		await page
			.getByRole( 'button', { name: 'Make a change to these' } )
			.click();
		const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
		await expect( panel ).toBeVisible();
		await expectAxeClean( page, 'the bulk panel' );
		await panel
			.getByRole( 'radio', { name: /^Increase or decrease/ } )
			.first()
			.check();
		await panel.getByRole( 'textbox', { name: 'How much' } ).fill( '1' );
		await panel.getByRole( 'button', { name: 'Check the change' } ).click();
		await expect(
			panel.getByRole( 'button', { name: /^Apply to / } )
		).toBeEnabled();
		await expectAxeClean( page, 'the preview' );
	} );

	test( 'detail drawer', async ( { page } ) => {
		await openScreen( page );
		const row = page.getByRole( 'row', {
			name: new RegExp( SEED.simple( 2 ) ),
		} );
		await row.getByRole( 'button', { name: 'Actions' } ).click();
		await page.getByRole( 'menuitem', { name: 'Edit details' } ).click();
		await expect( page.getByRole( 'dialog' ).first() ).toBeVisible();
		await expectAxeClean( page, 'the detail drawer' );
	} );

	test( 'history and an expanded batch', async ( { page } ) => {
		await openScreen( page, 'history' );
		await expect(
			page.getByRole( 'heading', { name: 'History', level: 2 } )
		).toBeVisible();
		await page.waitForSelector( '.bulkcat-batch, .bulkcat-history__empty' );
		await expectAxeClean( page, 'History' );
		const see = page.getByRole( 'button', { name: 'See every change' } );
		if ( await see.count() ) {
			await see.first().click();
			await page.waitForSelector( '.bulkcat-changes' );
			await expectAxeClean( page, 'an expanded batch' );
		}
	} );

	test( 'audit log', async ( { page } ) => {
		await openScreen( page, 'history' );
		await page.getByRole( 'tab', { name: 'Audit log' } ).click();
		await page.waitForTimeout( 1500 );
		await expectAxeClean( page, 'the audit log' );
	} );

	test( 'category manager and Move to…', async ( { page } ) => {
		await openScreen( page, 'categories' );
		const tree = page.getByRole( 'tree' ).first();
		await expect( tree ).toBeVisible();
		await expectAxeClean( page, 'the category manager' );
		const hats = page.getByRole( 'treeitem', {
			name: new RegExp( `^${ SEED.hats }` ),
		} );
		if ( ! ( await hats.count() ) ) {
			await page
				.getByRole( 'treeitem', {
					name: new RegExp( `^${ SEED.root }` ),
				} )
				.press( 'ArrowRight' );
		}
		await hats.press( 'Shift+F10' );
		await page
			.getByRole( 'menuitem', { name: /^Move to/ } )
			.press( 'Enter' );
		await expect(
			page.getByRole( 'dialog', { name: `Move “${ SEED.hats }”` } )
		).toBeVisible();
		await expectAxeClean( page, 'the Move to… dialog' );
	} );

	test( 'settings', async ( { page } ) => {
		await openScreen( page, 'settings' );
		await expect(
			page.getByRole( 'button', { name: 'Save settings' } )
		).toBeVisible();
		await expectAxeClean( page, 'Settings' );
	} );
} );

/**
 * Whether the page scrolls sideways.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<boolean>} True when it does.
 */
const scrollsSideways = ( page ) =>
	page.evaluate(
		() =>
			document.documentElement.scrollWidth >
			document.documentElement.clientWidth + 1
	);

for ( const [ label, size ] of [
	[ '320 pixels wide', { width: 320, height: 720 } ],
	// 400 per cent of a 1280 by 1024 window is a 320 by 256 CSS viewport.
	[ '400 per cent zoom', { width: 320, height: 256 } ],
] ) {
	test.describe( label, () => {
		test.use( { viewport: size } );

		for ( const panel of [ '', 'history', 'categories', 'settings' ] ) {
			test( `${ panel || 'catalogue' } has no sideways page scroll and every control is reachable`, async ( {
				page,
			} ) => {
				await page.goto(
					'/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce' +
						( panel ? `&panel=${ panel }` : '' )
				);
				await expect(
					page.locator( '#bulkcat-app .bulkcat-app' )
				).toBeVisible();
				await page.waitForTimeout( 2000 );
				expect( await scrollsSideways( page ) ).toBe( false );
				if ( ! panel ) {
					// The category panel is a control that opens it as a sheet.
					await expect(
						page.locator( 'button[aria-haspopup="dialog"]', {
							hasText: 'Categories',
						} )
					).toBeVisible();
				}
				// Every enabled control in the plugin's part of the page can be
				// scrolled to and is not clipped off the side.
				const offside = await page.evaluate( () =>
					Array.from(
						document.querySelectorAll(
							'#bulkcat-app a[href], #bulkcat-app button:not([disabled]), #bulkcat-app input:not([type=hidden]):not([disabled]), #bulkcat-app select'
						)
					)
						.filter( ( node ) => node.offsetParent !== null )
						.filter(
							( node ) =>
								! node.closest(
									'.dataviews-view-table, .bulkcat-catalogue__table'
								)
						)
						.filter( ( node ) => {
							const box = node.getBoundingClientRect();
							return (
								box.right >
									document.documentElement.clientWidth + 1 ||
								box.left < -1
							);
						} )
						.map(
							( node ) =>
								node.getAttribute( 'aria-label' ) ||
								node.textContent.trim().slice( 0, 40 )
						)
				);
				expect( offside ).toEqual( [] );
			} );
		}
	} );
}

// T113 (WCAG 2.4.11 Focus Not Obscured): a control that takes the focus is
// scrolled clear of the sticky selection bar, never left beneath it.
test( 'a focused row is never hidden under the sticky selection bar', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1440, height: 520 } );
	await openScreen( page );
	await page.getByRole( 'checkbox', { name: SEED.simple( 1 ) } ).check();
	const bar = page.getByRole( 'region', { name: 'Selection' } );
	await expect( bar ).toContainText( '1 product selected' );
	const target = page.getByRole( 'checkbox', { name: SEED.simple( 3 ) } );
	// Scroll until the row sits just under the admin bar, behind the sticky bar.
	await target.evaluate( ( node ) =>
		window.scrollBy( 0, node.getBoundingClientRect().top - 40 )
	);
	const hidden = await bar.boundingBox();
	const before = await target.boundingBox();
	expect( before.y ).toBeLessThan( hidden.y + hidden.height );
	// Keyboard focus reaches it: it is scrolled into the clear.
	await target.evaluate( ( node ) => node.focus() );
	await expect( target ).toBeFocused();
	const shown = await bar.boundingBox();
	const after = await target.boundingBox();
	expect( after.y ).toBeGreaterThanOrEqual( shown.y + shown.height );
} );

test( 'animation stops for reduced motion', async ( { page } ) => {
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	// Hold the products until the skeleton has been measured.
	let release;
	const held = new Promise( ( resolve ) => ( release = resolve ) );
	await page.route( /\/bulkcat\/v1\/products(\?|%3F|$)/, async ( route ) => {
		await held;
		await route.continue();
	} );
	await page.goto(
		'/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce'
	);
	const skeleton = page.locator( '.bulkcat-skel' ).first();
	await expect( skeleton ).toBeVisible();
	// Found and measured in one task of the page (T85): a redraw between
	// finding the node and reading its style would measure a detached node,
	// whose computed style is empty.
	const animation = await page.evaluate( () => {
		const node = document.querySelector( '.bulkcat-skel' );
		return node && node.isConnected
			? window.getComputedStyle( node ).animationName
			: null;
	} );
	release();
	expect( animation ).toBe( 'none' );
} );

// SPEC §14: text 4.5:1 and components 3:1 in both admin colour schemes. The
// site's default is Modern; the classic Default ("fresh") scheme is checked
// too by switching the administrator's own scheme through WP-CLI and back.
test.describe( 'in the Default admin colour scheme', () => {
	test.skip( ! process.env.WP_CMD, 'Needs WP_CMD to set the colour scheme.' );
	let was = '';
	test.beforeAll( () => {
		const { execSync } = require( 'child_process' );
		const user = process.env.BULKCAT_E2E_USER || 'admin';
		try {
			was = execSync(
				`${ process.env.WP_CMD } user meta get ${ user } admin_color`,
				{ encoding: 'utf8' }
			).trim();
		} catch {
			was = '';
		}
		execSync(
			`${ process.env.WP_CMD } user meta update ${ user } admin_color fresh`,
			{ stdio: 'ignore' }
		);
	} );
	test.afterAll( () => {
		const { execSync } = require( 'child_process' );
		const user = process.env.BULKCAT_E2E_USER || 'admin';
		execSync(
			was
				? `${ process.env.WP_CMD } user meta update ${ user } admin_color ${ was }`
				: `${ process.env.WP_CMD } user meta delete ${ user } admin_color`,
			{ stdio: 'ignore' }
		);
	} );

	test( 'catalogue, selection and bulk panel', async ( { page } ) => {
		await openScreen( page );
		await expect( page.locator( 'body.admin-color-fresh' ) ).toHaveCount(
			1
		);
		await page.getByRole( 'checkbox', { name: SEED.simple( 1 ) } ).check();
		await expect(
			page.getByRole( 'region', { name: 'Selection' } )
		).toContainText( '1 product selected' );
		await expectAxeClean( page, 'the catalogue in Default colours' );
		await page
			.getByRole( 'button', { name: 'Make a change to these' } )
			.click();
		await expect(
			page.getByRole( 'dialog', { name: 'Bulk change' } )
		).toBeVisible();
		await expectAxeClean( page, 'the bulk panel in Default colours' );
	} );

	test( 'history and settings', async ( { page } ) => {
		await openScreen( page, 'history' );
		await page.waitForSelector( '.bulkcat-batch, .bulkcat-history__empty' );
		await expectAxeClean( page, 'History in Default colours' );
		await openScreen( page, 'settings' );
		await expect(
			page.getByRole( 'button', { name: 'Save settings' } )
		).toBeVisible();
		await expectAxeClean( page, 'Settings in Default colours' );
	} );
} );
