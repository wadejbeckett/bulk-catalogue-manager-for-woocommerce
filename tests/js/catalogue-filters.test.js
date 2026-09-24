/**
 * T41: search, the registry's filters in DataViews, the one mapping from a
 * view to GET /products arguments (viewToQuery()), and the chips that read
 * each filter as a sentence and clear it.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';
import {
	defaultView,
	descriptorsOf,
	fieldsFromRegistry,
} from '../../assets/src/panels/catalogue/fields';
import {
	filterArg,
	filterChips,
	selectionQuery,
	viewToQuery,
	withFilters,
	withoutFilter,
} from '../../assets/src/panels/catalogue/toolbar';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const {
	REGISTRY,
	CATEGORIES,
	page,
	route,
	calls,
} = require( './catalogue-fixture' );

const descriptors = descriptorsOf( REGISTRY );
const base = defaultView( REGISTRY, {} );
const byKey = ( key ) => descriptors.find( ( field ) => field.key === key );
const NAMES = {
	categories: new Map( [
		[ 2, 'Clothing' ],
		[ 3, 'T-Shirts' ],
		[ 4, 'Golf Shirts' ],
	] ),
};

/**
 * Every §4.1 filter: the view filter DataViews produces, the route argument
 * it becomes, and the chip sentence it reads as.
 */
const TABLE = [
	[
		'category_ids',
		{ operator: 'isAny', value: [ 2 ] },
		{ operator: 'isAny', value: [ 2 ] },
		'Category is Clothing or its sub-categories',
	],
	[
		'category_ids',
		{ operator: 'isAny', value: [ 3, 4 ] },
		{ operator: 'isAny', value: [ 3, 4 ] },
		'Category is T-Shirts or Golf Shirts, or their sub-categories',
	],
	[
		'category_ids',
		{ operator: 'isNone', value: [ 3 ] },
		{ operator: 'isNone', value: [ 3 ] },
		'Category is not T-Shirts or its sub-categories',
	],
	[
		'tag_ids',
		{ operator: 'isAll', value: [ '7', 8 ] },
		{ operator: 'isAll', value: [ 7, 8 ] },
		'Tags include all of 7 and 8',
	],
	[
		'type',
		{ operator: 'isAny', value: [ 'variable' ] },
		{ operator: 'isAny', value: [ 'variable' ] },
		'Type is Variable product',
	],
	[
		'status',
		{ operator: 'isNone', value: [ 'draft', 'pending' ] },
		{ operator: 'isNone', value: [ 'draft', 'pending' ] },
		'Status is not Draft or Pending review',
	],
	[
		'catalog_visibility',
		{ operator: 'isAny', value: [ 'hidden' ] },
		{ operator: 'isAny', value: [ 'hidden' ] },
		'Catalogue visibility is Hidden',
	],
	[
		'featured',
		{ operator: 'is', value: true },
		{ operator: 'is', value: true },
		'Featured is on',
	],
	[
		'stock_status',
		{ operator: 'isAny', value: [ 'outofstock', 'onbackorder' ] },
		{ operator: 'isAny', value: [ 'outofstock', 'onbackorder' ] },
		'Stock status is Out of stock or On backorder',
	],
	[
		'manage_stock',
		{ operator: 'is', value: false },
		{ operator: 'is', value: false },
		'Manage stock is off',
	],
	[
		'regular_price',
		{ operator: 'between', value: [ 10, 20.5 ] },
		{ operator: 'between', value: [ 10, 20.5 ] },
		'Regular price is between 10 and 20.5',
	],
	[
		'regular_price',
		{ operator: 'between', value: [ '', 50 ] },
		{ operator: 'lessThanOrEqual', value: 50 },
		'Regular price is 50 or less',
	],
	[
		'stock_quantity',
		{ operator: 'lessThanOrEqual', value: 0 },
		{ operator: 'lessThanOrEqual', value: 0 },
		'Stock quantity is 0 or less',
	],
	[
		'stock_quantity',
		{ operator: 'greaterThanOrEqual', value: '5' },
		{ operator: 'greaterThanOrEqual', value: 5 },
		'Stock quantity is 5 or more',
	],
	[
		'shipping_class_id',
		{ operator: 'isAny', value: [ 9 ] },
		{ operator: 'isAny', value: [ 9 ] },
		'Shipping class is 9',
	],
	[
		'tax_class',
		{ operator: 'isAny', value: [ '' ] },
		{ operator: 'isAny', value: [ '' ] },
		'Tax class is Standard',
	],
	[
		'date_modified',
		{ operator: 'inThePast', value: { value: 7, unit: 'days' } },
		{ operator: 'inThePast', value: { value: 7, unit: 'days' } },
		'Last modified is in the last 7 days',
	],
	[
		'date_modified',
		{ operator: 'inThePast', value: { value: 1, unit: 'months' } },
		{ operator: 'inThePast', value: { value: 1, unit: 'months' } },
		'Last modified is in the last 1 month',
	],
	[
		'date_modified',
		{ operator: 'after', value: '2026-09-01T00:00:00.000Z' },
		{ operator: 'after', value: '2026-09-01 00:00:00' },
		'Last modified is after 1 September 2026',
	],
	[
		'date_created',
		{ operator: 'before', value: '2026-01-31' },
		{ operator: 'before', value: '2026-01-31' },
		'Date created is before 31 January 2026',
	],
];

beforeAll( () => {
	// Dates and lists read in the admin's language, <html lang>.
	document.documentElement.lang = 'en-GB';
} );

afterAll( () => {
	document.documentElement.lang = '';
} );

beforeEach( () => {
	apiFetch.mockReset();
	route( apiFetch, {
		fields: () => REGISTRY,
		products: () => page(),
		categories: () => CATEGORIES,
	} );
} );

describe( 'view to route arguments', () => {
	it.each( TABLE )(
		'maps a %s filter to its argument and its chip',
		( field, filter, arg, sentence ) => {
			const view = { ...base, filters: [ { field, ...filter } ] };
			const query = viewToQuery( view, descriptors );
			expect( query[ field ] ).toEqual( arg );
			const chips = filterChips( view, descriptors, NAMES );
			expect( chips.map( ( chip ) => chip.sentence ) ).toEqual( [
				sentence,
			] );
		}
	);

	it( 'covers every filter the registry offers', () => {
		const offered = descriptors
			.filter( ( field ) => field.dataviews.filterBy )
			.map( ( field ) => field.key );
		expect(
			[ ...new Set( TABLE.map( ( row ) => row[ 0 ] ) ) ].sort()
		).toEqual( offered.sort() );
	} );

	it( 'sends nothing for a filter that is not complete or not offered', () => {
		const incomplete = [
			{ field: 'regular_price', operator: 'between', value: [ '', '' ] },
			{ field: 'regular_price', operator: 'between', value: [ 9, 2 ] },
			{
				field: 'stock_quantity',
				operator: 'lessThanOrEqual',
				value: 1.5,
			},
			{ field: 'status', operator: 'isAny', value: [] },
			{ field: 'featured', operator: 'is', value: undefined },
			{ field: 'date_modified', operator: 'after', value: 'soon' },
			{
				field: 'date_modified',
				operator: 'inThePast',
				value: { value: 0, unit: 'days' },
			},
			{
				field: 'date_modified',
				operator: 'inThePast',
				value: { value: 3, unit: 'hours' },
			},
			{ field: 'status', operator: 'between', value: [ 1, 2 ] },
			{ field: 'sku', operator: 'isAny', value: [ 'X' ] },
			{ field: 'nonsense', operator: 'isAny', value: [ 'X' ] },
		];
		incomplete.forEach( ( filter ) =>
			expect( filterArg( filter, byKey( filter.field ) ) ).toBeNull()
		);
		const view = { ...base, filters: incomplete };
		expect(
			Object.keys( viewToQuery( view, descriptors ) ).sort()
		).toEqual( Object.keys( viewToQuery( base, descriptors ) ).sort() );
		expect( filterChips( view, descriptors ) ).toEqual( [] );
	} );

	it( 'sends the trimmed search on name and SKU, and reads it as a chip', () => {
		const view = { ...base, search: '  tee ' };
		expect( viewToQuery( view, descriptors ).search ).toBe( 'tee' );
		expect( filterChips( view, descriptors )[ 0 ].sentence ).toBe(
			'Name or SKU contains “tee”'
		);
	} );

	it( 'lets the category panel’s argument win over a filter on the same field', () => {
		const view = {
			...base,
			filters: [
				{ field: 'category_ids', operator: 'isNone', value: [ 3 ] },
			],
		};
		const extra = { category_ids: { operator: 'isAny', value: [ 2 ] } };
		expect( viewToQuery( view, descriptors, extra ).category_ids ).toEqual(
			extra.category_ids
		);
		expect( withoutFilter( view, 'category_ids' ).filters ).toEqual( [] );
	} );

	it( 'gives POST /selection the conditions without paging, sort or fields', () => {
		const view = {
			...base,
			page: 4,
			search: 'tee',
			sort: { field: 'sku', direction: 'desc' },
			filters: [
				{
					field: 'stock_quantity',
					operator: 'lessThanOrEqual',
					value: 0,
				},
			],
		};
		expect( selectionQuery( view, descriptors ) ).toEqual( {
			search: 'tee',
			stock_quantity: { operator: 'lessThanOrEqual', value: 0 },
		} );
	} );
} );

describe( 'filters offered through DataViews', () => {
	it( 'builds filterBy from the registry, with choices, switches and named terms', () => {
		const fields = withFilters(
			fieldsFromRegistry( REGISTRY ),
			descriptors,
			NAMES
		);
		const field = ( key ) => fields.find( ( each ) => each.id === key );
		expect( field( 'stock_status' ).filterBy.operators ).toEqual( [
			'isAny',
			'isNone',
		] );
		expect( field( 'stock_status' ).elements ).toHaveLength( 3 );
		expect( field( 'featured' ).elements.map( ( e ) => e.value ) ).toEqual(
			[ true, false ]
		);
		expect( field( 'regular_price' ).filterBy.operators ).toContain(
			'between'
		);
		expect( field( 'date_modified' ).filterBy.operators ).toContain(
			'inThePast'
		);
		expect( field( 'category_ids' ).elements ).toContainEqual( {
			value: 2,
			label: 'Clothing',
		} );
		expect( field( 'sku' ).filterBy ).toBe( false );
		// Terms the client cannot name yet are not offered.
		expect( field( 'tag_ids' ).filterBy ).toBe( false );
		expect( field( 'shipping_class_id' ).filterBy ).toBe( false );
	} );
} );

describe( 'filter chips in the catalogue', () => {
	const view = {
		search: 'tee',
		filters: [
			{ field: 'stock_quantity', operator: 'lessThanOrEqual', value: 0 },
			{ field: 'featured', operator: 'is', value: true },
		],
	};

	it( 'sends the filters, shows each as a sentence, and clears one at a time', async () => {
		render(
			<Catalogue settings={ { settings: {} } } initialView={ view } />
		);
		await screen.findByText( 'Classic Cotton T-Shirt' );
		expect( calls( apiFetch, 'products' )[ 0 ] ).toMatchObject( {
			search: 'tee',
			stock_quantity: { operator: 'lessThanOrEqual', value: '0' },
			featured: { operator: 'is', value: 'true' },
		} );
		const chips = document.querySelector( '.bulkcat-chips' );
		expect(
			Array.from( chips.querySelectorAll( 'li > span' ) ).map(
				( span ) => span.textContent
			)
		).toEqual( [
			'Name or SKU contains “tee”',
			'Stock quantity is 0 or less',
			'Featured is on',
		] );

		fireEvent.click(
			within( chips ).getByRole( 'button', {
				name: 'Clear the filter “Stock quantity is 0 or less”',
			} )
		);
		await waitFor( () =>
			expect( calls( apiFetch, 'products' ).length ).toBeGreaterThan( 1 )
		);
		const after = calls( apiFetch, 'products' ).at( -1 );
		expect( after.stock_quantity ).toBeUndefined();
		expect( after.search ).toBe( 'tee' );
		expect( after.featured ).toEqual( { operator: 'is', value: 'true' } );
		expect( after.page ).toBe( '1' );
	} );

	it( 'clears every filter and the search with Clear filters', async () => {
		render(
			<Catalogue settings={ { settings: {} } } initialView={ view } />
		);
		await screen.findByText( 'Classic Cotton T-Shirt' );
		fireEvent.click(
			within( document.querySelector( '.bulkcat-chips' ) ).getByRole(
				'button',
				{ name: 'Clear filters' }
			)
		);
		await waitFor( () =>
			expect( document.querySelector( '.bulkcat-chips' ) ).toBeNull()
		);
		const after = calls( apiFetch, 'products' ).at( -1 );
		expect( after.search ).toBeUndefined();
		expect( after.featured ).toBeUndefined();
	} );

	it( 'offers the Filters control of DataViews', async () => {
		render( <Catalogue settings={ { settings: {} } } /> );
		await screen.findByText( 'Classic Cotton T-Shirt' );
		expect(
			screen.getByRole( 'button', { name: 'Add filter' } )
		).toBeTruthy();
	} );
} );
