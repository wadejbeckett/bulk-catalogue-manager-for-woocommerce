/**
 * T84: the screen never sends a 1.0 body. Since T83 capture (`POST
 * /selection`), Apply (`POST /batches`) and undo (`POST /batches/{id}/undo`)
 * take only durable requests, a server grant's `uuid`, `request_proof` and
 * canonical `intent`. Three guards: request() refuses a 1.0 write before
 * anything is sent; no module but api.js calls apiFetch, so nothing goes
 * around it; and the whole journey — tick, search, tick, Select all
 * matching, untick, preview, Apply, and History's undo — sends only durable
 * bodies.
 */
import fs from 'fs';
import path from 'path';

import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import { isLegacyWrite, request } from '../../assets/src/api';
import Catalogue from '../../assets/src/panels/catalogue';
import { applyUndo, undoHolder } from '../../assets/src/panels/history/undo';

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

const SRC = path.join( __dirname, '../../assets/src' );

/**
 * Every JavaScript file under a folder.
 *
 * @param {string} dir Folder.
 * @return {Array<string>} Paths.
 */
function sources( dir ) {
	return fs
		.readdirSync( dir, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const full = path.join( dir, entry.name );
			if ( entry.isDirectory() ) {
				return sources( full );
			}
			return entry.name.endsWith( '.js' ) ? [ full ] : [];
		} );
}

const DURABLE = [ 'uuid', 'request_proof', 'intent' ];

/**
 * Whether a recorded write is a durable body: the grant's three keys, plus
 * only what the route takes beside them.
 *
 * @param {string} url  Route.
 * @param {Object} data Body.
 * @return {boolean} Durable.
 */
function durable( url, data ) {
	const extra = {
		selection: [],
		'selection/compose': [ 'token', 'add_token', 'remove_token' ],
		batches: [ 'token', 'preview_hash' ],
	};
	const allowed = [
		...DURABLE,
		...( extra[ url ] ?? ( /undo$/.test( url ) ? [] : null ) ?? [] ),
	];
	return (
		DURABLE.every( ( key ) => !! data?.[ key ] ) &&
		Object.keys( data ).every( ( key ) => allowed.includes( key ) )
	);
}

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => Promise.resolve( {} ) );
} );

describe( 'the 1.0 gate in request()', () => {
	it.each( [
		[
			'batches',
			{ uuid: 'x', operations: [], ids: [ 1 ], source: 'bulk' },
		],
		[
			'batches',
			{
				uuid: 'x',
				operations: [],
				token: 't',
				preview_count: 3,
				confirm_changed: true,
			},
		],
		[ 'batches/5/undo', { uuid: 'x', plan_hash: 'h', force: false } ],
		[ 'selection', { search: 'shirt' } ],
		[ 'selection', { ids: [ 1, 2 ] } ],
	] )( 'refuses a 1.0 POST to %s before sending it', async ( to, data ) => {
		expect( isLegacyWrite( to, { method: 'POST', data } ) ).toBe( true );
		await expect(
			request( to, { method: 'POST', data } )
		).rejects.toMatchObject( { code: 'bulkcat_legacy_request' } );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it.each( [
		[
			'batches',
			{ uuid: 'u', request_proof: 'p', intent: {}, preview_hash: 'h' },
		],
		[ 'batches/5/undo', { uuid: 'u', request_proof: 'p', intent: {} } ],
		[ 'batches/5/undo', { preview: true, per_page: 100 } ],
		[ 'selection', { uuid: 'u', request_proof: 'p', intent: {} } ],
		[ 'preview', { operations: [], ids: [ 1 ] } ],
		[ 'requests', { operation: 'batch.apply', intent: {} } ],
		[ 'batches/5/cancel', {} ],
	] )(
		'lets a durable or unrelated POST to %s through',
		async ( to, data ) => {
			expect( isLegacyWrite( to, { method: 'POST', data } ) ).toBe(
				false
			);
			await request( to, { method: 'POST', data } );
			expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		}
	);
} );

describe( 'no way around the gate', () => {
	it( 'has no module but api.js call apiFetch', () => {
		const callers = sources( SRC ).filter( ( file ) => {
			const code = fs
				.readFileSync( file, 'utf8' )
				.replace( /\/\*[\s\S]*?\*\//g, '' )
				.replace( /\/\/.*$/gm, '' );
			return /\bapiFetch\s*\(|\bfetch\s*\(|XMLHttpRequest|sendBeacon/.test(
				code
			);
		} );
		expect( callers.map( ( file ) => path.relative( SRC, file ) ) ).toEqual(
			[ 'api.js' ]
		);
		// nonce.js fetches only core's nonce endpoint (a GET), never a write.
		expect(
			fs.readFileSync( path.join( SRC, 'nonce.js' ), 'utf8' )
		).not.toMatch( /method:\s*'POST'/ );
	} );

	it( 'sends only durable bodies through the whole journey', async () => {
		const server = createSelectionServer();
		server.setMatches( () => [ 11, 12, 21 ] );
		const CAP = { ...ROWS[ 0 ], id: 31, name: 'Cap', sku: 'CAP-1' };
		route( apiFetch, {
			fields: () => REGISTRY,
			products: ( query ) =>
				query.search === 'Cap' ? page( [ CAP ], 1 ) : page( ROWS, 3 ),
			categories: () => CATEGORIES,
			...server.routes(),
			preview: ( query, options ) => ( {
				totals: {
					matched: 4,
					computed: 4,
					would_change: 4,
					already: 0,
					would_fail: {},
					rows: { changed: 4 },
				},
				sample: [],
				warnings: {},
				unreadable: [],
				selection: { kind: 'ids', count: 4 },
				hash: 'a'.repeat( 64 ),
				unavailable_count: 0,
				scope_hash: 'c'.repeat( 64 ),
				operations: options.data.operations,
			} ),
			'batches/101': () => ( {
				batch_id: 101,
				status: 'complete',
				total_items: 4,
				done_items: 4,
				failed_items: 0,
				pending_items: 0,
			} ),
			'batches/101/undo': () => ( {
				batch_id: 102,
				status: 'queued',
				total_items: 4,
				replayed: false,
			} ),
		} );
		const { unmount } = render(
			<Catalogue settings={ { settings: {} } } />
		);
		await screen.findByText( 'Classic Cotton T-Shirt' );
		const bar = () => screen.getByRole( 'region', { name: 'Selection' } );
		const counted = ( words ) =>
			waitFor( () => expect( bar().textContent ).toContain( words ) );
		const tick = ( name ) =>
			fireEvent.click(
				within(
					screen.getByRole( 'link', { name } ).closest( 'tr' )
				).getByRole( 'checkbox' )
			);

		tick( 'Classic Cotton T-Shirt' );
		await counted( '1 product selected' );
		fireEvent.change(
			screen.getByRole( 'searchbox', { name: 'Search by name or SKU' } ),
			{ target: { value: 'Cap' } }
		);
		await screen.findByRole( 'link', { name: 'Cap' } );
		tick( 'Cap' );
		await counted( '2 products selected' );
		fireEvent.click(
			within( bar() ).getByRole( 'button', { name: /^Select the 1/ } )
		);
		await counted( '4 products selected' );
		tick( 'Cap' );
		await counted( '3 products selected' );
		fireEvent.click(
			within( bar() ).getByRole( 'button', {
				name: 'Hide from shop and search',
			} )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Bulk change',
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Check the change' } )
		);
		const apply = await within( dialog ).findByRole( 'button', {
			name: /^Apply to 4 products/,
		} );
		fireEvent.click( apply );
		// Hiding always confirms (SPEC 4.5): the second click applies.
		const confirm = await within( dialog ).findByRole( 'button', {
			name: /^Yes, change 4 products/,
		} );
		fireEvent.click( confirm );
		await waitFor( () =>
			expect( server.calls( 'POST batches' ) ).toHaveLength( 1 )
		);
		// A change that finished in full clears what was applied.
		await waitFor( () =>
			expect(
				document.querySelector( '.bulkcat-selection' ).textContent
			).toContain( 'The change finished, so your selection was cleared.' )
		);
		unmount();
		// History's undo, through its own request.
		await applyUndo(
			undoHolder( 101 ),
			101,
			{ plan_hash: 'b'.repeat( 64 ) },
			false
		);

		const writes = apiFetch.mock.calls
			.map( ( [ options ] ) => options )
			.filter(
				( options ) =>
					options.method === 'POST' &&
					/^(selection|selection\/compose|batches|batches\/\d+\/undo)$/.test(
						options.url
					) &&
					! options.data?.preview
			);
		expect( writes.map( ( options ) => options.url ).sort() ).toEqual(
			expect.arrayContaining( [
				'batches',
				'batches/101/undo',
				'selection',
				'selection/compose',
			] )
		);
		writes.forEach( ( options ) =>
			expect( [
				options.url,
				durable( options.url, options.data ),
			] ).toEqual( [ options.url, true ] )
		);
	} );
} );
