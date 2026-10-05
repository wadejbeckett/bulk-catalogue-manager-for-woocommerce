/**
 * The category menu's own-category actions (tickets 23 and 24): the shop's
 * default category is marked and Make default sets it through
 * POST /categories/{id}/default; Delete… and Merge into… state WooCommerce's
 * consequences, say they cannot be undone and confirm with the preview's token.
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

	it( 'deletes after stating the consequences and that it cannot be undone', async () => {
		routes[ 'POST categories/delete' ] = ( options ) =>
			Promise.resolve(
				options.data.preview
					? {
							categories: [
								{ id: 6, name: 'Bags', path: 'Bags' },
							],
							subcategories: 0,
							products: 3,
							fallback: 2,
							into: null,
							default: { id: 5, path: 'Uncategorised' },
							token: 'tok',
						}
					: {
							batch_id: 8,
							status: 'complete',
							deleted: [ 6 ],
							failed: [],
						}
			);
		render( <CategoryPanel settings={ MANAGER } onChange={ jest.fn() } /> );
		await screen.findByRole( 'tree' );
		const own = openMenu( 'Uncategorised' );
		expect(
			within( own ).queryByRole( 'menuitem', { name: 'Delete…' } )
		).toBeNull();
		fireEvent.keyDown( own, { key: 'Escape' } );

		fireEvent.click(
			within( openMenu( 'Bags' ) ).getByRole( 'menuitem', {
				name: 'Delete…',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete this category',
		} );
		expect(
			await within( dialog ).findByText(
				'3 products lose these categories. The products themselves are kept.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).getByText(
				'2 products have no other category, so they go into the default category, Uncategorised.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).getByText(
				'A deleted category cannot be brought back, so this cannot be undone.'
			)
		).toBeTruthy();
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Delete' } )
			);
		} );
		const sent = apiFetch.mock.calls
			.map( ( [ o ] ) => o )
			.filter( ( o ) => o.method === 'POST' );
		expect( sent.map( ( o ) => o.data ) ).toEqual( [
			{ ids: [ 6 ], preview: true },
			{ ids: [ 6 ], token: 'tok' },
		] );
		expect( speak ).toHaveBeenCalledWith( '1 category deleted.', 'polite' );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'merges into a chosen category', async () => {
		routes[ 'POST categories/merge' ] = ( options ) =>
			Promise.resolve(
				options.data.preview
					? {
							categories: [
								{ id: 6, name: 'Bags', path: 'Bags' },
							],
							subcategories: 0,
							products: 4,
							fallback: 0,
							into: {
								id: options.data.into,
								path: 'Clothing › Hats',
							},
							default: null,
							token: 'tok' + options.data.into,
						}
					: {
							batch_id: 9,
							status: 'complete',
							deleted: [ 6 ],
							failed: [],
						}
			);
		render( <CategoryPanel settings={ MANAGER } onChange={ jest.fn() } /> );
		await screen.findByRole( 'tree' );
		fireEvent.click(
			within( openMenu( 'Bags' ) ).getByRole( 'menuitem', {
				name: 'Merge into…',
			} )
		);
		const dialog = await screen.findByRole( 'dialog' );
		fireEvent.change( within( dialog ).getByLabelText( 'Merge into' ), {
			target: { value: '2' },
		} );
		expect(
			await within( dialog ).findByText(
				'4 products move into Clothing › Hats.'
			)
		).toBeTruthy();
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Merge' } )
			);
		} );
		const last = apiFetch.mock.calls
			.map( ( [ o ] ) => o )
			.filter( ( o ) => o.method === 'POST' )
			.pop();
		expect( last.data ).toEqual( { ids: [ 6 ], into: 2, token: 'tok2' } );
		expect( speak ).toHaveBeenCalledWith(
			'1 category merged into Clothing › Hats.',
			'polite'
		);
	} );
} );
