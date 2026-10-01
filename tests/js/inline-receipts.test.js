/** Real safe holder over the HTTP/storage boundaries; no replaying transport mock. */
import { createInlineReceipts } from '../../assets/src/panels/catalogue/edit/receipts';
import { tabScope } from '../../assets/src/tab-store';
import { configureApi } from '../../assets/src/api';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	return { __esModule: true, default: apiFetch };
} );
const apiFetch = require( '@wordpress/api-fetch' ).default;
const SETTINGS = { root: '', user: { id: 7 } };
const key = tabScope( SETTINGS, 'inline-writes' );
const grant = ( id = 11 ) => ( {
	uuid: '00000000-0000-4000-8000-000000000001',
	request_proof: 'opaque-proof',
	accept_until: 1800000000,
	operation: 'product.inline',
	intent: {
		version: 1,
		payload_version: 1,
		registry_revision: 'a'.repeat( 64 ),
		object_id: id,
		source: 'inline',
		commitment: 'b'.repeat( 64 ),
	},
} );
const closed = ( status = 'complete' ) => ( {
	receipt_state: 'closed',
	write_status: status,
	batch_id: 81,
	steps: [
		{
			field: 'sku',
			state: status === 'complete' ? 'changed' : 'failed',
			code: '',
		},
	],
	replayed: true,
} );
const calls = ( path ) =>
	apiFetch.mock.calls.filter(
		( [ call ] ) => call.url.split( '?' )[ 0 ] === path
	);
const patchCalls = () =>
	apiFetch.mock.calls.filter( ( [ call ] ) => call.method === 'PATCH' );
const issueCalls = () => calls( 'requests' );
let patch, resolve, issue;
const seed = ( held = grant() ) =>
	sessionStorage.setItem(
		key,
		JSON.stringify( [ { object_id: 11, grant: held } ] )
	);
const tick = async () => {
	await jest.advanceTimersByTimeAsync( 1000 );
};
beforeEach( () => {
	jest.useFakeTimers();
	configureApi( SETTINGS );
	patch = jest.fn( () => Promise.reject( new TypeError( 'lost' ) ) );
	resolve = jest.fn( () => Promise.resolve( closed() ) );
	issue = jest.fn( () => Promise.resolve( grant() ) );
	apiFetch.mockReset().mockImplementation( ( call ) => {
		if ( call.url === 'requests' ) {
			return issue( call.data );
		}
		if ( call.url === 'requests/resolve' ) {
			return resolve( call.data );
		}
		if ( call.method === 'PATCH' ) {
			return patch( call );
		}
		throw new Error( 'Unexpected request' );
	} );
} );
afterEach( () => {
	jest.restoreAllMocks();
	jest.useRealTimers();
} );

it( 'stores only safe identity before PATCH, then adopts a lost completed response without another write', async () => {
	// Registry-controlled values include protected/content fields; no production field list.
	const registry = [ { key: 'private_note' }, { key: 'description' } ];
	const values = {
		[ registry[ 0 ].key ]: 'secret-sentinel',
		[ registry[ 1 ].key ]: 'content-sentinel',
	};
	patch.mockImplementation( ( call ) => {
		expect( JSON.parse( sessionStorage.getItem( key ) ) ).toEqual( [
			{ object_id: 11, grant: grant() },
		] );
		expect( sessionStorage.getItem( key ) ).not.toContain(
			'secret-sentinel'
		);
		expect( sessionStorage.getItem( key ) ).not.toContain(
			'content-sentinel'
		);
		expect( call.data ).toMatchObject( {
			...values,
			uuid: grant().uuid,
			intent: grant().intent,
		} );
		return Promise.reject( new TypeError( 'lost after completion' ) );
	} );
	const receipt = await createInlineReceipts( SETTINGS ).save(
		11,
		values,
		registry.map( ( f ) => f.key )
	);
	expect( receipt ).toMatchObject( { ...closed(), held: false } );
	expect( receipt.item ).toBeUndefined();
	expect( receipt.results ).toBeUndefined();
	expect( sessionStorage.getItem( key ) ).toBeNull();
	expect( patchCalls() ).toHaveLength( 1 );
	expect( issueCalls() ).toHaveLength( 1 );
} );

it( 'reserves before issuance, deduplicates identical intent and blocks new text while held', async () => {
	let release;
	issue.mockReturnValue(
		new Promise( ( yes ) => {
			release = yes;
		} )
	);
	resolve.mockResolvedValue( {
		receipt_state: 'unclosed',
		batch_id: 81,
		replayed: true,
	} );
	const holder = createInlineReceipts( SETTINGS );
	const first = holder.save( 11, { sku: 'one' } );
	expect( holder.save( 11, { sku: 'one' } ) ).toBe( first );
	await expect( holder.save( 11, { sku: 'two' } ) ).rejects.toBeTruthy();
	release( grant() );
	await tick();
	expect( await first ).toMatchObject( {
		receipt_state: 'unclosed',
		held: true,
	} );
	await expect( holder.save( 11, { sku: 'two' } ) ).rejects.toBeTruthy();
	expect( patchCalls() ).toHaveLength( 1 );
	expect( issueCalls() ).toHaveLength( 1 );
	expect( calls( 'requests/resolve' ) ).toHaveLength( 2 );
	await tick();
	expect( calls( 'requests/resolve' ) ).toHaveLength( 2 );
} );

it( 'locks synchronously on reload and performs one read even when restore is requested twice', async () => {
	seed();
	resolve.mockResolvedValue( {
		receipt_state: 'not_accepted',
		replayed: false,
	} );
	const holder = createInlineReceipts( SETTINGS );
	await expect( holder.save( 11, { sku: 'new' } ) ).rejects.toBeTruthy();
	await Promise.all( [ holder.restore(), holder.restore() ] );
	await tick();
	expect( calls( 'requests/resolve' ) ).toHaveLength( 1 );
	expect( issueCalls() ).toHaveLength( 0 );
	expect( patchCalls() ).toHaveLength( 0 );
	expect( sessionStorage.getItem( key ) ).not.toBeNull();
} );

it( 'a cloned tab resolves the same grant while the original request completes, without issuing or writing', async () => {
	let finish;
	patch.mockReturnValue(
		new Promise( ( yes ) => {
			finish = yes;
		} )
	);
	const original = createInlineReceipts( SETTINGS );
	const pending = original.save( 11, { sku: 'one' } );
	await jest.advanceTimersByTimeAsync( 0 );
	// sessionStorage is copied at tab cloning; the peer starts from that exact copy.
	const copy = new Map( [ [ key, sessionStorage.getItem( key ) ] ] );
	const peerStorage = {
		getItem: ( k ) => copy.get( k ) ?? null,
		setItem: ( k, v ) => copy.set( k, v ),
		removeItem: ( k ) => copy.delete( k ),
	};
	const tab = jest
		.spyOn( window, 'sessionStorage', 'get' )
		.mockReturnValue( peerStorage );
	const peer = createInlineReceipts( SETTINGS );
	tab.mockRestore();
	resolve.mockResolvedValueOnce( {
		receipt_state: 'unclosed',
		batch_id: 81,
		replayed: true,
	} );
	expect( ( await peer.restore() )[ 0 ].held ).toBe( true );
	finish( closed() );
	await pending;
	expect( peerStorage.getItem( key ) ).not.toBeNull();
	expect( await peer.resolve( 11 ) ).toMatchObject( closed() );
	expect( peerStorage.getItem( key ) ).toBeNull();
	expect(
		calls( 'requests/resolve' ).every(
			( [ c ] ) => c.data.uuid === grant().uuid
		)
	).toBe( true );
	expect( issueCalls() ).toHaveLength( 1 );
	expect( patchCalls() ).toHaveLength( 1 );
} );

it.each( [ 'partial', 'failed' ] )(
	'adopts closed %s without fabricating an item or results',
	async ( status ) => {
		resolve.mockResolvedValue( closed( status ) );
		const result = await createInlineReceipts( SETTINGS ).save( 11, {
			sku: 'one',
		} );
		expect( result ).toMatchObject( { write_status: status, held: false } );
		expect( result.item ).toBeUndefined();
		expect( result.results ).toBeUndefined();
	}
);

it( 'only a committed refusal permits corrected input after a pre-effect failure', async () => {
	patch.mockRejectedValue( {
		code: 'bulkcat_invalid_value',
		data: { status: 400, receipt_state: 'refused', replayed: false },
	} );
	const holder = createInlineReceipts( SETTINGS );
	expect( await holder.save( 11, { sku: 'bad' } ) ).toMatchObject( {
		receipt_state: 'refused',
		held: false,
	} );
	await holder.save( 11, { sku: 'corrected' } );
	expect( issueCalls() ).toHaveLength( 2 );
	expect( calls( 'requests/resolve' ) ).toHaveLength( 0 );
} );

it.each( [
	{ receipt_state: 'not_accepted', replayed: false },
	{},
	{ receipt_state: 'closed', write_status: 'complete', batch_id: 81 },
	{
		receipt_state: 'closed',
		write_status: 'partial',
		batch_id: 81,
		steps: [ { field: 'sku', state: 'invented', code: '' } ],
		replayed: true,
	},
	{ receipt_state: 'refused' },
	{ code: 'bulkcat_forbidden', data: { status: 403 } },
	{ code: 'bulkcat_request_expired', data: { status: 410 } },
	{ code: 'bulkcat_request_gone', data: { status: 410 } },
	{ code: 'bulkcat_request_conflict', data: { status: 409 } },
	{ code: 'bulkcat_inline_version', data: { status: 409 } },
] )(
	'retains identity for non-terminal or malformed observation %j',
	async ( observation ) => {
		seed();
		resolve.mockImplementation( () =>
			observation.code
				? Promise.reject( observation )
				: Promise.resolve( observation )
		);
		const holder = createInlineReceipts( SETTINGS );
		const [ result ] = await holder.restore();
		expect( result.held ).toBe( true );
		await expect(
			holder.save( 11, { sku: 'new intent' } )
		).rejects.toBeTruthy();
		expect(
			JSON.parse( sessionStorage.getItem( key ) )[ 0 ].grant
		).toEqual( grant() );
		expect( issueCalls() ).toHaveLength( 0 );
		expect( patchCalls() ).toHaveLength( 0 );
	}
);

it( 'an invisible acceptance across expiry remains held and later adopts its real closure', async () => {
	resolve
		.mockResolvedValueOnce( {
			receipt_state: 'not_accepted',
			replayed: false,
		} )
		.mockRejectedValueOnce( {
			code: 'bulkcat_request_expired',
			data: { status: 410 },
		} )
		.mockResolvedValue( closed() );
	const holder = createInlineReceipts( SETTINGS );
	const pending = holder.save( 11, { sku: 'one' } );
	await tick();
	expect( ( await pending ).held ).toBe( true );
	await expect( holder.save( 11, { sku: 'two' } ) ).rejects.toBeTruthy();
	expect( await holder.resolve( 11 ) ).toMatchObject( {
		...closed(),
		held: false,
	} );
	expect( patchCalls() ).toHaveLength( 1 );
	expect( issueCalls() ).toHaveLength( 1 );
} );

it.each( [
	new TypeError( 'offline' ),
	{ code: 'fetch_error' },
	{ code: 'invalid_json' },
	{ code: 'internal_server_error', data: { status: 500 } },
] )(
	'issuance failure %j sends no PATCH and permits a later deliberate save',
	async ( error ) => {
		issue.mockRejectedValueOnce( error );
		const holder = createInlineReceipts( SETTINGS );
		await expect( holder.save( 11, { sku: 'one' } ) ).rejects.toMatchObject(
			{
				code: 'bulkcat_inline_grant',
			}
		);
		expect( patchCalls() ).toHaveLength( 0 );
		await holder.save( 11, { sku: 'two' } );
		expect( patchCalls() ).toHaveLength( 1 );
	}
);

it.each( [ 'blocked', 'quota', 'silent', 'corrupt', 'shape' ] )(
	'fails closed before PATCH when storage is %s',
	async ( failure ) => {
		if ( failure === 'corrupt' ) {
			sessionStorage.setItem( key, '{bad' );
		}
		if ( failure === 'shape' ) {
			sessionStorage.setItem(
				key,
				JSON.stringify( [
					{
						object_id: 11,
						grant: {
							...grant(),
							intent: { values: 'secret-sentinel' },
						},
					},
				] )
			);
		}
		if ( failure === 'blocked' ) {
			jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation(
				() => {
					throw new Error( 'blocked' );
				}
			);
		}
		if ( failure === 'quota' ) {
			jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation(
				() => {
					throw new Error( 'quota' );
				}
			);
		}
		if ( failure === 'silent' ) {
			jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation(
				() => {}
			);
		}
		const holder = createInlineReceipts( SETTINGS );
		await expect( holder.save( 11, { sku: 'one' } ) ).rejects.toBeTruthy();
		expect( patchCalls() ).toHaveLength( 0 );
	}
);

it( 'keeps a known closed outcome and blocks another write until removal verifies', async () => {
	seed();
	const removal = jest
		.spyOn( Storage.prototype, 'removeItem' )
		.mockImplementation( () => {} );
	const holder = createInlineReceipts( SETTINGS );
	expect( ( await holder.restore() )[ 0 ] ).toMatchObject( {
		...closed(),
		held: true,
		clearFailed: true,
	} );
	await expect( holder.save( 11, { sku: 'two' } ) ).rejects.toBeTruthy();
	resolve.mockRejectedValue( {
		code: 'bulkcat_forbidden',
		data: { status: 403 },
	} );
	expect( await holder.resolve( 11 ) ).toMatchObject( {
		...closed(),
		held: true,
	} );
	removal.mockRestore();
	expect( await holder.resolve( 11 ) ).toMatchObject( {
		...closed(),
		held: false,
	} );
	expect( sessionStorage.getItem( key ) ).toBeNull();
} );

it( 'stale owners merge another object on persist and preserve it when clearing their own identity', async () => {
	resolve.mockResolvedValue( {
		receipt_state: 'unclosed',
		batch_id: 81,
		replayed: true,
	} );
	issue.mockImplementation( ( data ) =>
		Promise.resolve( {
			...grant( data.intent.object_id ),
			uuid:
				data.intent.object_id === 11
					? grant().uuid
					: '00000000-0000-4000-8000-000000000002',
		} )
	);
	const one = createInlineReceipts( SETTINGS );
	const two = createInlineReceipts( SETTINGS );
	const first = one.save( 11, { sku: 'first' } );
	await tick();
	await first;
	const second = two.save( 12, { sku: 'second' } );
	await tick();
	await second;
	expect(
		JSON.parse( sessionStorage.getItem( key ) )
			.map( ( r ) => r.object_id )
			.sort()
	).toEqual( [ 11, 12 ] );
	resolve.mockResolvedValue( closed() );
	await one.resolve( 11 );
	expect( JSON.parse( sessionStorage.getItem( key ) ) ).toEqual( [
		{
			object_id: 12,
			grant: {
				...grant( 12 ),
				uuid: '00000000-0000-4000-8000-000000000002',
			},
		},
	] );
	await expect(
		createInlineReceipts( SETTINGS ).save( 12, { sku: 'another' } )
	).rejects.toBeTruthy();
	await two.resolve( 12 );
	expect( sessionStorage.getItem( key ) ).toBeNull();
	expect( patchCalls() ).toHaveLength( 2 );
} );

it( 'a fresh holder resolves an unsupported signed version without asking for values or another grant', async () => {
	seed( {
		...grant(),
		intent: { ...grant().intent, payload_version: 90, version: 8 },
	} );
	expect(
		( await createInlineReceipts( SETTINGS ).restore() )[ 0 ]
	).toMatchObject( closed() );
	expect(
		calls( 'requests/resolve' )[ 0 ][ 0 ].data.intent.payload_version
	).toBe( 90 );
	expect( patchCalls() ).toHaveLength( 0 );
	expect( issueCalls() ).toHaveLength( 0 );
} );

it( 'makes the first recovery read immediately and only one more after a full second', async () => {
	resolve.mockResolvedValue( {
		receipt_state: 'unclosed',
		batch_id: 81,
		replayed: true,
	} );
	const pending = createInlineReceipts( SETTINGS ).save( 11, { sku: 'one' } );
	await jest.advanceTimersByTimeAsync( 0 );
	expect( calls( 'requests/resolve' ) ).toHaveLength( 1 );
	await jest.advanceTimersByTimeAsync( 999 );
	expect( calls( 'requests/resolve' ) ).toHaveLength( 1 );
	await jest.advanceTimersByTimeAsync( 1 );
	await pending;
	await jest.advanceTimersByTimeAsync( 5000 );
	expect( calls( 'requests/resolve' ) ).toHaveLength( 2 );
	expect( patchCalls() ).toHaveLength( 1 );
} );

it.each( [ 'uuid', 'registry_revision', 'commitment' ] )(
	'rejects a malformed grant whose %s is an array before storing or sending it',
	async ( field ) => {
		const malformed = grant();
		if ( field === 'uuid' ) {
			malformed.uuid = [ malformed.uuid ];
		} else {
			malformed.intent[ field ] = [ malformed.intent[ field ] ];
		}
		issue.mockResolvedValue( malformed );
		await expect(
			createInlineReceipts( SETTINGS ).save( 11, { sku: 'one' } )
		).rejects.toBeTruthy();
		expect( sessionStorage.getItem( key ) ).toBeNull();
		expect( patchCalls() ).toHaveLength( 0 );
	}
);
