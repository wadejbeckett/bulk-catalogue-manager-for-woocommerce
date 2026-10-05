/**
 * FRONTEND-8: reading a change's progress stops on an answer that will not
 * change by asking again (signed out, not allowed, gone), says so plainly
 * and offers Check again; a passing failure is still tried again.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { DoneStep } from '../../assets/src/panels/bulk/progress';
import { AppliedProgress } from '../../assets/src/panels/catalogue';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;

const SIGNED_OUT = {
	code: 'rest_not_logged_in',
	message: 'You are not currently logged in.',
	data: { status: 401 },
};
const STOPPED =
	'The progress can no longer be read. If you have been signed out, sign in again, then check again.';

let answers;
const reads = () =>
	apiFetch.mock.calls.filter( ( [ options ] ) => options.url === 'batches/9' )
		.length;
const pause = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => {
		const next = answers.length > 1 ? answers.shift() : answers[ 0 ];
		return next instanceof Error || next?.code
			? Promise.reject( next )
			: Promise.resolve( next );
	} );
} );

it( 'stops reading after a sign-out and offers Check again', async () => {
	answers = [
		{ status: 'running', done_items: 1, total_items: 4 },
		SIGNED_OUT,
		{ status: 'running', done_items: 2, total_items: 4 },
	];
	render( <DoneStep batchId={ 9 } count={ 4 } interval={ 10 } /> );
	expect( await screen.findByText( STOPPED ) ).toBeTruthy();
	const stoppedAt = reads();
	await pause( 120 );
	expect( reads() ).toBe( stoppedAt );
	fireEvent.click( screen.getByRole( 'button', { name: 'Check again' } ) );
	expect( await screen.findByText( '2 of 4 done' ) ).toBeTruthy();
	expect( screen.queryByText( STOPPED ) ).toBeNull();
} );

it( 'keeps trying after a passing failure', async () => {
	answers = [
		{ code: 'fetch_error', message: 'You are probably offline.' },
		{ status: 'running', done_items: 3, total_items: 4 },
	];
	render( <DoneStep batchId={ 9 } count={ 4 } interval={ 10 } /> );
	expect( await screen.findByText( '3 of 4 done' ) ).toBeTruthy();
	expect( screen.queryByText( STOPPED ) ).toBeNull();
} );

it( 'stops the banner’s reads too, and says so there', async () => {
	answers = [
		{ status: 'running', done_items: 1, total_items: 4 },
		SIGNED_OUT,
	];
	render( <AppliedProgress batchId={ 9 } onFinished={ jest.fn() } /> );
	expect(
		await screen.findByText( STOPPED, {}, { timeout: 8000 } )
	).toBeTruthy();
	const stoppedAt = reads();
	await pause( 300 );
	expect( reads() ).toBe( stoppedAt );
	await waitFor( () =>
		expect(
			screen.getByRole( 'button', { name: 'Check again' } )
		).toBeTruthy()
	);
} );
