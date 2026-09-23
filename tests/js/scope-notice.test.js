/**
 * The scope line (T59, SPEC §9.3, §4.6): one persistent, translatable
 * line on the History list and the Audit log tab, present in their empty
 * states as well as populated ones, ordinary content rather than an error
 * notice, translated with the literal text domain.
 */
import fs from 'fs';
import path from 'path';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { configureApi } = require( '../../assets/src/api' );
const History = require( '../../assets/src/panels/history/index.js' ).default;

const ROOT = 'https://example.test/wp-json/bcm/v1/';

const LINE =
	'Only changes made through this screen are recorded here. Edits made elsewhere in WordPress, such as on the product edit page, are not.';

const BATCH = {
	batch_id: 1,
	status: 'complete',
	source: 'bulk',
	user_id: 1,
	created_gmt: '2026-09-23 10:00:00',
	total_items: 3,
	done_items: 3,
	operations: [],
	actions: [],
	stalled: false,
	reversibility: { state: 'reversible', conflicts: null, reason: null },
	undo: null,
};

const CHANGE = {
	change_id: 5,
	batch_id: 1,
	user_id: 1,
	object_id: 9,
	object_type: 'product',
	name: 'Blue Widget',
	field: 'sku',
	field_label: 'SKU',
	old_value: 'A',
	new_value: 'B',
	result: 'applied',
	message: '',
	detail: '',
	applied_gmt: '2026-09-23 10:00:01',
};

/**
 * Serve the history and audit routes with or without rows.
 *
 * @param {boolean} empty Whether both lists are empty.
 */
function serve( empty ) {
	apiFetch.mockImplementation( ( { url } ) => {
		const route = new URL( url ).pathname;
		const page = ( items ) => ( {
			items: empty ? [] : items,
			pagination: {
				page: 1,
				perPage: 25,
				total: empty ? 0 : items.length,
				totalPages: empty ? 0 : 1,
			},
		} );
		if ( route.endsWith( '/bcm/v1/batches' ) ) {
			return Promise.resolve( page( [ BATCH ] ) );
		}
		if ( route.endsWith( '/bcm/v1/audit' ) ) {
			return Promise.resolve( page( [ CHANGE ] ) );
		}
		if ( route.endsWith( '/bcm/v1/fields' ) ) {
			return Promise.resolve( {
				fields: [ { key: 'sku', label: 'SKU', type: 'text' } ],
				actions: [],
			} );
		}
		return Promise.resolve( [] );
	} );
}

/**
 * The scope line, asserted as ordinary content.
 */
function expectScopeLine() {
	const line = screen.getByText( LINE );
	expect( line.tagName ).toBe( 'P' );
	expect( line.closest( '.components-notice' ) ).toBeNull();
	expect( line.closest( '.notice' ) ).toBeNull();
	expect( line.closest( '[role="alert"]' ) ).toBeNull();
	expect( screen.getAllByText( LINE ) ).toHaveLength( 1 );
}

async function openAudit() {
	await act( async () =>
		fireEvent.click( screen.getByRole( 'tab', { name: 'Audit log' } ) )
	);
}

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

describe( 'scope notice', () => {
	it( 'is translated with the literal text domain', () => {
		const source = fs.readFileSync(
			path.resolve(
				__dirname,
				'../../assets/src/panels/history/scope-notice.js'
			),
			'utf8'
		);
		expect( source ).toContain(
			`'${ LINE }',\n\t\t\t\t'bulk-catalogue-manager-for-woocommerce'`
		);
		expect( source ).toMatch( /__\(\s*'Only changes made through/ );
	} );

	it( 'shows on the populated History list and Audit log', async () => {
		serve( false );
		render( <History settings={ { root: ROOT, user: { id: 1 } } } /> );
		await waitFor( () =>
			expect( document.getElementById( 'bcm-batch-1' ) ).not.toBeNull()
		);
		expectScopeLine();

		await openAudit();
		await waitFor( () =>
			expect( screen.getByText( 'Blue Widget' ) ).toBeTruthy()
		);
		expectScopeLine();
	} );

	it( 'shows on the empty History list and the empty Audit log', async () => {
		serve( true );
		render( <History settings={ { root: ROOT, user: { id: 1 } } } /> );
		await waitFor( () =>
			expect(
				screen.getByText( 'No changes match these filters yet.' )
			).toBeTruthy()
		);
		expectScopeLine();

		await openAudit();
		await waitFor( () =>
			expect(
				screen.getByText( 'No field changes match these filters.' )
			).toBeTruthy()
		);
		expectScopeLine();
	} );
} );
