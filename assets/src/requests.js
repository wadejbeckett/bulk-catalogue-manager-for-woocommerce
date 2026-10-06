/**
 * Durable requests (T83, T84; docs/EXACT-SELECTION.md "Request identities
 * and durable replay"). Every capture, composition, Apply and undo the screen
 * sends carries a server-issued grant: `POST /requests` answers a fresh
 * `uuid`, its signed `request_proof` and the canonical `intent`, without
 * acting on it. The screen keeps that grant with its intent until the request
 * has a definite answer, and a retry sends the very same grant again, so the
 * server can answer what it already did (`replayed: true`) instead of doing it
 * twice. A new grant is only ever asked for new intent, never to retry an
 * uncertain one.
 */
import { request } from './api';

/**
 * Refusals after which a grant can never be accepted: drop it, so the next
 * deliberate attempt asks for a new one.
 */
export const DEAD = [
	'bulkcat_request_expired',
	'bulkcat_request_conflict',
	'bulkcat_request_gone',
	'bulkcat_invalid_request',
	'bulkcat_legacy_request',
	'bulkcat_invalid_value',
	'bulkcat_invalid_operation',
	'bulkcat_invalid_selection',
	'bulkcat_selection_expired',
	'bulkcat_selection_too_large',
];

/**
 * Ask for a grant. Losing this answer cannot have changed anything.
 *
 * @param {string} operation `selection.capture`, `selection.compose`, `batch.apply` or `batch.undo`.
 * @param {Object} intent    The intended payload, as the route documents it.
 * @return {Promise<Object>} `{ uuid, request_proof, accept_until, operation, intent }`.
 */
export function issueGrant( operation, intent ) {
	return request( 'requests', {
		method: 'POST',
		data: { operation, intent },
	} );
}

/**
 * What became of a grant: its original outcome, a definite refusal, or
 * `{ status: 'not_accepted' }`. Never creates anything.
 *
 * @param {Object} grant A grant from issueGrant().
 * @return {Promise<Object>} The outcome.
 */
export function resolveGrant( grant ) {
	return request( 'requests/resolve', {
		method: 'POST',
		...( grant.operation === 'product.inline'
			? { bulkcatNoRetry: true }
			: {} ),
		data: {
			uuid: grant.uuid,
			request_proof: grant.request_proof,
			intent: grant.intent,
			operation: grant.operation,
		},
	} );
}

/**
 * The durable envelope a route takes: the grant's identity, proof and
 * canonical intent, exactly as issued.
 *
 * @param {Object} grant A grant.
 * @return {Object} `{ uuid, request_proof, intent }`.
 */
export function envelope( grant ) {
	return {
		uuid: grant.uuid,
		request_proof: grant.request_proof,
		intent: grant.intent,
	};
}

/**
 * Whether a failed request may still have been accepted: no answer at all
 * (offline, a dropped connection, a page that is not JSON), a server error,
 * or the plugin's own "retry the same request" storage refusal. Only the same
 * grant may follow such a failure.
 *
 * @param {*} error What the request rejected with.
 * @return {boolean} True when the outcome is unknown.
 */
export function isUncertain( error ) {
	const code = typeof error?.code === 'string' ? error.code : '';
	const status = Number( error?.data?.status ) || 0;
	if ( code === 'bulkcat_request_storage' || status >= 500 ) {
		return true;
	}
	return ! code || code === 'fetch_error' || code === 'invalid_json';
}

/**
 * Whether a refusal ends a grant for good.
 *
 * @param {*} error What the request rejected with.
 * @return {boolean} True when the grant cannot be accepted any more.
 */
export function isDead( error ) {
	return DEAD.includes( error?.code );
}

/**
 * A holder whose grant outlives a reload of the tab (T85). `store` is a
 * tabStore(): while a grant is held — sent and not yet answered, or answered
 * with an unknown outcome — the tab keeps its identity, proof, intent and the
 * caller's `meta`, and nothing else. A grant read back after a reload is
 * uncertain by definition (the page went away before a definite answer), so
 * the same intent sends it again and a different intent resolves it first,
 * exactly as before the reload; the server's receipt then answers the change
 * it already made instead of making it twice.
 *
 * @param {Object|null} store tabStore(), or null to keep nothing.
 * @return {Object} `{ current, store }`, for sendWithGrant().
 */
export function keptHolder( store ) {
	const holder = { current: null, store: store || null };
	const saved = holder.store?.read();
	if (
		saved &&
		typeof saved.key === 'string' &&
		saved.grant?.uuid &&
		saved.grant?.request_proof
	) {
		holder.current = {
			key: saved.key,
			grant: saved.grant,
			meta: saved.meta || null,
			uncertain: true,
			restored: true,
		};
	}
	return holder;
}

/**
 * Write what a holder holds to its tab store, or clear it when it holds
 * nothing. A holder without a store keeps nothing.
 *
 * @param {Object} holder `{ current, store }`.
 */
export function keepHeld( holder ) {
	if ( ! holder?.store ) {
		return;
	}
	const held = holder.current;
	if ( ! held ) {
		holder.store.clear();
		return;
	}
	holder.store.write( {
		key: held.key,
		grant: held.grant,
		meta: held.meta || null,
	} );
}

/**
 * Send one durable request, keeping its grant until the answer is definite.
 *
 * `holder.current` is `{ key, grant, uncertain, meta }` between attempts. The
 * same `key` (the same intent) always sends the grant it already holds. A new
 * key is new intent: a held grant whose last attempt was uncertain is
 * resolved first, and when it turns out to have been accepted its outcome is
 * answered (`earlier: true`) and nothing new is sent; otherwise a new grant is
 * issued. A holder with a tab store (keptHolder()) writes the grant there
 * before it is sent and clears it on a definite answer, so a reload in
 * between still has it.
 *
 * @param {Object}   holder            `{ current, store? }`, kept by the caller between attempts.
 * @param {Object}   options           Options.
 * @param {string}   options.key       The intent's identity on this screen.
 * @param {string}   options.operation Grant operation.
 * @param {Object}   options.intent    Raw intent for issuance.
 * @param {Function} options.send      ( grant ) => Promise: the route call.
 * @param {Object}   options.meta      What the caller needs of a grant restored after a reload.
 * @param {Function} options.issue     Grant issuance (tests).
 * @param {Function} options.resolve   Grant resolution (tests).
 * @return {Promise<Object>} The route's answer.
 */
export async function sendWithGrant(
	holder,
	{
		key,
		operation,
		intent,
		send,
		meta = null,
		issue = issueGrant,
		resolve = resolveGrant,
	}
) {
	let held = holder.current;
	if ( held && held.key !== key ) {
		if ( held.uncertain ) {
			try {
				const outcome = await resolve( held.grant );
				if ( outcome && outcome.status !== 'not_accepted' ) {
					holder.current = null;
					keepHeld( holder );
					return { ...outcome, earlier: true };
				}
			} catch ( error ) {
				if ( isUncertain( error ) ) {
					throw error;
				}
			}
		}
		holder.current = null;
		keepHeld( holder );
		held = null;
	}
	if ( ! held ) {
		const grant = await issue( operation, intent );
		held = { key, grant, meta, uncertain: false };
		holder.current = held;
	}
	// Kept before it is sent: a reload during the request leaves it held.
	keepHeld( holder );
	try {
		const answer = await send( held.grant );
		if ( holder.current === held ) {
			holder.current = null;
			keepHeld( holder );
		}
		return answer;
	} catch ( error ) {
		held.uncertain = isUncertain( error );
		if ( isDead( error ) && holder.current === held ) {
			holder.current = null;
		}
		keepHeld( holder );
		throw error;
	}
}

/**
 * After a reload: what became of a grant the tab still held. Resolution
 * never creates work. A definite outcome (`{ batch_id, … }`) or a dead
 * refusal ends the grant; `not_accepted` and an unknown answer keep it, so
 * the same change sends it again and a different one resolves it first.
 *
 * @param {Object}   holder  keptHolder().
 * @param {Function} resolve Grant resolution (tests).
 * @return {Promise<Object|null>} The outcome when it had been accepted, else null.
 */
export async function resolveHeld( holder, resolve = resolveGrant ) {
	const held = holder?.current;
	if ( ! held?.restored ) {
		return null;
	}
	try {
		const outcome = await resolve( held.grant );
		if ( holder.current !== held ) {
			return null;
		}
		if ( outcome && outcome.status !== 'not_accepted' ) {
			holder.current = null;
			keepHeld( holder );
			return outcome;
		}
		held.restored = false;
		return null;
	} catch ( error ) {
		if ( holder.current === held && isDead( error ) ) {
			holder.current = null;
			keepHeld( holder );
		}
		return null;
	}
}
