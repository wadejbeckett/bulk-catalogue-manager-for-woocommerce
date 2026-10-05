/**
 * Ticket 19 through the catalogue screen: the stored display opens the
 * screen, over the default named view's display but not its filters; a
 * deliberately chosen view brings its own; rows per page re-queries
 * without a sort; nothing is saved on opening, and a user's change is
 * saved to /display-preferences, never to /views.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

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
const { createSelectionServer } = require( './selection-server' );

global.ResizeObserver =
	global.ResizeObserver ||
	class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};

const SETTINGS = {
	settings: { rows_per_page: 25, category_panel: true },
	user: { id: 3, name: 'Mia', capabilities: {} },
};

const SAVED = {
	id: 'aaaaaaaaaaaa',
	name: 'Shirts',
	view: { search: 'shirt', perPage: 25, fields: [ 'sku' ] },
	category: null,
	isDefault: true,
};

function serve( { display = null, views = [], defaultId = null } = {} ) {
	route( apiFetch, {
		...createSelectionServer().routes(),
		fields: () => REGISTRY,
		products: () => page(),
		categories: () => CATEGORIES,
		views: () => ( { items: views, default: defaultId, max: 50 } ),
		'display-preferences': ( query, options ) =>
			options.method === 'PUT'
				? { version: 1, display: options.data.display }
				: { version: 1, display },
	} );
}

const puts = () =>
	apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter( ( options ) => options.method && options.method !== 'GET' );

beforeEach( () => {
	apiFetch.mockReset();
	window.localStorage.clear();
} );

afterEach( () => jest.useRealTimers() );

async function opened() {
	render( <Catalogue settings={ SETTINGS } /> );
	await screen.findByText( 'Classic Cotton T-Shirt' );
	await act( async () => {} );
}

describe( 'personal display on the catalogue screen', () => {
	it( 'opens with the stored page size and saves nothing on opening', async () => {
		serve( { display: { perPage: 100, fields: [ 'sku' ] } } );
		await opened();
		expect(
			screen.getByRole( 'combobox', { name: 'Rows per page' } ).value
		).toBe( '100' );
		expect( calls( apiFetch, 'products' ).at( -1 ).per_page ).toBe( '100' );
		await act( async () => new Promise( ( r ) => setTimeout( r, 1200 ) ) );
		expect( puts() ).toEqual( [] );
	} );

	it( 'keeps the stored display over the default view, and its filters', async () => {
		serve( {
			display: { perPage: 50 },
			views: [ SAVED ],
			defaultId: SAVED.id,
		} );
		await opened();
		const last = calls( apiFetch, 'products' ).at( -1 );
		expect( last.search ).toBe( 'shirt' );
		expect( last.per_page ).toBe( '50' );
		await act( async () => new Promise( ( r ) => setTimeout( r, 1200 ) ) );
		expect( puts() ).toEqual( [] );
	} );

	it( 'changes rows per page without a sort and remembers it for the user only', async () => {
		serve();
		await opened();
		fireEvent.change(
			screen.getByRole( 'combobox', { name: 'Rows per page' } ),
			{ target: { value: '50' } }
		);
		await act( async () => {} );
		const last = calls( apiFetch, 'products' ).at( -1 );
		expect( last.per_page ).toBe( '50' );
		expect( last.orderby ).toBeUndefined();
		await act( async () => new Promise( ( r ) => setTimeout( r, 1200 ) ) );
		const sent = puts();
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].url.split( '?' )[ 0 ] ).toBe( 'display-preferences' );
		expect( sent[ 0 ].method ).toBe( 'PUT' );
		expect( sent[ 0 ].data.display.perPage ).toBe( 50 );
		expect( sent[ 0 ].data ).not.toHaveProperty( 'user_id' );
	} );
} );
