/**
 * T40, T43: the Catalogue panel renders a DataViews table from GET /products
 * with registry-built columns, re-queries on page and sort changes, and
 * carries the row menu.
 */
import {
	render,
	screen,
	waitFor,
	within,
	fireEvent,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';

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
	calls,
} = require( './catalogue-fixture' );

const SETTINGS = { settings: { rows_per_page: 25 } };

beforeEach( () => {
	apiFetch.mockReset();
	route( apiFetch, {
		fields: () => REGISTRY,
		products: () => page(),
		// T47's detail panel reads its row through GET /products/{id}.
		'products/11': () => ( { item: ROWS[ 0 ] } ),
		categories: () => CATEGORIES,
	} );
} );

async function renderLoaded( props = {} ) {
	const result = render( <Catalogue settings={ SETTINGS } { ...props } /> );
	await screen.findByText( 'Classic Cotton T-Shirt' );
	return result;
}

describe( 'catalogue table', () => {
	it( 'renders the rows of a fixture response with the name linking to the product editor', async () => {
		await renderLoaded();
		const link = screen.getByRole( 'link', {
			name: 'Classic Cotton T-Shirt',
		} );
		expect( link.getAttribute( 'href' ) ).toBe(
			'post.php?post=11&action=edit'
		);
		expect(
			screen
				.getByRole( 'link', { name: 'Ladies Golf Shirt' } )
				.getAttribute( 'href' )
		).toBe( 'post.php?post=12&action=edit' );
		expect( screen.getByText( 'TSH-100' ) ).toBeTruthy();
		expect( screen.getByText( 'In stock · 659' ) ).toBeTruthy();
		expect( screen.getByText( 'Draft' ) ).toBeTruthy();
		expect( await screen.findByText( 'T-Shirts' ) ).toBeTruthy();
		expect( screen.getByText( 'Golf Shirts' ) ).toBeTruthy();
		const thumbnail = document.querySelector( 'img.bulkcat-thumb' );
		expect( thumbnail.getAttribute( 'src' ) ).toBe(
			ROWS[ 0 ].thumbnail_url
		);
	} );

	it( 'shows the default columns and no others', async () => {
		await renderLoaded();
		const headers = screen
			.getAllByRole( 'columnheader' )
			.map( ( th ) => th.textContent.trim() )
			.filter( Boolean );
		expect( headers ).toEqual( [
			'Name',
			'SKU',
			'Categories',
			'Regular price',
			'Stock status',
			'Status',
			'Catalogue visibility',
			'Shown in shop and search',
			'Actions',
		] );
	} );

	it( 'asks for the first page at the settings page size, newest first', async () => {
		await renderLoaded( { settings: { settings: { rows_per_page: 50 } } } );
		const [ first ] = calls( apiFetch, 'products' );
		expect( first.page ).toBe( '1' );
		expect( first.per_page ).toBe( '50' );
		expect( first.orderby ).toBeUndefined();
		expect( first.fields ).toEqual(
			expect.arrayContaining( [ 'id', 'name', 'thumbnail', 'sku' ] )
		);
	} );

	it( 're-queries the next page when the page changes', async () => {
		await renderLoaded();
		fireEvent.click( screen.getByRole( 'button', { name: 'Next page' } ) );
		await waitFor( () =>
			expect( calls( apiFetch, 'products' ) ).toHaveLength( 2 )
		);
		expect( calls( apiFetch, 'products' )[ 1 ] ).toMatchObject( {
			page: '2',
			per_page: '25',
		} );
	} );

	it( 're-queries sorted when a sortable column is sorted', async () => {
		await renderLoaded();
		fireEvent.click( screen.getByRole( 'button', { name: 'SKU' } ) );
		fireEvent.click(
			await screen.findByRole( 'menuitemradio', {
				name: 'Sort descending',
			} )
		);
		await waitFor( () =>
			expect( calls( apiFetch, 'products' ) ).toHaveLength( 2 )
		);
		expect( calls( apiFetch, 'products' )[ 1 ] ).toMatchObject( {
			orderby: [ 'sku' ],
			order: [ 'desc' ],
		} );
		await screen.findByText( 'Classic Cotton T-Shirt' );
		const sku = screen
			.getAllByRole( 'columnheader' )
			.find( ( th ) => th.textContent.includes( 'SKU' ) );
		expect( sku.getAttribute( 'aria-sort' ) ).toBe( 'descending' );
	} );

	it( 'offers no sort on a column the registry does not mark sortable', async () => {
		await renderLoaded();
		fireEvent.click( screen.getByRole( 'button', { name: 'Status' } ) );
		await screen.findByRole( 'menuitem', { name: 'Hide column' } );
		expect(
			screen.queryByRole( 'menuitemradio', { name: 'Sort descending' } )
		).toBeNull();
	} );

	it( 'carries a row menu with Edit details and the server-authorised product editor', async () => {
		const onEditDetails = jest.fn();
		const item = {
			...ROWS[ 0 ],
			native_actions: {
				details: true,
				editor: {
					url: 'https://example.test/wp-admin/post.php?post=11&action=edit',
				},
				view: null,
				duplicate: null,
				trash: null,
				unavailable: '',
			},
		};
		route( apiFetch, {
			fields: () => REGISTRY,
			products: () => ( {
				...page(),
				items: [ item, ...ROWS.slice( 1 ) ],
			} ),
			'products/11': () => ( { item } ),
			categories: () => CATEGORIES,
		} );
		await renderLoaded( { onEditDetails } );
		const row = screen
			.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
			.closest( 'tr' );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Actions' } )
		);
		expect(
			await screen.findByRole( 'menuitem', {
				name: 'Open product editor',
			} )
		).toBeTruthy();
		fireEvent.click(
			screen.getByRole( 'menuitem', { name: 'Edit details' } )
		);
		expect( onEditDetails ).toHaveBeenCalledWith( item );
		// The detail panel opens and settles its reads (T47).
		expect( await screen.findByRole( 'dialog' ) ).toBeTruthy();
		await screen.findByRole( 'button', { name: 'Save' } );
	} );
} );
