/**
 * T43: the grid's states render the copy SPEC §4.1 specifies — loading
 * skeleton, no products, no matches with the active filters, an error that
 * keeps the rows already on screen — and Try again reads again.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
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
	CATEGORIES,
	page,
	route,
	calls,
} = require( './catalogue-fixture' );

const SETTINGS = { settings: { rows_per_page: 25 } };

const failure = () => {
	const error = new Error( 'The response is not a valid JSON response.' );
	error.code = 'invalid_json';
	throw error;
};

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'catalogue states', () => {
	it( 'draws skeleton rows while the registry and the first page load', async () => {
		route( apiFetch, { fields: () => REGISTRY } );
		const { container } = render( <Catalogue settings={ SETTINGS } /> );
		expect( container.querySelector( '.bcm-skeleton' ) ).toBeTruthy();
		await waitFor( () =>
			expect( calls( apiFetch, 'products' ) ).toHaveLength( 1 )
		);
		const skeleton = container.querySelector( '.bcm-skeleton' );
		expect( skeleton ).toBeTruthy();
		expect(
			skeleton.querySelectorAll( 'tr.bcm-skeleton__row' )
		).toHaveLength( 10 );
		expect(
			skeleton.querySelector( 'table' ).getAttribute( 'aria-hidden' )
		).toBe( 'true' );
		expect( screen.getByRole( 'status' ).textContent ).toBe(
			'Loading products…'
		);
	} );

	it( 'says there are no products yet, links to Add New, and shows nothing else', async () => {
		route( apiFetch, {
			fields: () => REGISTRY,
			products: () => page( [], 0 ),
		} );
		const { container } = render( <Catalogue settings={ SETTINGS } /> );
		expect(
			await screen.findByText( 'There are no products yet.' )
		).toBeTruthy();
		expect(
			screen
				.getByRole( 'link', { name: 'Add a new product' } )
				.getAttribute( 'href' )
		).toBe( 'post-new.php?post_type=product' );
		expect( container.querySelector( '.dataviews-wrapper' ) ).toBeNull();
		expect( screen.queryByRole( 'searchbox' ) ).toBeNull();
		expect( screen.getAllByRole( 'link' ) ).toHaveLength( 1 );
	} );

	it( 'says nothing matches, lists the active filters, and clears them', async () => {
		route( apiFetch, {
			fields: () => REGISTRY,
			products: ( query ) => ( query.search ? page( [], 0 ) : page() ),
			categories: () => CATEGORIES,
		} );
		render(
			<Catalogue
				settings={ SETTINGS }
				initialView={ {
					search: 'zzz',
					page: 3,
					filters: [
						{
							field: 'stock_status',
							operator: 'isAny',
							value: [ 'outofstock', 'onbackorder' ],
						},
					],
				} }
			/>
		);
		expect(
			await screen.findByText( 'No products match these filters.' )
		).toBeTruthy();
		const state = document.querySelector( '.bcm-state' );
		const list = within( state ).getByRole( 'list', {
			name: 'Active filters',
		} );
		expect(
			Array.from( list.querySelectorAll( 'li' ) ).map(
				( li ) => li.textContent
			)
		).toEqual( [
			'Name or SKU contains “zzz”',
			'Stock status is Out of stock or On backorder',
		] );
		expect( screen.queryByText( 'There are no products yet.' ) ).toBeNull();

		fireEvent.click(
			within( state ).getByRole( 'button', { name: 'Clear filters' } )
		);
		expect(
			await screen.findByText( 'Classic Cotton T-Shirt' )
		).toBeTruthy();
		const last = calls( apiFetch, 'products' ).pop();
		expect( last.search ).toBeUndefined();
		expect( last.page ).toBe( '1' );
	} );

	it( 'names what failed, keeps the loaded rows on screen, and tries again', async () => {
		let fail = false;
		route( apiFetch, {
			fields: () => REGISTRY,
			products: () => ( fail ? failure() : page() ),
			categories: () => CATEGORIES,
		} );
		render( <Catalogue settings={ SETTINGS } /> );
		await screen.findByText( 'Classic Cotton T-Shirt' );

		fail = true;
		fireEvent.click( screen.getByRole( 'button', { name: 'Next page' } ) );
		expect(
			await screen.findByText( 'The products could not be loaded.' )
		).toBeTruthy();
		// The rows loaded before stay mounted behind the notice.
		expect( screen.getByText( 'Classic Cotton T-Shirt' ) ).toBeTruthy();
		expect( screen.getByText( 'Show details' ) ).toBeTruthy();
		expect(
			screen.getByText(
				'invalid_json: The response is not a valid JSON response.'
			)
		).toBeTruthy();

		fail = false;
		const before = calls( apiFetch, 'products' ).length;
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await waitFor( () =>
			expect(
				screen.queryByText( 'The products could not be loaded.' )
			).toBeNull()
		);
		const again = calls( apiFetch, 'products' );
		expect( again ).toHaveLength( before + 1 );
		expect( again.pop().page ).toBe( '2' );
	} );

	it( 'names a failed first read without showing an empty state', async () => {
		route( apiFetch, {
			fields: () => REGISTRY,
			products: failure,
		} );
		render( <Catalogue settings={ SETTINGS } /> );
		expect(
			await screen.findByText( 'The products could not be loaded.' )
		).toBeTruthy();
		expect(
			screen.queryByText( 'No products match these filters.' )
		).toBeNull();
		expect( screen.queryByText( 'There are no products yet.' ) ).toBeNull();
	} );

	it( 'names a failed registry read and tries it again', async () => {
		let fail = true;
		route( apiFetch, {
			fields: () => ( fail ? failure() : REGISTRY ),
			products: () => page(),
			categories: () => CATEGORIES,
		} );
		render( <Catalogue settings={ SETTINGS } /> );
		expect(
			await screen.findByText(
				'The list of product fields could not be loaded, so the products cannot be shown.'
			)
		).toBeTruthy();
		fail = false;
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect(
			await screen.findByText( 'Classic Cotton T-Shirt' )
		).toBeTruthy();
	} );
} );
