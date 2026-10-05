/**
 * Make default, Delete… and Merge into… in the full-width Categories
 * workspace (SPEC §4.7, tickets 23 and 24): registered into the workspace's
 * action slot, offered alike by the tree row's menu, the details pane and the
 * selection pane, never for the shop's default category (WooCommerce never
 * deletes it), with the Default badge in the tree and the details. After a
 * delete the pane clears; after a merge the target stays chosen; each change
 * reads the tree again and is announced.
 */
import { act, fireEvent, render, screen, within } from '@testing-library/react';

import CategoriesTab from '../../assets/src/panels/categories/index';
import { indexTree } from '../../assets/src/panels/categories/tree-model';
import { categoryActions } from '../../assets/src/panels/categories/workspace-actions';
import { ShellContext } from '../../assets/src/shell';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { REGISTRY, ROWS, route } = require( './catalogue-fixture' );

const node = ( id, name, children = [], extra = {} ) => ( {
	id,
	name,
	slug: name.toLowerCase().replace( /[^a-z]+/g, '-' ),
	description: '',
	count: 2,
	countWithDescendants: 2,
	children,
	...extra,
} );

const TREE = () => [
	node( 1, 'Kitchen', [ node( 2, 'Pots' ), node( 3, 'Pans' ) ] ),
	node( 5, 'Uncategorised', [], { default: true } ),
	node( 6, 'Bags' ),
];

const MANAGER = {
	user: { id: 7, name: 'Mia', capabilities: { manage_product_terms: true } },
	settings: {},
};

let current;
let posts;

const preview = ( options, into ) => ( {
	categories: options.data.ids.map( ( id ) => ( { id, path: '' } ) ),
	subcategories: 0,
	products: 3,
	fallback: into ? 0 : 1,
	into: into ? { id: into, path: 'Kitchen › Pans' } : null,
	default: { id: 5, path: 'Uncategorised' },
	token: 'tok',
} );

function routes() {
	route( apiFetch, {
		fields: () => REGISTRY,
		categories: () => JSON.parse( JSON.stringify( current ) ),
		products: () => ( {
			items: ROWS,
			pagination: { page: 1, perPage: 10, total: 2, totalPages: 1 },
		} ),
		'categories/delete': ( query, options ) => {
			posts.push( options.data );
			return options.data.preview
				? preview( options, 0 )
				: {
						batch_id: 8,
						status: 'complete',
						deleted: options.data.ids,
						failed: [],
					};
		},
		'categories/merge': ( query, options ) => {
			posts.push( options.data );
			return options.data.preview
				? preview( options, options.data.into )
				: {
						batch_id: 9,
						status: 'complete',
						deleted: options.data.ids,
						failed: [],
					};
		},
		'categories/2': ( query, options ) => {
			if ( options.method === 'PATCH' ) {
				posts.push( options.data );
				return {
					item: { ...node( 2, 'Pots' ), ...options.data },
					batch_id: 10,
					status: 'complete',
				};
			}
			return { ...node( 2, 'Pots' ), display_type: '', thumbnail_id: 0 };
		},
		'categories/6/default': ( query, options ) => {
			posts.push( { url: options.url } );
			return { item: { id: 6, default: true }, status: 'complete' };
		},
	} );
}

async function renderTab() {
	render(
		<ShellContext.Provider
			value={ {
				exportSlot: null,
				bulkRequested: false,
				consumeBulk: () => {},
				navigate: jest.fn(),
			} }
		>
			<CategoriesTab settings={ MANAGER } />
		</ShellContext.Provider>
	);
	await screen.findByRole( 'tree' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Expand all' } ) );
}

const item = ( name ) =>
	screen
		.getAllByRole( 'treeitem' )
		.find( ( el ) =>
			el.getAttribute( 'aria-label' ).startsWith( name + ',' )
		);
const rowOf = ( name ) => item( name ).querySelector( '.bulkcat-cat-row' );
const reads = () =>
	apiFetch.mock.calls.filter(
		( [ options ] ) =>
			options.url.split( '?' )[ 0 ] === 'categories' && ! options.method
	).length;
const menuLabels = ( name ) => {
	fireEvent.click(
		screen.getByRole( 'button', { name: `More actions for ${ name }` } )
	);
	const menu = screen.getByRole( 'menu', { name: `Actions for ${ name }` } );
	const labels = within( menu )
		.getAllByRole( 'menuitem' )
		.map( ( el ) => el.textContent );
	return { menu, labels };
};
const EXTRA = [ 'Make default', 'Delete…', 'Merge into…' ];

let speak;

beforeEach( () => {
	apiFetch.mockReset();
	window.localStorage.clear();
	speak = jest.fn();
	window.wp = { a11y: { speak } };
	current = TREE();
	posts = [];
	routes();
} );

afterEach( () => {
	delete window.wp;
} );

describe( 'workspace category actions: registrations', () => {
	it( 'registers Make default for one, Delete… and Merge into… for many, none for the default', () => {
		const tree = { byId: indexTree( TREE() ) };
		const keys = ( ids ) =>
			categoryActions( ids.length, { ids, tree } ).map(
				( action ) => action.key
			);
		const byKey = Object.fromEntries(
			categoryActions( 1 ).map( ( action ) => [ action.key, action ] )
		);
		expect( byKey.delete ).toMatchObject( {
			label: 'Delete…',
			isDestructive: true,
			many: true,
		} );
		expect( byKey.merge ).toMatchObject( {
			label: 'Merge into…',
			many: true,
		} );
		expect( byKey.merge.isDestructive ).toBeFalsy();
		expect( byKey.default ).toMatchObject( { label: 'Make default' } );
		expect( byKey.default.many ).toBeFalsy();

		expect( keys( [ 6 ] ) ).toEqual( [ 'default', 'delete', 'merge' ] );
		expect( keys( [ 5 ] ) ).toEqual( [] );
		expect( keys( [ 2, 6 ] ) ).toEqual( [ 'delete', 'merge' ] );
		// The default among several: the others can still go.
		expect( keys( [ 5, 6 ] ) ).toEqual( [ 'delete', 'merge' ] );
	} );
} );

describe( 'workspace category actions: panes and row menu', () => {
	it( 'shows the Default badge in the tree and the details, and offers nothing that WooCommerce refuses for it', async () => {
		await renderTab();
		expect(
			within( item( 'Uncategorised' ) ).getByText( 'Default' )
		).toBeTruthy();
		expect( within( item( 'Bags' ) ).queryByText( 'Default' ) ).toBeNull();

		fireEvent.click( rowOf( 'Uncategorised' ) );
		const pane = await screen.findByRole( 'region', {
			name: 'Uncategorised',
		} );
		expect(
			pane.querySelector( '.bulkcat-cat-pane__head .bulkcat-cat-default' )
				.textContent
		).toBe( 'Default' );
		EXTRA.forEach( ( name ) =>
			expect(
				within( pane ).queryByRole( 'button', { name } )
			).toBeNull()
		);
		const { menu, labels } = menuLabels( 'Uncategorised' );
		EXTRA.forEach( ( name ) => expect( labels ).not.toContain( name ) );
		fireEvent.keyDown( menu, { key: 'Escape' } );
	} );

	it( 'offers the same actions in the row menu and the details pane', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Bags' ) );
		const pane = await screen.findByRole( 'region', { name: 'Bags' } );
		EXTRA.forEach( ( name ) =>
			expect(
				within( pane ).getByRole( 'button', { name } )
			).toBeTruthy()
		);
		expect(
			within( pane )
				.getByRole( 'button', { name: 'Delete…' } )
				.classList.contains( 'is-destructive' )
		).toBe( true );
		expect(
			pane.querySelector( '.bulkcat-cat-pane__head .bulkcat-cat-default' )
		).toBeNull();
		const { labels } = menuLabels( 'Bags' );
		expect( labels ).toEqual( expect.arrayContaining( EXTRA ) );
	} );

	it( 'makes a category the default from the details pane, reads the tree again and says so', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Bags' ) );
		const pane = await screen.findByRole( 'region', { name: 'Bags' } );
		const before = reads();
		current = TREE();
		current[ 1 ].default = false;
		current[ 2 ].default = true;
		await act( async () => {
			fireEvent.click(
				within( pane ).getByRole( 'button', { name: 'Make default' } )
			);
		} );
		expect( posts ).toEqual( [ { url: 'categories/6/default' } ] );
		expect( reads() ).toBeGreaterThan( before );
		expect( speak ).toHaveBeenCalledWith(
			'Bags is now the default category. Products left without a category are put in it.',
			'polite'
		);
		const after = await screen.findByRole( 'region', { name: 'Bags' } );
		expect(
			await within( after ).findByText( 'Default', {
				selector: '.bulkcat-cat-default',
			} )
		).toBeTruthy();
		expect(
			within( after ).queryByRole( 'button', { name: 'Make default' } )
		).toBeNull();
	} );

	it( 'deletes one category from the details pane, clears the pane and says so', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Bags' ) );
		const pane = await screen.findByRole( 'region', { name: 'Bags' } );
		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Delete…' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete this category',
		} );
		await within( dialog ).findByText(
			'1 product has no other category, so it goes into the default category, Uncategorised.'
		);
		const before = reads();
		current = TREE().filter( ( each ) => each.id !== 6 );
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Delete' } )
			);
		} );
		expect( posts ).toEqual( [
			{ ids: [ 6 ], preview: true },
			{ ids: [ 6 ], token: 'tok' },
		] );
		expect( speak ).toHaveBeenCalledWith( '1 category deleted.', 'polite' );
		expect( reads() ).toBeGreaterThan( before );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect(
			await screen.findByText(
				'Choose a category on the left to see its details and products.'
			)
		).toBeTruthy();
	} );

	it( 'deletes two chosen categories from the selection pane', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Pots' ) );
		fireEvent.click( rowOf( 'Bags' ), { ctrlKey: true } );
		const pane = await screen.findByRole( 'region', {
			name: '2 categories selected',
		} );
		expect(
			within( pane ).queryByRole( 'button', { name: 'Make default' } )
		).toBeNull();
		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Delete…' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete these categories',
		} );
		expect( within( dialog ).getByText( 'Kitchen › Pots' ) ).toBeTruthy();
		expect( within( dialog ).getByText( 'Bags' ) ).toBeTruthy();
		await within( dialog ).findByText(
			'3 products lose these categories. The products themselves are kept.'
		);
		current = TREE().filter( ( each ) => each.id !== 6 );
		current[ 0 ].children = [ current[ 0 ].children[ 1 ] ];
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Delete' } )
			);
		} );
		expect( posts.pop() ).toEqual( { ids: [ 2, 6 ], token: 'tok' } );
		expect( speak ).toHaveBeenCalledWith(
			'2 categories deleted.',
			'polite'
		);
		expect(
			await screen.findByText(
				'Choose a category on the left to see its details and products.'
			)
		).toBeTruthy();
	} );

	it( 'leaves the default category out of a delete of several, and says so', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Uncategorised' ) );
		fireEvent.click( rowOf( 'Bags' ), { ctrlKey: true } );
		const pane = await screen.findByRole( 'region', {
			name: '2 categories selected',
		} );
		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Delete…' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete this category',
		} );
		expect( within( dialog ).queryByText( 'Uncategorised' ) ).toBeNull();
		expect(
			within( dialog ).getByText(
				'The default category, Uncategorised, is left out: WooCommerce never deletes it.'
			)
		).toBeTruthy();
		await within( dialog ).findByText(
			'3 products lose these categories. The products themselves are kept.'
		);
		expect( posts ).toEqual( [ { ids: [ 6 ], preview: true } ] );
	} );

	it( 'opens Merge into… with nothing chosen and nothing checked, so no refusal shows before a choice (1.3.4)', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Pots' ) );
		const pane = await screen.findByRole( 'region', { name: 'Pots' } );
		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Merge into…' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Merge this category into another',
		} );
		const select = within( dialog ).getByLabelText( 'Merge into' );
		expect( select.value ).toBe( '' );
		expect(
			within( dialog ).getByText( 'Choose the category to merge into.' )
		).toBeTruthy();
		expect( within( dialog ).queryByRole( 'alert' ) ).toBeNull();
		expect( dialog.textContent ).not.toContain(
			'Checking what this would change'
		);
		expect( posts ).toEqual( [] );
		expect(
			within( dialog )
				.getByRole( 'button', { name: 'Merge' } )
				.getAttribute( 'aria-disabled' )
		).toBe( 'true' );
		fireEvent.change( select, { target: { value: '3' } } );
		await within( dialog ).findByText(
			'3 products move into Kitchen › Pans.'
		);
		expect( posts ).toEqual( [ { ids: [ 2 ], into: 3, preview: true } ] );
	} );

	it( 'says visibly that the details of a category were saved (1.3.4)', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Pots' ) );
		const pane = await screen.findByRole( 'region', { name: 'Pots' } );
		fireEvent.click(
			within( pane ).getByRole( 'button', { name: 'Edit details' } )
		);
		fireEvent.change(
			await within( pane ).findByLabelText( 'Description' ),
			{
				target: { value: 'Pots of every size' },
			}
		);
		await act( async () => {
			fireEvent.click(
				within( pane ).getByRole( 'button', { name: 'Save category' } )
			);
		} );
		expect( posts.pop() ).toEqual( { description: 'Pots of every size' } );
		expect(
			await within( pane ).findByText( 'Category "Pots" saved.' )
		).toBeTruthy();
		// Choosing another category starts afresh.
		fireEvent.click( rowOf( 'Pans' ) );
		const pans = await screen.findByRole( 'region', { name: 'Pans' } );
		expect( within( pans ).queryByText( /saved\./ ) ).toBeNull();
	} );

	it( 'merges from the row menu of one of several chosen, keeping the target chosen', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Pots' ) );
		fireEvent.click( rowOf( 'Bags' ), { ctrlKey: true } );
		await screen.findByRole( 'region', { name: '2 categories selected' } );
		const { menu } = menuLabels( 'Bags' );
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', { name: 'Merge into…' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Merge these categories into another',
		} );
		fireEvent.change( within( dialog ).getByLabelText( 'Merge into' ), {
			target: { value: '3' },
		} );
		await within( dialog ).findByText(
			'3 products move into Kitchen › Pans.'
		);
		current = TREE().filter( ( each ) => each.id !== 6 );
		current[ 0 ].children = [ current[ 0 ].children[ 1 ] ];
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Merge' } )
			);
		} );
		expect( posts.pop() ).toEqual( {
			ids: [ 2, 6 ],
			into: 3,
			token: 'tok',
		} );
		expect( speak ).toHaveBeenCalledWith(
			'2 categories merged into Kitchen › Pans.',
			'polite'
		);
		expect(
			await screen.findByRole( 'region', { name: 'Pans' } )
		).toBeTruthy();
		expect( item( 'Pans' ).getAttribute( 'aria-selected' ) ).toBe( 'true' );
	} );
} );
