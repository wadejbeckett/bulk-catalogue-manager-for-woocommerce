/**
 * The category menu's own-category actions (tickets 23 and 24): the shop's
 * default category is marked and Make default sets it through
 * POST /categories/{id}/default.
 */
import { act, fireEvent, render, screen, within } from '@testing-library/react';

import { CategoryPanel } from '../../assets/src/panels/categories/index';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;

const node = ( id, name, extra = {}, children = [] ) => ( {
	id,
	name,
	slug: name.toLowerCase(),
	parent: 0,
	count: 1,
	countWithDescendants: 1,
	children,
	...extra,
} );

const TREE = [
	node( 1, 'Clothing', { count: 4, countWithDescendants: 5 }, [
		{ ...node( 2, 'Hats' ), parent: 1 },
	] ),
	node( 5, 'Uncategorised', { default: true } ),
	node( 6, 'Bags' ),
];

const MANAGER = {
	user: { id: 7, name: 'Mia', capabilities: { manage_product_terms: true } },
	settings: { category_panel: true },
};

let routes;

function answer( options ) {
	const route = options.url.split( '?' )[ 0 ];
	const key = `${ options.method || 'GET' } ${ route }`;
	const handler = routes[ key ];
	if ( ! handler ) {
		return Promise.reject( new Error( `unexpected ${ key }` ) );
	}
	return handler( options );
}

const item = ( name ) =>
	screen
		.getAllByRole( 'treeitem' )
		.find( ( el ) =>
			el.getAttribute( 'aria-label' ).startsWith( name + ',' )
		);

const openMenu = ( name ) => {
	fireEvent.click(
		screen.getByRole( 'button', { name: `More actions for ${ name }` } )
	);
	return screen.getByRole( 'menu', { name: `Actions for ${ name }` } );
};

describe( 'category actions', () => {
	let speak;

	beforeEach( () => {
		speak = jest.fn();
		window.wp = { a11y: { speak } };
		window.localStorage.clear();
		routes = { 'GET categories': () => Promise.resolve( TREE ) };
		apiFetch.mockReset();
		apiFetch.mockImplementation( answer );
	} );

	afterEach( () => {
		delete window.wp;
	} );

	it( 'marks the default category and makes another one the default', async () => {
		routes[ 'POST categories/6/default' ] = () =>
			Promise.resolve( {
				item: { id: 6, default: true },
				batch_id: 4,
				status: 'complete',
			} );
		render( <CategoryPanel settings={ MANAGER } onChange={ jest.fn() } /> );
		await screen.findByRole( 'tree' );
		expect(
			within( item( 'Uncategorised' ) ).getByText( 'Default' )
		).toBeTruthy();
		expect( within( item( 'Bags' ) ).queryByText( 'Default' ) ).toBeNull();

		const ownMenu = openMenu( 'Uncategorised' );
		expect(
			within( ownMenu ).queryByRole( 'menuitem', {
				name: 'Make default',
			} )
		).toBeNull();
		fireEvent.keyDown( ownMenu, { key: 'Escape' } );

		const menu = openMenu( 'Bags' );
		await act( async () => {
			fireEvent.click(
				within( menu ).getByRole( 'menuitem', { name: 'Make default' } )
			);
		} );
		const call = apiFetch.mock.calls.find(
			( [ o ] ) => o.method === 'POST'
		)[ 0 ];
		expect( call.url ).toBe( 'categories/6/default' );
		expect( speak ).toHaveBeenCalledWith(
			'Bags is now the default category. Products left without a category are put in it.',
			'polite'
		);
		expect(
			apiFetch.mock.calls.filter( ( [ o ] ) => ! o.method ).length
		).toBe( 2 );
	} );
} );
