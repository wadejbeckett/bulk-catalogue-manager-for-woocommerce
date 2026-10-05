/**
 * The product grid opened from the Categories tab (SPEC §4.7): a link naming
 * one or several categories (and, from a product in a category's list, the
 * product to search for) opens the grid filtered to them, with the category
 * chip, instead of the default view; the arguments are read once.
 */
import { render, screen, waitFor } from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';
import { linkedFrom } from '../../assets/src/panels/catalogue/linked-category';

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

const SETTINGS = {
	settings: { rows_per_page: 25, category_panel: true },
	user: { id: 3, name: 'Mia', capabilities: {} },
};

const SCREEN =
	'/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce';

beforeEach( () => {
	apiFetch.mockReset();
	window.localStorage.clear();
	route( apiFetch, {
		...createSelectionServer().routes(),
		fields: () => REGISTRY,
		products: () => page(),
		categories: () => CATEGORIES,
		views: () => ( { items: [], default: null } ),
	} );
} );

afterEach( () => {
	window.history.replaceState( {}, '', '/' );
} );

describe( 'a link from the Categories tab', () => {
	it( 'reads one or several category ids and a search, ignoring anything else', () => {
		expect( linkedFrom( '?category=3' ) ).toEqual( {
			category: { id: 3, includeChildren: true },
			search: '',
		} );
		expect( linkedFrom( '?category=3,4&search=Golf%20shirt' ) ).toEqual( {
			category: { id: 3, ids: [ 3, 4 ], includeChildren: true },
			search: 'Golf shirt',
		} );
		expect( linkedFrom( '?category=abc,-2&panel=catalogue' ) ).toEqual( {
			category: null,
			search: '',
		} );
	} );

	it( 'opens the grid filtered to the category and searched for the product', async () => {
		window.history.replaceState(
			{},
			'',
			`${ SCREEN }&category=3&search=Classic`
		);
		render( <Catalogue settings={ SETTINGS } /> );
		await screen.findByText( 'Classic Cotton T-Shirt' );
		await waitFor( () => {
			const last = calls( apiFetch, 'products' ).pop();
			expect( last ).toMatchObject( {
				search: 'Classic',
				category_ids: { operator: 'isAny', value: [ '3' ] },
			} );
		} );
		expect(
			await screen.findByText(
				'Category is T-Shirts or its sub-categories'
			)
		).toBeTruthy();
		// Read once: a reload or another panel does not bring it back.
		expect( window.location.search ).not.toContain( 'category=' );
		expect( window.location.search ).not.toContain( 'search=' );
		expect( window.location.search ).toContain( 'page=' );
	} );

	it( 'opens the grid filtered to several categories', async () => {
		window.history.replaceState( {}, '', `${ SCREEN }&category=3,4` );
		render( <Catalogue settings={ SETTINGS } /> );
		await screen.findByText( 'Classic Cotton T-Shirt' );
		await waitFor( () => {
			const last = calls( apiFetch, 'products' ).pop();
			expect( last.category_ids ).toMatchObject( {
				operator: 'isAny',
				value: [ '3', '4' ],
			} );
		} );
		expect(
			await screen.findByText(
				'Category is T-Shirts or Golf Shirts, or their sub-categories'
			)
		).toBeTruthy();
	} );
} );
