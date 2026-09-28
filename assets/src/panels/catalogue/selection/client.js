/**
 * The selection routes (T82, docs/EXACT-SELECTION.md "API"), all POST and all
 * through api.js request(). Capture and compose take a grant from
 * requests.js; inspect, renew and release are idempotent handle operations
 * and take only the opaque token.
 */
import { request } from '../../../api';
import { envelope } from '../../../requests';

/**
 * Capture a query or explicit ids into a new frozen snapshot.
 *
 * @param {Object} grant A `selection.capture` grant.
 * @return {Promise<Object>} The snapshot envelope.
 */
export function capture( grant ) {
	return request( 'selection', { method: 'POST', data: envelope( grant ) } );
}

/**
 * Compose a successor of a snapshot: union or subtraction of ids or of
 * another snapshot. `credentials` are the current tokens: `token` for the
 * base and `add_token` or `remove_token` for a snapshot operand.
 *
 * @param {Object} grant       A `selection.compose` grant.
 * @param {Object} credentials Current tokens.
 * @return {Promise<Object>} The successor's envelope.
 */
export function compose( grant, credentials ) {
	return request( 'selection/compose', {
		method: 'POST',
		data: { ...envelope( grant ), ...credentials },
	} );
}

/**
 * A page of a snapshot's members and which of `probe_ids` it holds.
 *
 * @param {string} token   Current token.
 * @param {Object} options `{ page, per_page, probe_ids }`.
 * @return {Promise<Object>} Envelope plus `items`, `pagination` and `selected_ids`.
 */
export function inspect( token, options = {} ) {
	return request( 'selection/inspect', {
		method: 'POST',
		data: { token, ...options },
	} );
}

/**
 * Keep a snapshot: extend its lease (the same token while it is live, the
 * next epoch after it lapsed, with `requires_preview`) and its retention.
 *
 * @param {string} token A token the screen holds, even an expired one.
 * @return {Promise<Object>} The envelope.
 */
export function renew( token ) {
	return request( 'selection/renew', {
		method: 'POST',
		data: { token },
	} );
}

/**
 * Let the server clean up snapshots the screen no longer refers to, one to
 * four at a time. Best effort: a lost answer falls back to the server's
 * seven-day inactivity rule.
 *
 * @param {Array<string>} tokens Tokens.
 * @return {Promise<Object>} `{ released }`.
 */
export function release( tokens ) {
	return request( 'selection/release', {
		method: 'POST',
		data: { tokens },
	} );
}

/**
 * A change's progress report (GET /batches/{id}), read while the selection
 * waits for the change applied to it to finish.
 *
 * @param {number} id Batch id.
 * @return {Promise<Object>} The report.
 */
export function batch( id ) {
	return request( 'batches/' + Number( id ) );
}
