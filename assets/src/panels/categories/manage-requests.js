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
