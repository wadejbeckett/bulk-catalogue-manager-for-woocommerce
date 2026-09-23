/**
 * The undo flow's requests and words (SPEC §4.6, §9.4; T56, T57): preview
 * first (POST /batches/{id}/undo with `preview: true`), then a plain
 * confirmation sentence, then apply with the client's `uuid`, the
 * preview's `plan_hash` and `force` (**Revert anyway**, off unless the
 * owner chose "Undo all anyway").
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import { request } from '../../api';

/**
 * A request id for one undo, so a double click undoes once.
 *
 * @return {string} UUID v4.
 */
export function newUuid() {
	if ( typeof window?.crypto?.randomUUID === 'function' ) {
		return window.crypto.randomUUID();
	}
	const bytes = new Uint8Array( 16 );
	if ( typeof window?.crypto?.getRandomValues === 'function' ) {
		window.crypto.getRandomValues( bytes );
	} else {
		for ( let i = 0; i < 16; i++ ) {
			bytes[ i ] = Math.floor( Math.random() * 256 );
		}
	}
	const digits = Array.from( bytes, ( b ) =>
		b.toString( 16 ).padStart( 2, '0' )
	).join( '' );
	// Version 4, variant 10xx, set on the hexadecimal digits themselves.
	const hex =
		digits.slice( 0, 12 ) +
		'4' +
		digits.slice( 13, 16 ) +
		'89ab'[ bytes[ 8 ] % 4 ] +
		digits.slice( 17 );
	return `${ hex.slice( 0, 8 ) }-${ hex.slice( 8, 12 ) }-${ hex.slice(
		12,
		16
	) }-${ hex.slice( 16, 20 ) }-${ hex.slice( 20 ) }`;
}

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
 * Apply a previewed undo.
 *
 * @param {number}  batchId Batch.
 * @param {Object}  plan    The preview answer.
 * @param {boolean} force   Revert anyway.
 * @param {string}  uuid    Request id.
 * @return {Promise<Object>} `{ batch_id, status, total_items, replayed, undo }`.
 */
export function applyUndo( batchId, plan, force, uuid ) {
	return request( `batches/${ batchId }/undo`, {
		method: 'POST',
		data: { uuid, plan_hash: plan.plan_hash, force: !! force },
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
 * prices. Continue?"
 *
 * @param {number} count How many are put back.
 * @param {string} kind  valueKind().
 * @return {string} Sentence.
 */
export function confirmSentence( count, kind ) {
	const n = count.toLocaleString();
	switch ( kind ) {
		case 'prices':
			return sprintf(
				/* translators: %s: number of products, such as "120". */
				_n(
					'This puts %s product back to its old price. Continue?',
					'This puts %s products back to their old prices. Continue?',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'stock':
			return sprintf(
				/* translators: %s: number of products, such as "120". */
				_n(
					'This puts %s product back to its old stock. Continue?',
					'This puts %s products back to their old stock. Continue?',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'visibility':
			return sprintf(
				/* translators: %s: number of products, such as "120". */
				_n(
					'This puts %s product back to how it showed on the site. Continue?',
					'This puts %s products back to how they showed on the site. Continue?',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		default:
			return sprintf(
				/* translators: %s: number of products, such as "120". */
				_n(
					'This puts %s product back to its old values. Continue?',
					'This puts %s products back to their old values. Continue?',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
	}
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
	const putBack = sprintf(
		/* translators: %s: number of products, such as "1,248". */
		_n(
			'%s product was put back.',
			'%s products were put back.',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		count.toLocaleString()
	);
	const text = redo
		? sprintf(
				/* translators: %s: how many were put back, such as "12 products were put back.". */
				__( 'Redone. %s', 'bulk-catalogue-manager-for-woocommerce' ),
				putBack
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
	const extra = report?.undo?.sentence;
	return extra ? `${ text } ${ extra }` : text;
}
