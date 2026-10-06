/**
 * <CategoryPanel> (T61, T62, T64, T65, T66): the tree renders nested nodes
 * with counts, searches, selects and emits the filter value with its
 * sentence, remembers its width and collapsed state per user, shows the
 * empty and read-only states, moves by drag and through Move to…, hands a
 * dropped product selection on, walks by keyboard and announces through
 * wp.a11y.speak.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import {
	CATEGORY_DRAG_TYPE,
	CategoryPanel,
	SELECTION_DRAG_TYPE,
} from '../../assets/src/panels/categories/index';
import { storageKey } from '../../assets/src/panels/categories/storage';
import {
	coreJson,
	expectNoRawText,
	expectPlain,
	html500,
	htmlPage,
} from './error-fixtures';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;

const node = ( id, name, children = [], count = 1, all = count ) => ( {
	id,
	name,
	slug: name.toLowerCase(),
	parent: 0,
	count,
	countWithDescendants: all,
	children,
} );

const TREE = [
	node(
		1,
		'Clothing',
		[
			node( 2, 'T-Shirts', [ node( 4, 'Short sleeve', [], 5 ) ], 10, 15 ),
			node( 3, 'Hats', [], 0 ),
		],
		5,
		20
	),
	node( 5, 'Drinkware', [], 7 ),
	node( 6, 'Bags', [], 3 ),
];

const MANAGER = {
	user: { id: 7, name: 'Mia', capabilities: { manage_product_terms: true } },
	settings: { category_panel: true },
};
const VIEWER = { user: { id: 8, name: 'Sam' }, settings: {} };

let routes;

/**
 * Answer apiFetch by method and route; unknown calls fail loudly.
 *
 * @param {Object} options apiFetch options.
 * @return {Promise<*>} Answer.
 */
function answer( options ) {
	const route = options.url.split( '?' )[ 0 ];
	const key = `${ options.method || 'GET' } ${ route }`;
	const handler = routes[ key ];
	if ( ! handler ) {
		return Promise.reject( new Error( `unexpected ${ key }` ) );
	}
	return handler( options );
}

/**
 * Render the panel and wait for the tree.
 *
 * @param {Object} props Props.
 * @return {Object} Render result plus onChange.
 */
async function renderPanel( props = {} ) {
	const onChange = jest.fn();
	const result = render(
		<CategoryPanel
			settings={ MANAGER }
			onChange={ onChange }
			{ ...props }
		/>
	);
	await screen.findByRole( 'tree' );
	return { ...result, onChange };
}

const item = ( name ) =>
	screen
		.getAllByRole( 'treeitem' )
		.find( ( el ) =>
			el.getAttribute( 'aria-label' ).startsWith( name + ',' )
		);

const rowOf = ( name ) => item( name ).querySelector( '.bulkcat-cat-row' );

const names = () =>
	screen
		.getAllByRole( 'treeitem' )
		.map( ( el ) => el.getAttribute( 'aria-label' ) );

describe( 'category panel', () => {
	let speak;

	beforeEach( () => {
		window.localStorage.clear();
		speak = jest.fn();
		window.wp = { a11y: { speak } };
		routes = {
			'GET categories': () => Promise.resolve( TREE ),
		};
		apiFetch.mockReset();
		apiFetch.mockImplementation( answer );
	} );

	afterEach( () => {
		delete window.wp;
	} );

	it( 'renders nested nodes with counts and loads with the grid filters', async () => {
		await renderPanel( {
			query: {
				search: 'shirt',
				stock_status: { operator: 'is', value: 'instock' },
			},
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toContain( 'categories?' );
		expect(
			decodeURIComponent( apiFetch.mock.calls[ 0 ][ 0 ].url )
		).toContain( 'stock_status[operator]=is' );
		expect( names() ).toEqual( [
			'All products',
			'Clothing, 20 products',
			'Drinkware, 7 products',
			'Bags, 3 products',
		] );
		const clothing = item( 'Clothing' );
		expect( clothing.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( clothing.getAttribute( 'aria-level' ) ).toBe( '1' );

		fireEvent.click( clothing.querySelector( '.bulkcat-cat-twist' ) );
		expect( names() ).toEqual( [
			'All products',
			'Clothing, 20 products',
			'T-Shirts, 15 products',
			'Hats, 0 products',
			'Drinkware, 7 products',
			'Bags, 3 products',
		] );
		expect( item( 'T-Shirts' ).getAttribute( 'aria-level' ) ).toBe( '2' );
		expect( item( 'T-Shirts' ).getAttribute( 'aria-posinset' ) ).toBe(
			'1'
		);
		expect( item( 'T-Shirts' ).getAttribute( 'aria-setsize' ) ).toBe( '2' );
		expect( speak ).toHaveBeenCalledWith( 'Clothing expanded', 'polite' );
	} );

	it( 'filters the tree by search, keeping the path to each match, and Show all clears it', async () => {
		const { onChange } = await renderPanel();
		fireEvent.change( screen.getByRole( 'searchbox' ), {
			target: { value: 'sleeve' },
		} );
		expect( names() ).toEqual( [
			'Clothing, 20 products',
			'T-Shirts, 15 products',
			'Short sleeve, 5 products',
		] );
		fireEvent.change( screen.getByRole( 'searchbox' ), {
			target: { value: 'zzz' },
		} );
		expect(
			screen.getByText( 'No categories match this search.' )
		).toBeTruthy();

		fireEvent.click( screen.getByRole( 'button', { name: 'Show all' } ) );
		expect( screen.getByRole( 'searchbox' ).value ).toBe( '' );
		expect( onChange ).toHaveBeenLastCalledWith( null, '' );
		expect( names()[ 0 ] ).toBe( 'All products' );
	} );

	it( 'emits the filter value and its sentence, with or without sub-categories', async () => {
		const { onChange, rerender } = await renderPanel();
		fireEvent.click( rowOf( 'Clothing' ) );
		expect( onChange ).toHaveBeenLastCalledWith(
			{ id: 1, includeChildren: true },
			'Category is Clothing or its sub-categories'
		);
		expect( speak ).toHaveBeenLastCalledWith(
			'Category is Clothing or its sub-categories',
			'polite'
		);

		rerender(
			<CategoryPanel
				settings={ MANAGER }
				onChange={ onChange }
				value={ { id: 1, includeChildren: true } }
			/>
		);
		expect( item( 'Clothing' ).getAttribute( 'aria-selected' ) ).toBe(
			'true'
		);

		// The toggle: this category only, and the counts become each node's own.
		fireEvent.click(
			screen.getByRole( 'checkbox', { name: 'Include sub-categories' } )
		);
		expect( onChange ).toHaveBeenLastCalledWith(
			{ id: 1, includeChildren: false },
			'Category is Clothing'
		);
		expect( names()[ 1 ] ).toBe( 'Clothing, 5 products' );

		// Ctrl or Cmd click adds another.
		fireEvent.click( rowOf( 'Drinkware' ), { ctrlKey: true } );
		expect( onChange ).toHaveBeenLastCalledWith(
			{ id: 1, ids: [ 1, 5 ], includeChildren: false },
			'Category is Clothing or Drinkware'
		);

		// All products clears.
		fireEvent.click(
			screen
				.getAllByRole( 'treeitem' )[ 0 ]
				.querySelector( '.bulkcat-cat-row' )
		);
		expect( onChange ).toHaveBeenLastCalledWith( null, '' );
		expect( speak ).toHaveBeenLastCalledWith(
			'Showing all products.',
			'polite'
		);
	} );

	it( 'remembers its width and collapsed state per user, starting from the setting', async () => {
		render(
			<CategoryPanel
				settings={ { ...MANAGER, settings: { category_panel: false } } }
			/>
		);
		// Collapsed by default: no tree, a Show categories control.
		expect( screen.queryByRole( 'tree' ) ).toBeNull();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show categories' } )
		);
		await screen.findByRole( 'tree' );
		const edge = screen.getByRole( 'separator', {
			name: 'Resize the category panel',
		} );
		expect( edge.getAttribute( 'aria-valuenow' ) ).toBe( '236' );
		fireEvent.keyDown( edge, { key: 'ArrowRight' } );
		fireEvent.keyDown( edge, { key: 'ArrowRight' } );
		expect( edge.getAttribute( 'aria-valuenow' ) ).toBe( '268' );
		expect(
			JSON.parse( window.localStorage.getItem( storageKey( 7 ) ) )
		).toEqual( { width: 268, collapsed: false } );

		// Drag the edge.
		// jsdom has no PointerEvent; a MouseEvent of the same type carries clientX.
		const pointer = ( type, clientX ) =>
			new window.MouseEvent( type, { bubbles: true, clientX } );
		act( () => {
			edge.dispatchEvent( pointer( 'pointerdown', 500 ) );
		} );
		act( () => {
			window.dispatchEvent( pointer( 'pointermove', 440 ) );
			window.dispatchEvent( pointer( 'pointerup', 440 ) );
		} );
		expect( edge.getAttribute( 'aria-valuenow' ) ).toBe( '208' );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Hide categories' } )
		);
		expect(
			JSON.parse( window.localStorage.getItem( storageKey( 7 ) ) )
		).toEqual( { width: 208, collapsed: true } );
	} );

	it( 'restores the stored state on reload', async () => {
		window.localStorage.setItem(
			storageKey( 7 ),
			JSON.stringify( { width: 300, collapsed: false } )
		);
		const { container } = await renderPanel( {
			settings: { ...MANAGER, settings: { category_panel: false } },
		} );
		expect(
			container.querySelector( '.bulkcat-cat-panel' ).style.width
		).toBe( '300px' );
	} );

	it( 'shows the empty state with Create your first category', async () => {
		routes[ 'GET categories' ] = () => Promise.resolve( [] );
		render( <CategoryPanel settings={ MANAGER } /> );
		expect(
			await screen.findByText( 'You have no product categories yet.' )
		).toBeTruthy();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create your first category' } )
		);
		expect(
			screen.getByRole( 'dialog', { name: 'Add a category' } )
		).toBeTruthy();
	} );

	it( 'is read-only without manage_product_terms, with a one-line reason', async () => {
		await renderPanel( { settings: VIEWER } );
		expect(
			screen.getByText(
				'You can browse and filter by categories, but changing them needs permission to manage product categories.'
			)
		).toBeTruthy();
		expect(
			screen.queryByRole( 'button', { name: /More actions/ } )
		).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: '+ Add category' } )
		).toBeNull();
		expect(
			document.querySelectorAll( '.bulkcat-cat-row[draggable="true"]' )
				.length
		).toBe( 0 );
		fireEvent.keyDown( item( 'Clothing' ), { key: 'F2' } );
		expect( screen.queryByRole( 'textbox' ) ).toBeNull();
		fireEvent.keyDown( item( 'Clothing' ), { key: 'F10', shiftKey: true } );
		expect( screen.queryByRole( 'menu' ) ).toBeNull();
		// Browsing and filtering still work.
		expect( names()[ 1 ] ).toBe( 'Clothing, 20 products' );
	} );

	it( 'walks the tree by keyboard with a roving tab index and type-ahead', async () => {
		const { onChange } = await renderPanel();
		const tabbable = () =>
			screen
				.getAllByRole( 'treeitem' )
				.filter( ( el ) => el.tabIndex === 0 );
		expect( tabbable() ).toHaveLength( 1 );
		expect( tabbable()[ 0 ].getAttribute( 'aria-label' ) ).toBe(
			'All products'
		);

		const tree = screen.getByRole( 'tree' );
		const press = ( key, extra = {} ) =>
			fireEvent.keyDown( document.activeElement, { key, ...extra } );

		act( () => tabbable()[ 0 ].focus() );
		press( 'ArrowDown' );
		expect( document.activeElement ).toBe( item( 'Clothing' ) );
		expect( tabbable() ).toEqual( [ item( 'Clothing' ) ] );

		press( 'ArrowRight' ); // expands
		expect( item( 'Clothing' ).getAttribute( 'aria-expanded' ) ).toBe(
			'true'
		);
		press( 'ArrowRight' ); // first child
		expect( document.activeElement ).toBe( item( 'T-Shirts' ) );
		press( 'ArrowLeft' ); // to the parent
		expect( document.activeElement ).toBe( item( 'Clothing' ) );
		press( 'ArrowLeft' ); // collapses
		expect( item( 'Clothing' ).getAttribute( 'aria-expanded' ) ).toBe(
			'false'
		);
		expect( speak ).toHaveBeenCalledWith( 'Clothing collapsed', 'polite' );

		press( 'End' );
		expect( document.activeElement ).toBe( item( 'Bags' ) );
		press( 'Home' );
		expect( document.activeElement.getAttribute( 'aria-label' ) ).toBe(
			'All products'
		);

		// The test keeps the type-ahead clock itself (ticket 11), so a slow
		// run cannot turn two quick keys into two separate searches.
		let clock = Date.now();
		const now = jest.spyOn( Date, 'now' ).mockImplementation( () => clock );
		press( 'd' );
		expect( document.activeElement ).toBe( item( 'Drinkware' ) );
		// A slow run: the machine pauses between two keys typed together.
		const until = performance.now() + 600;
		while ( performance.now() < until ) {
			// Busy: as a loaded test runner would be.
		}
		clock += 100;
		press( 'b' );
		// "db" matches nothing, so focus stays; a fresh letter after the pause would.
		expect( document.activeElement ).toBe( item( 'Drinkware' ) );
		clock += 10000;
		press( 'b' );
		now.mockRestore();
		expect( document.activeElement ).toBe( item( 'Bags' ) );

		press( 'Enter' );
		expect( onChange ).toHaveBeenLastCalledWith(
			{ id: 6, includeChildren: true },
			'Category is Bags or its sub-categories'
		);
		press( 'ArrowUp' );
		press( ' ', { ctrlKey: true } );
		expect( onChange ).toHaveBeenLastCalledWith(
			{ id: 5, includeChildren: true },
			'Category is Drinkware or its sub-categories'
		);
		expect( tree.getAttribute( 'aria-multiselectable' ) ).toBe( 'true' );
	} );

	it( 'moves a category through the Move to… dialog from the keyboard', async () => {
		routes[ 'POST categories/6/move' ] = () =>
			Promise.resolve( {
				item: { id: 6 },
				batch_id: 9,
				status: 'complete',
			} );
		await renderPanel();
		act( () => item( 'Bags' ).focus() );
		fireEvent.keyDown( item( 'Bags' ), { key: 'F10', shiftKey: true } );
		const menu = screen.getByRole( 'menu', { name: 'Actions for Bags' } );
		expect( document.activeElement ).toBe(
			within( menu ).getByRole( 'menuitem', { name: 'Rename' } )
		);
		expect(
			within( menu )
				.getAllByRole( 'menuitem' )
				.map( ( el ) => el.textContent )
		).toEqual( [
			'Rename',
			'Edit details…',
			'Move to…',
			'Make default',
			'Delete…',
			'Merge into…',
		] );
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', { name: 'Move to…' } )
		);

		const dialog = screen.getByRole( 'dialog', { name: 'Move “Bags”' } );
		fireEvent.change( within( dialog ).getByLabelText( 'Move into' ), {
			target: { value: '1' },
		} );
		expect(
			within( dialog ).getByText( 'New place: Clothing › Bags' )
		).toBeTruthy();
		fireEvent.change( within( dialog ).getByLabelText( 'Position' ), {
			target: { value: '1' },
		} );
		expect(
			within( dialog )
				.getAllByRole( 'option' )
				.map( ( el ) => el.textContent )
				.slice( -3 )
		).toEqual( [ 'At the end', 'Before “T-Shirts”', 'Before “Hats”' ] );
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Move' } )
			);
		} );
		const move = apiFetch.mock.calls.find(
			( [ o ] ) => o.method === 'POST'
		)[ 0 ];
		expect( move.url ).toBe( 'categories/6/move' );
		expect( move.data ).toEqual( { parent: 1, position: 1 } );
		expect( speak ).toHaveBeenCalledWith(
			'Bags moved to Clothing.',
			'polite'
		);
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);
		// The tree reloads after the write.
		expect(
			apiFetch.mock.calls.filter( ( [ o ] ) => ! o.method ).length
		).toBe( 2 );
	} );

	it( 'shows a refused move in plain words with the details behind a toggle', async () => {
		routes[ 'POST categories/6/move' ] = () =>
			Promise.reject( {
				code: 'bulkcat_category_exists',
				message:
					'A category called "Bags" already exists in "Clothing". Choose a different name, or put it somewhere else.',
				data: { status: 400 },
			} );
		await renderPanel();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'More actions for Bags' } )
		);
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Move to…' } ) );
		const dialog = screen.getByRole( 'dialog' );
		fireEvent.change( within( dialog ).getByLabelText( 'Move into' ), {
			target: { value: '1' },
		} );
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Move' } )
			);
		} );
		expect(
			within( dialog ).getByText(
				'A category called "Bags" already exists in "Clothing". Choose a different name, or put it somewhere else.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).queryByText( /bulkcat_category_exists/ )
		).toBeNull();
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Show details' } )
		);
		expect(
			within( dialog ).getByText( 'bulkcat_category_exists · HTTP 400' )
		).toBeTruthy();
		expect( speak ).toHaveBeenCalledWith(
			'A category called "Bags" already exists in "Clothing". Choose a different name, or put it somewhere else.',
			'assertive'
		);
	} );

	it( 'drags a category onto another to re-parent it, with a drop indicator and the new path', async () => {
		routes[ 'POST categories/5/move' ] = () =>
			Promise.resolve( { item: { id: 5 } } );
		await renderPanel();
		const data = new Map();
		const dataTransfer = {
			setData: ( type, value ) => data.set( type, value ),
			getData: ( type ) => data.get( type ),
			get types() {
				return [ ...data.keys() ];
			},
		};
		fireEvent.dragStart( rowOf( 'Drinkware' ), { dataTransfer } );
		expect( data.get( CATEGORY_DRAG_TYPE ) ).toBe( '5' );
		fireEvent.dragOver( item( 'Bags' ), { dataTransfer } );
		expect( rowOf( 'Bags' ).className ).toContain( 'is-drop-inside' );
		expect(
			screen.getByText( 'New place: Bags › Drinkware' )
		).toBeTruthy();
		await act( async () => {
			fireEvent.drop( item( 'Bags' ), { dataTransfer } );
		} );
		const move = apiFetch.mock.calls.find(
			( [ o ] ) => o.method === 'POST'
		)[ 0 ];
		expect( move.url ).toBe( 'categories/5/move' );
		expect( move.data ).toEqual( { parent: 6 } );
		expect( speak ).toHaveBeenCalledWith(
			'Drinkware moved to Bags.',
			'polite'
		);
	} );

	it( 'blocks dropping a category inside itself', async () => {
		await renderPanel();
		fireEvent.click(
			item( 'Clothing' ).querySelector( '.bulkcat-cat-twist' )
		);
		const dataTransfer = { setData: () => {}, types: [] };
		fireEvent.dragStart( rowOf( 'Clothing' ), { dataTransfer } );
		fireEvent.dragOver( item( 'T-Shirts' ), { dataTransfer } );
		expect( rowOf( 'T-Shirts' ).className ).toContain( 'is-drop-blocked' );
		expect(
			screen.getByText( 'A category cannot be moved inside itself.' )
		).toBeTruthy();
		fireEvent.drop( item( 'T-Shirts' ), { dataTransfer } );
		expect( apiFetch.mock.calls.some( ( [ o ] ) => o.method ) ).toBe(
			false
		);
		expect( speak ).toHaveBeenCalledWith(
			'A category cannot be moved inside itself.',
			'assertive'
		);
	} );

	it( 'hands a dropped product selection to onDropSelection and writes nothing', async () => {
		const onDropSelection = jest.fn();
		await renderPanel( { onDropSelection } );
		const dataTransfer = { types: [ SELECTION_DRAG_TYPE ], dropEffect: '' };
		fireEvent.dragOver( item( 'Drinkware' ), { dataTransfer } );
		expect( rowOf( 'Drinkware' ).className ).toContain( 'is-drop-inside' );
		expect(
			screen.getByText(
				'Drop to add the selected products to “Drinkware”.'
			)
		).toBeTruthy();
		fireEvent.drop( item( 'Drinkware' ), { dataTransfer } );
		expect( onDropSelection ).toHaveBeenCalledWith( 5 );
		expect( apiFetch.mock.calls.some( ( [ o ] ) => o.method ) ).toBe(
			false
		);
	} );

	it( 'renames inline with F2 and reports a refusal', async () => {
		routes[ 'PATCH categories/5' ] = () =>
			Promise.reject( {
				code: 'bulkcat_category_exists',
				message:
					'A category called "Bags" already exists at the top level. Choose a different name, or put it somewhere else.',
				data: { status: 400 },
			} );
		await renderPanel();
		act( () => item( 'Drinkware' ).focus() );
		fireEvent.keyDown( item( 'Drinkware' ), { key: 'F2' } );
		const input = screen.getByRole( 'textbox', {
			name: 'New name for Drinkware',
		} );
		fireEvent.change( input, { target: { value: 'Bags' } } );
		await act( async () => {
			fireEvent.keyDown( input, { key: 'Enter' } );
		} );
		const patch = apiFetch.mock.calls.find(
			( [ o ] ) => o.method === 'PATCH'
		)[ 0 ];
		expect( patch.data ).toEqual( { name: 'Bags' } );
		expect(
			screen.getByText(
				'A category called "Bags" already exists at the top level. Choose a different name, or put it somewhere else.'
			)
		).toBeTruthy();
		expect( document.activeElement ).toBe( item( 'Drinkware' ) );
	} );

	it( 'falls back to its own live region without wp.a11y', async () => {
		delete window.wp;
		await renderPanel();
		fireEvent.click( rowOf( 'Bags' ) );
		expect(
			document.querySelector( '[data-bulkcat-announcer]' ).textContent
		).toBe( 'Category is Bags or its sub-categories' );
	} );

	it( 'says a server’s HTML error page in plain words, never its text (ticket 11)', async () => {
		routes[ 'GET categories' ] = () => Promise.reject( html500() );
		render( <CategoryPanel settings={ MANAGER } onChange={ jest.fn() } /> );
		const sentence = 'The categories could not be loaded.';
		const box = (
			await screen.findByText( sentence, { selector: 'p' } )
		).closest( '.components-notice' );
		expectPlain( box, sentence );
		fireEvent.click(
			within( box ).getByRole( 'button', { name: 'Show details' } )
		);
		expect(
			within( box ).getByText( 'internal_server_error · HTTP 500' )
		).toBeTruthy();
		expectNoRawText();

		// A move answered by an HTML page: the dialog and the announcement say the plain sentence.
		routes[ 'GET categories' ] = () => Promise.resolve( TREE );
		routes[ 'POST categories/6/move' ] = () => Promise.reject( htmlPage() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await screen.findByRole( 'tree' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'More actions for Bags' } )
		);
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Move to…' } ) );
		const dialog = screen.getByRole( 'dialog' );
		fireEvent.change( within( dialog ).getByLabelText( 'Move into' ), {
			target: { value: '1' },
		} );
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Move' } )
			);
		} );
		expectPlain(
			dialog,
			'The category could not be moved. Try again in a moment.'
		);
		expectNoRawText();
		expect( speak ).not.toHaveBeenCalledWith(
			expect.stringMatching( /not a valid JSON/ ),
			expect.anything()
		);
	} );

	it( 'shows a redacted refresh failure alongside the retained tree', async () => {
		const { rerender } = await renderPanel();
		routes[ 'GET categories' ] = () => Promise.reject( html500() );
		rerender(
			<CategoryPanel settings={ MANAGER } query={ { search: 'new' } } />
		);
		const sentence = 'The categories could not be loaded.';
		const box = (
			await screen.findByText( sentence, { selector: 'p' } )
		).closest( '.components-notice' );
		expectPlain( box, sentence );
		expect( item( 'Clothing' ) ).toBeTruthy();
		fireEvent.click(
			within( box ).getByRole( 'button', { name: 'Show details' } )
		);
		expect(
			within( box ).getByText( 'internal_server_error · HTTP 500' )
		).toBeTruthy();
		expectNoRawText();
		routes[ 'GET categories' ] = () => Promise.resolve( TREE );
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await waitFor( () =>
			expect( screen.queryByText( sentence ) ).toBeNull()
		);
	} );

	it.each( [ 'Cancel', 'Close', 'Escape' ] )(
		'returns create-dialog focus to the opener on %s',
		async ( action ) => {
			await renderPanel();
			const opener = screen.getByRole( 'button', {
				name: '+ Add category',
			} );
			// A pointer activation must restore focus even when the browser did not focus the button.
			fireEvent.click( opener );
			const dialog = screen.getByRole( 'dialog' );
			if ( action === 'Escape' ) {
				fireEvent.keyDown( dialog, { key: 'Escape', keyCode: 27 } );
			} else {
				fireEvent.click(
					within( dialog ).getByRole( 'button', { name: action } )
				);
			}
			await waitFor( () =>
				expect( screen.queryByRole( 'dialog' ) ).toBeNull()
			);
			expect( document.activeElement ).toBe( opener );
		}
	);

	it( 'says a JSON error that is not the plugin’s own refusal in plain words (ticket 11)', async () => {
		routes[ 'PATCH categories/6' ] = () => Promise.reject( coreJson() );
		await renderPanel();
		act( () => item( 'Bags' ).focus() );
		fireEvent.keyDown( item( 'Bags' ), { key: 'F2' } );
		const input = screen.getByRole( 'textbox', {
			name: 'New name for Bags',
		} );
		fireEvent.change( input, { target: { value: 'Totes' } } );
		await act( async () => {
			fireEvent.keyDown( input, { key: 'Enter' } );
		} );
		const sentence =
			'The category could not be renamed. Try again in a moment.';
		const box = (
			await screen.findByText( sentence, { selector: 'p' } )
		).closest( '.components-notice' );
		expectPlain( box, sentence );
		expect( speak ).toHaveBeenCalledWith( sentence, 'assertive' );
		expectNoRawText();
	} );

	it( 'reloads the tree when a rename was made but not recorded (SILENT-FAILURES-9)', async () => {
		const sentence =
			'The category was changed, but the change could not be recorded in History, so it cannot be undone there. Reload the categories to see it.';
		routes[ 'PATCH categories/6' ] = () =>
			Promise.reject( {
				code: 'bulkcat_save_failed',
				message: sentence,
				data: { status: 500, batch_id: 9, applied: true },
			} );
		await renderPanel();
		let reads = 0;
		routes[ 'GET categories' ] = () => {
			reads += 1;
			return Promise.resolve( TREE );
		};
		act( () => item( 'Bags' ).focus() );
		fireEvent.keyDown( item( 'Bags' ), { key: 'F2' } );
		const input = screen.getByRole( 'textbox', {
			name: 'New name for Bags',
		} );
		fireEvent.change( input, { target: { value: 'Totes' } } );
		await act( async () => {
			fireEvent.keyDown( input, { key: 'Enter' } );
		} );
		expect(
			await screen.findByText( sentence, { selector: 'p' } )
		).toBeTruthy();
		await waitFor( () => expect( reads ).toBe( 1 ) );
	} );

	it( 'is a button opening the tree as a sheet below 960 pixels', async () => {
		const matchMedia = jest.fn( () => ( {
			matches: true,
			addEventListener: jest.fn(),
			removeEventListener: jest.fn(),
		} ) );
		window.matchMedia = matchMedia;
		try {
			render( <CategoryPanel settings={ MANAGER } /> );
			expect( matchMedia ).toHaveBeenCalledWith( '(max-width: 959px)' );
			expect( screen.queryByRole( 'tree' ) ).toBeNull();
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Categories' } )
			);
			const sheet = screen.getByRole( 'dialog', { name: 'Categories' } );
			expect( await within( sheet ).findByRole( 'tree' ) ).toBeTruthy();
		} finally {
			delete window.matchMedia;
		}
	} );
} );
