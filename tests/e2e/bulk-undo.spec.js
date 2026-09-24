/* eslint-disable @wordpress/no-global-active-element -- this code runs in the page, through evaluate(). */
/**
 * The whole path from filter to select to bulk change to preview to apply to
 * undo, by keyboard alone (SPEC §14, §16.4; T49, T57, T59, T75): every step
 * is a key press, focus returns to what opened the panel, and the catalogue
 * is read through GET /products after the change and after the undo.
 */
const { test, expect } = require( '@playwright/test' );
const {
	SEED,
	openScreen,
	runQueue,
	untilVisible,
	tabTo,
	focused,
	productByName,
} = require( './helpers' );

test( 'keyboard-only bulk change and undo', async ( { page } ) => {
	// Nothing left over from an earlier run is still waiting to be written.
	runQueue();
	await openScreen( page );
	const before = {
		one: await productByName( page, SEED.simple( 1 ) ),
		two: await productByName( page, SEED.simple( 2 ) ),
	};
	const keys = page.keyboard;

	// Skip the admin menu, then search.
	await keys.press( 'Tab' );
	await expect(
		page.getByRole( 'link', { name: 'Skip to main content' } )
	).toBeFocused();
	await keys.press( 'Enter' );
	const search = page.getByRole( 'searchbox', {
		name: 'Search by name or SKU',
	} );
	await tabTo( page, search );
	await keys.type( 'Probe Simple 0' );
	await expect(
		page.getByRole( 'checkbox', { name: SEED.simple( 10 ) } )
	).toHaveCount( 0 );

	// Tick 01, then the arrow key moves up the column to 02.
	const one = page.getByRole( 'checkbox', { name: SEED.simple( 1 ) } );
	await tabTo( page, one );
	await keys.press( 'Space' );
	await keys.press( 'ArrowUp' );
	await expect(
		page.getByRole( 'checkbox', { name: SEED.simple( 2 ) } )
	).toBeFocused();
	await keys.press( 'Space' );
	await expect( page.getByText( '2 products selected' ) ).toBeVisible();

	// The selection bar sits above the table.
	const makeChange = page.getByRole( 'button', {
		name: 'Make a change to these',
	} );
	await tabTo( page, makeChange, { back: true } );
	await keys.press( 'Enter' );
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await expect( panel ).toBeVisible();

	// Escape closes it and gives focus back to its opener (SPEC §14).
	await keys.press( 'Escape' );
	await expect( panel ).toBeHidden();
	await expect( makeChange ).toBeFocused();
	await keys.press( 'Enter' );
	await expect( panel ).toBeVisible();

	// Choose Pricing › Increase or decrease with the arrows in the radio group.
	const first = panel.getByRole( 'radio' ).first();
	await tabTo( page, first );
	const increase = panel
		.getByRole( 'radio', { name: /^Increase or decrease/ } )
		.first();
	for ( let i = 0; i < 6 && ! ( await increase.isChecked() ); i++ ) {
		await keys.press( 'ArrowDown' );
	}
	await expect( increase ).toBeChecked();
	await tabTo( page, panel.getByRole( 'textbox', { name: 'How much' } ) );
	await keys.type( '1' );

	// Tab stays inside the slide-over while it is open.
	await tabTo(
		page,
		panel.getByRole( 'button', { name: 'Check the change' } )
	);
	await keys.press( 'Enter' );
	const apply = panel.getByRole( 'button', { name: 'Apply to 2 products' } );
	await expect( apply ).toBeEnabled();
	await expect( panel.getByRole( 'table' ) ).toContainText(
		SEED.simple( 1 )
	);
	for ( let i = 0; i < 40; i++ ) {
		await keys.press( 'Tab' );
		expect(
			await page.evaluate(
				() => !! document.activeElement.closest( '.bulkcat-bulk' )
			),
			'focus stays in the bulk panel'
		).toBe( true );
	}
	await tabTo( page, apply );
	await keys.press( 'Enter' );

	// Done: the change is applied and offers its undo.
	const undo = panel.getByRole( 'button', { name: 'Undo this change' } );
	await untilVisible( undo );
	const after = await productByName( page, SEED.simple( 1 ) );
	expect( Number( after.regular_price ) ).toBeCloseTo(
		Number( before.one.regular_price ) + 1,
		2
	);
	expect(
		Number(
			( await productByName( page, SEED.simple( 2 ) ) ).regular_price
		)
	).toBeCloseTo( Number( before.two.regular_price ) + 1, 2 );

	// Undo this change goes to History and starts the undo there.
	await tabTo( page, undo );
	await keys.press( 'Enter' );
	const confirm = page.getByRole( 'dialog', { name: 'Undo this change?' } );
	await expect( confirm ).toBeVisible( { timeout: 30_000 } );
	await tabTo(
		page,
		confirm.getByRole( 'button', { name: 'Undo', exact: true } )
	);
	await keys.press( 'Enter' );
	await expect( confirm ).toBeHidden();
	await expect
		.poll(
			async () => {
				runQueue();
				return Number(
					( await productByName( page, SEED.simple( 1 ) ) )
						.regular_price
				);
			},
			{ timeout: 90_000 }
		)
		.toBeCloseTo( Number( before.one.regular_price ), 2 );
	expect(
		Number(
			( await productByName( page, SEED.simple( 2 ) ) ).regular_price
		)
	).toBeCloseTo( Number( before.two.regular_price ), 2 );
	expect( ( await focused( page ) ).tag ).not.toBe( 'BODY' );
} );
