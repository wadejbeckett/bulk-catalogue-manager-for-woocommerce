/**
 * T84: the selection session (panels/catalogue/selection/session.js) over the
 * v2 routes, with apiFetch mocked and answered by an in-memory stand-in for
 * the server (selection-server.js): capture and composition with grants,
 * union and subtraction, the serialized queue (rapid toggles, captures
 * followed by other searches, late answers after Clear), an uncertain answer
 * retried with the same grant, lease renewal and drift, recovery after a
 * reload, lost work, release, and clearing only after a change that
 * finished in full.
 */
import {
	MAX_IDS,
	WATCH_MS,
	WATCH_BACKOFF_MS,
	WATCH_TRIES,
	cleanIds,
	createSelectionSession,
} from '../../assets/src/panels/catalogue/selection/session';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { createSelectionServer, restError } = require( './selection-server' );

let server;
let timers;

/**
 * A tab store in memory.
 *
 * @return {Object} Store.
 */
function memoryStore() {
	let value = null;
	return {
		read: () => ( value ? JSON.parse( value ) : null ),
		write: ( next ) => {
			value = JSON.stringify( next );
		},
		clear: () => {
			value = null;
		},
		raw: () => value,
	};
}

/**
 * A session over the fake server, with a timer queue to fire by hand.
 *
 * @param {Object} options Options for createSelectionSession().
 * @return {Object} Session.
 */
function makeSession( options = {} ) {
	return createSelectionSession( {
		schedule: ( fn, ms ) => {
			const timer = { fn, ms, cancelled: false };
			timers.push( timer );
			return () => {
				timer.cancelled = true;
			};
		},
		...options,
	} );
}

const flush = async ( rounds = 6 ) => {
	for ( let i = 0; i < rounds; i++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}
};

const tokenOf = ( session ) => session.getState().current?.token;
const members = ( session ) => server.members( tokenOf( session ) );
const posted = ( key ) => server.calls( key ).map( ( call ) => call.data );

beforeEach( () => {
	server = createSelectionServer();
	timers = [];
	apiFetch.mockReset();
	apiFetch.mockImplementation( ( options ) =>
		Promise.resolve().then( () => server.handle( options ) )
	);
} );

describe( 'selection session: building the selection', () => {
	it( 'captures the first tick, then composes every later one on the snapshot it got', async () => {
		const session = makeSession();
		expect( session.getState().count ).toBe( 0 );
		await expect( session.add( [ '11' ] ) ).resolves.toBe( true );
		const first = tokenOf( session );
		expect( members( session ) ).toEqual( [ 11 ] );
		expect( posted( 'POST requests' )[ 0 ] ).toEqual( {
			operation: 'selection.capture',
			intent: { ids: [ 11 ] },
		} );

		await session.add( [ 12 ] );
		expect( members( session ) ).toEqual( [ 11, 12 ] );
		expect( posted( 'POST requests' )[ 1 ] ).toEqual( {
			operation: 'selection.compose',
			intent: { token: first, add_ids: [ 12 ] },
		} );
		// The compose carries the grant, its canonical intent and the base token.
		const [ compose ] = posted( 'POST selection/compose' );
		expect( Object.keys( compose ).sort() ).toEqual( [
			'intent',
			'request_proof',
			'token',
			'uuid',
		] );
		expect( compose.token ).toBe( first );
		expect( compose.intent ).toMatchObject( {
			version: 1,
			add_ids: [ 12 ],
		} );
		// The replaced snapshot is released once nothing refers to it.
		expect( server.released( first ) ).toBe( true );
		expect( session.getState() ).toMatchObject( {
			count: 2,
			byType: { products: 2, variations: 0 },
			status: 'idle',
		} );
	} );

	it( 'unions a Select all matching capture with ticked rows and deduplicates', async () => {
		const session = makeSession();
		await session.add( [ 31 ] );
		server.setMatches( () => [ 11, 21, 31 ] );
		await session.addMatching( { search: 'Shirt' } );
		expect( members( session ) ).toEqual( [ 11, 21, 31 ] );
		const [ capture, union ] = posted( 'POST requests' ).slice( 1 );
		expect( capture ).toEqual( {
			operation: 'selection.capture',
			intent: { query: { search: 'Shirt' } },
		} );
		expect( union.operation ).toBe( 'selection.compose' );
		expect( Object.keys( union.intent ).sort() ).toEqual( [
			'add_token',
			'token',
		] );
		const [ compose ] = posted( 'POST selection/compose' );
		expect( compose.add_token ).toBeTruthy();
		expect( session.getState().lastMatching ).toBe(
			JSON.stringify( { search: 'Shirt' } )
		);
		// The captured operand is released once it is part of the selection.
		expect( server.released( compose.add_token ) ).toBe( true );
	} );

	it( 'removes one row after Select all matching, off the page, and keeps the rest', async () => {
		const session = makeSession();
		server.setMatches( () => [ 11, 12, 21, 22 ] );
		await session.addMatching( {} );
		expect( members( session ) ).toEqual( [ 11, 12, 21, 22 ] );
		await session.remove( [ 21 ] );
		expect( members( session ) ).toEqual( [ 11, 12, 22 ] );
		expect( posted( 'POST requests' ).at( -1 ) ).toMatchObject( {
			operation: 'selection.compose',
			intent: { remove_ids: [ 21 ] },
		} );
		expect( session.isSelected( 21 ) ).toBe( false );
	} );

	it( 'keeps both of two rapid toggles: the second composes on the first one’s answer', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		const a = session.add( [ 12 ] );
		const b = session.add( [ 21 ] );
		// Both show as selected while they are pending.
		expect( session.isSelected( 12 ) ).toBe( true );
		expect( session.isSelected( 21 ) ).toBe( true );
		expect( session.getState().status ).toBe( 'saving' );
		await Promise.all( [ a, b ] );
		expect( members( session ) ).toEqual( [ 11, 12, 21 ] );
		const composes = posted( 'POST selection/compose' );
		expect( composes ).toHaveLength( 2 );
		expect( composes[ 1 ].intent.base ).not.toBe(
			composes[ 0 ].intent.base
		);
	} );

	it( 'captures a query as it was when chosen, even after another search', async () => {
		const session = makeSession();
		server.setMatches( ( conditions ) =>
			conditions.search === 'TSH' ? [ 11 ] : [ 21, 22 ]
		);
		server.hold( 'POST selection' );
		const first = session.addMatching( { search: 'TSH' } );
		const second = session.addMatching( { search: 'Jacket' } );
		await flush();
		server.release( 'POST selection' );
		await Promise.all( [ first, second ] );
		expect( members( session ) ).toEqual( [ 11, 21, 22 ] );
		const captures = posted( 'POST requests' ).filter(
			( each ) => each.operation === 'selection.capture'
		);
		expect( captures.map( ( each ) => each.intent.query.search ) ).toEqual(
			[ 'TSH', 'Jacket' ]
		);
	} );

	it( 'adds and removes in parts of at most a thousand ids', async () => {
		expect( cleanIds( [ '3', 2, 2, 'x', -1, 1.5, 1 ] ) ).toEqual( [
			1, 2, 3,
		] );
		const ids = Array.from( { length: MAX_IDS + 5 }, ( _, i ) => i + 1 );
		server = createSelectionServer( {
			objects: ids.map( ( id ) => ( {
				id,
				name: `P${ id }`,
				sku: `S${ id }`,
				type: 'product',
			} ) ),
		} );
		const session = makeSession();
		await session.add( ids );
		expect( session.getState().count ).toBe( MAX_IDS + 5 );
		const requests = posted( 'POST requests' );
		expect( requests[ 0 ].intent.ids ).toHaveLength( MAX_IDS );
		expect( requests[ 1 ].intent.add_ids ).toHaveLength( 5 );
	} );
} );

describe( 'selection session: failures and late answers', () => {
	it( 'keeps an uncertain composition’s grant and resends it, which the server replays', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		const before = tokenOf( session );
		// The server makes the successor, but the answer is lost.
		server.fail(
			'POST selection/compose',
			{ code: 'fetch_error', message: 'You are probably offline.' },
			{ after: true }
		);
		const pending = session.add( [ 12 ] );
		await flush();
		expect( session.getState() ).toMatchObject( {
			status: 'uncertain',
			count: 1,
			error: { uncertain: true, retry: true },
		} );
		// The last confirmed snapshot is still the selection.
		expect( tokenOf( session ) ).toBe( before );
		expect( session.isSelected( 12 ) ).toBe( true );
		// A later intent waits behind it.
		const later = session.add( [ 21 ] );
		await flush();
		expect( posted( 'POST selection/compose' ) ).toHaveLength( 1 );

		session.retry();
		await expect( pending ).resolves.toBe( true );
		await later;
		const [ first, again ] = posted( 'POST selection/compose' );
		expect( again.uuid ).toBe( first.uuid );
		expect( again.request_proof ).toBe( first.request_proof );
		expect( members( session ) ).toEqual( [ 11, 12, 21 ] );
		// Two grants for compositions (12, 21), not three: no new identity.
		expect(
			posted( 'POST requests' ).filter(
				( each ) => each.operation === 'selection.compose'
			)
		).toHaveLength( 2 );
	} );

	it( 'drops a refused intent with its sentence and keeps the selection as it was', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		const done = await session.add( [ 999 ] );
		expect( done ).toBe( false );
		expect( session.getState() ).toMatchObject( {
			status: 'idle',
			count: 1,
			error: {
				code: 'bulkcat_invalid_value',
				retry: false,
				message:
					'These products or filters could not be selected. Check your selection and try again.',
			},
		} );
		expect( session.isSelected( 999 ) ).toBe( false );
	} );

	it( 'ignores a late answer after Clear and releases what it made', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		server.hold( 'POST selection/compose' );
		const pending = session.add( [ 12 ] );
		await flush();
		const held = tokenOf( session );
		session.clear();
		expect( session.getState().count ).toBe( 0 );
		await flush();
		expect( server.released( held ) ).toBe( true );
		server.release( 'POST selection/compose' );
		await expect( pending ).resolves.toBe( false );
		await flush();
		expect( session.getState().count ).toBe( 0 );
		expect( session.isSelected( 12 ) ).toBe( false );
		// The successor made after Clear is released too.
		const late = [ ...server.snapshots.values() ].at( -1 );
		expect( late.released ).toBe( true );
	} );

	it( 'renews a lapsed lease inside a composition and sends the same grant again', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		server.expireLeases();
		await session.add( [ 12 ] );
		const composes = posted( 'POST selection/compose' );
		expect( composes ).toHaveLength( 2 );
		expect( composes[ 1 ].uuid ).toBe( composes[ 0 ].uuid );
		expect( composes[ 1 ].token ).not.toBe( composes[ 0 ].token );
		expect( members( session ) ).toEqual( [ 11, 12 ] );
	} );
} );

describe( 'selection session: leases, recovery and release', () => {
	it( 'renews on its timer a third of the way through the lease, keeping a live token', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		const token = tokenOf( session );
		const timer = timers.filter( ( each ) => ! each.cancelled ).at( -1 );
		expect( timer.ms ).toBeGreaterThanOrEqual( 15000 );
		expect( timer.ms ).toBeLessThanOrEqual( 5 * 60 * 1000 );
		await timer.fn();
		expect( posted( 'POST selection/renew' ) ).toEqual( [ { token } ] );
		expect( tokenOf( session ) ).toBe( token );
		expect( session.getState().drift ).toBe( false );
	} );

	it( 'reauthorizes the same products after a suspension and asks for a new preview', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		const token = tokenOf( session );
		server.expireLeases();
		server.advance( 30 * 60 * 1000 );
		await session.wake();
		expect( tokenOf( session ) ).not.toBe( token );
		expect( members( session ) ).toEqual( [ 11, 12 ] );
		expect( session.getState() ).toMatchObject( {
			drift: true,
			count: 2,
		} );
	} );

	it( 'recovers the same selection from the tab’s copy after a reload', async () => {
		const store = memoryStore();
		const first = makeSession( { store } );
		await first.add( [ 11, 21 ] );
		const token = tokenOf( first );
		// Only handles are kept, never the ids.
		expect( store.raw() ).not.toContain( '"ids"' );
		first.dispose();

		const reloaded = makeSession( { store } );
		expect( reloaded.recover() ).toBe( true );
		expect( reloaded.getState() ).toMatchObject( {
			count: 2,
			notice: { kind: 'restored' },
		} );
		await flush();
		expect( posted( 'POST selection/renew' ) ).toEqual( [ { token } ] );
		expect( tokenOf( reloaded ) ).toBe( token );
		expect( reloaded.getState().drift ).toBe( false );
	} );

	it( 'recovers after an overnight suspension with the same ids and a drift notice', async () => {
		const store = memoryStore();
		const first = makeSession( { store } );
		await first.add( [ 11 ] );
		first.dispose();
		server.expireLeases();
		server.advance( 12 * 3600 * 1000 );
		const reloaded = makeSession( { store } );
		reloaded.recover();
		await flush();
		expect( reloaded.getState() ).toMatchObject( {
			count: 1,
			drift: true,
		} );
		expect( members( reloaded ) ).toEqual( [ 11 ] );
	} );

	it( 'reports work past its retention as lost, with the count it had, never as empty', async () => {
		const store = memoryStore();
		const first = makeSession( { store } );
		await first.add( [ 11, 12, 21 ] );
		first.dispose();
		server.dropAll();
		const reloaded = makeSession( { store } );
		reloaded.recover();
		await flush();
		expect( reloaded.getState() ).toMatchObject( {
			current: null,
			count: 0,
			lost: {
				count: 3,
				message:
					'These selected products are no longer retained. Choose them again before starting a new change.',
			},
		} );
	} );

	it( 'pauses an intent that was in flight at the reload and resends its grant on Try again', async () => {
		const store = memoryStore();
		const first = makeSession( { store } );
		await first.add( [ 11 ] );
		server.fail(
			'POST selection/compose',
			{ code: 'fetch_error', message: 'offline' },
			{ after: true }
		);
		first.add( [ 12 ] );
		await flush();
		expect( first.getState().status ).toBe( 'uncertain' );
		first.dispose();

		const reloaded = makeSession( { store } );
		reloaded.recover();
		expect( reloaded.getState() ).toMatchObject( {
			status: 'uncertain',
			error: { kind: 'unconfirmed', retry: true },
		} );
		reloaded.retry();
		await flush();
		const [ lost, again ] = posted( 'POST selection/compose' );
		expect( again.uuid ).toBe( lost.uuid );
		expect( again.data ).toBeUndefined();
		expect( members( reloaded ) ).toEqual( [ 11, 12 ] );
		expect( reloaded.getState().status ).toBe( 'idle' );
	} );

	it( 'keeps the selection while the right to use it is lost, and uses it again once restored', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		const token = tokenOf( session );
		server.fail(
			'POST selection/renew',
			restError(
				'rest_forbidden',
				403,
				'Sorry, you are not allowed to do that.'
			)
		);
		await session.wake();
		expect( session.getState() ).toMatchObject( {
			status: 'blocked',
			count: 2,
			blocked: { code: 'rest_forbidden', retry: true },
		} );
		await session.wake();
		expect( session.getState() ).toMatchObject( {
			status: 'idle',
			blocked: null,
			count: 2,
		} );
		expect( tokenOf( session ) ).toBe( token );
	} );

	it( 'shows nothing when a release answer is lost: the server’s bounds clean up', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		server.fail( 'POST selection/release', {
			code: 'fetch_error',
			message: 'offline',
		} );
		await session.add( [ 12 ] );
		await flush();
		expect( posted( 'POST selection/release' ) ).toHaveLength( 1 );
		expect( session.getState() ).toMatchObject( {
			status: 'idle',
			error: null,
			count: 2,
		} );
	} );

	it( 'releases the selection on Clear and keeps nothing in the tab', async () => {
		const store = memoryStore();
		const session = makeSession( { store } );
		await session.add( [ 11 ] );
		const token = tokenOf( session );
		session.clear();
		await flush();
		expect( posted( 'POST selection/release' ) ).toEqual( [
			{ tokens: [ token ] },
		] );
		expect( store.raw() ).toBeNull();
		session.clear( 'search' );
		// Nothing was selected, so there is nothing to restore.
		expect( session.getState().notice ).toEqual( {
			kind: 'searchCleared',
			restorable: false,
		} );
	} );

	it( 'keeps in the tab the handles and counts, and a pending intent’s own ids, query and grants until it is confirmed — never a snapshot’s members', async () => {
		const store = memoryStore();
		const session = makeSession( { store } );
		server.setMatches( () => [ 11, 12, 21, 22, 31 ] );
		await session.addMatching( { search: 'Shirt' } );
		// Idle: handles and counts only; the five members are nowhere.
		let kept = JSON.parse( store.raw() );
		expect( kept.jobs ).toEqual( [] );
		expect( kept.handles ).toHaveLength( 1 );
		expect( Object.keys( kept.handles[ 0 ] ).sort() ).toEqual( [
			'byType',
			'count',
			'epoch',
			'expiresAt',
			'key',
			'retainedUntil',
			'token',
		] );
		expect( store.raw() ).not.toContain( '"ids"' );
		expect( store.raw() ).not.toContain( '[11,12,21,22,31]' );

		// A tick whose answer is lost: its own id and grant are kept.
		server.fail( 'POST selection/compose', {
			code: 'fetch_error',
			message: 'offline',
		} );
		session.add( [ 41 ] );
		await flush();
		kept = JSON.parse( store.raw() );
		expect( kept.jobs ).toHaveLength( 1 );
		expect( kept.jobs[ 0 ] ).toMatchObject( {
			type: 'add',
			args: { ids: [ 41 ] },
		} );
		const [ grant ] = Object.values( kept.jobs[ 0 ].grants );
		expect( grant ).toMatchObject( {
			uuid: expect.any( String ),
			request_proof: expect.any( String ),
		} );
		// Confirmed: the intent and its grant are gone from the tab.
		session.retry();
		await flush();
		kept = JSON.parse( store.raw() );
		expect( kept.jobs ).toEqual( [] );
		expect( store.raw() ).not.toContain( '"ids"' );

		// A Select all matching whose answer is lost keeps its query.
		server.fail( 'POST selection', {
			code: 'fetch_error',
			message: 'offline',
		} );
		session.addMatching( { search: 'Cap' } );
		await flush();
		kept = JSON.parse( store.raw() );
		expect( kept.jobs[ 0 ] ).toMatchObject( {
			type: 'matching',
			args: { query: { search: 'Cap' } },
		} );
	} );
} );

describe( 'selection session: completion the screen did not see (T85)', () => {
	/**
	 * Fire the session's latest pending watch.
	 */
	const fireWatch = () => {
		// The watch's own timer (its callback is the session's `poll`).
		const timer = timers
			.filter( ( each ) => ! each.cancelled && each.fn.name === 'poll' )
			.pop();
		expect( timer ).toBeTruthy();
		timer.cancelled = true;
		timer.fn();
	};
	const COMPLETE = {
		total_items: 2,
		done_items: 2,
		failed_items: 0,
		pending_items: 0,
	};

	it( 'follows a change applied before a reload to its end and clears what it applied, once', async () => {
		const store = memoryStore();
		const first = makeSession( { store } );
		await first.add( [ 11, 12 ] );
		const token = tokenOf( first );
		server.setBatch( 40, 'running', { total_items: 2, done_items: 0 } );
		first.submit( 40 );
		await flush();
		first.dispose();

		// A reload while it runs: no banner on the new page knows about it.
		const reloaded = makeSession( { store } );
		reloaded.recover();
		await flush();
		expect( reloaded.getState() ).toMatchObject( {
			count: 2,
			submitted: { batchId: 40 },
		} );
		server.setBatch( 40, 'complete', COMPLETE );
		fireWatch();
		await flush();
		expect( reloaded.getState() ).toMatchObject( {
			count: 0,
			current: null,
			submitted: null,
			notice: { kind: 'done', batchId: 40, kept: 0 },
		} );
		expect( server.released( token ) ).toBe( true );
		// The banner of another page seeing it end too changes nothing.
		const before = server.calls().length;
		await expect(
			reloaded.finish( 40, { status: 'complete', ...COMPLETE } )
		).resolves.toBe( false );
		await flush();
		expect( server.calls().length ).toBe( before );
		expect( reloaded.getState().notice ).toMatchObject( {
			kind: 'done',
			batchId: 40,
		} );
	} );

	it( 'finishes a change while another panel is open, keeping what was selected since', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		server.setBatch( 41, 'queued', { total_items: 2 } );
		session.submit( 41 );
		await flush();
		// History is open: the catalogue's banner is not there to finish it.
		await session.add( [ 21 ] );
		server.setBatch( 41, 'complete', COMPLETE );
		fireWatch();
		await flush( 12 );
		expect( members( session ) ).toEqual( [ 21 ] );
		expect( session.getState().notice ).toMatchObject( {
			kind: 'done',
			batchId: 41,
			kept: 1,
		} );
		// Exactly once: one subtraction and one union, no second round.
		const composes = posted( 'POST requests' ).filter(
			( each ) =>
				each.operation === 'selection.compose' &&
				( each.intent.remove_token || each.intent.add_token )
		);
		expect( composes ).toHaveLength( 2 );
		await session.finish( 41, { status: 'complete', ...COMPLETE } );
		await flush();
		expect(
			posted( 'POST requests' ).filter(
				( each ) =>
					each.operation === 'selection.compose' &&
					( each.intent.remove_token || each.intent.add_token )
			)
		).toHaveLength( 2 );
	} );

	const watchTimers = () =>
		timers.filter(
			( each ) => ! each.cancelled && each.fn.name === 'poll'
		);
	const reads = ( id ) => server.calls( `GET batches/${ id }` ).length;

	it( 'stops reading once the change is final (T85)', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		server.setBatch( 43, 'running', { total_items: 2 } );
		session.submit( 43 );
		await flush();
		expect( watchTimers().map( ( each ) => each.ms ) ).toEqual( [
			WATCH_MS,
		] );
		server.setBatch( 43, 'complete', COMPLETE );
		fireWatch();
		await flush();
		expect( session.getState().notice ).toMatchObject( {
			kind: 'done',
			batchId: 43,
		} );
		expect( watchTimers() ).toEqual( [] );
		expect( reads( 43 ) ).toBe( 2 );
	} );

	it( 'backs off when the read fails, stops after a bounded number of tries with a plain sentence, and checks again when asked (T85)', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		server.setBatch( 44, 'running', { total_items: 2 } );
		server.fail(
			'GET batches/44',
			{ code: 'fetch_error', message: 'offline' },
			{ times: 50 }
		);
		session.submit( 44 );
		await flush();
		const waits = [];
		for ( let i = 0; i < 20 && watchTimers().length; i++ ) {
			waits.push( watchTimers()[ 0 ].ms );
			fireWatch();
			await flush();
		}
		// Ticket 11: what really happens — 10, 20, 40, 80 and 160 seconds,
		// about five minutes in all, then the sixth failed read stops it.
		expect( waits ).toEqual( [ 10000, 20000, 40000, 80000, 160000 ] );
		expect( waits ).toEqual(
			Array.from(
				{ length: WATCH_TRIES - 1 },
				( _, n ) => WATCH_BACKOFF_MS * 2 ** n
			)
		);
		expect( waits.reduce( ( sum, ms ) => sum + ms, 0 ) ).toBe( 310000 );
		expect( reads( 44 ) ).toBe( WATCH_TRIES );
		expect( watchTimers() ).toEqual( [] );
		expect( session.getState() ).toMatchObject( {
			count: 2,
			submitted: { batchId: 44 },
			unwatched: { batchId: 44 },
		} );
		// Check again: one more read, now answered, and it follows again.
		server.clearFailures( 'GET batches/44' );
		server.setBatch( 44, 'complete', COMPLETE );
		session.checkAgain();
		await flush();
		expect( session.getState() ).toMatchObject( {
			unwatched: null,
			count: 0,
			notice: { kind: 'done', batchId: 44 },
		} );
	} );

	it( 'starts one watch however fast Check again is pressed (ticket 11)', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		server.setBatch( 47, 'running', { total_items: 2 } );
		server.fail(
			'GET batches/47',
			{ code: 'fetch_error', message: 'offline' },
			{ times: 50 }
		);
		session.submit( 47 );
		await flush();
		for ( let i = 0; i < 20 && watchTimers().length; i++ ) {
			fireWatch();
			await flush();
		}
		expect( session.getState().unwatched ).toEqual( { batchId: 47 } );
		server.clearFailures( 'GET batches/47' );
		const before = reads( 47 );
		// Two clicks before the first read has answered.
		session.checkAgain();
		session.checkAgain();
		await flush();
		expect( reads( 47 ) - before ).toBe( 1 );
		expect( watchTimers() ).toHaveLength( 1 );
		fireWatch();
		await flush();
		expect( reads( 47 ) - before ).toBe( 2 );
		expect( watchTimers() ).toHaveLength( 1 );
	} );

	it( 'a reload re-arms a watch that is still bounded (T85)', async () => {
		const store = memoryStore();
		const first = makeSession( { store } );
		await first.add( [ 11 ] );
		server.setBatch( 45, 'running', { total_items: 1 } );
		first.submit( 45 );
		await flush();
		first.dispose();
		server.fail(
			'GET batches/45',
			{ code: 'fetch_error', message: 'offline' },
			{ times: 50 }
		);
		const before = reads( 45 );
		const reloaded = makeSession( { store } );
		reloaded.recover();
		await flush();
		for ( let i = 0; i < 20 && watchTimers().length; i++ ) {
			fireWatch();
			await flush();
		}
		expect( reads( 45 ) - before ).toBe( WATCH_TRIES );
		expect( watchTimers() ).toEqual( [] );
		expect( reloaded.getState().unwatched ).toEqual( { batchId: 45 } );
	} );

	it( 'counts a completion that was under way at the reload as seen, so it is made once (T85, pinned)', async () => {
		const store = memoryStore();
		const first = makeSession( { store } );
		await first.add( [ 11, 12 ] );
		server.setBatch( 46, 'running', { total_items: 2 } );
		first.submit( 46 );
		await flush();
		await first.add( [ 21 ] );
		// The completion's subtraction is made, its answer lost.
		server.fail(
			'POST selection/compose',
			{ code: 'fetch_error', message: 'offline' },
			{ after: true }
		);
		first.finish( 46, { status: 'complete', ...COMPLETE } );
		await flush( 12 );
		expect( first.getState().status ).toBe( 'uncertain' );
		first.dispose();

		server.setBatch( 46, 'complete', COMPLETE );
		const reloaded = makeSession( { store } );
		reloaded.recover();
		await flush();
		// The watch sees the change final; the completion is already under way.
		const again = reloaded.finish( 46, {
			status: 'complete',
			...COMPLETE,
		} );
		await expect( again ).resolves.toBe( false );
		reloaded.retry();
		await flush( 12 );
		expect( members( reloaded ) ).toEqual( [ 21 ] );
		const subtractions = posted( 'POST selection/compose' ).filter(
			( each ) => each.intent.remove_snapshot
		);
		expect( subtractions ).toHaveLength( 2 );
		expect( subtractions[ 1 ].uuid ).toBe( subtractions[ 0 ].uuid );
	} );

	it( 'keeps the selection when the change it followed ended in part', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		server.setBatch( 42, 'running', { total_items: 2 } );
		session.submit( 42 );
		await flush();
		server.setBatch( 42, 'partial', {
			total_items: 2,
			done_items: 2,
			failed_items: 1,
		} );
		fireWatch();
		await flush();
		expect( session.getState() ).toMatchObject( {
			count: 2,
			submitted: null,
			notice: { kind: 'kept', batchId: 42 },
		} );
	} );
} );

describe( 'selection session: redraws', () => {
	it( 'does not redraw when the grid probes its rows with nothing selected (the reduced-motion flake)', async () => {
		const session = makeSession();
		const listener = jest.fn();
		session.subscribe( listener );
		await session.probe( [ 11, 12 ] );
		await session.probe( [ 11, 12 ] );
		expect( listener ).not.toHaveBeenCalled();
		expect( server.calls( 'POST selection/inspect' ) ).toHaveLength( 0 );
	} );

	it( 'drops a probe answer that arrives after a later probe of other rows (FRONTEND-2)', async () => {
		const session = makeSession();
		await session.add( [ 11, 12, 21, 22 ] );
		server.hold( 'POST selection/inspect' );
		// Page 1, then page 2 before page 1 has answered.
		const first = session.probe( [ 11, 12 ] );
		const second = session.probe( [ 21, 22 ] );
		await flush();
		server.deliver( 'POST selection/inspect', ( data ) =>
			( data.probe_ids || [] ).includes( 21 )
		);
		await second;
		expect( session.isSelected( 21 ) ).toBe( true );
		expect( session.isSelected( 22 ) ).toBe( true );
		// The slow answer for page 1 must not replace what page 2 shows.
		server.release( 'POST selection/inspect' );
		await first;
		await flush();
		expect( session.isSelected( 21 ) ).toBe( true );
		expect( session.isSelected( 22 ) ).toBe( true );
	} );
} );

describe( 'selection session: Restore after Clear search (T85)', () => {
	it( 'restores the same snapshot while the server keeps it, never capturing again', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		const token = tokenOf( session );
		session.clear( 'search' );
		await flush();
		expect( session.getState() ).toMatchObject( {
			count: 0,
			notice: { kind: 'searchCleared', restorable: true },
		} );
		expect( server.released( token ) ).toBe( true );
		await expect( session.restore() ).resolves.toBe( true );
		expect( tokenOf( session ) ).toBe( token );
		expect( members( session ) ).toEqual( [ 11, 12 ] );
		expect( session.getState() ).toMatchObject( {
			count: 2,
			notice: { kind: 'restored' },
		} );
		// Kept again by a renewal of the released snapshot.
		expect( posted( 'POST selection/renew' ).at( -1 ) ).toEqual( {
			token,
		} );
		expect( server.released( token ) ).toBe( false );
		expect( posted( 'POST selection' ) ).toHaveLength( 1 );
	} );

	it( 'waits for the release Clear search sent before keeping the snapshot again (T85)', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		const token = tokenOf( session );
		server.hold( 'POST selection/release' );
		session.clear( 'search' );
		const restoring = session.restore();
		await flush();
		// The release has not answered yet: no renewal overtakes it.
		expect( posted( 'POST selection/renew' ) ).toEqual( [] );
		server.release( 'POST selection/release' );
		await restoring;
		await flush();
		expect( posted( 'POST selection/renew' ) ).toEqual( [ { token } ] );
		expect( tokenOf( session ) ).toBe( token );
		expect( server.released( token ) ).toBe( false );
	} );

	it( 'offers no Restore once something new is selected, and reports one past the hour as lost', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		session.clear( 'search' );
		await session.add( [ 21 ] );
		expect( session.getState().notice ).toEqual( {
			kind: 'searchCleared',
			restorable: false,
		} );
		await expect( session.restore() ).resolves.toBe( false );
		expect( members( session ) ).toEqual( [ 21 ] );

		const other = makeSession();
		await other.add( [ 11, 12 ] );
		other.clear( 'search' );
		await flush();
		// The server keeps a released snapshot an hour at most.
		server.advance( 61 * 60 * 1000 );
		await other.restore();
		expect( other.getState() ).toMatchObject( {
			current: null,
			count: 0,
			notice: null,
			lost: { count: 2 },
		} );
	} );
} );

describe( 'selection session: completion', () => {
	it( 'clears the selection only when the change finished in full', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		const token = tokenOf( session );
		session.submit( 7 );
		// Queued, running and a batch report that is not final change nothing.
		expect( session.getState().count ).toBe( 2 );
		await session.finish( 8, { status: 'complete' } );
		expect( session.getState().count ).toBe( 2 );
		await session.finish( 7, {
			status: 'complete',
			failed_items: 0,
			pending_items: 0,
		} );
		expect( session.getState() ).toMatchObject( {
			count: 0,
			current: null,
			submitted: null,
			notice: { kind: 'done', batchId: 7, kept: 0 },
		} );
		expect( server.released( token ) ).toBe( true );
	} );

	it.each( [
		[ 'partial', { status: 'partial', failed_items: 1 } ],
		[ 'failed', { status: 'failed', failed_items: 2 } ],
		[ 'cancelled', { status: 'cancelled' } ],
		[
			'complete with a failure',
			{ status: 'complete', failed_items: 1, pending_items: 0 },
		],
	] )( 'keeps the selection when the change ends %s', async ( _, report ) => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		const token = tokenOf( session );
		session.submit( 9 );
		await session.finish( 9, report );
		expect( session.getState() ).toMatchObject( {
			count: 2,
			submitted: null,
			notice: { kind: 'kept', batchId: 9 },
		} );
		expect( tokenOf( session ) ).toBe( token );
	} );

	it( 'keeps what was selected after applying: (current − submitted) ∪ additions', async () => {
		const session = makeSession();
		await session.add( [ 11, 12 ] );
		session.submit( 5 );
		// While the change runs: 21 is added, 12 is removed and added again.
		await session.add( [ 21 ] );
		await session.remove( [ 12 ] );
		await session.add( [ 12 ] );
		expect( members( session ) ).toEqual( [ 11, 12, 21 ] );
		await session.finish( 5, {
			status: 'complete',
			failed_items: 0,
			pending_items: 0,
		} );
		// 11 was applied and not touched since: gone. 21 and the re-added 12 stay.
		expect( members( session ) ).toEqual( [ 12, 21 ] );
		expect( session.getState().notice ).toMatchObject( {
			kind: 'done',
			kept: 2,
		} );
		const composes = posted( 'POST requests' )
			.filter( ( each ) => each.operation === 'selection.compose' )
			.map( ( each ) => Object.keys( each.intent ).sort().join( ',' ) );
		expect( composes.slice( -2 ) ).toEqual( [
			'remove_token,token',
			'add_token,token',
		] );
	} );

	it( 'explains, and keeps the newer selection, when the applied set is no longer kept', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		session.submit( 6 );
		await session.add( [ 21 ] );
		server.fail(
			'POST selection/compose',
			restError( 'bulkcat_selection_expired', 410, 'gone' )
		);
		await session.finish( 6, {
			status: 'complete',
			failed_items: 0,
			pending_items: 0,
		} );
		expect( session.getState() ).toMatchObject( {
			count: 2,
			submitted: null,
			notice: { kind: 'cleanup', batchId: 6 },
		} );
	} );

	it( 'never sends a 1.0 selection body', async () => {
		const session = makeSession();
		await session.add( [ 11 ] );
		await session.addMatching( { search: 'Shirt' } );
		await session.remove( [ 11 ] );
		posted( 'POST selection' ).forEach( ( body ) => {
			expect( body ).toEqual( {
				uuid: expect.any( String ),
				request_proof: expect.any( String ),
				intent: expect.objectContaining( { version: 1 } ),
			} );
		} );
	} );
} );
