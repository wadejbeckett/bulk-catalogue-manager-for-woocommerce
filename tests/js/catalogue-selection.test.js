/**
 * T42, T84: the selection in the catalogue. Ticked rows and Select all
 * matching add exact identities to one server-held selection, which survives
 * searches, filters and pages; checkboxes show actual membership; unticking
 * removes only that row; Clear selection and Clear search are the deliberate
 * clears, and editing the search box (even through empty text) is not; the
 * bar counts the whole selection in words from the server; Review selected
 * products lists and removes members, unavailable ones too; a reload
 * recovers the selection; an unconfirmed change offers Try again with the
 * same grant; the quick actions open the panel on the snapshot.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';
import {
	SelectionBar,
	countPhrase,
	getSelectionSession,
	panelSelection,
	resetSelectionSession,
	selectedWords,
	visibilityOperation,
} from '../../assets/src/panels/catalogue/selection';
import { waitingReason } from '../../assets/src/panels/catalogue/selection/model';

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
} = require( './catalogue-fixture' );
const { createSelectionServer } = require( './selection-server' );

const SECOND = [
	{ ...ROWS[ 0 ], id: 21, name: 'Polo Shirt', sku: 'POL-1' },
	{ ...ROWS[ 1 ], id: 22, name: 'Rain Jacket', sku: 'RJ-2' },
];
const CAP = { ...ROWS[ 0 ], id: 31, name: 'Cap', sku: 'CAP-1' };
const FILTERED = {
	filters: [
		{ field: 'stock_quantity', operator: 'lessThanOrEqual', value: 0 },
	],
};

let server;

beforeAll( () => {
	// Numbers group in the admin's language, <html lang>.
	document.documentElement.lang = 'en-GB';
} );

afterAll( () => {
	document.documentElement.lang = '';
} );

/**
 * The catalogue's GET /products: a search for "Cap" finds the cap, page 2
 * holds the second pair, anything else the first pair of 1,284.
 *
 * @param {Object} query Query.
 * @return {Object} Answer.
 */
function products( query ) {
	if ( query.search === 'Cap' ) {
		return page( [ CAP ], 1 );
	}
	return query.page === '2' ? page( SECOND, 1284 ) : page( ROWS, 1284 );
}

beforeEach( () => {
	server = createSelectionServer();
	server.setMatches( () => [ 11, 12, 21, 22 ] );
	apiFetch.mockReset();
	route( apiFetch, {
		fields: () => REGISTRY,
		products,
		categories: () => CATEGORIES,
		...server.routes(),
	} );
} );

async function renderLoaded( initialView = FILTERED ) {
	const result = render(
		<Catalogue settings={ { settings: {} } } initialView={ initialView } />
	);
	await screen.findByText( 'Classic Cotton T-Shirt' );
	return result;
}

const header = () =>
	within( screen.getByRole( 'table' ) ).getByRole( 'checkbox', {
		name: 'Select all',
	} );
const bar = () => screen.getByRole( 'region', { name: 'Selection' } );
const box = ( name ) =>
	within( screen.getByRole( 'link', { name } ).closest( 'tr' ) ).getByRole(
		'checkbox'
	);
const tick = ( name ) => fireEvent.click( box( name ) );
const counted = ( words ) =>
	waitFor( () => expect( bar().textContent ).toContain( words ) );
const settled = () =>
	waitFor( () =>
		expect( getSelectionSession().getState().status ).toBe( 'idle' )
	);
const search = async ( text ) => {
	fireEvent.change(
		screen.getByRole( 'searchbox', { name: 'Search by name or SKU' } ),
		{ target: { value: text } }
	);
	await waitFor( () =>
		expect(
			apiFetch.mock.calls.some(
				( [ options ] ) =>
					options.url.startsWith( 'products?' ) &&
					new URLSearchParams( options.url.split( '?' )[ 1 ] ).get(
						'search'
					) === ( text || null )
			)
		).toBe( true )
	);
};

describe( 'selection model', () => {
	it( 'counts in words, by type', () => {
		expect( selectedWords( 1, { products: 1 } ) ).toBe(
			'1 product selected'
		);
		expect( selectedWords( 1284, { products: 1284 } ) ).toBe(
			'1,284 products selected'
		);
		expect( selectedWords( 3, { variations: 3 } ) ).toBe(
			'3 variations selected'
		);
		expect(
			selectedWords( 1250, { products: 1200, variations: 50 } )
		).toBe( '1,200 products and 50 variations selected' );
		expect( countPhrase( 2, { products: 1, variations: 1 } ) ).toBe(
			'1 product and 1 variation'
		);
	} );

	it( 'gives the panel the snapshot, and why it must wait', () => {
		const state = {
			status: 'saving',
			current: {
				key: 'h1',
				token: 'v2.a.1.s',
				count: 2,
				byType: { products: 2, variations: 0 },
			},
		};
		expect( panelSelection( state ) ).toEqual( {
			kind: 'snapshot',
			key: 'h1',
			token: 'v2.a.1.s',
			count: 2,
			byType: { products: 2, variations: 0 },
			waiting: waitingReason( state ),
		} );
		expect( waitingReason( state ) ).toBe(
			'Your selection is still being saved. Wait a moment, then check the change.'
		);
		expect( waitingReason( { status: 'idle' } ) ).toBe( '' );
	} );

	it( 'prefills Show on site and Hide from site as a set step on the switch', () => {
		const hide = visibilityOperation( REGISTRY, {}, false );
		expect( hide ).toMatchObject( {
			action: 'set_value',
			args: { field: 'visible_on_site', value: 'off' },
		} );
		expect( visibilityOperation( REGISTRY, {}, true ).args.value ).toBe(
			'on'
		);
		expect(
			visibilityOperation(
				{
					...REGISTRY,
					fields: REGISTRY.fields.filter(
						( field ) => field.key !== 'visible_on_site'
					),
				},
				{},
				true
			)
		).toBeNull();
	} );
} );

describe( 'the selection bar’s notices', () => {
	const state = ( notice ) => ( {
		count: 2,
		byType: { products: 2, variations: 0 },
		status: 'idle',
		hasSelection: notice?.kind !== 'done',
		notice,
		offerAll: false,
		dismiss: jest.fn(),
		clear: jest.fn(),
		retry: jest.fn(),
	} );

	it( 'moves from a plain notice to one with actions without breaking (the live crash)', () => {
		const onUndo = jest.fn();
		const onViewHistory = jest.fn();
		const props = {
			pageCount: 2,
			total: 2,
			filtered: false,
			onMakeChange: jest.fn(),
			onUndo,
			onViewHistory,
		};
		const { rerender } = render(
			<SelectionBar
				{ ...props }
				selection={ state( { kind: 'restored' } ) }
			/>
		);
		const shown = () =>
			document.querySelector( '.bulkcat-selection' ).textContent;
		expect( shown() ).toContain(
			'Your selection is still here: 2 products.'
		);
		rerender(
			<SelectionBar
				{ ...props }
				selection={ state( { kind: 'done', batchId: 7, kept: 0 } ) }
			/>
		);
		expect( shown() ).toContain(
			'The change finished, so your selection was cleared.'
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Undo this change' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'View in History' } )
		);
		expect( onUndo ).toHaveBeenCalledWith( 7 );
		expect( onViewHistory ).toHaveBeenCalledWith( 7 );
		rerender(
			<SelectionBar
				{ ...props }
				selection={ state( { kind: 'restored' } ) }
			/>
		);
	} );
} );

describe( 'the selection bar when a change could not be followed (T85)', () => {
	it( 'says so plainly and offers Check again and History', () => {
		const checkAgain = jest.fn();
		const onViewHistory = jest.fn();
		render(
			<SelectionBar
				selection={ {
					count: 2,
					byType: { products: 2, variations: 0 },
					status: 'idle',
					hasSelection: true,
					notice: null,
					offerAll: false,
					unwatched: { batchId: 9 },
					checkAgain,
					dismiss: jest.fn(),
					clear: jest.fn(),
					retry: jest.fn(),
				} }
				pageCount={ 2 }
				total={ 2 }
				filtered={ false }
				onMakeChange={ jest.fn() }
				onViewHistory={ onViewHistory }
			/>
		);
		expect(
			document.querySelector( '.bulkcat-selection' ).textContent
		).toContain(
			'We could not check whether your last change has finished, so your selection is kept as it was.'
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check again' } )
		);
		expect( checkAgain ).toHaveBeenCalled();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'View in History' } )
		);
		expect( onViewHistory ).toHaveBeenCalledWith( 9 );
	} );
} );

describe( 'selection in the catalogue', () => {
	it( 'keeps ticked rows across a search, a filter change and pages, adding each tick', async () => {
		await renderLoaded();
		expect(
			screen.queryByRole( 'region', { name: 'Selection' } )
		).toBeNull();

		tick( 'Classic Cotton T-Shirt' );
		// Shown ticked at once, counted by the server.
		expect( box( 'Classic Cotton T-Shirt' ).checked ).toBe( true );
		await counted( '1 product selected' );

		// A search: the selection stays; the found row can be added.
		await search( 'Cap' );
		await screen.findByRole( 'link', { name: 'Cap' } );
		expect( bar().textContent ).toContain( '1 product selected' );
		expect( box( 'Cap' ).checked ).toBe( false );
		tick( 'Cap' );
		await counted( '2 products selected' );

		// Editing the search through empty text is not a clear.
		await search( '' );
		await screen.findByText( 'Classic Cotton T-Shirt' );
		expect( bar().textContent ).toContain( '2 products selected' );
		await waitFor( () =>
			expect( box( 'Classic Cotton T-Shirt' ).checked ).toBe( true )
		);

		// A filter removed: still there, no notice.
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Clear the filter “Stock quantity is 0 or less”',
			} )
		);
		await screen.findByText( 'Classic Cotton T-Shirt' );
		expect( bar().textContent ).toContain( '2 products selected' );
		expect( document.querySelector( '.bulkcat-selection__notice' ) ).toBe(
			null
		);

		// Next page: its rows are not selected; a tick adds a third.
		fireEvent.click( screen.getByRole( 'button', { name: 'Next page' } ) );
		await screen.findByText( 'Polo Shirt' );
		expect( box( 'Polo Shirt' ).checked ).toBe( false );
		tick( 'Polo Shirt' );
		await counted( '3 products selected' );
		expect(
			server.members( getSelectionSession().getState().current.token )
		).toEqual( [ 11, 21, 31 ] );

		// Back: the first page's row is still ticked, from inspection.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Previous page' } )
		);
		await screen.findByText( 'Classic Cotton T-Shirt' );
		await waitFor( () =>
			expect( box( 'Classic Cotton T-Shirt' ).checked ).toBe( true )
		);
		expect( box( 'Ladies Golf Shirt' ).checked ).toBe( false );
		// Nothing was ever sent as a 1.0 selection body.
		server
			.calls( 'POST selection' )
			.forEach( ( call ) => expect( call.data.uuid ).toBeTruthy() );
	} );

	it( 'adds every match to what is selected, shows membership, and unticks only one', async () => {
		await renderLoaded();
		await search( 'Cap' );
		await screen.findByRole( 'link', { name: 'Cap' } );
		tick( 'Cap' );
		await counted( '1 product selected' );
		await search( '' );
		await screen.findByText( 'Classic Cotton T-Shirt' );

		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: 'Select all 1,284 products matching these filters.',
			} )
		);
		// The capture is added to the cap, not in its place.
		await counted( '5 products selected' );
		const capture = server
			.calls( 'POST requests' )
			.find( ( call ) => call.data.intent.query );
		expect( capture.data.intent.query ).toEqual( {
			stock_quantity: { operator: 'lessThanOrEqual', value: 0 },
		} );
		// The page's checkboxes show actual membership.
		await waitFor( () =>
			expect(
				box( 'Classic Cotton T-Shirt' ).checked &&
					box( 'Ladies Golf Shirt' ).checked
			).toBe( true )
		);
		// The offer is not repeated for the same filters.
		expect(
			within( bar() ).queryByRole( 'button', {
				name: /^Select all 1,284/,
			} )
		).toBeNull();

		tick( 'Ladies Golf Shirt' );
		await counted( '4 products selected' );
		expect(
			server.members( getSelectionSession().getState().current.token )
		).toEqual( [ 11, 21, 22, 31 ] );
		expect( box( 'Classic Cotton T-Shirt' ).checked ).toBe( true );
	} );

	it( 'offers Select all in the words of the grid’s mode (ticket 11)', async () => {
		await renderLoaded( { ...FILTERED, mode: 'variations' } );
		fireEvent.click( header() );
		await waitFor( () =>
			expect( bar().textContent ).toContain(
				'All 2 on this page are selected. Select all 1,284 variations matching these filters.'
			)
		);
	} );

	it( 'clears the search and the selection with Clear search, and only the selection with Clear selection', async () => {
		await renderLoaded();
		await search( 'Cap' );
		await screen.findByRole( 'link', { name: 'Cap' } );
		tick( 'Cap' );
		await counted( '1 product selected' );
		const token = getSelectionSession().getState().current.token;
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Clear search and selection (1)',
			} )
		);
		await screen.findByText( 'Classic Cotton T-Shirt' );
		expect(
			screen.queryByRole( 'region', { name: 'Selection' } )
		).toBeNull();
		expect(
			document.querySelector( '.bulkcat-selection__notice' ).textContent
		).toContain( 'The search and the selection were cleared.' );
		await waitFor( () => expect( server.released( token ) ).toBe( true ) );

		tick( 'Classic Cotton T-Shirt' );
		await counted( '1 product selected' );
		act( () => {
			fireEvent.click(
				within( bar() ).getByRole( 'button', {
					name: 'Clear selection',
				} )
			);
		} );
		expect(
			screen.queryByRole( 'region', { name: 'Selection' } )
		).toBeNull();
		// The filters are untouched.
		expect(
			screen.getByRole( 'button', {
				name: 'Clear the filter “Stock quantity is 0 or less”',
			} )
		).toBeTruthy();
	} );

	it( 'names Clear search by what it clears, and restores the selection it cleared (T85)', async () => {
		await renderLoaded();
		await search( 'Cap' );
		await screen.findByRole( 'link', { name: 'Cap' } );
		tick( 'Cap' );
		await counted( '1 product selected' );
		await settled();
		const token = getSelectionSession().getState().current.token;
		// Beside Clear filters and the search box's own ×, which keep it.
		const clearSearch = screen.getByRole( 'button', {
			name: 'Clear search and selection (1)',
		} );
		fireEvent.click( clearSearch );
		await screen.findByText( 'Classic Cotton T-Shirt' );
		await waitFor( () => expect( server.released( token ) ).toBe( true ) );
		const notice = document.querySelector( '.bulkcat-selection__notice' );
		expect( notice.textContent ).toContain(
			'The search and the selection were cleared.'
		);
		fireEvent.click(
			within( notice ).getByRole( 'button', {
				name: 'Restore selection',
			} )
		);
		await counted( '1 product selected' );
		expect( getSelectionSession().getState().current.token ).toBe( token );
		expect( server.released( token ) ).toBe( false );
		expect(
			document.querySelector( '.bulkcat-selection__notice' ).textContent
		).toContain( 'Your selection is still here: 1 product.' );
		// No capture of the search again: the same frozen snapshot.
		expect( server.calls( 'POST selection' ) ).toHaveLength( 1 );
	} );

	it( 'reviews the whole selection, removes one and an unavailable one, a page at a time', async () => {
		server.setMatches( () => [ 11, 12, 21, 22, 31, 2201 ] );
		await renderLoaded();
		fireEvent.click( header() );
		await counted( '2 products selected' );
		expect( bar().textContent ).toContain(
			'All 2 on this page are selected. Select all 1,284 products matching these filters.'
		);
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: /^Select all/ } )
		);
		await counted( '5 products and 1 variation selected' );
		server.remove( 22 );

		const opener = within( bar() ).getByRole( 'button', {
			name: 'Review selected products',
		} );
		opener.focus();
		fireEvent.click( opener );
		const drawer = await screen.findByRole( 'dialog', {
			name: 'Selected products',
		} );
		expect( document.activeElement ).toBe(
			within( drawer ).getByRole( 'heading', {
				name: 'Selected products',
			} )
		);
		await within( drawer ).findByText( 'Polo Shirt' );
		expect( drawer.textContent ).toContain(
			'5 products and 1 variation selected'
		);
		// The unavailable member keeps its place without its details.
		expect(
			within( drawer ).getByText( 'No longer available' )
		).toBeTruthy();
		expect( drawer.textContent ).not.toContain( 'Rain Jacket' );
		expect( drawer.textContent ).toContain( 'Variation · POL-1-S' );

		fireEvent.click(
			within( drawer ).getByRole( 'button', {
				name: 'Remove Polo Shirt from the selection',
			} )
		);
		await counted( '4 products and 1 variation selected' );
		fireEvent.click(
			within( drawer ).getByRole( 'button', {
				name: 'Remove this product that is no longer available from the selection',
			} )
		);
		await counted( '3 products and 1 variation selected' );
		expect(
			server.members( getSelectionSession().getState().current.token )
		).toEqual( [ 11, 12, 31, 2201 ] );
		await waitFor( () =>
			expect( within( drawer ).queryByText( 'Polo Shirt' ) ).toBeNull()
		);

		fireEvent.keyDown( drawer, { key: 'Escape' } );
		expect(
			screen.queryByRole( 'dialog', { name: 'Selected products' } )
		).toBeNull();
		expect( document.activeElement ).toBe( opener );
	} );

	it( 'pages through a long review', async () => {
		const ids = Array.from( { length: 30 }, ( _, i ) => 500 + i );
		server = createSelectionServer( {
			objects: [
				...ids.map( ( id ) => ( {
					id,
					name: `Item ${ id }`,
					sku: `I-${ id }`,
					type: 'product',
				} ) ),
				{
					id: 11,
					name: 'Classic Cotton T-Shirt',
					sku: 'T',
					type: 'product',
				},
				{
					id: 12,
					name: 'Ladies Golf Shirt',
					sku: 'G',
					type: 'product',
				},
			],
		} );
		server.setMatches( () => ids );
		route( apiFetch, {
			fields: () => REGISTRY,
			products,
			categories: () => CATEGORIES,
			...server.routes(),
		} );
		await renderLoaded();
		fireEvent.click( header() );
		await counted( '2 products selected' );
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: /^Select all/ } )
		);
		await counted( '32 products selected' );
		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: 'Review selected products',
			} )
		);
		const drawer = await screen.findByRole( 'dialog', {
			name: 'Selected products',
		} );
		await within( drawer ).findByText( 'Classic Cotton T-Shirt' );
		expect( drawer.textContent ).toContain( 'Page 1 of 2' );
		fireEvent.click(
			within( drawer ).getByRole( 'button', { name: 'Next' } )
		);
		await within( drawer ).findByText( 'Item 529' );
		expect( drawer.textContent ).toContain( 'Page 2 of 2' );
		const pages = server
			.calls( 'POST selection/inspect' )
			.filter( ( call ) => call.data.per_page === 25 );
		expect( pages.map( ( call ) => call.data.page ) ).toEqual( [ 1, 2 ] );
	} );

	it( 'recovers the selection after a reload, saying so', async () => {
		const first = await renderLoaded();
		tick( 'Classic Cotton T-Shirt' );
		await counted( '1 product selected' );
		await settled();
		const token = getSelectionSession().getState().current.token;
		first.unmount();
		// A reload: a new page, the same tab.
		resetSelectionSession();
		await renderLoaded();
		await counted( '1 product selected' );
		expect(
			document.querySelector( '.bulkcat-selection__notice' ).textContent
		).toContain( 'Your selection is still here: 1 product.' );
		expect( server.calls( 'POST selection/renew' ).at( -1 ).data ).toEqual(
			{ token }
		);
		await waitFor( () =>
			expect( box( 'Classic Cotton T-Shirt' ).checked ).toBe( true )
		);
	} );

	it( 'says when the selection was checked again after an idle spell', async () => {
		await renderLoaded();
		tick( 'Classic Cotton T-Shirt' );
		await counted( '1 product selected' );
		await settled();
		server.expireLeases();
		await act( () => getSelectionSession().wake() );
		await waitFor( () =>
			expect(
				document.querySelector( '.bulkcat-selection' ).textContent
			).toContain(
				'This page was idle for a while, so your selection was checked again.'
			)
		);
		expect( bar().textContent ).toContain( '1 product selected' );
	} );

	it( 'offers Try again when a change to the selection was not confirmed, and resends the same grant', async () => {
		await renderLoaded();
		tick( 'Classic Cotton T-Shirt' );
		await counted( '1 product selected' );
		await settled();
		server.fail(
			'POST selection/compose',
			{ code: 'fetch_error', message: 'You are probably offline.' },
			{ after: true }
		);
		tick( 'Ladies Golf Shirt' );
		const retry = await screen.findByRole( 'button', {
			name: 'Try again',
		} );
		expect(
			document.querySelector( '.bulkcat-selection' ).textContent
		).toContain( 'Your selection could not be saved just now.' );
		// The panel opens, but a change cannot be checked while it is unsettled.
		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: 'Make a change to these',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Bulk change',
		} );
		fireEvent.click(
			within( dialog ).getAllByRole( 'radio', {
				name: /^Increase or decrease/,
			} )[ 0 ]
		);
		expect(
			within( dialog ).getByRole( 'button', { name: 'Check the change' } )
				.disabled
		).toBe( true );
		expect( dialog.textContent ).toContain(
			'A change to your selection was not confirmed. Try again, or clear the selection, before checking a change.'
		);
		fireEvent.click( retry );
		await counted( '2 products selected' );
		const [ lost, again ] = server.calls( 'POST selection/compose' );
		expect( again.data.uuid ).toBe( lost.data.uuid );
		// Settled: the open panel reads the new selection at once.
		await waitFor( () =>
			expect( dialog.textContent ).toContain( '2 selected products' )
		);
	} );

	describe( 'an Apply whose answer was lost, then a reload (T85)', () => {
		const { previewResponse } = require( './bulk-fixtures' );
		const {
			resetApplyGrant,
		} = require( '../../assets/src/panels/bulk/hooks' );

		beforeEach( () => {
			route( apiFetch, {
				fields: () => REGISTRY,
				products,
				categories: () => CATEGORIES,
				...server.routes(),
				preview: () => previewResponse(),
				'batches/101': ( query, options ) => server.handle( options ),
			} );
		} );

		/**
		 * Select the page, open the panel, check an increase and apply it.
		 */
		async function applyIncrease() {
			fireEvent.click(
				within( bar() ).getByRole( 'button', {
					name: 'Make a change to these',
				} )
			);
			const dialog = await screen.findByRole( 'dialog', {
				name: 'Bulk change',
			} );
			fireEvent.click(
				within( dialog ).getAllByRole( 'radio', {
					name: /^Increase or decrease/,
				} )[ 0 ]
			);
			fireEvent.change( within( dialog ).getByLabelText( 'How much' ), {
				target: { value: '1' },
			} );
			fireEvent.click(
				within( dialog ).getByRole( 'button', {
					name: 'Check the change',
				} )
			);
			fireEvent.click(
				await within( dialog ).findByRole( 'button', {
					name: 'Apply to 20 products',
				} )
			);
			return dialog;
		}

		/**
		 * A reload: a new page in the same tab, the modules' memory gone.
		 *
		 * @param {Object} rendered The screen to unmount.
		 */
		async function reload( rendered ) {
			rendered.unmount();
			resetSelectionSession();
			resetApplyGrant();
			await renderLoaded();
		}

		it( 'resolves the same grant after a reload and shows the change it had made', async () => {
			const rendered = await renderLoaded();
			fireEvent.click( header() );
			await counted( '2 products selected' );
			await settled();
			// The batch is made; only its answer is lost.
			server.fail(
				'POST batches',
				{ code: 'fetch_error', message: 'You are probably offline.' },
				{ after: true }
			);
			const dialog = await applyIncrease();
			await within( dialog ).findByText(
				'We could not confirm whether the change was applied'
			);
			const [ lost ] = server.calls( 'POST batches' );

			await reload( rendered );
			const banner = await screen.findByRole( 'group', {
				name: 'Change in progress',
			} );
			expect( banner.textContent ).toContain(
				'This change had already been accepted, so it was not sent again.'
			);
			const [ resolved ] = server.calls( 'POST requests/resolve' );
			expect( resolved.data.uuid ).toBe( lost.data.uuid );
			expect( resolved.data.request_proof ).toBe(
				lost.data.request_proof
			);
			// One batch; nothing was sent again.
			expect( server.batches.size ).toBe( 1 );
			expect( server.calls( 'POST batches' ) ).toHaveLength( 1 );
			// The snapshot it was applied to is the one kept until it finishes.
			expect( getSelectionSession().getState().submitted ).toEqual( {
				batchId: 101,
			} );
			expect(
				window.sessionStorage.getItem( 'bulkcat-selection::0:apply' )
			).toBeNull();
		} );

		it( 'clears what a change applied once it finished, even when the page was reloaded while it ran', async () => {
			const rendered = await renderLoaded();
			fireEvent.click( header() );
			await counted( '2 products selected' );
			await settled();
			const token = getSelectionSession().getState().current.token;
			await applyIncrease();
			await waitFor( () => expect( server.batches.size ).toBe( 1 ) );
			// It finishes while the page is away; the new page has no banner.
			server.setBatch( 101, 'complete', {
				total_items: 2,
				done_items: 2,
				failed_items: 0,
				pending_items: 0,
			} );
			await reload( rendered );
			await waitFor( () =>
				expect(
					document.querySelector( '.bulkcat-selection' )?.textContent
				).toContain(
					'The change finished, so your selection was cleared.'
				)
			);
			expect(
				screen.queryByRole( 'region', { name: 'Selection' } )
			).toBeNull();
			expect( getSelectionSession().getState() ).toMatchObject( {
				count: 0,
				submitted: null,
			} );
			expect( server.released( token ) ).toBe( true );
		} );

		it( 'keeps a grant the server never received and sends it again for the same change', async () => {
			const rendered = await renderLoaded();
			fireEvent.click( header() );
			await counted( '2 products selected' );
			await settled();
			// Refused before it acts: nothing is made.
			server.fail( 'POST batches', {
				code: 'fetch_error',
				message: 'You are probably offline.',
			} );
			const dialog = await applyIncrease();
			await within( dialog ).findByText(
				'We could not confirm whether the change was applied'
			);
			const [ lost ] = server.calls( 'POST batches' );

			await reload( rendered );
			await waitFor( () =>
				expect( server.calls( 'POST requests/resolve' ) ).toHaveLength(
					1
				)
			);
			expect(
				screen.queryByRole( 'group', { name: 'Change in progress' } )
			).toBeNull();
			expect( server.batches.size ).toBe( 0 );

			await applyIncrease();
			await waitFor( () =>
				expect( server.calls( 'POST batches' ) ).toHaveLength( 2 )
			);
			const [ , again ] = server.calls( 'POST batches' );
			expect( again.data.uuid ).toBe( lost.data.uuid );
			expect(
				server
					.calls( 'POST requests' )
					.filter( ( call ) => call.data.operation === 'batch.apply' )
			).toHaveLength( 1 );
			expect( server.batches.size ).toBe( 1 );
		} );
	} );

	it( 'opens the panel on the snapshot and previews its token', async () => {
		await renderLoaded();
		fireEvent.click( header() );
		await counted( '2 products selected' );
		await settled();
		const token = getSelectionSession().getState().current.token;
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: 'Hide from site' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Bulk change',
		} );
		expect( dialog.textContent ).toContain( '2 selected products' );
		expect( dialog.textContent ).toContain( 'Visible on site' );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: /^Check/ } )
		);
		await waitFor( () =>
			expect(
				apiFetch.mock.calls.some(
					( [ options ] ) => options.url === 'preview'
				)
			).toBe( true )
		);
		const preview = apiFetch.mock.calls.find(
			( [ options ] ) => options.url === 'preview'
		)[ 0 ];
		expect( preview.data.token ).toBe( token );
		expect( preview.data.ids ).toBeUndefined();
	} );

	it( 'prefills the bulk panel from Show on site', async () => {
		await renderLoaded();
		tick( 'Ladies Golf Shirt' );
		await counted( '1 product selected' );
		await settled();
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: 'Show on site' } )
		);
		const dialog = await screen.findByRole( 'dialog' );
		expect( dialog.textContent ).toContain( '1 selected product' );
		expect( dialog.textContent ).toContain( 'Visible on site' );
	} );
} );
