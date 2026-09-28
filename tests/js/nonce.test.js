/**
 * Nonce refresh through Heartbeat and one automatic retry (T39; SPEC §13,
 * §4.8). Runs the real @wordpress/api-fetch with a stubbed fetch, so the
 * counts below are every request that would leave the browser.
 */
const PAGE_NONCE = 'abc123def4';
const ENDPOINT =
	'https://example.test/wp-admin/admin-ajax.php?action=rest-nonce';
const ROOT = 'https://example.test/wp-json/bulkcat/v1/';

const INVALID = {
	code: 'rest_cookie_invalid_nonce',
	message: 'Cookie check failed',
	data: { status: 403 },
};

/**
 * A fetch Response stand-in carrying JSON.
 *
 * @param {number} status HTTP status.
 * @param {*}      body   Parsed body.
 * @return {Object} Response.
 */
function json( status, body ) {
	const response = {
		ok: status >= 200 && status < 300,
		status,
		headers: { get: () => null },
		json: async () => body,
		text: async () => JSON.stringify( body ),
		clone: () => response,
	};
	return response;
}

/**
 * A fetch Response stand-in carrying plain text, as admin-ajax answers.
 *
 * @param {string} body   Text.
 * @param {number} status HTTP status.
 * @return {Object} Response.
 */
function text( body, status = 200 ) {
	return { ok: status === 200, status, text: async () => body };
}

/**
 * A jQuery stand-in with just what Heartbeat's events need.
 *
 * @return {Object} { jQuery, trigger }.
 */
function fakeJQuery() {
	const handlers = {};
	const wrapped = {
		on( name, fn ) {
			( handlers[ name ] = handlers[ name ] || [] ).push( fn );
			return wrapped;
		},
		off( name, fn ) {
			handlers[ name ] = ( handlers[ name ] || [] ).filter(
				( h ) => h !== fn
			);
			return wrapped;
		},
	};
	return {
		jQuery: () => wrapped,
		trigger: ( name, data ) =>
			( handlers[ name ] || [] ).forEach( ( fn ) => fn( {}, data ) ),
		count: ( name ) => ( handlers[ name ] || [] ).length,
	};
}

/**
 * Let every pending promise settle.
 *
 * @return {Promise<void>} Settled.
 */
const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'nonce refresh and retry', () => {
	let apiFetch;
	let api;
	let fetch;
	let heartbeat;
	let answers;

	/**
	 * Load fresh modules and wire the screen's API with a queue of answers.
	 *
	 * @param {Object}   options           Options.
	 * @param {Function} options.beforeApi Runs with apiFetch before configureApi.
	 */
	function setUp( { beforeApi } = {} ) {
		jest.resetModules();
		apiFetch = require( '@wordpress/api-fetch' ).default;
		apiFetch.nonceEndpoint = ENDPOINT;
		if ( beforeApi ) {
			beforeApi( apiFetch );
		}
		api = require( '../../assets/src/api.js' );
		fetch = jest.fn( async ( url ) => {
			const next = answers.shift();
			if ( ! next ) {
				throw new Error( `Unexpected request to ${ url }` );
			}
			return next;
		} );
		global.fetch = fetch;
		heartbeat = fakeJQuery();
		return api.configureApi(
			{ root: ROOT, nonce: PAGE_NONCE },
			{ fetch, jQuery: heartbeat.jQuery, document }
		);
	}

	/**
	 * The nonce header a recorded request carried.
	 *
	 * @param {number} index Call index.
	 * @return {string|undefined} Nonce.
	 */
	const nonceOf = ( index ) =>
		fetch.mock.calls[ index ][ 1 ].headers[ 'X-WP-Nonce' ];

	afterEach( () => {
		delete global.fetch;
	} );

	it( 'refreshes once, retries once with the same body and succeeds', async () => {
		answers = [
			json( 403, INVALID ),
			text( 'fresh12345' ),
			json( 200, { batch_id: 9 } ),
		];
		setUp();

		// A durable Apply (T84): the retry keeps the same grant.
		const body = {
			uuid: '0f8e1c2a-3b4d-4e5f-8a9b-0c1d2e3f4a5b',
			request_proof: 'proof',
			intent: { version: 1 },
			preview_hash: 'a'.repeat( 64 ),
		};
		await expect(
			api.request( 'batches', { method: 'POST', data: body } )
		).resolves.toEqual( { batch_id: 9 } );

		expect( fetch ).toHaveBeenCalledTimes( 3 );
		const [ first, refresh, retry ] = fetch.mock.calls;
		expect( first[ 0 ] ).toContain( ROOT + 'batches' );
		expect( nonceOf( 0 ) ).toBe( PAGE_NONCE );
		expect( refresh[ 0 ] ).toBe( ENDPOINT );
		expect( refresh[ 1 ] ).toEqual( { credentials: 'same-origin' } );
		expect( retry[ 0 ] ).toBe( first[ 0 ] );
		expect( nonceOf( 2 ) ).toBe( 'fresh12345' );
		expect( retry[ 1 ].body ).toBe( first[ 1 ].body );
		expect( JSON.parse( retry[ 1 ].body ).uuid ).toBe( body.uuid );
		expect( JSON.parse( retry[ 1 ].body ).request_proof ).toBe( 'proof' );
		expect( apiFetch.nonceMiddleware.nonce ).toBe( 'fresh12345' );
	} );

	it( 'reports a second failure in plain words and never tries a third time', async () => {
		answers = [
			json( 403, INVALID ),
			text( 'fresh12345' ),
			json( 403, INVALID ),
		];
		setUp();

		const error = await api.request( 'products' ).catch( ( e ) => e );
		await flush();

		expect( fetch ).toHaveBeenCalledTimes( 3 );
		expect( fetch.mock.calls[ 1 ][ 0 ] ).toBe( ENDPOINT );
		expect( error.code ).toBe( 'bulkcat_nonce_expired' );
		expect( error.message ).toBe(
			'Your sign-in check has expired and could not be renewed, so this request was not sent. Reload the page, then try again.'
		);
		expect( error.message ).not.toMatch( /rest_|bulkcat_|nonce/ );
		expect( error.details ).toContain( 'rest_cookie_invalid_nonce' );
		expect( answers ).toEqual( [] );
	} );

	it( 'does not retry when the refresh itself fails', async () => {
		for ( const refresh of [
			text( '0', 400 ),
			text( '-1' ),
			text( '<html>' ),
		] ) {
			answers = [ json( 403, INVALID ), refresh ];
			setUp();
			const error = await api.request( 'products' ).catch( ( e ) => e );
			await flush();
			expect( fetch ).toHaveBeenCalledTimes( 2 );
			expect( error.code ).toBe( 'bulkcat_nonce_expired' );
		}
	} );

	it( 'leaves every other failure alone', async () => {
		const forbidden = {
			code: 'bulkcat_forbidden',
			message: 'No.',
			data: { status: 403 },
		};
		answers = [ json( 403, forbidden ) ];
		setUp();
		await expect( api.request( 'products' ) ).rejects.toEqual( forbidden );
		expect( fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'recovers a request made without parsing', async () => {
		answers = [
			json( 403, INVALID ),
			text( 'fresh12345' ),
			json( 200, 'csv' ),
		];
		setUp();
		const response = await api.request( 'export', { parse: false } );
		expect( response.status ).toBe( 200 );
		expect( fetch ).toHaveBeenCalledTimes( 3 );
		expect( nonceOf( 2 ) ).toBe( 'fresh12345' );
	} );

	it( 'shares one refresh between requests failing together', async () => {
		answers = [
			json( 403, INVALID ),
			json( 403, INVALID ),
			text( 'fresh12345' ),
			json( 200, { a: 1 } ),
			json( 200, { b: 2 } ),
		];
		setUp();
		const results = await Promise.all( [
			api.request( 'a' ),
			api.request( 'b' ),
		] );
		expect( results ).toEqual( [ { a: 1 }, { b: 2 } ] );
		expect(
			fetch.mock.calls.filter( ( [ url ] ) => url === ENDPOINT )
		).toHaveLength( 1 );
		expect( fetch ).toHaveBeenCalledTimes( 5 );
	} );

	it( 'asks for a nonce on each Heartbeat tick and sends the one it gets', async () => {
		answers = [ json( 200, {} ), json( 200, {} ) ];
		const middleware = setUp();

		const data = {};
		heartbeat.trigger( 'heartbeat-send', data );
		expect( data ).toEqual( { bulkcat_nonce: 1 } );

		await api.request( 'fields' );
		expect( nonceOf( 0 ) ).toBe( PAGE_NONCE );

		heartbeat.trigger( 'heartbeat-tick', { bulkcat_nonce: 'beat000001' } );
		expect( middleware.nonce ).toBe( 'beat000001' );
		heartbeat.trigger( 'heartbeat-tick', { bulkcat_nonce: '0' } );
		heartbeat.trigger( 'heartbeat-tick', { other: 'x' } );
		heartbeat.trigger( 'heartbeat-tick', null );
		expect( middleware.nonce ).toBe( 'beat000001' );

		await api.request( 'fields' );
		expect( nonceOf( 1 ) ).toBe( 'beat000001' );
		expect( fetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( "reuses the host page's nonce middleware so a new nonce is really sent", async () => {
		answers = [ json( 200, {} ) ];
		let host;
		const middleware = setUp( {
			beforeApi: ( fetcher ) => {
				host = fetcher.createNonceMiddleware( PAGE_NONCE );
				fetcher.nonceMiddleware = host;
				fetcher.use( host );
			},
		} );
		expect( middleware ).toBe( host );

		heartbeat.trigger( 'heartbeat-tick', { bulkcat_nonce: 'beat000002' } );
		await api.request( 'fields' );
		expect( nonceOf( 0 ) ).toBe( 'beat000002' );
	} );

	it( 'sets nothing up without a nonce', () => {
		jest.resetModules();
		const fresh = require( '../../assets/src/api.js' );
		const { jQuery, count } = fakeJQuery();
		expect(
			fresh.configureApi(
				{ root: ROOT, nonce: '' },
				{ jQuery, document }
			)
		).toBeNull();
		expect( count( 'heartbeat-tick' ) ).toBe( 0 );
	} );
} );
