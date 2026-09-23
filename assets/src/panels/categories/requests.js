/**
 * The category routes (SPEC §7, §12.1; T21, T63, T64), always through the
 * screen's own request() so the nonce and the bcm/v1 root apply.
 */
import { request } from '../../api';

/**
 * GET /categories with the grid's active filter arguments, so the counts
 * follow them.
 *
 * @param {Object} query The grid's search, mode and per-field filter arguments.
 * @return {Promise<Array<Object>>} Top-level nodes.
 */
export function fetchTree( query = {} ) {
	return request( 'categories', { query } );
}

/**
 * POST /categories.
 *
 * @param {Object} data { name, slug?, description?, parent? }.
 * @return {Promise<Object>} { item, batch_id, status, results }.
 */
export function createCategory( data ) {
	return request( 'categories', { method: 'POST', data } );
}

/**
 * PATCH /categories/{id} with only the properties that changed.
 *
 * @param {number} id   Category id.
 * @param {Object} data Changed properties.
 * @return {Promise<Object>} { item, batch_id, status, results }.
 */
export function updateCategory( id, data ) {
	return request( `categories/${ Number( id ) }`, { method: 'PATCH', data } );
}

/**
 * POST /categories/{id}/move.
 *
 * @param {number}           id       Category id.
 * @param {number}           parent   New parent, 0 for the top level.
 * @param {number|undefined} position Place among the new siblings from 0; last when undefined.
 * @return {Promise<Object>} { item, batch_id, status, results }.
 */
export function moveCategory( id, parent, position ) {
	const data = { parent: Number( parent ) || 0 };
	if ( Number.isInteger( position ) ) {
		data.position = position;
	}
	return request( `categories/${ Number( id ) }/move`, {
		method: 'POST',
		data,
	} );
}
