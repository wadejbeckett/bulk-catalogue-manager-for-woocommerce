/**
 * The Categories tab as a workspace (SPEC §4.7) in a real browser: the tree
 * and the details pane side by side, Expand all, a category's details and
 * products, choosing several by keyboard, axe-core on each state; and All
 * fields: WordPress's own category form in the pane, carrying a field another
 * plugin adds through product_cat_edit_form_fields, which saves through
 * WordPress and refreshes the pane.
 *
 * The All fields journey installs a throwaway must-use plugin ("Sample
 * category field") on the test site and removes it afterwards, so it runs
 * only on a fixture opted in with BULKCAT_E2E_FIXTURE=1 and WP_CMD
 * (lifecycle/guard.js). It is not part of the plugin.
 */
const { test, expect } = require( '@playwright/test' );
const { SCREEN, expectAxeClean } = require( './helpers' );
const { fixtureCheck, php } = require( './lifecycle/support' );

const FIELD_PLUGIN = 'bulkcat-e2e-sample-term-field.php';

const FIELD_SOURCE = `<?php
/* Plugin Name: Sample category field (throwaway test site only) */
add_action( 'product_cat_edit_form_fields', static function ( $term ) {
	$value = (string) get_term_meta( $term->term_id, 'sample_note', true );
	wp_nonce_field( 'sample_note_' . $term->term_id, 'sample_note_nonce' );
	echo '<tr class="form-field"><th scope="row"><label for="sample-note">Sample note</label></th><td><input name="sample_note" id="sample-note" type="text" value="' . esc_attr( $value ) . '" /></td></tr>';
} );
add_action( 'edited_product_cat', static function ( $term_id ) {
	if ( ! isset( $_POST['sample_note'], $_POST['sample_note_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sample_note_nonce'] ) ), 'sample_note_' . $term_id ) ) {
		return;
	}
	update_term_meta( $term_id, 'sample_note', sanitize_text_field( wp_unslash( $_POST['sample_note'] ) ) );
} );
`;

/**
 * Open the Categories tab and wait for its tree.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openWorkspace( page ) {
	await page.goto( `${ SCREEN }&panel=categories` );
	await expect( page.locator( '.bulkcat-cat-workspace' ) ).toBeVisible();
	await expect( page.getByRole( 'tree' ) ).toBeVisible();
}

/**
 * The first category that has subcategories, as its tree row.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} Its treeitem.
 */
const firstBranch = ( page ) =>
	page.locator( '[role="treeitem"][aria-expanded]' ).first();

const nameOf = async ( item ) =>
	( await item.getAttribute( 'aria-label' ) ).replace(
		/, [\d,]+ products?$/,
		''
	);

test.describe( 'categories workspace', () => {
	test( 'shows the tree and the details side by side', async ( { page } ) => {
		await openWorkspace( page );
		const tree = await page
			.locator( '.bulkcat-cat-workspace__tree' )
			.boundingBox();
		const pane = await page
			.locator( '.bulkcat-cat-workspace__pane' )
			.boundingBox();
		expect( pane.x ).toBeGreaterThan( tree.x + tree.width - 1 );
		expect( tree.width ).toBeGreaterThan( 340 );
		await expectAxeClean( page, 'the categories workspace' );

		const before = await page.getByRole( 'treeitem' ).count();
		await page.getByRole( 'button', { name: 'Expand all' } ).click();
		expect( await page.getByRole( 'treeitem' ).count() ).toBeGreaterThan(
			before
		);

		const branch = firstBranch( page );
		const name = await nameOf( branch );
		await branch.locator( '.bulkcat-cat-name' ).click();
		const region = page.getByRole( 'region', { name } );
		await expect( region ).toBeVisible();
		await expect(
			region.getByRole( 'navigation', { name: 'Category path' } )
		).toBeVisible();
		await expect(
			region
				.getByRole( 'table', { name: `Products in ${ name }` } )
				.or( region.getByText( /^No products are in this category/ ) )
		).toBeVisible();
		await expectAxeClean( page, 'a chosen category' );
	} );

	test( 'chooses several categories from the keyboard', async ( {
		page,
	} ) => {
		await openWorkspace( page );
		const first = page.getByRole( 'treeitem' ).first();
		await first.focus();
		await first.press( 'Enter' );
		await first.press( 'ArrowDown' );
		await page.keyboard.press( 'Space' );
		const region = page.getByRole( 'region', {
			name: '2 categories selected',
		} );
		await expect( region ).toBeVisible();
		await expect(
			region.getByRole( 'link', {
				name: 'Show their products in the product grid',
			} )
		).toBeVisible();
		await expectAxeClean( page, 'several chosen categories' );
		await region.getByRole( 'button', { name: 'Clear selection' } ).click();
		await expect(
			page.getByText(
				'Choose a category on the left to see its details and products.'
			)
		).toBeVisible();
	} );

	test( 'All fields shows and saves another plugin’s category field', async ( {
		page,
	} ) => {
		test.skip( !! fixtureCheck(), fixtureCheck() );
		php(
			`wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/' . $in['file'], $in['source'] ); echo wp_json_encode( true );`,
			{ file: FIELD_PLUGIN, source: FIELD_SOURCE }
		);
		let termId = 0;
		try {
			await openWorkspace( page );
			const item = page.getByRole( 'treeitem' ).first();
			const name = await nameOf( item );
			termId = Number(
				( await item.getAttribute( 'id' ) ).replace( /\D+/g, '' )
			);
			await item.locator( '.bulkcat-cat-name' ).click();
			const region = page.getByRole( 'region', { name } );
			await region.getByRole( 'button', { name: 'All fields' } ).click();
			const frame = page.frameLocator(
				`iframe[title="All fields for ${ name }"]`
			);
			const field = frame.getByLabel( 'Sample note' );
			await expect( field ).toBeVisible();
			await expect( frame.locator( '#adminmenumain' ) ).toBeHidden();
			await expect( frame.locator( '#wpadminbar' ) ).toBeHidden();
			const value = `Saved from the workspace ${ Date.now() }`;
			await field.fill( value );
			await frame.getByRole( 'button', { name: 'Update' } ).click();
			await expect(
				region.getByText(
					'Saved through WordPress’s category form. The details and the tree are up to date.'
				)
			).toBeVisible();
			expect(
				php(
					`echo wp_json_encode( get_term_meta( (int) $in, 'sample_note', true ) );`,
					termId
				)
			).toBe( value );
		} finally {
			php(
				`$f = WPMU_PLUGIN_DIR . '/' . $in['file']; if ( file_exists( $f ) ) { unlink( $f ); } if ( $in['term'] ) { delete_term_meta( (int) $in['term'], 'sample_note' ); } echo wp_json_encode( true );`,
				{ file: FIELD_PLUGIN, term: termId }
			);
		}
	} );
} );
