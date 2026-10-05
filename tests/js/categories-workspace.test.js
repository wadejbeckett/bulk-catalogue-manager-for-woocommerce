/**
 * The Categories tab as a full-width workspace (SPEC §4.7): the tree across
 * the left of the screen with Expand all and Collapse all, names that are
 * never cut off, search that keeps each match's path and marks it, a newly
 * created category revealed and highlighted; a details pane on the right for
 * the chosen category (its path, fields, counts and a paged list of its
 * products, each linking to the product grid filtered to the category) with
 * its actions; and a selection of several categories with its count and the
 * actions that apply to many.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import CategoriesTab from '../../assets/src/panels/categories/index';
import {
	branchIds,
	catalogueParams,
	markParts,
	nextSelection,
	topmost,
} from '../../assets/src/panels/categories/workspace-model';
import { indexTree } from '../../assets/src/panels/categories/tree-model';
import { ShellContext } from '../../assets/src/shell';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { REGISTRY, ROWS, route, calls } = require( './catalogue-fixture' );

const matched = ( pass, message ) => ( { pass, message: () => message } );

// The few DOM matchers these tests use (jest-dom is not a dependency).
expect.extend( {
	toHaveAttribute( element, name, value ) {
		const actual = element.getAttribute( name );
		return matched(
			value === undefined ? actual !== null : actual === String( value ),
			`expected [${ name }] to be ${ value }, got ${ actual }`
		);
	},
	toBeVisible( element ) {
		return matched(
			!! element && element.ownerDocument.body.contains( element ),
			'expected the element to be in the document'
		);
	},
	toHaveClass( element, name ) {
		return matched(
			element.classList.contains( name ),
			`expected class ${ name } in "${ element.className }"`
		);
	},
	toHaveValue( element, value ) {
		return matched(
			element.value === value,
			`expected value ${ value }, got ${ element.value }`
		);
	},
	toHaveTextContent( element, text ) {
		return matched(
			element.textContent.includes( text ),
			`expected "${ element.textContent }" to contain "${ text }"`
		);
	},
} );

const LONG =
	'Pressure cookers, slow cookers and multi-cookers for every kitchen';

const node = (
	id,
	name,
	children = [],
	count = 1,
	all = count,
	extra = {}
) => ( {
	id,
	name,
	slug: name.toLowerCase().replace( /[^a-z]+/g, '-' ),
	description: '',
	count,
	countWithDescendants: all,
	children,
	...extra,
} );

const tree = ( extra = [] ) => [
	node(
		1,
		'Kitchen',
		[
			node(
				2,
				'Cookware',
				[
					node(
						3,
						'Pots',
						[
							node( 4, 'Stock pots', [], 20 ),
							node( 5, 'Saucepans', [], 15 ),
							...extra,
						],
						17,
						52,
						{ description: 'Pots for every hob.' }
					),
					node( 6, 'Pans', [], 56 ),
					node( 12, LONG, [], 4 ),
				],
				0,
				128
			),
			node( 7, 'Knives', [], 100 ),
		],
		0,
		228
	),
	node( 8, 'Garden', [ node( 9, 'Outdoor furniture', [], 30 ) ], 2, 32 ),
	node( 10, 'Sports', [], 9 ),
];

const MANAGER = {
	user: { id: 7, name: 'Mia', capabilities: { manage_product_terms: true } },
	settings: {},
	categoryEdit: {
		url: 'http://shop.test/wp-admin/term.php?taxonomy=cat&post_type=product',
		flag: 'bulkcat_embed',
	},
};
const VIEWER = { user: { id: 8, name: 'Sam', capabilities: {} }, settings: {} };

let current;
let navigate;

function routes( overrides = {} ) {
	route( apiFetch, {
		fields: () => REGISTRY,
		categories: ( query, options ) => {
			if ( options.method === 'POST' ) {
				return {
					item: { id: 11, name: 'Dutch ovens', parent: 3 },
					batch_id: 40,
					status: 'complete',
				};
			}
			return current;
		},
		'categories/3': () => ( {
			item: {
				id: 3,
				name: 'Pots',
				display_type: 'both',
				thumbnail_id: 21,
				thumbnail_url: 'http://shop.test/pots.png',
			},
		} ),
		'categories/3/move': () => ( { item: { id: 3 }, status: 'complete' } ),
		'categories/7/move': () => ( { item: { id: 7 }, status: 'complete' } ),
		products: ( query ) => ( {
			items: ROWS,
			pagination: {
				page: Number( query.page ) || 1,
				perPage: 10,
				total: 52,
				totalPages: 6,
			},
		} ),
		...overrides,
	} );
}

async function renderTab( settings = MANAGER ) {
	navigate = jest.fn();
	const result = render(
		<ShellContext.Provider
			value={ {
				exportSlot: null,
				bulkRequested: false,
				consumeBulk: () => {},
				navigate,
			} }
		>
			<CategoriesTab settings={ settings } />
		</ShellContext.Provider>
	);
	await screen.findByRole( 'tree' );
	return result;
}

const items = () => screen.getAllByRole( 'treeitem' );
const item = ( name ) =>
	items().find( ( el ) =>
		el.getAttribute( 'aria-label' ).startsWith( name + ',' )
	);
const rowOf = ( name ) => item( name ).querySelector( '.bulkcat-cat-row' );

beforeEach( () => {
	apiFetch.mockReset();
	window.localStorage.clear();
	window.wp = { a11y: { speak: jest.fn() } };
	current = tree();
	routes();
} );

afterEach( () => {
	delete window.wp;
} );

describe( 'workspace model', () => {
	it( 'lists every branch, so Expand all opens the whole tree', () => {
		expect( [ ...branchIds( indexTree( tree() ) ) ].sort() ).toEqual( [
			1, 2, 3, 8,
		] );
	} );

	it( 'marks each place a name matches the search, ignoring case', () => {
		expect( markParts( 'Saucepans and sauce boats', 'SAUCE' ) ).toEqual( [
			{ text: 'Sauce', match: true },
			{ text: 'pans and ', match: false },
			{ text: 'sauce', match: true },
			{ text: ' boats', match: false },
		] );
		expect( markParts( 'Pots', '' ) ).toEqual( [
			{ text: 'Pots', match: false },
		] );
	} );

	it( 'chooses one, adds or removes one, or takes a range of rows', () => {
		const rows = [ 1, 2, 3, 4, 5 ].map( ( id ) => ( { id } ) );
		expect( nextSelection( [ 2 ], 4, { rows } ) ).toEqual( [ 4 ] );
		expect( nextSelection( [ 2 ], 4, { multiple: true, rows } ) ).toEqual( [
			2, 4,
		] );
		expect(
			nextSelection( [ 2, 4 ], 2, { multiple: true, rows } )
		).toEqual( [ 4 ] );
		expect(
			nextSelection( [ 2 ], 5, { range: true, anchor: 2, rows } )
		).toEqual( [ 2, 3, 4, 5 ] );
	} );

	it( 'keeps only the top of each selected branch', () => {
		expect( topmost( indexTree( tree() ), [ 4, 3, 10 ] ) ).toEqual( [
			3, 10,
		] );
	} );

	it( 'builds the product grid link for one or several categories', () => {
		expect( catalogueParams( [ 3 ] ) ).toEqual( { category: '3' } );
		expect( catalogueParams( [ 3, 6 ], { search: 'Pot 1' } ) ).toEqual( {
			category: '3,6',
			search: 'Pot 1',
		} );
	} );
} );

describe( 'categories workspace', () => {
	it( 'shows the whole tree across the screen, without an All products row', async () => {
		const { container } = await renderTab();
		expect( container.querySelector( '.bulkcat-cat-workspace' ) ).not.toBe(
			null
		);
		expect(
			items().map( ( el ) => el.getAttribute( 'aria-label' ) )
		).toEqual( [
			'Kitchen, 228 products',
			'Garden, 32 products',
			'Sports, 9 products',
		] );
		fireEvent.click( screen.getByRole( 'button', { name: 'Expand all' } ) );
		expect( items() ).toHaveLength( 11 );
		// Names wrap instead of being cut off.
		expect( screen.getByRole( 'tree' ) ).toHaveClass( 'is-wrapping' );
		expect( screen.getByText( LONG ) ).toBeVisible();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Collapse all' } )
		);
		expect( items() ).toHaveLength( 3 );
	} );

	it( 'asks the owner to choose a category before showing details', async () => {
		await renderTab();
		expect(
			screen.getByText(
				'Choose a category on the left to see its details and products.'
			)
		).toBeVisible();
	} );

	it( 'shows the chosen category’s path, details and products, paged', async () => {
		await renderTab();
		fireEvent.click( screen.getByRole( 'button', { name: 'Expand all' } ) );
		fireEvent.click( rowOf( 'Pots' ) );
		const pane = await screen.findByRole( 'region', {
			name: 'Pots',
		} );
		expect(
			within( pane ).getByRole( 'navigation', { name: 'Category path' } )
		).toHaveTextContent( 'Kitchen›Cookware›Pots' );
		expect( within( pane ).getByText( 'pots' ) ).toBeVisible();
		expect(
			within( pane ).getByText( 'Pots for every hob.' )
		).toBeVisible();
		expect(
			within( pane ).getByText( 'Kitchen › Cookware' )
		).toBeVisible();
		expect( await within( pane ).findByText( 'Both' ) ).toBeVisible();
		expect(
			within( pane ).getByRole( 'img', { name: 'Category image' } )
		).toHaveAttribute( 'src', 'http://shop.test/pots.png' );
		expect(
			within( pane ).getByText(
				'17 in this category, 52 with its subcategories'
			)
		).toBeVisible();
		expect( within( pane ).getByText( '2 subcategories' ) ).toBeVisible();

		const products = await within( pane ).findByRole( 'table', {
			name: 'Products in Pots',
		} );
		expect(
			within( products ).getByText( 'Classic Cotton T-Shirt' )
		).toBeVisible();
		const asked = calls( apiFetch, 'products' );
		expect( asked[ asked.length - 1 ] ).toMatchObject( {
			page: '1',
			category_ids: { operator: 'isAny', value: [ '3' ] },
		} );
		const link = within( products ).getByRole( 'link', {
			name: 'Classic Cotton T-Shirt',
		} );
		// The product grid is the screen's default panel.
		expect( link.getAttribute( 'href' ) ).not.toContain( 'panel=' );
		expect( link.getAttribute( 'href' ) ).toContain( 'category=3' );
		fireEvent.click( link );
		expect( navigate ).toHaveBeenCalledWith( 'catalogue', {
			category: '3',
			search: 'Classic Cotton T-Shirt',
		} );

		expect( within( pane ).getByText( 'Page 1 of 6' ) ).toBeVisible();
		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Next page' } )
		);
		await within( pane ).findByText( 'Page 2 of 6' );
		const later = calls( apiFetch, 'products' );
		expect( later[ later.length - 1 ] ).toMatchObject( { page: '2' } );

		// This category only.
		fireEvent.click(
			within( pane ).getByRole( 'checkbox', {
				name: 'Include products in subcategories',
			} )
		);
		await waitFor( () => {
			const last = calls( apiFetch, 'products' ).pop();
			expect( last ).toMatchObject( {
				page: '1',
				category_ids: { include_children: 'false' },
			} );
		} );

		fireEvent.click(
			within( pane ).getByRole( 'link', {
				name: 'Show them in the product grid',
			} )
		);
		expect( navigate ).toHaveBeenLastCalledWith( 'catalogue', {
			category: '3',
		} );
	} );

	it( 'offers rename, move, add subcategory and edit on the chosen category', async () => {
		await renderTab();
		fireEvent.click( screen.getByRole( 'button', { name: 'Expand all' } ) );
		fireEvent.click( rowOf( 'Pots' ) );
		const pane = await screen.findByRole( 'region', { name: 'Pots' } );
		[ 'Rename', 'Move to…', 'Add subcategory', 'Edit details' ].forEach(
			( name ) =>
				expect(
					within( pane ).getByRole( 'button', { name } )
				).toBeVisible()
		);

		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Rename' } )
		);
		const input = within( pane ).getByRole( 'textbox', {
			name: 'New name for Pots',
		} );
		fireEvent.change( input, { target: { value: 'Cooking pots' } } );
		fireEvent.submit( input.closest( 'form' ) );
		await waitFor( () =>
			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					method: 'PATCH',
					data: { name: 'Cooking pots' },
				} )
			)
		);

		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Add subcategory' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Add a category',
		} );
		expect(
			within( dialog ).getByRole( 'combobox', {
				name: 'Parent category',
			} )
		).toHaveValue( '3' );
	} );

	it( 'selects several categories and offers what applies to many', async () => {
		await renderTab();
		fireEvent.click( screen.getByRole( 'button', { name: 'Expand all' } ) );
		fireEvent.click( rowOf( 'Pots' ) );
		fireEvent.click( rowOf( 'Knives' ), { ctrlKey: true } );
		const pane = await screen.findByRole( 'region', {
			name: '2 categories selected',
		} );
		expect( item( 'Pots' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( item( 'Knives' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			within( pane ).getByText( 'Kitchen › Cookware › Pots' )
		).toBeVisible();
		expect( within( pane ).getByText( 'Kitchen › Knives' ) ).toBeVisible();
		expect(
			within( pane ).getByRole( 'button', { name: 'Move to…' } )
		).toBeVisible();

		fireEvent.click(
			within( pane ).getByRole( 'link', {
				name: 'Show their products in the product grid',
			} )
		);
		expect( navigate ).toHaveBeenLastCalledWith( 'catalogue', {
			category: '3,7',
		} );

		// The checkbox adds a row without a modifier key; Shift takes a range.
		fireEvent.click(
			item( 'Sports' ).querySelector( '.bulkcat-cat-check' )
		);
		await screen.findByRole( 'region', { name: '3 categories selected' } );
		fireEvent.click( rowOf( 'Stock pots' ) );
		fireEvent.click( rowOf( 'Pans' ), { shiftKey: true } );
		await screen.findByRole( 'region', { name: '3 categories selected' } );
		expect( item( 'Saucepans' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Clear selection' } )
		);
		expect(
			screen.getByText(
				'Choose a category on the left to see its details and products.'
			)
		).toBeVisible();
	} );

	it( 'moves several categories into one parent', async () => {
		await renderTab();
		fireEvent.click( screen.getByRole( 'button', { name: 'Expand all' } ) );
		fireEvent.click( rowOf( 'Pots' ) );
		fireEvent.click( rowOf( 'Knives' ), { ctrlKey: true } );
		const pane = await screen.findByRole( 'region', {
			name: '2 categories selected',
		} );
		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Move to…' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Move 2 categories',
		} );
		const parent = within( dialog ).getByRole( 'combobox', {
			name: 'New parent category',
		} );
		// Neither a selected category nor anything inside one is offered.
		const offered = within( parent )
			.getAllByRole( 'option' )
			.map( ( option ) => option.textContent );
		expect( offered ).not.toContain( 'Kitchen › Cookware › Pots' );
		expect( offered ).not.toContain(
			'Kitchen › Cookware › Pots › Stock pots'
		);
		fireEvent.change( parent, { target: { value: '8' } } );
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', {
					name: 'Move 2 categories',
				} )
			);
		} );
		const moves = apiFetch.mock.calls
			.map( ( [ options ] ) => options )
			.filter( ( options ) => options.method === 'POST' );
		expect(
			moves.map( ( options ) => options.url.split( '?' )[ 0 ] )
		).toEqual( [ 'categories/3/move', 'categories/7/move' ] );
		moves.forEach( ( options ) =>
			expect( options.data ).toEqual( { parent: 8 } )
		);
	} );

	it( 'searches, keeping each match’s path and marking the match', async () => {
		await renderTab();
		fireEvent.change(
			screen.getByRole( 'searchbox', { name: 'Search categories' } ),
			{ target: { value: 'sauce' } }
		);
		expect(
			items().map( ( el ) => el.getAttribute( 'aria-label' ) )
		).toEqual( [
			'Kitchen, 228 products',
			'Cookware, 128 products',
			'Pots, 52 products',
			'Saucepans, 15 products',
		] );
		expect( screen.getByText( '1 category matches' ) ).toBeVisible();
		const mark = item( 'Saucepans' ).querySelector( 'mark' );
		expect( mark ).toHaveTextContent( 'Sauce' );
		expect( rowOf( 'Kitchen' ) ).toHaveClass( 'is-context' );
		expect( rowOf( 'Saucepans' ) ).not.toHaveClass( 'is-context' );
	} );

	it( 'reveals, highlights and chooses a category just created', async () => {
		await renderTab();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Add category' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Add a category',
		} );
		fireEvent.change(
			within( dialog ).getByRole( 'textbox', { name: 'Name' } ),
			{ target: { value: 'Dutch ovens' } }
		);
		current = tree( [ node( 11, 'Dutch ovens', [], 0 ) ] );
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Add category' } )
			);
		} );
		await waitFor( () => expect( item( 'Dutch ovens' ) ).toBeTruthy() );
		expect( rowOf( 'Dutch ovens' ) ).toHaveClass( 'is-new' );
		expect( item( 'Dutch ovens' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect(
			await screen.findByRole( 'region', { name: 'Dutch ovens' } )
		).toBeVisible();
	} );

	it( 'keeps the tree browsable but offers no changes without permission', async () => {
		await renderTab( VIEWER );
		expect(
			screen.queryByRole( 'button', { name: 'Add category' } )
		).toBeNull();
		fireEvent.click( rowOf( 'Sports' ) );
		const pane = await screen.findByRole( 'region', { name: 'Sports' } );
		expect(
			within( pane ).queryByRole( 'button', { name: 'Rename' } )
		).toBeNull();
		expect(
			within( pane ).getByText(
				'You can browse categories and their products, but changing them needs permission to manage product categories.'
			)
		).toBeVisible();
	} );
} );
