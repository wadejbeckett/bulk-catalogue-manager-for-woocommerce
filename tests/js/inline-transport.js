/* global expect */
/**
 * Receipt transport for existing native-control journeys. Only wraps their
 * HTTP boundary: product state, draft handling and reconciliation stay real.
 * Refusals must explicitly carry their fence in each test; generic errors
 * remain unresolved. This is not a native server/concurrency fixture.
 *
 * @param {Function} apiFetch The suite's already-routed HTTP mock.
 */
export function withInlineReceipts( apiFetch ) {
	const send = apiFetch.getMockImplementation();
	const grants = new Map();
	apiFetch.mockImplementation( async ( options ) => {
		const path = options.url.split( '?' )[ 0 ];
		if (
			path === 'requests' &&
			options.data.operation === 'product.inline'
		) {
			const uuid = `00000000-0000-4000-8000-${ String( grants.size + 1 ).padStart( 12, '0' ) }`;
			const grant = {
				uuid,
				request_proof: 'opaque-proof',
				operation: 'product.inline',
				accept_until: 1800000000,
				intent: {
					version: 1,
					payload_version: 1,
					registry_revision: 'a'.repeat( 64 ),
					commitment: 'b'.repeat( 64 ),
					object_id: options.data.intent.object_id,
					source: 'inline',
				},
			};
			grants.set( uuid, grant );
			return grant;
		}
		if (
			path === 'requests/resolve' &&
			options.data.operation === 'product.inline'
		) {
			return { receipt_state: 'not_accepted', replayed: false };
		}
		if ( options.method === 'PATCH' ) {
			const grant = grants.get( options.data.uuid );
			expect( grant ).toBeTruthy();
			expect( options.data.intent ).toEqual( grant.intent );
			expect( options.data.request_proof ).toBe( grant.request_proof );
			expect( options.bulkcatNoRetry ).toBe( true );
			const answer = await send( options );
			return answer?.status
				? {
						...answer,
						receipt_state: 'closed',
						write_status: answer.status,
						steps: [],
						replayed: false,
					}
				: answer;
		}
		return send( options );
	} );
}
