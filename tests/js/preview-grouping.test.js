/**
 * Ticket 133 bug 4: the preview lists each product once, with all its
 * steps; steps on the same field read from the value before the first to
 * the value after the last.
 */
import { groupSample } from '../../assets/src/panels/bulk/sample-rows';

const row = ( id, field, current, next, state ) => ( {
	object_id: id,
	object_type: 'product',
	name: 'Pot ' + id,
	field,
	current,
	new: next,
	state,
	reason: '',
} );

describe( 'preview sample grouping', () => {
	it( 'gives one row per product with every step', () => {
		// Add Pots (76) then remove Saucepans (78): 2 products, 4 route rows.
		const groups = groupSample( [
			row( 1, 'category_ids', [ 78 ], [ 78, 76 ], 'changed' ),
			row( 1, 'category_ids', [ 78, 76 ], [ 76 ], 'changed' ),
			row( 2, 'category_ids', [ 76, 78 ], [ 76, 78 ], 'already' ),
			row( 2, 'category_ids', [ 76, 78 ], [ 76 ], 'changed' ),
		] );
		expect( groups.map( ( group ) => group.object_id ) ).toEqual( [
			1, 2,
		] );
		expect( groups[ 0 ].lines ).toHaveLength( 1 );
		expect( groups[ 0 ].lines[ 0 ] ).toMatchObject( {
			current: [ 78 ],
			new: [ 76 ],
			state: 'changed',
		} );
		expect( groups[ 1 ].lines[ 0 ] ).toMatchObject( {
			current: [ 76, 78 ],
			new: [ 76 ],
			state: 'changed',
		} );
		expect( groups.map( ( group ) => group.state ) ).toEqual( [
			'changed',
			'changed',
		] );
	} );

	it( 'keeps steps on other fields and refusals as their own lines', () => {
		const groups = groupSample( [
			row( 3, 'regular_price', '10', '12', 'changed' ),
			row( 3, 'sale_price', '9', '9', 'already' ),
			{ ...row( 4, 'regular_price', '', '', 'failed' ), reason: 'No' },
			row( 4, 'regular_price', '', '5', 'changed' ),
		] );
		expect( groups[ 0 ].lines.map( ( line ) => line.field ) ).toEqual( [
			'regular_price',
			'sale_price',
		] );
		expect( groups[ 1 ].lines.map( ( line ) => line.state ) ).toEqual( [
			'failed',
			'changed',
		] );
		expect( groups[ 1 ].state ).toBe( 'changed' );
		expect(
			groupSample( [ row( 5, 'sku', 'A', 'A', 'already' ) ] )[ 0 ].state
		).toBe( 'already' );
	} );
} );
