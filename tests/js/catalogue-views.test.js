/**
 * T51, T52: the layout switch and the grid layout; saved views as the
 * segmented chips row — the built-ins from the registry, then the stored
 * views — with save, update, rename, duplicate, reorder (menu and drag),
 * default and delete each calling its /bulkcat/v1/views route; the default view
 * applied on load; and a view serialising and reading back unchanged,
 * an unrecognised key included.
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
	CatalogueLayout,
	LayoutSwitch,
	SavedViewsBar,
	builtInViews,
	fromSaved,
	toSaved,
	withLayout,
} from '../../assets/src/panels/catalogue/views';
import {
	moved,
	freeName,
} from '../../assets/src/panels/catalogue/views/use-saved-views';
import { gridOf } from '../../assets/src/panels/catalogue/views/cards';
import {
	defaultView,
	fieldsFromRegistry,
} from '../../assets/src/panels/catalogue/fields';
import {
	coreJson,
	expectNoRawText,
	expectPlain,
	html500,
} from './error-fixtures';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { REGISTRY, ROWS } = require( './catalogue-fixture' );

// DataViews' grid measures itself; jsdom has no ResizeObserver.
global.ResizeObserver =
	global.ResizeObserver ||
	class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};

const SETTINGS = { settings: { rows_per_page: 25 } };
const BASE = defaultView( REGISTRY, SETTINGS );

const STORED = [
	{
		id: 'aaaaaaaaaaaa',
		name: 'Shirts',
		view: { ...toSaved( BASE ).view, search: 'shirt', type: 'grid' },
		category: { id: 3, includeChildren: true },
		isDefault: false,
	},
	{
		id: 'bbbbbbbbbbbb',
		name: 'Drafts',
		view: {
			...toSaved( BASE ).view,
			filters: [
				{ field: 'status', operator: 'isAny', value: [ 'draft' ] },
			],
		},
		category: null,
		isDefault: true,
	},
];

let answer;
function list( items = answer.items ) {
	return {
		items,
		default: items.find( ( item ) => item.isDefault )?.id || null,
		max: 50,
	};
}

beforeEach( () => {
	answer = list( STORED );
	apiFetch.mockReset();
	apiFetch.mockImplementation( ( { method = 'GET' } ) =>
		Promise.resolve( method === 'GET' ? answer : list() )
	);
} );

/** Every request as [ method, path, data ]. */
const requests = () =>
	apiFetch.mock.calls.map( ( [ options ] ) => [
		options.method || 'GET',
		options.url.split( '?' )[ 0 ],
		options.data,
	] );

async function renderBar( props = {} ) {
	const onApply = jest.fn();
	const utils = render(
		<SavedViewsBar
			registry={ REGISTRY }
			view={ BASE }
			category={ null }
			baseView={ BASE }
			onApply={ onApply }
			{ ...props }
		/>
	);
	await screen.findByRole( 'button', { name: 'Options for Drafts' } );
	return { ...utils, onApply };
}

function chipNames() {
	return within( screen.getByRole( 'group', { name: 'Saved views' } ) )
		.getAllByRole( 'button' )
		.filter(
			( button ) =>
				! /^Options for/.test(
					button.getAttribute( 'aria-label' ) || ''
				)
		)
		.map( ( button ) => button.textContent );
}

async function menu( name, item ) {
	fireEvent.click(
		screen.getByRole( 'button', { name: `Options for ${ name }` } )
	);
	fireEvent.click( await screen.findByRole( 'menuitem', { name: item } ) );
}

describe( 'built-in views', () => {
	it( 'offers only the presets the registry can filter by', () => {
		expect(
			builtInViews( REGISTRY ).map( ( preset ) => preset.name )
		).toEqual( [ 'All products', 'Hidden from site', 'Low stock' ] );
		const low = builtInViews( REGISTRY ).find(
			( p ) => p.name === 'Low stock'
		);
		expect( low.filters ).toEqual( [
			{ field: 'stock_status', operator: 'isAny', value: [ 'instock' ] },
			{ field: 'stock_quantity', operator: 'lessThanOrEqual', value: 2 },
		] );
		expect( builtInViews( REGISTRY )[ 1 ].filters ).toEqual( [
			{
				field: 'catalog_visibility',
				operator: 'isAny',
				value: [ 'hidden' ],
			},
		] );
	} );

	it( 'adds On sale once the sale price can be filtered', () => {
		const registry = {
			...REGISTRY,
			fields: REGISTRY.fields.map( ( field ) =>
				( field.editKinds || [] ).includes( 'copy_from_regular' )
					? {
							...field,
							dataviews: {
								...field.dataviews,
								filterBy: { operators: [ 'greaterThan' ] },
							},
						}
					: field
			),
		};
		const presets = builtInViews( registry );
		expect( presets[ 1 ].name ).toBe( 'On sale' );
		expect( presets[ 1 ].filters ).toEqual( [
			{ field: 'sale_price', operator: 'greaterThan', value: 0 },
		] );
	} );

	it( 'drops a preset whose fields the registry lacks', () => {
		expect( builtInViews( { fields: [] } ).map( ( p ) => p.name ) ).toEqual(
			[ 'All products' ]
		);
	} );
} );

describe( 'saved views row', () => {
	it( 'shows the built-ins, then the stored views in order, then Save', async () => {
		await renderBar();
		expect( chipNames() ).toEqual( [
			'All products',
			'Hidden from site',
			'Low stock',
			'Shirts',
			'Drafts(opens first)',
			'+ Save this view',
		] );
		expect( requests() ).toEqual( [ [ 'GET', 'views', undefined ] ] );
	} );

	it( 'applies the default view once the list arrives, so the screen opens with it', async () => {
		const { onApply } = await renderBar();
		await waitFor( () => expect( onApply ).toHaveBeenCalledTimes( 1 ) );
		const [ view, category ] = onApply.mock.calls[ 0 ];
		expect( view.filters ).toEqual( STORED[ 1 ].view.filters );
		expect( view.page ).toBe( 1 );
		expect( category ).toBeNull();
	} );

	it( 'does not apply the default when told not to', async () => {
		const { onApply } = await renderBar( { applyDefault: false } );
		await act( async () => {} );
		expect( onApply ).not.toHaveBeenCalled();
	} );

	it( 'marks the chip the screen shows as pressed', async () => {
		const shown = fromSaved( STORED[ 0 ], BASE );
		await renderBar( {
			view: shown.view,
			category: shown.category,
			applyDefault: false,
		} );
		expect(
			screen
				.getByRole( 'button', { name: 'Shirts' } )
				.getAttribute( 'aria-pressed' )
		).toBe( 'true' );
		expect(
			screen
				.getByRole( 'button', { name: 'All products' } )
				.getAttribute( 'aria-pressed' )
		).toBe( 'false' );
	} );

	it( 'applies a built-in and a saved view on click', async () => {
		const { onApply } = await renderBar( { applyDefault: false } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Low stock' } ) );
		expect( onApply.mock.calls[ 0 ][ 0 ].filters ).toHaveLength( 2 );
		expect( onApply.mock.calls[ 0 ][ 0 ].fields ).toEqual( BASE.fields );
		expect( onApply.mock.calls[ 0 ][ 1 ] ).toBeNull();
		fireEvent.click( screen.getByRole( 'button', { name: 'Shirts' } ) );
		const [ view, category ] = onApply.mock.calls[ 1 ];
		expect( view.type ).toBe( 'grid' );
		expect( view.search ).toBe( 'shirt' );
		expect( category ).toEqual( { id: 3, includeChildren: true } );
	} );

	it( 'saves the current view by name', async () => {
		const view = {
			...BASE,
			page: 4,
			search: 'golf',
			type: 'grid',
			perPage: 50,
		};
		const category = { id: 4, includeChildren: true };
		await renderBar( { view, category, applyDefault: false } );
		fireEvent.click(
			screen.getByRole( 'button', { name: '+ Save this view' } )
		);
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Golf' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save view' } ) );
		await waitFor( () => expect( requests() ).toHaveLength( 2 ) );
		const [ method, path, data ] = requests()[ 1 ];
		expect( [ method, path ] ).toEqual( [ 'POST', 'views' ] );
		expect( data.name ).toBe( 'Golf' );
		expect( data.isDefault ).toBe( false );
		expect( data.category ).toEqual( category );
		expect( data.view ).toEqual( toSaved( view ).view );
		expect( data.view.page ).toBeUndefined();
		expect( data.view ).toMatchObject( {
			search: 'golf',
			type: 'grid',
			perPage: 50,
		} );
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);
	} );

	it( 'shows a refused name inside the dialog', async () => {
		await renderBar( { applyDefault: false } );
		apiFetch.mockImplementation( ( { method = 'GET' } ) =>
			method === 'GET'
				? Promise.resolve( answer )
				: Promise.reject( {
						code: 'bulkcat_view_exists',
						message:
							'You already have a view called "Shirts". Choose another name.',
					} )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: '+ Save this view' } )
		);
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Shirts' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save view' } ) );
		expect( ( await screen.findByRole( 'alert' ) ).textContent ).toContain(
			'already have a view called'
		);
		expect( screen.getByRole( 'dialog' ) ).toBeTruthy();
	} );

	it( 'renames through PATCH', async () => {
		const view = { ...BASE, search: 'new' };
		await renderBar( { view, applyDefault: false } );

		await menu( 'Shirts', 'Rename' );
		const field = screen.getByLabelText( 'Name' );
		expect( field.value ).toBe( 'Shirts' );
		fireEvent.change( field, { target: { value: 'Tees' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Rename' } ) );
		await waitFor( () => expect( requests() ).toHaveLength( 2 ) );
		expect( requests()[ 1 ] ).toEqual( [
			'PATCH',
			'views/aaaaaaaaaaaa',
			{ name: 'Tees' },
		] );
	} );

	it( 'duplicates under a free name', async () => {
		await renderBar( { applyDefault: false } );
		await menu( 'Shirts', 'Duplicate' );
		await waitFor( () => expect( requests() ).toHaveLength( 2 ) );
		expect( requests()[ 1 ] ).toEqual( [
			'POST',
			'views',
			{
				name: 'Shirts (copy)',
				view: STORED[ 0 ].view,
				category: STORED[ 0 ].category,
			},
		] );
		expect(
			freeName( 'A', [ { name: 'a (COPY)' }, { name: 'A (copy 2)' } ] )
		).toBe( 'A (copy 3)' );
	} );

	it( 'updates a view with what is on screen', async () => {
		const view = { ...BASE, search: 'new', page: 2 };
		await renderBar( { view, applyDefault: false } );
		await menu( 'Shirts', 'Update with what is on screen' );
		await waitFor( () => expect( requests() ).toHaveLength( 2 ) );
		expect( requests()[ 1 ] ).toEqual( [
			'PATCH',
			'views/aaaaaaaaaaaa',
			toSaved( view, null ),
		] );
	} );

	it( 'sets and clears the default', async () => {
		await renderBar( { applyDefault: false } );
		await menu( 'Shirts', 'Open the screen with this view' );
		await waitFor( () => expect( requests() ).toHaveLength( 2 ) );
		expect( requests()[ 1 ] ).toEqual( [
			'POST',
			'views/aaaaaaaaaaaa/default',
			{ default: true },
		] );
		await menu( 'Drafts', 'Stop opening the screen with this view' );
		await waitFor( () => expect( requests() ).toHaveLength( 3 ) );
		expect( requests()[ 2 ] ).toEqual( [
			'POST',
			'views/bbbbbbbbbbbb/default',
			{ default: false },
		] );
	} );

	it( 'reorders with Move earlier and Move later', async () => {
		await renderBar( { applyDefault: false } );
		await menu( 'Drafts', 'Move earlier' );
		await waitFor( () => expect( requests() ).toHaveLength( 2 ) );
		expect( requests()[ 1 ] ).toEqual( [
			'POST',
			'views/reorder',
			{ ids: [ 'bbbbbbbbbbbb', 'aaaaaaaaaaaa' ] },
		] );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Options for Drafts' } )
		);
		expect(
			(
				await screen.findByRole( 'menuitem', { name: 'Move later' } )
			).getAttribute( 'aria-disabled' )
		).toBe( 'true' );
	} );

	it( 'reorders by dragging one chip onto another', async () => {
		await renderBar( { applyDefault: false } );
		const shirts = screen.getByRole( 'button', {
			name: 'Shirts',
		} ).parentElement;
		const drafts = screen.getByRole( 'button', {
			name: /^Drafts/,
		} ).parentElement;
		fireEvent.dragStart( shirts );
		fireEvent.dragOver( drafts );
		fireEvent.drop( drafts );
		await waitFor( () => expect( requests() ).toHaveLength( 2 ) );
		expect( requests()[ 1 ] ).toEqual( [
			'POST',
			'views/reorder',
			{ ids: [ 'bbbbbbbbbbbb', 'aaaaaaaaaaaa' ] },
		] );
	} );

	it( 'deletes after a confirmation', async () => {
		await renderBar( { applyDefault: false } );
		await menu( 'Shirts', 'Delete' );
		expect( screen.getByRole( 'dialog' ).textContent ).toContain(
			'"Shirts" will be removed'
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Delete view' } )
		);
		await waitFor( () => expect( requests() ).toHaveLength( 2 ) );
		expect( requests()[ 1 ] ).toEqual( [
			'DELETE',
			'views/aaaaaaaaaaaa',
			undefined,
		] );
	} );

	it( 'says a server’s HTML error page in plain words, never its text (ticket 11)', async () => {
		apiFetch.mockImplementation( () => Promise.reject( html500() ) );
		render(
			<SavedViewsBar
				registry={ REGISTRY }
				view={ BASE }
				onApply={ jest.fn() }
			/>
		);
		const sentence =
			'Your saved views could not be loaded or changed. Check your connection and try again.';
		const box = (
			await screen.findByText( sentence, { selector: 'p' } )
		).closest( '.components-notice' );
		expectPlain( box, sentence );
		fireEvent.click( within( box ).getByText( 'Show details' ) );
		expect(
			within( box ).getByText( /internal_server_error/ )
		).toBeTruthy();
		expectNoRawText();
	} );

	it( 'says a JSON error that is not the plugin’s own refusal in plain words inside the dialog (ticket 11)', async () => {
		await renderBar( { applyDefault: false } );
		apiFetch.mockImplementation( ( { method = 'GET' } ) =>
			method === 'GET'
				? Promise.resolve( answer )
				: Promise.reject( coreJson() )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: '+ Save this view' } )
		);
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Shirts' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save view' } ) );
		expectPlain(
			await screen.findByRole( 'alert' ),
			'The view could not be saved. Try again in a moment.'
		);
		expect( screen.getByRole( 'dialog' ) ).toBeTruthy();
		expectNoRawText();
	} );

	it( 'reports a failed load in plain words', async () => {
		apiFetch.mockImplementation( () =>
			Promise.reject( new Error( 'offline' ) )
		);
		render(
			<SavedViewsBar
				registry={ REGISTRY }
				view={ BASE }
				onApply={ jest.fn() }
			/>
		);
		// The notice's own paragraph: the Notice also speaks it into the
		// a11y live region, which a slow run can reach first.
		expect(
			await screen.findByText( /Your saved views could not be loaded/, {
				selector: 'p',
			} )
		).toBeTruthy();
		// The built-ins still work.
		expect(
			screen.getByRole( 'button', { name: 'All products' } )
		).toBeTruthy();
	} );

	it( 'reads the saved views again on Try again after a failed load (FRONTEND-5)', async () => {
		apiFetch.mockImplementationOnce( () =>
			Promise.reject( new Error( 'offline' ) )
		);
		render(
			<SavedViewsBar
				registry={ REGISTRY }
				view={ BASE }
				onApply={ jest.fn() }
				applyDefault={ false }
			/>
		);
		const box = (
			await screen.findByText( /Your saved views could not be loaded/, {
				selector: 'p',
			} )
		).closest( '.components-notice' );
		fireEvent.click(
			within( box ).getByRole( 'button', { name: 'Try again' } )
		);
		expect(
			await screen.findByRole( 'button', { name: 'Options for Drafts' } )
		).toBeTruthy();
		expect( requests() ).toEqual( [
			[ 'GET', 'views', undefined ],
			[ 'GET', 'views', undefined ],
		] );
		expect(
			screen.queryByText( /Your saved views could not be loaded/, {
				selector: 'p',
			} )
		).toBeNull();
	} );

	it( 'offers Dismiss, not a Try again that repeats nothing, after a failed change (FRONTEND-5)', async () => {
		await renderBar( { applyDefault: false } );
		apiFetch.mockImplementation( ( { method = 'GET' } ) =>
			method === 'GET'
				? Promise.resolve( answer )
				: Promise.reject( new Error( 'offline' ) )
		);
		await menu( 'Shirts', 'Open the screen with this view' );
		const box = (
			await screen.findByText( /Your saved views could not be loaded/, {
				selector: 'p',
			} )
		).closest( '.components-notice' );
		expect(
			within( box ).queryByRole( 'button', { name: 'Try again' } )
		).toBeNull();
		fireEvent.click(
			within( box ).getByRole( 'button', { name: 'Dismiss' } )
		);
		await waitFor( () =>
			expect(
				screen.queryByText( /Your saved views could not be loaded/, {
					selector: 'p',
				} )
			).toBeNull()
		);
		// Dismissing reads and writes nothing more.
		expect( requests() ).toHaveLength( 2 );
	} );
} );

describe( 'serialising a view', () => {
	it( 'reads back identical, an unrecognised key untouched, on page 1', () => {
		const view = {
			...BASE,
			page: 3,
			type: 'grid',
			sort: { field: 'regular_price', direction: 'desc' },
			filters: [
				{ field: 'status', operator: 'isAny', value: [ 'draft' ] },
			],
			futureKey: { nested: [ 1, 'two' ] },
		};
		const category = { id: 4, ids: [ 4, 5 ], includeChildren: true };
		const stored = JSON.parse(
			JSON.stringify( toSaved( view, category ) )
		);
		expect( stored.view.page ).toBeUndefined();
		expect( stored.view.futureKey ).toEqual( { nested: [ 1, 'two' ] } );
		expect( fromSaved( stored, BASE ) ).toEqual( {
			view: { ...view, page: 1 },
			category,
		} );
	} );
} );

describe( 'moving a view', () => {
	const ids = [ 'a', 'b', 'c' ];
	it.each( [
		[ 'b', -1, [ 'b', 'a', 'c' ] ],
		[ 'b', 1, [ 'a', 'c', 'b' ] ],
		[ 'a', -1, ids ],
		[ 'c', 1, ids ],
		[ 'a', 'c', [ 'b', 'c', 'a' ] ],
		[ 'c', 'a', [ 'c', 'a', 'b' ] ],
		[ 'a', 'a', ids ],
		[ 'x', 1, ids ],
	] )( 'moves %s by %s', ( id, target, want ) => {
		expect( moved( ids, id, target ) ).toEqual( want );
	} );
} );

describe( 'layouts', () => {
	it( 'switches the view between table and grid and back', () => {
		const onChangeView = jest.fn();
		const { rerender } = render(
			<LayoutSwitch view={ BASE } onChangeView={ onChangeView } />
		);
		const group = screen.getByRole( 'group', { name: 'Layout' } );
		expect(
			within( group )
				.getByRole( 'button', { name: 'Table' } )
				.getAttribute( 'aria-pressed' )
		).toBe( 'true' );
		fireEvent.click(
			within( group ).getByRole( 'button', { name: 'Grid' } )
		);
		const grid = onChangeView.mock.calls[ 0 ][ 0 ];
		expect( grid ).toEqual( { ...BASE, type: 'grid' } );
		rerender(
			<LayoutSwitch view={ grid } onChangeView={ onChangeView } />
		);
		expect(
			screen
				.getByRole( 'button', { name: 'Grid' } )
				.getAttribute( 'aria-pressed' )
		).toBe( 'true' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Table' } ) );
		expect( onChangeView.mock.calls[ 1 ][ 0 ] ).toEqual( BASE );
		expect( withLayout( BASE, 'kanban' ) ).toBe( BASE );
	} );

	async function renderLayout( view, extra = {} ) {
		const utils = render(
			<CatalogueLayout
				fields={ fieldsFromRegistry( REGISTRY ) }
				view={ view }
				onChangeView={ jest.fn() }
				items={ ROWS }
				pagination={ { totalItems: 2, totalPages: 1 } }
				isLoading={ false }
				hasLoaded
				actions={ [
					{
						id: 'make-change',
						label: 'Make a change to these',
						supportsBulk: true,
						callback: jest.fn(),
					},
				] }
				termNames={ {} }
				pending={ new Set() }
				selection={ [] }
				onChangeSelection={ jest.fn() }
				{ ...extra }
			/>
		);
		await act( async () => {} );
		return utils;
	}

	it( 'renders the same registry fields as cards in the grid layout', async () => {
		const { container, unmount } = await renderLayout( BASE );
		expect(
			container.querySelector( '.dataviews-view-table' )
		).toBeTruthy();
		const tableText = [ 'TSH-100', 'In stock · 659', 'Draft' ].map(
			( text ) => screen.getAllByText( text ).length
		);
		unmount();

		const grid = await renderLayout( { ...BASE, type: 'grid' } );
		expect(
			grid.container.querySelector( '.dataviews-view-table' )
		).toBeNull();
		expect(
			grid.container.querySelector( '.dataviews-view-grid' )
		).toBeTruthy();
		// Thumbnail, name, price and stock status on each card.
		expect(
			grid.container.querySelectorAll( 'img.bulkcat-thumb' )
		).toHaveLength( 2 );
		expect(
			screen.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
		).toBeTruthy();
		expect( screen.getByText( '12.50' ) ).toBeTruthy();
		expect(
			[ 'TSH-100', 'In stock · 659', 'Draft' ].map(
				( text ) => screen.getAllByText( text ).length
			)
		).toEqual( tableText );
		// Each card is selectable.
		expect(
			screen.getAllByRole( 'checkbox' ).length
		).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'fills the Shown in shop and search slot and keeps it out of the saved columns', async () => {
		const renderSwitch = ( item ) => (
			<span data-testid="switch">{ `switch ${ item.id }` }</span>
		);
		await renderLayout( { ...BASE, type: 'grid' }, { renderSwitch } );
		expect(
			screen.getAllByTestId( 'switch' ).map( ( el ) => el.textContent )
		).toEqual( [ 'switch 11', 'switch 12' ] );

		const fields = fieldsFromRegistry( REGISTRY );
		// A view without the switch still gets the slot (T93: the default
		// view has none), and one with it keeps its own place.
		const without = {
			...BASE,
			fields: BASE.fields.filter( ( key ) => key !== 'visible_on_site' ),
		};
		const withSwitch = {
			...BASE,
			fields: [ ...without.fields, 'visible_on_site' ],
		};
		expect( without.fields ).toEqual( BASE.fields );
		const { view, added } = gridOf( fields, without, renderSwitch );
		expect( added ).toBe( 'visible_on_site' );
		expect( view.fields ).toEqual( [
			...without.fields,
			'visible_on_site',
		] );
		expect( gridOf( fields, without ).view.fields ).toEqual(
			without.fields
		);
		expect(
			gridOf( fields, withSwitch, renderSwitch ).added
		).toBeUndefined();
	} );
} );
