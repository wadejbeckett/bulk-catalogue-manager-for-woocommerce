/**
 * Delivery ticket 30 (1.4.3): until the first item is done, a change reads
 * "Queued, starts within a minute" instead of "0 of N done", in the bulk
 * panel's Done step and in the shared progress strip the Products screen
 * and History use. The count takes over with the first item done, and the
 * stalled notice still wins over the queued line.
 */
import { render, screen, waitFor } from '@testing-library/react';

import { DoneStep } from '../../assets/src/panels/bulk/progress';
import {
	BatchProgress,
	isQueued,
} from '../../assets/src/panels/history/stalled-notice';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );
jest.mock( '../../assets/src/panels/categories/announce', () => ( {
	announce: jest.fn(),
} ) );

const apiFetch = require( '@wordpress/api-fetch' ).default;

const QUEUED_MESSAGE =
	"Queued, starts within a minute. Nothing has changed yet: the site's background tasks pick new changes up on their next run.";

const QUEUED = {
	batch_id: 181,
	status: 'queued',
	source: 'bulk',
	user_id: 2,
	created_gmt: '2026-10-07 10:00:00',
	total_items: 16,
	done_items: 0,
	changed_items: 0,
	failed_items: 0,
	pending_items: 16,
	operations: [],
	actions: [],
	queued: true,
	queued_message: QUEUED_MESSAGE,
	stalled: false,
	stalled_message: '',
	reversibility: { state: 'in_progress', conflicts: null, reason: null },
	undo: null,
};

const RUNNING = {
	...QUEUED,
	status: 'running',
	done_items: 1,
	changed_items: 1,
	pending_items: 15,
	queued: false,
	queued_message: '',
};

let report;

beforeAll( () => {
	document.documentElement.lang = 'en-GB';
} );

afterAll( () => {
	document.documentElement.lang = '';
} );

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => Promise.resolve( report ) );
} );

describe( 'the queued flag', () => {
	it( 'is read from the report, and stalled wins', () => {
		expect( isQueued( QUEUED ) ).toBe( true );
		expect( isQueued( RUNNING ) ).toBe( false );
		expect( isQueued( { ...QUEUED, stalled: true } ) ).toBe( false );
		expect( isQueued( { ...QUEUED, queued: undefined } ) ).toBe( false );
		expect( isQueued( null ) ).toBe( false );
	} );
} );

describe( 'the shared progress strip', () => {
	it( 'reads Queued with an indeterminate bar until the first item is done', () => {
		const { container } = render( <BatchProgress report={ QUEUED } /> );
		expect(
			screen.getByText( 'Queued, starts within a minute' )
		).toBeTruthy();
		expect( screen.queryByText( /0 of 16 done/ ) ).toBeNull();
		const bar = container.querySelector( 'progress' );
		expect( bar ).not.toBeNull();
		// No value: the bar is indeterminate, not 0 of 16.
		expect( bar.hasAttribute( 'value' ) ).toBe( false );
		expect( bar.getAttribute( 'aria-valuetext' ) ).toBe(
			'Queued, starts within a minute'
		);
		expect( container.querySelector( '.bulkcat-stalled' ) ).toBeNull();
	} );

	it( 'shows the count from the first item done', () => {
		const { container } = render( <BatchProgress report={ RUNNING } /> );
		expect( screen.getByText( '1 of 16 done' ) ).toBeTruthy();
		expect( screen.queryByText( /Queued/ ) ).toBeNull();
		expect(
			container.querySelector( 'progress' ).getAttribute( 'value' )
		).toBe( '1' );
	} );

	it( 'gives way to the stalled notice', () => {
		const { container } = render(
			<BatchProgress
				report={ {
					...QUEUED,
					stalled: true,
					stalled_message: 'The queue has not run.',
				} }
			/>
		);
		expect( container.querySelector( '.bulkcat-stalled' ) ).not.toBeNull();
		expect( screen.queryByText( /Queued/ ) ).toBeNull();
	} );
} );

describe( 'the Done step', () => {
	it( 'reads Queued until the first item is done, then the count', async () => {
		report = QUEUED;
		const { container } = render(
			<DoneStep batchId={ 181 } count={ 16 } interval={ 20 } />
		);
		expect( await screen.findByText( QUEUED_MESSAGE ) ).toBeTruthy();
		expect( screen.queryByText( /0 of 16 done/ ) ).toBeNull();
		expect(
			screen.getByText( 'Working through 16 items in the background.' )
		).toBeTruthy();
		const bar = screen.getByRole( 'progressbar' );
		expect( bar.hasAttribute( 'aria-valuenow' ) ).toBe( false );
		expect( bar.getAttribute( 'aria-valuetext' ) ).toBe( QUEUED_MESSAGE );
		expect( container.querySelector( '.is-queued' ) ).not.toBeNull();

		report = RUNNING;
		await waitFor( () =>
			expect( screen.getByText( '1 of 16 done' ) ).toBeTruthy()
		);
		expect( screen.queryByText( QUEUED_MESSAGE ) ).toBeNull();
		expect(
			screen.getByRole( 'progressbar' ).getAttribute( 'aria-valuenow' )
		).toBe( '1' );
		expect( container.querySelector( '.is-queued' ) ).toBeNull();
	} );

	it( 'still hands a stalled report to renderStalled', async () => {
		report = {
			...QUEUED,
			stalled: true,
			stalled_message: 'The background queue has not run.',
		};
		render(
			<DoneStep
				batchId={ 181 }
				count={ 16 }
				interval={ 20 }
				renderStalled={ ( r ) => (
					<p data-testid="stalled">{ r.stalled_message }</p>
				) }
			/>
		);
		expect( ( await screen.findByTestId( 'stalled' ) ).textContent ).toBe(
			'The background queue has not run.'
		);
		expect( screen.queryByText( /Queued/ ) ).toBeNull();
	} );
} );
