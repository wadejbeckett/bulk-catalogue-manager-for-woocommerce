/**
 * The requests the editing controls make, all through api.js request():
 * one PATCH /products/{id} per change (recorded by the server as a batch of
 * one, SPEC §9.3), the product's full row for the detail panel, its
 * variations and its most recent recorded change.
 */
import { request } from '../../../api';
import { ROW } from '../defaults';
import { isVariation } from './rules';

/** The batch status of a write that changed everything it was asked to. */
const COMPLETE = 'complete';

/** Step states that mean a field was not written (Operations\\Result). */
const REFUSED = [ 'skipped', 'failed' ];

/**
 * Write field values to one product or variation.
 *
 * Resolves with `{ item, batch_id, status, results }` when the batch is
 * complete. A partial or failed batch rejects with the first refused step
 * as the error (its reason as the message, its field in `data.field`) and
 * the answer in `data.answer`, so a caller treats it like a refusal.
 *
 * @param {number}        id     Product or variation id.
 * @param {Object}        values Field key => value.
 * @param {Array<string>} fields Fields the returned row should carry; all when empty.
 * @return {Promise<Object>} The answer.
 */
export function patchProduct( id, values, fields = [] ) {
	return request( `products/${ Number( id ) }`, {
		method: 'PATCH',
		data: values,
		query: fields.length ? { fields } : {},
	} ).then( ( answer ) => {
		if ( answer?.status && answer.status !== COMPLETE ) {
			const refused =
				( answer.results || [] ).find( ( result ) =>
					REFUSED.includes( result.state )
				) || {};
			const error = new Error( refused.reason || '' );
			error.code = refused.code || answer.status;
			error.data = { status: 200, field: refused.field, answer };
			throw error;
		}
		return answer;
	} );
}

/**
 * Every field of one row, for the detail panel: GET /products/{id} (a
 * product or a variation) with the panel's fields; when it cannot be read
 * the grid's row is used as it is.
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
	} )
		.then( ( answer ) =>
			answer?.item && Number( answer.item.id ) === id
				? { ...item, ...answer.item }
				: item
		)
		.catch( () => item );
}

/**
 * A product's variations, read-only for the detail panel.
 *
 * @param {Object} item Row.
 * @return {Promise<Array<Object>>} Variation rows; none for a variation.
 */
export function loadVariations( item ) {
	if ( isVariation( item ) || Number( item?.[ ROW.parentId ] ) < 0 ) {
		return Promise.resolve( [] );
	}
	return request( `products/${ Number( item.id ) }/variations`, {
		query: { per_page: 100 },
	} )
		.then( ( answer ) =>
			Array.isArray( answer?.items ) ? answer.items : []
		)
		.catch( () => [] );
}

/**
 * The newest recorded change to one product or variation.
 *
 * @param {number} id Product or variation id.
 * @return {Promise<Object|null>} Audit row, or null when there is none.
 */
export function loadRecentChange( id ) {
	return request( 'audit', {
		query: { product: Number( id ), per_page: 1 },
	} )
		.then( ( answer ) => ( answer?.items || [] )[ 0 ] || null )
		.catch( () => null );
}
