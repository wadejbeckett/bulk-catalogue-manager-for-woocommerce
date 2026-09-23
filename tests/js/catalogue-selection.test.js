/**
 * T42: the header checkbox selects the page, the line above the table offers
 * every matching product through POST /selection, the sticky selection bar
 * counts in words and opens the bulk change panel on `{ ids }` or `{ token,
 * count }`, the selection survives paging, clears with a notice when the
 * filters change, and Show on site / Hide from site prefill the panel.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';
import {
	mergePageSelection,
	panelSelection,
	visibilityOperation,
} from '../../assets/src/panels/catalogue/selection';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const {
	REGISTRY,
	ROWS,
	CATEGORIES,
	page,
	route,
} = require( './catalogue-fixture' );

const SECOND = [
	{ ...ROWS[ 0 ], id: 21, name: 'Polo Shirt', sku: 'POL-1' },
	{ ...ROWS[ 1 ], id: 22, name: 'Rain Jacket', sku: 'RJ-2' },
];
const FILTERED = {
	filters: [
		{ field: 'stock_quantity', operator: 'lessThanOrEqual', value: 0 },
	],
};

beforeAll( () => {
	// Numbers group in the admin's language, <html lang>.
	document.documentElement.lang = 'en-GB';
} );

afterAll( () => {
	document.documentElement.lang = '';
} );

beforeEach( () => {
	apiFetch.mockReset();
	route( apiFetch, {
		fields: () => REGISTRY,
		products: ( query ) =>
			query.page === '2' ? page( SECOND, 1284 ) : page( ROWS, 1284 ),
		categories: () => CATEGORIES,
		selection: () => ( { count: 1284, token: 'abc.123' } ),
	} );
} );

async function renderLoaded( initialView = FILTERED ) {
	render(
		<Catalogue settings={ { settings: {} } } initialView={ initialView } />
	);
	await screen.findByText( 'Classic Cotton T-Shirt' );
}

const header = () =>
	within( screen.getByRole( 'table' ) ).getByRole( 'checkbox', {
		name: 'Select all',
	} );
const bar = () => screen.getByRole( 'region', { name: 'Selection' } );
const tick = ( name ) =>
	fireEvent.click(
		within(
			screen.getByRole( 'link', { name } ).closest( 'tr' )
		).getByRole( 'checkbox' )
	);
const selectionPosts = () =>
	apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter( ( options ) => options.url.split( '?' )[ 0 ] === 'selection' );

describe( 'selection model', () => {
	it( 'keeps other pages’ ids when DataViews answers for one page', () => {
		expect(
			mergePageSelection( [ '1', '2', '11' ], [ '12' ], [ '11', '12' ] )
		).toEqual( [ '1', '2', '12' ] );
		expect(
			mergePageSelection( [ '1', '11' ], [], [ '11', '12' ] )
		).toEqual( [ '1' ] );
	} );

	it( 'serialises either kind for the bulk panel', () => {
		expect(
			panelSelection( { ids: [ '11', '12' ], matching: null } )
		).toEqual( { kind: 'ids', ids: [ 11, 12 ] } );
		expect(
			panelSelection(
				{
					ids: [],
					matching: { status: 'ready', token: 't', count: 9 },
				},
				[ { label: 'Stock', sentence: 'Stock quantity is 0 or less' } ]
			)
		).toEqual( {
			kind: 'filter',
			token: 't',
			count: 9,
			filters: [
				{ label: 'Stock', value: 'Stock quantity is 0 or less' },
			],
		} );
	} );

	it( 'prefills Show on site and Hide from site as a set step on the switch', () => {
		const hide = visibilityOperation( REGISTRY, {}, false );
		expect( hide ).toMatchObject( {
			action: 'set_value',
			args: { field: 'visible_on_site', value: 'off' },
		} );
		expect( visibilityOperation( REGISTRY, {}, true ).args.value ).toBe(
			'on'
		);
		expect(
			visibilityOperation(
				{
					...REGISTRY,
					fields: REGISTRY.fields.filter(
						( field ) => field.key !== 'visible_on_site'
					),
				},
				{},
				true
			)
		).toBeNull();
	} );
} );

describe( 'selection in the catalogue', () => {
	it( 'selects the page, offers every match, exchanges it and opens the panel on the token', async () => {
		await renderLoaded();
		expect(
			screen.queryByRole( 'region', { name: 'Selection' } )
		).toBeNull();

		fireEvent.click( header() );
		expect( bar().textContent ).toContain( '2 products selected' );
		expect( bar().textContent ).toContain(
			'All 2 on this page are selected.'
		);

		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: 'Select all 1,284 products matching these filters.',
			} )
		);
		await waitFor( () =>
			expect( bar().textContent ).toContain(
				'All 1,284 products matching these filters are selected.'
			)
		);
		const [ post ] = selectionPosts();
		expect( post.method ).toBe( 'POST' );
		expect( post.data ).toEqual( {
			stock_quantity: { operator: 'lessThanOrEqual', value: 0 },
		} );

		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: 'Make a change to these',
			} )
		);
		const dialog = await screen.findByRole( 'dialog' );
		expect( dialog.textContent ).toContain(
			'All 1,284 products matching the current filter'
		);
		expect( dialog.textContent ).toContain( 'Stock quantity is 0 or less' );
	} );

	it( 'sends the token, not the ids, when the panel previews', async () => {
		route( apiFetch, {
			fields: () => REGISTRY,
			products: () => page( ROWS, 1284 ),
			categories: () => CATEGORIES,
			selection: () => ( { count: 1284, token: 'abc.123' } ),
			preview: () => new Promise( () => {} ),
		} );
		await renderLoaded();
		fireEvent.click( header() );
		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: /^Select all 1,284/,
			} )
		);
		await waitFor( () =>
			expect( bar().textContent ).toContain( 'are selected' )
		);
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: 'Hide from site' } )
		);
		const dialog = await screen.findByRole( 'dialog' );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: /^Check/ } )
		);
		await waitFor( () =>
			expect(
				apiFetch.mock.calls.some(
					( [ options ] ) => options.url === 'preview'
				)
			).toBe( true )
		);
		const preview = apiFetch.mock.calls.find(
			( [ options ] ) => options.url === 'preview'
		)[ 0 ];
		expect( preview.data.token ).toBe( 'abc.123' );
		expect( preview.data.ids ).toBeUndefined();
		expect( preview.data.operations ).toEqual( [
			expect.objectContaining( {
				action: 'set_value',
				args: expect.objectContaining( {
					field: 'visible_on_site',
					value: 'off',
				} ),
			} ),
		] );
	} );

	it( 'keeps ticked rows across pages and opens the panel on every id', async () => {
		await renderLoaded();
		tick( 'Classic Cotton T-Shirt' );
		expect( bar().textContent ).toContain( '1 product selected' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Next page' } ) );
		await screen.findByText( 'Polo Shirt' );
		expect( bar().textContent ).toContain( '1 product selected' );
		tick( 'Polo Shirt' );
		expect( bar().textContent ).toContain( '2 products selected' );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Previous page' } )
		);
		await screen.findByText( 'Classic Cotton T-Shirt' );
		expect(
			within(
				screen
					.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
					.closest( 'tr' )
			).getByRole( 'checkbox' ).checked
		).toBe( true );

		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: 'Make a change to these',
			} )
		);
		const dialog = await screen.findByRole( 'dialog' );
		expect( dialog.textContent ).toContain( '2 selected products' );
	} );

	it( 'clears the selection with a notice when a filter changes', async () => {
		await renderLoaded();
		fireEvent.click( header() );
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: /^Select all 1,284/ } )
		);
		await waitFor( () =>
			expect( bar().textContent ).toContain( 'are selected' )
		);
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Clear the filter “Stock quantity is 0 or less”',
			} )
		);
		await waitFor( () =>
			expect(
				document.querySelector( '.bcm-selection__notice' )?.textContent
			).toContain(
				'The selection was cleared because the filters changed.'
			)
		);
		expect(
			screen.queryByRole( 'region', { name: 'Selection' } )
		).toBeNull();
	} );

	it( 'clears with Clear, and narrows back with Select only this page', async () => {
		await renderLoaded();
		fireEvent.click( header() );
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: /^Select all 1,284/ } )
		);
		await waitFor( () =>
			expect( bar().textContent ).toContain( 'are selected' )
		);
		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: 'Select only this page',
			} )
		);
		expect( bar().textContent ).toContain( '2 products selected' );
		act( () => {
			fireEvent.click(
				within( bar() ).getByRole( 'button', { name: 'Clear' } )
			);
		} );
		expect(
			screen.queryByRole( 'region', { name: 'Selection' } )
		).toBeNull();
	} );

	it( 'prefills the bulk panel from Show on site', async () => {
		await renderLoaded();
		tick( 'Ladies Golf Shirt' );
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: 'Show on site' } )
		);
		const dialog = await screen.findByRole( 'dialog' );
		expect( dialog.textContent ).toContain( '1 selected product' );
		expect( dialog.textContent ).toContain( 'Visible on site' );
	} );
} );
