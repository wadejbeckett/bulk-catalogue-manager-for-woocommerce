/**
 * Historical status filters remain exact after native Status14. Synthetic
 * legacy metadata is labelled as such here; actual released batches/receipts
 * are created by native-status-upgrade.sh, not fabricated by this UI test.
 * Ordinary Status journeys live in native-status.spec.js.
 */
const { randomUUID } = require( 'crypto' );
const { test, expect } = require( '@playwright/test' );
const { openScreen, rest } = require( './helpers' );
const { php, fixtureCheck } = require( './lifecycle/support' );

test( 'legacy saved filters stay exact and a new edit ignores a remembered target', async ( {
	page,
} ) => {
	if ( fixtureCheck() ) {
		throw new Error( fixtureCheck() );
	}
	const run = randomUUID();
	const ids = php(
		`
		$out=[];
		foreach ( [ 'off', 'changed_outside', 'plain' ] as $name ) {
			$p=new WC_Product_Simple(); $p->set_name( 'Legacy view ' . $in . ' ' . $name );
			$p->set_status( 'changed_outside' === $name ? 'private' : 'draft' );
			$p->update_meta_data( '_bulkcat_native_probe', $in ); $id=$p->save();
			if ( 'plain' !== $name ) { $p->update_meta_data( '_bulkcat_availability_off', \\BulkCatalogueManager\\Availability\\OffRecord::create( $id, 'pending', '2026-09-01T08:00:00Z', 1 )->to_json() ); $p->save_meta_data(); }
			$out[$name]=$id;
		}
		echo wp_json_encode( $out );`,
		run
	);
	const views = [];
	try {
		await openScreen( page );
		for ( const predicate of [ 'off', 'changed_outside' ] ) {
			const view = {
				type: 'table',
				perPage: 25,
				search: run,
				filters: [
					{
						field: 'status',
						operator: 'isAny',
						value: [ predicate ],
					},
				],
			};
			const saved = await rest( page, 'views', {
				method: 'POST',
				data: { name: `${ predicate } ${ run }`, view },
			} );
			expect( saved.status ).toBe( 201 );
			views.push( saved.json.item.id );
			await page.reload();
			await page
				.getByRole( 'group', { name: 'Saved views' } )
				.getByRole( 'button', {
					name: `${ predicate } ${ run }`,
					exact: true,
				} )
				.click();
			await expect(
				page.locator( '.dataviews-view-table tbody tr' )
			).toHaveCount( 1 );
			await expect(
				page.getByRole( 'link', {
					name: `Legacy view ${ run } ${ predicate }`,
					exact: true,
				} )
			).toBeVisible();
			await expect( page.locator( '.bulkcat-chips' ) ).toContainText(
				'legacy status filter'
			);
			const query = new URLSearchParams( {
				search: run,
				'status[operator]': 'isAny',
				'status[value][]': predicate,
				'fields[]': 'status',
			} );
			const found = await rest( page, `products?${ query }` );
			expect( found.json.items.map( ( item ) => item.id ) ).toEqual( [
				ids[ predicate ],
			] );
			const again = await rest( page, 'views' );
			expect(
				again.json.items.find(
					( item ) => item.id === saved.json.item.id
				).view
			).toEqual( saved.json.item.view );
		}
		const refused = await rest( page, `products/${ ids.changed_outside }`, {
			method: 'PATCH',
			data: { status: 'publish', keep_changed_status: true },
		} );
		expect( refused.status ).toBe( 400 );
		expect( refused.json.message ).toMatch( /Reload|review/ );
		const changed = await rest( page, `products/${ ids.off }`, {
			method: 'PATCH',
			data: { status: 'publish' },
		} );
		expect( changed.status ).toBe( 200 );
		expect( changed.json.item.status ).toBe( 'publish' );
		expect( changed.json.item.off_state ).toBeNull();
		const history = await rest(
			page,
			`batches/${ changed.json.batch_id }/changes`
		);
		expect(
			history.json.items.map( ( item ) => item.field ).sort()
		).toEqual( [ 'availability_off', 'status' ] );
	} finally {
		for ( const id of views ) {
			await rest( page, `views/${ id }`, { method: 'DELETE' } );
		}
		php(
			`foreach ( $in['ids'] as $id ) { if ( get_post_meta( $id, '_bulkcat_native_probe', true ) !== $in['run'] ) { throw new RuntimeException( 'Ownership mismatch' ); } wp_delete_post( $id, true ); } echo 'true';`,
			{ ids: Object.values( ids ), run }
		);
	}
} );
