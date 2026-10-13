/**
 * The undo flow's requests and words (SPEC §4.6, §9.4; T56, T57, T84):
 * preview first (POST /batches/{id}/undo with `preview: true`), then a plain
 * confirmation sentence, then the durable undo: a `batch.undo` grant from
 * POST /requests over the batch, the preview's `plan_hash` and `force`
 * (**Revert anyway**, off unless the owner chose it), sent to
 * POST /batches/{id}/undo. A retry of the same undo sends the same grant, so
 * it can only happen once.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import { request } from '../../api';
import { envelope, sendWithGrant } from '../../requests';
import {
	formatNumber,
	itemsPhrase,
	productsPhrase,
	variationsPhrase,
} from '../bulk/words';
import { countPhrase } from '../catalogue/selection/model';

/**
 * Plan an undo and show it, changing nothing.
 *
 * @param {number} batchId Batch.
 * @return {Promise<Object>} `{ counts, total, items, plan_hash, sentence, … }`.
 */
export function previewUndo( batchId ) {
	return request( `batches/${ batchId }/undo`, {
		method: 'POST',
		data: { preview: true, per_page: 100 },
	} );
}

/**
 * The identity of one undo's intent: the batch, the plan previewed and
 * whether it reverts anyway. The same identity sends the grant it holds.
 *
 * @param {number}  batchId Batch.
 * @param {Object}  plan    The preview answer.
 * @param {boolean} force   Revert anyway.
 * @return {string} Key.
 */
export function undoKey( batchId, plan, force ) {
	return JSON.stringify( {
		batch: Number( batchId ),
		plan: plan?.plan_hash || '',
		force: !! force,
	} );
}

/**
 * The grant each batch's undo holds between attempts, for as long as the
 * screen is open, so a card drawn again after the list is read still
 * resends, or first resolves, an uncertain undo.
 */
const holders = new Map();

/**
 * The holder of a batch's undo grant.
 *
 * @param {number} batchId Batch.
 * @return {Object} `{ current }`.
 */
export function undoHolder( batchId ) {
	const id = Number( batchId );
	if ( ! holders.has( id ) ) {
		holders.set( id, { current: null } );
	}
	return holders.get( id );
}

/**
 * Forget every held undo grant (tests).
 */
export function resetUndoGrants() {
	holders.clear();
}

/**
 * Apply a previewed undo as a durable request.
 *
 * @param {Object}  holder  `{ current }`: the grant kept between attempts (undoHolder()).
 * @param {number}  batchId Batch.
 * @param {Object}  plan    The preview answer.
 * @param {boolean} force   Revert anyway.
 * @return {Promise<Object>} `{ batch_id, status, total_items, replayed }`.
 */
export function applyUndo( holder, batchId, plan, force ) {
	const id = Number( batchId );
	return sendWithGrant( holder, {
		key: undoKey( id, plan, force ),
		operation: 'batch.undo',
		intent: {
			source: 'undo',
			batch_id: id,
			plan_hash: plan.plan_hash,
			force: !! force,
		},
		send: ( grant ) =>
			request( `batches/${ id }/undo`, {
				method: 'POST',
				data: envelope( grant ),
			} ),
	} );
}

/**
 * Read one batch (progress, stalled flag, summary, conflicts).
 *
 * @param {number} batchId Batch.
 * @return {Promise<Object>} Report.
 */
export function readBatch( batchId ) {
	return request( `batches/${ batchId }` );
}

/**
 * How many objects an undo will put back.
 *
 * @param {Object}  plan  The preview answer.
 * @param {boolean} force Revert anyway.
 * @return {number} Count.
 */
export function putBackCount( plan, force ) {
	const counts = plan?.counts || {};
	return (
		( Number( counts.revertible ) || 0 ) +
		( force ? Number( counts.conflicted ) || 0 : 0 )
	);
}

/**
 * How many products and how many variations an undo will put back, from the
 * preview's `by_type` (T114), or null when the preview did not split them.
 *
 * @param {Object}  plan  The preview answer.
 * @param {boolean} force Revert anyway.
 * @return {{products: number, variations: number}|null} Counts.
 */
export function putBackTypes( plan, force ) {
	const byType = plan?.by_type;
	if ( ! byType ) {
		return null;
	}
	const of = ( type ) =>
		( Number( byType[ type ]?.revertible ) || 0 ) +
		( force ? Number( byType[ type ]?.conflicted ) || 0 : 0 );
	return {
		products: of( 'product' ),
		variations: of( 'variation' ),
		categories: of( 'term' ),
	};
}

/**
 * "1 category" or "4 categories" (ticket 133: an undo of category changes
 * puts categories back, not products).
 *
 * @param {number} count Count.
 * @return {string} Phrase.
 */
export function categoriesPhrase( count ) {
	return sprintf(
		/* translators: %s: a number of categories, such as "4". */
		_n(
			'%s category',
			'%s categories',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( count )
	);
}

/**
 * What kind of values the batch changed, for the confirmation sentence.
 *
 * @param {Object} batch The batch row.
 * @param {Object} byKey Field descriptors by key.
 * @return {string} prices, stock, visibility or values.
 */
export function valueKind( batch, byKey ) {
	const keys = ( batch?.operations || [] ).map(
		( operation ) => operation?.args?.field
	);
	if ( ! keys.length ) {
		return 'values';
	}
	if ( keys.every( ( key ) => byKey?.[ key ]?.type === 'money' ) ) {
		return 'prices';
	}
	// By the registry's panel group, so no field key is named here (T40).
	const groups = keys.map( ( key ) => byKey?.[ key ]?.group );
	if ( groups.every( ( group ) => group === 'stock' ) ) {
		return 'stock';
	}
	if ( groups.every( ( group ) => group === 'publishing' ) ) {
		return 'visibility';
	}
	return 'values';
}

/**
 * The confirmation sentence: "This puts 120 products back to their old
 * prices. Continue?", naming variations where the undo puts any back
 * ("100 products and 20 variations", T114).
 *
 * @param {number}      count How many are put back.
 * @param {string}      kind  valueKind().
 * @param {Object|null} types putBackTypes(), or null for products.
 * @return {string} Sentence.
 */
export function confirmSentence( count, kind, types = null ) {
	if (
		Number( types?.categories ) > 0 &&
		! Number( types?.products ) &&
		! Number( types?.variations )
	) {
		return sprintf(
			/* translators: %s: what is put back, such as "1 category" or "4 categories". */
			_n(
				'This puts %s back as it was before. Continue?',
				'This puts %s back as they were before. Continue?',
				count,
				'bulk-catalogue-manager-for-woocommerce'
			),
			categoriesPhrase( count )
		);
	}
	const what = countPhrase( count, types || {} );
	switch ( kind ) {
		case 'prices':
			return sprintf(
				/* translators: %s: what is put back, such as "120 products" or "100 products and 20 variations". */
				_n(
					'This puts %s back to its old price. Continue?',
					'This puts %s back to their old prices. Continue?',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				what
			);
		case 'stock':
			return sprintf(
				/* translators: %s: what is put back, such as "120 products" or "100 products and 20 variations". */
				_n(
					'This puts %s back to its old stock. Continue?',
					'This puts %s back to their old stock. Continue?',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				what
			);
		case 'visibility':
			return sprintf(
				/* translators: %s: what is put back, such as "120 products" or "100 products and 20 variations". */
				_n(
					'This puts %s back to how it showed on the site. Continue?',
					'This puts %s back to how they showed on the site. Continue?',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				what
			);
		default:
			return sprintf(
				/* translators: %s: what is put back, such as "120 products" or "100 products and 20 variations". */
				_n(
					'This puts %s back to its old values. Continue?',
					'This puts %s back to their old values. Continue?',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				what
			);
	}
}

/**
 * What an undo put back, in words: products, variations, or items when it
 * held both or when an older undo did not record which (T114).
 *
 * @param {number}      count  How many.
 * @param {Object|null} byType The undo summary's `by_type`, or null.
 * @return {string} Phrase.
 */
function putBackPhrase( count, byType ) {
	const products = Number( byType?.products ) || 0;
	const variations = Number( byType?.variations ) || 0;
	const categories = Number( byType?.categories ) || 0;
	if ( byType && categories > 0 && 0 === products && 0 === variations ) {
		return categoriesPhrase( count );
	}
	if ( byType && products > 0 && 0 === variations ) {
		return productsPhrase( count );
	}
	if ( byType && variations > 0 && 0 === products ) {
		return variationsPhrase( count );
	}
	return itemsPhrase( count );
}

/**
 * The notice when an undo has finished: "Undone: Increased the regular
 * price by 8%. 1,248 products were put back." plus the route's own line
 * about any left alone.
 *
 * @param {string}  what   The batch undone, in words.
 * @param {Object}  report The finished undo batch's report.
 * @param {boolean} redo   Whether this undid an undo.
 * @return {string} Text.
 */
export function undoneNotice( what, report, redo = false ) {
	const count =
		Number( report?.changed_items ) || Number( report?.done_items ) || 0;
	if (
		report?.changed_items !== undefined &&
		0 === Number( report.changed_items )
	) {
		// The lead reviewer's F2: an undo that put nothing back is not called done; the route says why.
		return (
			report?.undo?.sentence ||
			__(
				'Nothing was put back.',
				'bulk-catalogue-manager-for-woocommerce'
			)
		);
	}
	const putBack = sprintf(
		/* translators: %s: what was put back, such as "1,248 products", "20 variations" or "1,268 items". */
		_n(
			'%s was put back.',
			'%s were put back.',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		putBackPhrase( count, report?.undo?.by_type || null )
	);
	// The route's own line about any left alone is part of the sentence a
	// translator orders (FRONTEND-11).
	const extra = report?.undo?.sentence;
	if ( redo ) {
		return extra
			? sprintf(
					/* translators: 1: what was put back, such as "12 products were put back."; 2: the products left alone, a sentence. */
					__(
						'Redone. %1$s %2$s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					putBack,
					extra
				)
			: sprintf(
					/* translators: %s: what was put back, such as "12 products were put back.". */
					__(
						'Redone. %s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					putBack
				);
	}
	return extra
		? sprintf(
				/* translators: 1: the change undone, in words, such as "Increased the regular price by 8%"; 2: how many were put back; 3: the products left alone, a sentence. */
				__(
					'Undone: %1$s. %2$s %3$s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				what,
				putBack,
				extra
			)
		: sprintf(
				/* translators: 1: the change undone, in words, such as "Increased the regular price by 8%"; 2: how many were put back. */
				__(
					'Undone: %1$s. %2$s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				what,
				putBack
			);
}
