/**
 * The category routes for the shop's default category, deleting and
 * merging (tickets 23 and 24), always through the screen's own request() so
 * the nonce and the bulkcat/v1 root apply.
 */
import { request } from '../../api';

/**
 * POST /categories/{id}/default: make a category the shop's default
 * product category, as WooCommerce's own Make default does.
 *
 * @param {number} id Category id.
 * @return {Promise<Object>} { item, batch_id, status, results }.
 */
export function makeDefaultCategory( id ) {
	return request( `categories/${ Number( id ) }/default`, {
		method: 'POST',
	} );
}

/**
 * POST /categories/delete: with `{ preview: true }` what deleting would do
 * and its token; with `{ token }` the delete itself.
 *
 * @param {Array<number>} ids     Category ids.
 * @param {Object}        options `{ preview: true }` or `{ token }`.
 * @return {Promise<Object>} The preview, or { batch_id, status, deleted, failed }.
 */
export function deleteCategories( ids, options ) {
	return request( 'categories/delete', {
		method: 'POST',
		data: { ids: ids.map( Number ), ...options },
	} );
}

/**
 * POST /categories/merge: as deleteCategories(), moving the products into
 * `into` first.
 *
 * @param {Array<number>} ids     Category ids.
 * @param {Object}        options `{ preview: true }` or `{ token }`.
 * @param {number}        into    The category the products move into.
 * @return {Promise<Object>} The preview, or { batch_id, status, deleted, failed }.
 */
export function mergeCategories( ids, options, into ) {
	return request( 'categories/merge', {
		method: 'POST',
		data: { ids: ids.map( Number ), into: Number( into ), ...options },
	} );
}
