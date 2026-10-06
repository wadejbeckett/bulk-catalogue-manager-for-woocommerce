/**
 * Keeping the wp_rest nonce fresh (SPEC §13, T39).
 *
 * The page gets a nonce at load. The Heartbeat API (core's `heartbeat`
 * script, a dependency of this screen) sends `bulkcat_nonce` on every tick and
 * src/Admin/Heartbeat.php answers with a fresh nonce, which is installed on
 * apiFetch's nonce middleware. If a request still fails with
 * `rest_cookie_invalid_nonce` (the nonce aged out or the user signed in
 * again), the retry middleware asks WordPress for a fresh nonce once, through
 * core's own nonce endpoint (`apiFetch.nonceEndpoint`, admin-ajax
 * `rest-nonce`, which needs only the login cookie), and sends the original
 * request once more with the same options, so a write keeps its `uuid`. A
 * second failure is reported in plain words and never retried.
 *
 * The refresh cannot be a bulkcat/v1 route: core refuses a REST request carrying
 * a stale nonce before any route runs, and treats one without a nonce as
 * signed out.
 */
import { __ } from '@wordpress/i18n';

/**
 * The Heartbeat data key sent on each tick and the key of the answer.
 */
export const HEARTBEAT_KEY = 'bulkcat_nonce';

/**
 * The REST error code core returns for a nonce it does not accept.
 */
export const INVALID_NONCE = 'rest_cookie_invalid_nonce';

/**
 * The code of the error reported once the retry has failed. Deliberately not
 * INVALID_NONCE, so apiFetch's own recovery never retries it again.
 */
export const EXPIRED_CODE = 'bulkcat_nonce_expired';

/**
 * Whether a value looks like a nonce WordPress made: a short run of letters
 * and digits, never the "0" or "-1" admin-ajax answers when signed out.
 *
 * @param {*} value Candidate.
 * @return {boolean} Whether to install it.
 */
export function isNonce( value ) {
	return (
		typeof value === 'string' &&
		/^[A-Za-z0-9]{6,64}$/.test( value ) &&
		! /^0+$/.test( value )
	);
}

/**
 * The error code of a failed apiFetch call: the parsed error's `code`, or,
 * for a request made with `parse: false`, the code in the response body.
 *
 * @param {*} error What apiFetch rejected with.
 * @return {Promise<string>} The code, or '' when there is none.
 */
async function codeOf( error ) {
	if ( ! error || typeof error !== 'object' ) {
		return '';
	}
	if ( typeof error.code === 'string' ) {
		return error.code;
	}
	if ( error.status === 403 && typeof error.clone === 'function' ) {
		try {
			const body = await error.clone().json();
			return body && typeof body.code === 'string' ? body.code : '';
		} catch {
			return '';
		}
	}
	return '';
}

/**
 * The error shown when the nonce could not be renewed (SPEC §4.8): what
 * happened and what to do in the message, the technical line in `details`.
 *
 * @param {*} cause The last failure.
 * @return {Object} An apiFetch-shaped error.
 */
export function expiredError( cause ) {
	return {
		code: EXPIRED_CODE,
		message: __(
			'Your sign-in check has expired and could not be renewed, so this request was not sent. Reload the page, then try again.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		data: { status: 403 },
		details:
			cause && typeof cause.message === 'string'
				? `${ INVALID_NONCE }: ${ cause.message }`
				: INVALID_NONCE,
	};
}

/**
 * Fetch a fresh nonce from core's nonce endpoint with the login cookie.
 *
 * @param {Function} fetchImpl A fetch implementation.
 * @param {string}   endpoint  The endpoint (apiFetch.nonceEndpoint).
 * @return {Promise<string>} The nonce; rejects when there is none.
 */
export async function fetchNonce( fetchImpl, endpoint ) {
	if ( ! endpoint || typeof fetchImpl !== 'function' ) {
		throw new Error( 'No nonce endpoint.' );
	}
	const response = await fetchImpl( endpoint, {
		credentials: 'same-origin',
	} );
	if ( ! response || ! response.ok ) {
		throw new Error( 'The nonce endpoint refused.' );
	}
	const text = String( await response.text() ).trim();
	if ( ! isNonce( text ) ) {
		throw new Error( 'The nonce endpoint gave no nonce.' );
	}
	return text;
}

/**
 * An apiFetch middleware that recovers from one invalid-nonce failure per
 * request: refresh once, retry once, then report. Concurrent failures share
 * one refresh.
 *
 * @param {Object}   options         Wiring.
 * @param {Function} options.send    ( requestOptions, next ) => Promise: the rest of this
 *                                   middleware's own chain (the nonce middleware, when it owns one).
 * @param {Function} options.refresh () => Promise<string>: a fresh nonce.
 * @param {Function} options.install ( nonce ) => void: put it on the nonce middleware.
 * @return {Function} The middleware.
 */
export function createRetryMiddleware( { send, refresh, install } ) {
	let pending = null;

	const renew = () => {
		if ( ! pending ) {
			pending = Promise.resolve()
				.then( refresh )
				.then( ( nonce ) => {
					if ( ! isNonce( nonce ) ) {
						throw new Error( 'No nonce.' );
					}
					install( nonce );
					return nonce;
				} )
				.finally( () => {
					pending = null;
				} );
		}
		return pending;
	};

	return async ( requestOptions, next ) => {
		// One retry for this request, whatever happens: the flag lives in this call alone.
		let retried = false;
		try {
			return await send( requestOptions, next );
		} catch ( error ) {
			if ( retried || ( await codeOf( error ) ) !== INVALID_NONCE ) {
				throw error;
			}
			// Inline receipts use bounded read-only recovery. Rename this error
			// before api-fetch's outer nonce handler can resend the PATCH too.
			if ( requestOptions.bulkcatNoRetry ) {
				throw expiredError( error );
			}
			retried = true;
			try {
				await renew();
			} catch {
				throw expiredError( error );
			}
			try {
				return await send( requestOptions, next );
			} catch ( second ) {
				if ( ( await codeOf( second ) ) === INVALID_NONCE ) {
					throw expiredError( second );
				}
				throw second;
			}
		}
	};
}

/**
 * Subscribe to the Heartbeat API: ask for a nonce on every tick and hand
 * each valid answer to `onNonce`. Heartbeat triggers its events through
 * jQuery on the document; without jQuery (no Heartbeat) this does nothing.
 *
 * @param {Window}   win     The window.
 * @param {Function} onNonce ( nonce ) => void.
 * @return {Function|null} Unsubscribe, or null when Heartbeat is not there.
 */
export function subscribeHeartbeat( win, onNonce ) {
	const jQuery = win && win.jQuery;
	if ( typeof jQuery !== 'function' ) {
		return null;
	}
	const doc = jQuery( win.document );
	const onSend = ( event, data ) => {
		if ( data && typeof data === 'object' ) {
			data[ HEARTBEAT_KEY ] = 1;
		}
	};
	const onTick = ( event, data ) => {
		const nonce = data && data[ HEARTBEAT_KEY ];
		if ( isNonce( nonce ) ) {
			onNonce( nonce );
		}
	};
	doc.on( 'heartbeat-send', onSend );
	doc.on( 'heartbeat-tick', onTick );
	return () => {
		doc.off( 'heartbeat-send', onSend );
		doc.off( 'heartbeat-tick', onTick );
	};
}
