/**
 * T40: the view object maps to GET /products arguments (SPEC §12.1).
 */
import {
	categoryArgs,
	clearFilters,
	isFiltered,
	toQuery,
} from '../../assets/src/panels/catalogue/query';
import {
	defaultView,
	descriptorsOf,
} from '../../assets/src/panels/catalogue/fields';

const { REGISTRY } = require( './catalogue-fixture' );

const descriptors = descriptorsOf( REGISTRY );
const base = defaultView( REGISTRY, {} );

describe( 'view to route arguments', () => {
	it( 'pins the default view', () => {
		expect( toQuery( base, descriptors ) ).toEqual( {
			page: 1,
			per_page: 25,
			fields: [
				'name',
				'status',
				// T93: no switch column by default, so neither it nor its
				// catalogue visibility companion is read.
				'category_ids',
				'regular_price',
				'sale_price',
				'stock_status',
				// T67–T68 (variations/registry.js withVariations()): the
				// stock quantity's condition, the type and variation count
				// that mark a variable row, and the price range.
				'manage_stock',
				'stock_quantity',
				'sku',
				'id',
				'type',
				'thumbnail',
				'price_html',
				'variation_count',
			],
		} );
	} );

	it( 'sends the page, size, trimmed search and sort', () => {
		const query = toQuery(
			{
				...base,
				page: 3,
				perPage: 100,
				search: '  shirt ',
				sort: { field: 'regular_price', direction: 'desc' },
			},
			descriptors
		);
		expect( query.page ).toBe( 3 );
		expect( query.per_page ).toBe( 100 );
		expect( query.search ).toBe( 'shirt' );
		expect( query.orderby ).toEqual( [ 'regular_price' ] );
		expect( query.order ).toEqual( [ 'desc' ] );
		expect(
			toQuery(
				{ ...base, sort: { field: 'sku', direction: 'asc' } },
				descriptors
			).order
		).toEqual( [ 'asc' ] );
	} );

	it( 'asks for a column the owner adds, and only fields the registry has', () => {
		const query = toQuery(
			{ ...base, fields: [ ...base.fields, 'units_sold', 'nonsense' ] },
			descriptors
		);
		expect( query.fields ).toContain( 'units_sold' );
		expect( query.fields ).not.toContain( 'nonsense' );
	} );

	it( 'knows when the view filters and how to clear it', () => {
		expect( isFiltered( base ) ).toBe( false );
		expect( isFiltered( { ...base, search: '  ' } ) ).toBe( false );
		expect( isFiltered( { ...base, search: 'x' } ) ).toBe( true );
		const filtered = {
			...base,
			page: 4,
			filters: [
				{ field: 'status', operator: 'isAny', value: [ 'draft' ] },
			],
		};
		expect( isFiltered( filtered ) ).toBe( true );
		expect(
			isFiltered( {
				...base,
				filters: [ { field: 'status', operator: 'isAny', value: [] } ],
			} )
		).toBe( false );
		expect( clearFilters( filtered ) ).toEqual( {
			...filtered,
			page: 1,
			search: '',
			filters: [],
		} );
	} );
} );

describe( 'category panel arguments', () => {
	it( 'send include_children: false for this category only (T62)', () => {
		expect( categoryArgs( { id: 3, includeChildren: true } ) ).toEqual( {
			category_ids: { operator: 'isAny', value: [ 3 ] },
		} );
		expect(
			categoryArgs( { id: 3, ids: [ 3, 4 ], includeChildren: false } )
		).toEqual( {
			category_ids: {
				operator: 'isAny',
				value: [ 3, 4 ],
				include_children: false,
			},
		} );
		expect( categoryArgs( null ) ).toEqual( {} );
	} );
} );
