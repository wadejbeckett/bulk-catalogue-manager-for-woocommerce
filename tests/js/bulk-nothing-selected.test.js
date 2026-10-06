/**
 * FRONTEND-1 (C3, ticket 20): the header's Bulk change with nothing selected
 * opens the panel on nothing and asks. It never selects every match by
 * itself. The panel offers Select all matching through the exact-selection
 * capture, counted and captured over the view the operator actually sees
 * (the saved default view when the catalogue is entered from another tab),
 * and says so when nothing matches.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import App from '../../assets/src/app';
import { defaultView } from '../../assets/src/panels/catalogue';
import { getSelectionSession } from '../../assets/src/panels/catalogue/selection';
import { toSaved } from '../../assets/src/panels/catalogue/views/serialize';

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

const SETTINGS = { settings: { rows_per_page: 25 } };
const DRAFTS = {
	id: 'bbbbbbbbbbbb',
	name: 'Drafts',
	view: {
		...toSaved( defaultView( REGISTRY, SETTINGS ) ).view,
		filters: [ { field: 'status', operator: 'isAny', value: [ 'draft' ] } ],
	},
	category: null,
	isDefault: true,
};

let server;
let captured;
let views;

/**
 * GET /products: a status filter finds one draft (or none, for "pending"),
 * anything else the first pair of four.
 *
 * @param {Object} query Query.
 * @return {Object} Answer.
 */
function products( query ) {
	const status = JSON.stringify( query.status || '' );
	if ( status.includes( 'pending' ) ) {
		return page( [], 0 );
	}
	if ( status.includes( 'draft' ) ) {
		return page( [ ROWS[ 1 ] ], 1 );
	}
	return page( ROWS, 4 );
}

/**
 * Route every request; GET /views answers through `views`.
 */
function routes() {
	route( apiFetch, {
		fields: () => REGISTRY,
		products,
		categories: () => CATEGORIES,
		views: () => views(),
		...server.routes(),
	} );
}

beforeAll( () => {
	document.documentElement.lang = 'en-GB';
} );

afterAll( () => {
	document.documentElement.lang = '';
} );

beforeEach( () => {
	window.history.replaceState( null, '', '/' );
	server = createSelectionServer();
	captured = [];
	server.setMatches( ( conditions ) => {
		captured.push( conditions );
		return JSON.stringify( conditions ).includes( 'draft' )
			? [ 12 ]
			: [ 11, 12, 21, 22 ];
	} );
	views = () => ( { items: [], default: null, max: 50 } );
	apiFetch.mockReset();
	routes();
} );

const bulkButton = () => screen.getByRole( 'button', { name: 'Bulk change' } );
const openPanel = async () => {
	fireEvent.click( bulkButton() );
	return screen.findByRole( 'dialog', { name: 'Bulk change' } );
};
/** Captures of a view's matches (a ticked row is captured by its id). */
const captures = () =>
	server
		.calls( 'POST requests' )
		.filter(
			( call ) =>
				call.data.operation === 'selection.capture' &&
				! call.data.intent?.ids
		);

describe( 'Bulk change with nothing selected', () => {
	it( 'opens on nothing, asks, and selects all matching only when asked', async () => {
		render( <App settings={ SETTINGS } /> );
		await screen.findByText( 'Classic Cotton T-Shirt' );
		const dialog = await openPanel();
		await within( dialog ).findByText(
			'Nothing is selected. Select products in the table, or select all 4 in the catalogue.'
		);
		// Nothing was captured or selected on the operator's behalf.
		expect( captures() ).toHaveLength( 0 );
		expect( getSelectionSession().getState().count ).toBe( 0 );
		expect(
			screen.queryByRole( 'region', { name: 'Selection' } )
		).toBeNull();

		fireEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Select all 4 products in the catalogue.',
			} )
		);
		await waitFor( () =>
			expect( dialog.textContent ).toContain( '4 selected products' )
		);
		expect( captures() ).toHaveLength( 1 );
		expect(
			within( dialog ).queryByText( /^Nothing is selected\./ )
		).toBeNull();
	} );

	it( 'counts and captures the view the operator sees when entered from another tab', async () => {
		let answer;
		views = () =>
			new Promise( ( resolve ) => {
				answer = resolve;
			} );
		window.history.replaceState( null, '', '/?panel=settings' );
		render( <App settings={ SETTINGS } /> );
		const dialog = await openPanel();
		await screen.findByText( 'Classic Cotton T-Shirt' );
		// Until the saved default view is on screen, no count is offered:
		// the unfiltered start view is not what the operator works on.
		expect(
			within( dialog ).getByText(
				'Nothing is selected. Select products in the table.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).queryByRole( 'button', { name: /^Select/ } )
		).toBeNull();

		await act( async () =>
			answer( { items: [ DRAFTS ], default: DRAFTS.id } )
		);
		await within( dialog ).findByText(
			'Nothing is selected. Select products in the table, or select the 1 matching the current filters.'
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Select the 1 product matching these filters.',
			} )
		);
		await waitFor( () =>
			expect( dialog.textContent ).toContain( '1 selected product' )
		);
		expect( captured ).toHaveLength( 1 );
		expect( JSON.stringify( captured[ 0 ] ) ).toContain( 'draft' );
	} );

	it( 'says so when nothing matches the current filters', async () => {
		views = () => ( {
			items: [
				{
					...DRAFTS,
					view: {
						...DRAFTS.view,
						filters: [
							{
								field: 'status',
								operator: 'isAny',
								value: [ 'pending' ],
							},
						],
					},
				},
			],
			default: DRAFTS.id,
		} );
		render( <App settings={ SETTINGS } /> );
		await waitFor( () =>
			expect(
				apiFetch.mock.calls.some(
					( [ options ] ) =>
						options.url.startsWith( 'products?' ) &&
						options.url.includes( 'pending' )
				)
			).toBe( true )
		);
		const dialog = await openPanel();
		await within( dialog ).findByText(
			'Nothing is selected, and no products match the current filters.'
		);
		expect(
			within( dialog ).queryByRole( 'button', { name: /^Select/ } )
		).toBeNull();
		expect( captures() ).toHaveLength( 0 );
	} );

	it( 'opens on the selection when there is one', async () => {
		render( <App settings={ SETTINGS } /> );
		await screen.findByText( 'Classic Cotton T-Shirt' );
		fireEvent.click(
			within(
				screen
					.getByRole( 'link', { name: 'Ladies Golf Shirt' } )
					.closest( 'tr' )
			).getByRole( 'checkbox' )
		);
		await waitFor( () =>
			expect( getSelectionSession().getState().count ).toBe( 1 )
		);
		const dialog = await openPanel();
		await waitFor( () =>
			expect( dialog.textContent ).toContain( '1 selected product' )
		);
		expect(
			within( dialog ).queryByText( /^Nothing is selected\./ )
		).toBeNull();
		expect( captures() ).toHaveLength( 0 );
	} );
} );
