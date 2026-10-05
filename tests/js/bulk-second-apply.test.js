/**
 * FRONTEND-3: a second Apply while a change runs would leave the first one
 * unwatched (its rows pending, its banner gone, the grid never refreshed).
 * While a change applied from the catalogue is running, a bulk panel opened
 * afterwards holds Apply with a plain sentence; the running change is still
 * followed while that panel is open, and Apply is offered again once it has
 * finished.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

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
	ROWS,
	CATEGORIES,
	page,
	route,
} = require( './catalogue-fixture' );
const { createSelectionServer } = require( './selection-server' );
const { previewResponse } = require( './bulk-fixtures' );

const RUNNING =
	'Another change is still running. You can apply this one once it has finished.';

let server;

beforeAll( () => {
	document.documentElement.lang = 'en-GB';
} );

afterAll( () => {
	document.documentElement.lang = '';
} );

beforeEach( () => {
	server = createSelectionServer();
	apiFetch.mockReset();
	route( apiFetch, {
		fields: () => REGISTRY,
		products: () => page( ROWS, 2 ),
		categories: () => CATEGORIES,
		...server.routes(),
		preview: () => previewResponse(),
		'batches/101': ( query, options ) => server.handle( options ),
	} );
} );

const bar = () => screen.getByRole( 'region', { name: 'Selection' } );

/**
 * Open the panel from the bar, choose an increase and check it.
 *
 * @return {Promise<Element>} The panel.
 */
async function checkIncrease() {
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
		within( dialog ).getByRole( 'button', { name: 'Check the change' } )
	);
	await within( dialog ).findByRole( 'button', {
		name: 'Apply to 20 products',
	} );
	return dialog;
}

it( 'holds a second Apply while the first change runs, and offers it once that change has finished', async () => {
	render( <Catalogue settings={ { settings: {} } } /> );
	await screen.findByText( 'Classic Cotton T-Shirt' );
	fireEvent.click(
		within(
			screen
				.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
				.closest( 'tr' )
		).getByRole( 'checkbox' )
	);
	await waitFor( () =>
		expect( bar().textContent ).toContain( '1 product selected' )
	);

	let dialog = await checkIncrease();
	fireEvent.click(
		within( dialog ).getByRole( 'button', { name: 'Apply to 20 products' } )
	);
	await waitFor( () => expect( server.batches.size ).toBe( 1 ) );
	server.setBatch( 101, 'running', { total_items: 1, done_items: 0 } );
	fireEvent.click(
		await within( dialog ).findByRole( 'button', {
			name: 'Back to products',
		} )
	);
	await screen.findByRole( 'group', { name: 'Change in progress' } );

	dialog = await checkIncrease();
	const apply = within( dialog ).getByRole( 'button', {
		name: 'Apply to 20 products',
	} );
	expect( apply.disabled ).toBe( true );
	expect( dialog.textContent ).toContain( RUNNING );
	fireEvent.click( apply );
	expect( server.calls( 'POST batches' ) ).toHaveLength( 1 );

	// The first change finishes while this panel is open: it is still
	// followed, so Apply is offered again.
	server.setBatch( 101, 'partial', {
		total_items: 1,
		done_items: 1,
		failed_items: 1,
		pending_items: 0,
	} );
	await waitFor(
		() =>
			expect(
				within( dialog ).getByRole( 'button', {
					name: 'Apply to 20 products',
				} ).disabled
			).toBe( false ),
		{ timeout: 10000 }
	);
	expect( dialog.textContent ).not.toContain( RUNNING );
} );
