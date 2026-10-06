/**
 * FRONTEND-12: closing the bulk panel while Apply is being sent must not hide
 * an answer that never came. The catalogue keeps a notice that the change may
 * or may not have been made, and Check again resolves the same grant (which
 * never makes the change): a change that had been accepted is then followed
 * as any other, and one that was not is said plainly.
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

const UNCONFIRMED =
	'We could not confirm whether your last bulk change was applied.';
const DROPPED = { code: 'fetch_error', message: 'Network down.' };

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
 * Tick a row, open the panel, check an increase, Apply with the answer held,
 * then close the panel and let the answer drop.
 *
 * @param {boolean} made Whether the server made the change before the answer was lost.
 */
async function applyThenClose( made ) {
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
	server.hold( 'POST batches' );
	server.fail( 'POST batches', DROPPED, { after: made } );
	fireEvent.click(
		await within( dialog ).findByRole( 'button', {
			name: 'Apply to 20 products',
		} )
	);
	await waitFor( () =>
		expect( server.waiting( 'POST batches' ) ).toHaveLength( 1 )
	);
	fireEvent.keyDown( dialog, { key: 'Escape' } );
	await waitFor( () =>
		expect(
			screen.queryByRole( 'dialog', { name: 'Bulk change' } )
		).toBeNull()
	);
	await act( async () => server.release( 'POST batches' ) );
}

it( 'keeps an unconfirmed Apply in view after the panel closed, and follows it once Check again finds it made', async () => {
	await applyThenClose( true );
	const notice = await screen.findByRole( 'alert', {
		name: UNCONFIRMED,
	} );
	fireEvent.click(
		within( notice ).getByRole( 'button', { name: 'Check again' } )
	);
	await screen.findByRole( 'group', { name: 'Change in progress' } );
	expect( screen.queryByRole( 'alert', { name: UNCONFIRMED } ) ).toBeNull();
	// Checking never sent the change again.
	expect( server.calls( 'POST batches' ) ).toHaveLength( 1 );
} );

it( 'says plainly when Check again finds the change was not made', async () => {
	await applyThenClose( false );
	const notice = await screen.findByRole( 'alert', {
		name: UNCONFIRMED,
	} );
	fireEvent.click(
		within( notice ).getByRole( 'button', { name: 'Check again' } )
	);
	await waitFor( () =>
		expect( notice.textContent ).toContain(
			'Your last bulk change was not made.'
		)
	);
	expect( server.calls( 'POST batches' ) ).toHaveLength( 1 );
	expect( server.batches.size ).toBe( 0 );
	fireEvent.click(
		within( notice ).getByRole( 'button', { name: 'Dismiss' } )
	);
	expect( screen.queryByRole( 'alert', { name: UNCONFIRMED } ) ).toBeNull();
} );
