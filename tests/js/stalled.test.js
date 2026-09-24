/**
 * The stalled queue notice (T44, SPEC §4.8, §12.6): a stalled batch
 * report replaces the progress bar with a notice saying the queue has not
 * run and nothing has been applied, linking to WooCommerce → Status →
 * Scheduled Actions, with a static count and no false progress — in the
 * shared component and in the History row.
 */
import { render, screen, waitFor, within } from '@testing-library/react';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { configureApi } = require( '../../assets/src/api' );
const {
	BatchProgress,
	StalledNotice,
	isStalled,
	SCHEDULED_ACTIONS_URL,
} = require( '../../assets/src/panels/history/stalled-notice.js' );
const History = require( '../../assets/src/panels/history/index.js' ).default;

// Counts are formatted for the viewer's locale.
const THOUSANDS = ( 1248 ).toLocaleString().replace( /\s/g, ' ' );

const ROOT = 'https://example.test/wp-json/bulkcat/v1/';

const MESSAGE =
	"This change has not moved for five minutes because the site's background tasks are not running, so nothing more has been changed. Open WooCommerce > Status > Scheduled Actions to check them.";

const STALLED = {
	batch_id: 418,
	status: 'queued',
	source: 'bulk',
	user_id: 1,
	created_gmt: '2026-09-23 10:00:00',
	total_items: 1248,
	done_items: 0,
	changed_items: 0,
	failed_items: 0,
	pending_items: 1248,
	operations: [],
	actions: [],
	stalled: true,
	stalled_message: MESSAGE,
	reversibility: { state: 'in_progress', conflicts: null, reason: null },
	undo: null,
};

let errors;

beforeAll( () => {
	global.ResizeObserver = class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
	configureApi( { root: ROOT } );
} );

beforeEach( () => {
	errors = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
} );

afterEach( () => {
	errors.mockRestore();
} );

describe( 'stalled notice', () => {
	it( 'reads the stalled flag from the batch report', () => {
		expect( isStalled( STALLED ) ).toBe( true );
		expect( isStalled( { ...STALLED, stalled: false } ) ).toBe( false );
		expect( isStalled( null ) ).toBe( false );
	} );

	it( 'maps a stalled payload to the notice, the link and no progress bar', () => {
		const { container } = render( <BatchProgress report={ STALLED } /> );
		const notice = container.querySelector( '.bulkcat-stalled' );
		expect( notice ).not.toBeNull();
		expect(
			within( notice ).getByText( 'The background queue has not run.' )
		).toBeTruthy();
		expect( notice.textContent ).toContain( MESSAGE );
		expect(
			within( notice ).getByText( 'Nothing has been applied yet.' )
		).toBeTruthy();
		const link = within( notice ).getByRole( 'link', {
			name: 'Open WooCommerce → Status → Scheduled Actions',
		} );
		expect( link.getAttribute( 'href' ) ).toBe(
			'admin.php?page=wc-status&tab=action-scheduler'
		);
		expect( SCHEDULED_ACTIONS_URL ).toBe(
			'admin.php?page=wc-status&tab=action-scheduler'
		);
		// No false progress: no bar, no progress role, no live count.
		expect( container.querySelector( 'progress' ) ).toBeNull();
		expect( screen.queryByRole( 'progressbar' ) ).toBeNull();
		expect( container.querySelector( '[aria-live]' ) ).toBeNull();
		expect( container.querySelector( '[role="status"]' ) ).toBeNull();
	} );

	it( 'states a partly applied batch with a static count', () => {
		render( <StalledNotice report={ { ...STALLED, done_items: 40 } } /> );
		expect(
			screen.getByText(
				`40 of ${ THOUSANDS } products have been applied; nothing more will change until the queue runs.`
			)
		).toBeTruthy();
	} );

	it( 'shows the progress bar while the queue is moving', () => {
		const { container } = render(
			<BatchProgress
				report={ { ...STALLED, stalled: false, done_items: 40 } }
			/>
		);
		expect( container.querySelector( 'progress' ) ).not.toBeNull();
		expect( screen.getByText( `40 of ${ THOUSANDS } done` ) ).toBeTruthy();
		expect( container.querySelector( '.bulkcat-stalled' ) ).toBeNull();
	} );

	it( 'replaces the History row progress with the notice', async () => {
		apiFetch.mockImplementation( ( { url } ) => {
			const path = new URL( url ).pathname;
			if ( path.endsWith( '/bulkcat/v1/batches' ) ) {
				return Promise.resolve( {
					items: [
						{ ...STALLED, stalled: false, stalled_message: '' },
					],
					pagination: {
						page: 1,
						perPage: 25,
						total: 1,
						totalPages: 1,
					},
				} );
			}
			if ( path.endsWith( '/bulkcat/v1/batches/418' ) ) {
				return Promise.resolve( STALLED );
			}
			if ( path.endsWith( '/bulkcat/v1/fields' ) ) {
				return Promise.resolve( { fields: [], actions: [] } );
			}
			return Promise.resolve( [] );
		} );
		const { container } = render(
			<History
				settings={ { root: ROOT, user: { id: 1, name: 'Mia' } } }
			/>
		);
		await waitFor( () =>
			expect(
				container.querySelector( '.bulkcat-stalled' )
			).not.toBeNull()
		);
		const row = document
			.getElementById( 'bulkcat-batch-418' )
			.closest( 'article' );
		expect( row.querySelector( 'progress' ) ).toBeNull();
		expect(
			within( row ).queryByRole( 'button', { name: 'Undo' } )
		).toBeNull();
		expect(
			within( row ).getByRole( 'link', {
				name: 'Open WooCommerce → Status → Scheduled Actions',
			} )
		).toBeTruthy();
	} );
} );
