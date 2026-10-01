/**
 * T85: a grant whose answer is unknown survives a reload of the tab. The
 * holder keeps its grant in the tab's copy before the request is sent and
 * clears it on a definite answer; a grant read back after a reload is
 * uncertain, so the same intent resends it and a different one resolves it
 * first; resolution on its own ends the grant only on a definite outcome.
 */
jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const {
	keepHeld,
	keptHolder,
	resolveHeld,
	sendWithGrant,
} = require( '../../assets/src/requests' );
const { tabScope, tabStore } = require( '../../assets/src/tab-store' );

const SETTINGS = {
	root: 'http://shop.test/wp-json/bulkcat/v1/',
	user: { id: 7 },
};
const KEY = tabScope( SETTINGS, 'apply' );

let seq = 0;
const issue = jest.fn( ( operation, intent ) => {
	seq += 1;
	return Promise.resolve( {
		uuid: `00000000-0000-4000-8000-00000000000${ seq }`,
		request_proof: `proof.${ seq }`,
		operation,
		intent,
	} );
} );
const lost = () =>
	Promise.reject( { code: 'fetch_error', message: 'Offline.' } );
const stored = () => JSON.parse( window.sessionStorage.getItem( KEY ) );

beforeEach( () => {
	seq = 0;
	issue.mockClear();
} );

describe( 'a grant kept in the tab', () => {
	it( 'is scoped to the site and the user, beside the selection', () => {
		expect( tabScope( SETTINGS ) ).toBe(
			'bulkcat-selection:http://shop.test/wp-json/bulkcat/v1/:7'
		);
		expect( KEY ).toBe(
			'bulkcat-selection:http://shop.test/wp-json/bulkcat/v1/:7:apply'
		);
	} );

	it( 'is written before the request is sent and cleared on a definite answer', async () => {
		const holder = keptHolder( tabStore( KEY ) );
		let during = null;
		const answer = await sendWithGrant( holder, {
			key: 'change-a',
			operation: 'batch.apply',
			intent: { ids: [ 1 ] },
			meta: { selectionKey: 'h1' },
			issue,
			send: ( grant ) => {
				during = stored();
				expect( during.grant ).toEqual( grant );
				return Promise.resolve( { batch_id: 5 } );
			},
		} );
		expect( answer.batch_id ).toBe( 5 );
		expect( during ).toMatchObject( {
			key: 'change-a',
			meta: { selectionKey: 'h1' },
		} );
		// Only the grant and what the caller asked to keep; no ids of a snapshot.
		expect( Object.keys( during ).sort() ).toEqual( [
			'grant',
			'key',
			'meta',
		] );
		expect( stored() ).toBeNull();
		expect( holder.current ).toBeNull();
	} );

	it( 'is kept after an unknown answer, and read back after a reload as uncertain', async () => {
		const first = keptHolder( tabStore( KEY ) );
		await expect(
			sendWithGrant( first, {
				key: 'change-a',
				operation: 'batch.apply',
				intent: { ids: [ 1 ] },
				issue,
				send: lost,
			} )
		).rejects.toMatchObject( { code: 'fetch_error' } );
		const sent = stored().grant;

		// A reload: a new page, the same tab.
		const again = keptHolder( tabStore( KEY ) );
		expect( again.current ).toMatchObject( {
			key: 'change-a',
			grant: sent,
			uncertain: true,
			restored: true,
		} );
		const send = jest.fn( () =>
			Promise.resolve( { batch_id: 9, replayed: true } )
		);
		const answer = await sendWithGrant( again, {
			key: 'change-a',
			operation: 'batch.apply',
			intent: { ids: [ 1 ] },
			issue,
			send,
		} );
		// The same grant, never a new one.
		expect( send ).toHaveBeenCalledWith( sent );
		expect( issue ).toHaveBeenCalledTimes( 1 );
		expect( answer ).toMatchObject( { batch_id: 9, replayed: true } );
		expect( stored() ).toBeNull();
	} );

	it( 'is resolved first when a different change follows a reload', async () => {
		const first = keptHolder( tabStore( KEY ) );
		await sendWithGrant( first, {
			key: 'change-a',
			operation: 'batch.apply',
			intent: { ids: [ 1 ] },
			issue,
			send: lost,
		} ).catch( () => {} );
		const again = keptHolder( tabStore( KEY ) );
		const resolve = jest.fn( () =>
			Promise.resolve( {
				batch_id: 4,
				status: 'complete',
				replayed: true,
			} )
		);
		const send = jest.fn();
		const answer = await sendWithGrant( again, {
			key: 'change-b',
			operation: 'batch.apply',
			intent: { ids: [ 2 ] },
			issue,
			resolve,
			send,
		} );
		expect( answer ).toMatchObject( { batch_id: 4, earlier: true } );
		expect( send ).not.toHaveBeenCalled();
		expect( issue ).toHaveBeenCalledTimes( 1 );
		expect( stored() ).toBeNull();
	} );

	it( 'is dropped on a refusal that ends it, and not kept by a holder without a store', async () => {
		const holder = keptHolder( tabStore( KEY ) );
		await sendWithGrant( holder, {
			key: 'change-a',
			operation: 'batch.apply',
			intent: { ids: [ 1 ] },
			issue,
			send: () =>
				Promise.reject( {
					code: 'bulkcat_request_conflict',
					data: { status: 409 },
				} ),
		} ).catch( () => {} );
		expect( stored() ).toBeNull();

		const plain = keptHolder( null );
		await sendWithGrant( plain, {
			key: 'change-a',
			operation: 'batch.apply',
			intent: { ids: [ 1 ] },
			issue,
			send: lost,
		} ).catch( () => {} );
		expect( plain.current.uncertain ).toBe( true );
		expect( stored() ).toBeNull();
		keepHeld( plain );
		expect( stored() ).toBeNull();
	} );
} );

describe( 'resolving a grant restored after a reload', () => {
	async function restored() {
		await sendWithGrant( keptHolder( tabStore( KEY ) ), {
			key: 'change-a',
			operation: 'batch.apply',
			intent: { ids: [ 1 ] },
			meta: { selectionKey: 'h1' },
			issue,
			send: lost,
		} ).catch( () => {} );
		return keptHolder( tabStore( KEY ) );
	}

	it( 'answers the change it had made and ends the grant', async () => {
		const holder = await restored();
		const grant = holder.current.grant;
		const resolve = jest.fn( () =>
			Promise.resolve( { batch_id: 3, status: 'queued', replayed: true } )
		);
		const outcome = await resolveHeld( holder, resolve );
		expect( resolve ).toHaveBeenCalledWith( grant );
		expect( outcome ).toMatchObject( { batch_id: 3 } );
		expect( holder.current ).toBeNull();
		expect( stored() ).toBeNull();
	} );

	it( 'keeps the grant for the same change when it was not accepted', async () => {
		const holder = await restored();
		const outcome = await resolveHeld( holder, () =>
			Promise.resolve( { status: 'not_accepted' } )
		);
		expect( outcome ).toBeNull();
		expect( holder.current ).toMatchObject( {
			key: 'change-a',
			uncertain: true,
			restored: false,
		} );
		expect( stored().key ).toBe( 'change-a' );
		// Resolved once per load.
		const resolve = jest.fn();
		expect( await resolveHeld( holder, resolve ) ).toBeNull();
		expect( resolve ).not.toHaveBeenCalled();
	} );

	it( 'keeps it when the answer is unknown again, and drops it when it can never be accepted', async () => {
		const holder = await restored();
		expect( await resolveHeld( holder, lost ) ).toBeNull();
		expect( stored() ).not.toBeNull();

		const other = await restored();
		expect(
			await resolveHeld( other, () =>
				Promise.reject( {
					code: 'bulkcat_request_expired',
					data: { status: 410 },
				} )
			)
		).toBeNull();
		expect( other.current ).toBeNull();
		expect( stored() ).toBeNull();
	} );
} );

describe( 'required tab storage beside permissive selection storage', () => {
	const { requiredTabStore } = require( '../../assets/src/tab-store' );
	it( 'does not treat inaccessible or corrupt storage as an empty receipt store', () => {
		expect( () => requiredTabStore( KEY, null ).read() ).toThrow();
		sessionStorage.setItem( KEY, '{bad' );
		expect( () => requiredTabStore( KEY ).read() ).toThrow();
		expect( tabStore( KEY ).read() ).toBeNull();
	} );
	it( 'verifies both persistence and removal instead of trusting silent storage failures', () => {
		const store = requiredTabStore( KEY );
		const write = jest
			.spyOn( Storage.prototype, 'setItem' )
			.mockImplementation( () => {} );
		expect( () => store.write( [ { object_id: 11 } ] ) ).toThrow();
		write.mockRestore();
		store.write( [ { object_id: 11 } ] );
		const clear = jest
			.spyOn( Storage.prototype, 'removeItem' )
			.mockImplementation( () => {} );
		expect( () => store.clear() ).toThrow();
		clear.mockRestore();
		store.clear();
		expect( store.read() ).toBeNull();
	} );
} );
