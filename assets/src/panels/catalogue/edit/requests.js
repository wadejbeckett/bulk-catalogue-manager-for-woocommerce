/**
 * The requests the editing controls make, all through api.js request():
 * one PATCH /products/{id} per change (recorded by the server as a batch of
 * one, SPEC §9.3), the product's full row for the detail panel, its
 * variations and its most recent recorded change.
 */
import { request } from '../../../api';
import { envelope } from '../../../requests';
import { ROW } from '../defaults';
import { isVariation } from './rules';

/**
 * The synchronous transport. Receipt adoption belongs to receipts.js; a
 * duplicate answer intentionally has no item/results.
 *
 * @param {number} id      Product identity.
 * @param {Object} values  Original typed values.
 * @param {Array}  fields  Current row projection.
 * @param {Object} options Grant and optional legacy keep flag.
 * @return {Promise<Object>} Immediate response or safe receipt.
 */
export function patchProduct( id, values, fields = [], options = {} ) {
	return request( `products/${ Number( id ) }`, {
		method: 'PATCH',
		bulkcatNoRetry: true,
		data: {
			...values,
			source: options.grant.intent.source,
			...( options.keep ? { keep_changed_status: true } : {} ),
			...envelope( options.grant ),
		},
		query: fields.length ? { fields } : {},
	} );
}

/**
 * Strict current-row read. Never substitute a stale drawer row for a failure.
 *
 * @param {number} id     Product identity.
 * @param {Array}  fields Current projection.
 * @return {Promise<Object>} Current item; rejects on failure or malformed data.
 */
export async function readProduct( id, fields = [] ) {
	const answer = await request( `products/${ Number( id ) }`, {
		query: fields.length ? { fields } : {},
	} );
	if ( Number( answer?.item?.id ) !== Number( id ) ) {
		throw new Error( 'Invalid product response' );
	}
	return answer.item;
}

/**
 * Every field of one row, for the detail panel: GET /products/{id} (a
 * product or a variation) with the panel's fields. A failed read rejects,
 * so the panel can say so (FRONTEND-9) and show the grid's row meanwhile.
 *
 * @param {Object}        item   The grid's row.
 * @param {Array<string>} fields Field keys the panel shows.
 * @return {Promise<Object>} The full row, or the grid's row.
 */
export function loadProduct( item, fields = [] ) {
	const id = Number( item?.id );
	if ( ! id ) {
		return Promise.resolve( item );
	}
	return request( `products/${ id }`, {
		query: fields.length ? { fields } : {},
	} ).then( ( answer ) =>
		answer?.item && Number( answer.item.id ) === id
			? { ...item, ...answer.item }
			: item
	);
}

/**
 * A product's first 100 variations, read-only for the detail panel, and how
 * many it has in all. A failed read rejects (FRONTEND-9).
 *
 * @param {Object} item Row.
 * @return {Promise<{items: Array<Object>, total: number}>} Variation rows (none for a variation) and the total.
 */
export function loadVariations( item ) {
	if ( isVariation( item ) || Number( item?.[ ROW.parentId ] ) < 0 ) {
		return Promise.resolve( { items: [], total: 0 } );
	}
	return request( `products/${ Number( item.id ) }/variations`, {
		query: { per_page: 100 },
	} ).then( ( answer ) => {
		const items = Array.isArray( answer?.items ) ? answer.items : [];
		return {
			items,
			total: Math.max(
				items.length,
				Number( answer?.pagination?.total ) || 0
			),
		};
	} );
}

/**
 * The newest recorded change to one product or variation. A failed read
 * rejects, so it is never mistaken for none (FRONTEND-9).
 *
 * @param {number} id Product or variation id.
 * @return {Promise<Object|null>} Audit row, or null when there is none.
 */
export function loadRecentChange( id ) {
	return request( 'audit', {
		query: { product: Number( id ), per_page: 1 },
	} ).then( ( answer ) => ( answer?.items || [] )[ 0 ] || null );
}
