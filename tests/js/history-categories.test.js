/**
 * History in plain words for categories (ticket 133 bug 3): the route's
 * headline names the categories, its words stand for stored values, the
 * first column says Category for category rows, and entities decode.
 */
import {
	decodeEntities,
	describeBatch,
	formatValue,
	objectHeading,
	rowValue,
} from '../../assets/src/panels/history/describe';

describe( 'history words for categories', () => {
	it( 'uses the route headline instead of "Changed some products"', () => {
		expect(
			describeBatch( {
				source: 'tree',
				operations: [
					{ action: 'edit_category', args: { name: 'Golf shirts' } },
				],
				headline: 'Renamed category Golf to Golf shirts',
			} )
		).toBe( 'Renamed category Golf to Golf shirts' );
		expect(
			describeBatch( {
				source: 'bulk',
				operations: [
					{
						action: 'add_terms',
						args: { field: 'category_ids', terms: [ 4 ] },
					},
				],
				headline: 'Moved 15 products from Saucepans to Kitchen › Pots',
			} )
		).toBe( 'Moved 15 products from Saucepans to Kitchen › Pots' );
	} );

	it( 'shows the route words for a value, else the formatted value', () => {
		expect(
			rowValue(
				{
					old_value: [ 228, 78 ],
					old_text: 'Shirts, Saucepans',
				},
				'old'
			)
		).toBe( 'Shirts, Saucepans' );
		expect( rowValue( { new_value: '12.50' }, 'new' ) ).toBe( '12.50' );
		expect( rowValue( { new_value: '', new_text: '' }, 'new' ) ).toBe(
			'Empty'
		);
	} );

	it( 'heads the first column by what the rows are', () => {
		expect( objectHeading( [ { object_type: 'term' } ] ) ).toBe(
			'Category'
		);
		expect(
			objectHeading( [
				{ object_type: 'term' },
				{ object_type: 'product' },
			] )
		).toBe( 'Product or category' );
		expect( objectHeading( [ { object_type: 'product' } ] ) ).toBe(
			'Product'
		);
	} );

	it( 'decodes entities a person should read as characters', () => {
		expect(
			decodeEntities( 'Pots &amp; Pans &#8211; &quot;new&quot;' )
		).toBe( 'Pots & Pans – "new"' );
		expect( formatValue( 'Bed &amp; Bath' ) ).toBe( 'Bed & Bath' );
		expect( decodeEntities( '&notanentity; &' ) ).toBe( '&notanentity; &' );
	} );
} );
