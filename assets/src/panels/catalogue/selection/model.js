/**
 * The selection's pure parts (T84, SPEC §4.1): the words the bar, the review
 * and the notices use, and the selection as the bulk change panel reads it.
 * The state they read is the session's (session.js).
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	formatNumber,
	productsPhrase,
	variationsPhrase,
} from '../../bulk/words';

/**
 * "25 products", "3 variations" or "1,200 products and 50 variations".
 *
 * @param {number} count  Objects selected.
 * @param {Object} byType `{ products, variations }`.
 * @return {string} Phrase.
 */
export function countPhrase( count, byType = {} ) {
	const products = Number( byType?.products ) || 0;
	const variations = Number( byType?.variations ) || 0;
	if ( products && variations ) {
		return sprintf(
			/* translators: 1: a number of products, such as "1,200 products"; 2: a number of variations, such as "50 variations". */
			__( '%1$s and %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
			productsPhrase( products ),
			variationsPhrase( variations )
		);
	}
	if ( variations ) {
		return variationsPhrase( variations );
	}
	return productsPhrase( products || count );
}

/**
 * The bar's count: "25 products selected", "3 variations selected" or
 * "1,200 products and 50 variations selected", from the server's counts.
 *
 * @param {number} count  Objects selected.
 * @param {Object} byType `{ products, variations }`.
 * @return {string} Words.
 */
export function selectedWords( count, byType = {} ) {
	const products = Number( byType?.products ) || 0;
	const variations = Number( byType?.variations ) || 0;
	if ( products && variations ) {
		return sprintf(
			/* translators: 1: a number of products, such as "1,200 products"; 2: a number of variations, such as "50 variations". */
			__(
				'%1$s and %2$s selected',
				'bulk-catalogue-manager-for-woocommerce'
			),
			productsPhrase( products ),
			variationsPhrase( variations )
		);
	}
	if ( variations ) {
		return sprintf(
			/* translators: %s: a number of variations, such as "3". */
			_n(
				'%s variation selected',
				'%s variations selected',
				variations,
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( variations )
		);
	}
	const total = products || Number( count ) || 0;
	return sprintf(
		/* translators: %s: a number of products, such as "25". */
		_n(
			'%s product selected',
			'%s products selected',
			total,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( total )
	);
}

/**
 * Whether every row on the page is selected.
 *
 * @param {Array<string>} ids     Selected ids on screen.
 * @param {Array<string>} pageIds Ids of the page's rows.
 * @return {boolean} True when the page is fully selected.
 */
export function isPageSelected( ids, pageIds ) {
	return pageIds.length > 0 && pageIds.every( ( id ) => ids.includes( id ) );
}

/**
 * Why a change cannot be checked while the selection is not settled, or ''.
 *
 * @param {Object} state The session's state.
 * @return {string} Reason.
 */
export function waitingReason( state ) {
	switch ( state?.status ) {
		case 'saving':
			return __(
				'Your selection is still being saved. Wait a moment, then check the change.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'uncertain':
			return __(
				'A change to your selection was not confirmed. Try again, or clear the selection, before checking a change.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'blocked':
			return (
				state.blocked?.message ||
				__(
					'Your selection is kept, but it cannot be used until you sign in again. Reload the page, then try again.',
					'bulk-catalogue-manager-for-woocommerce'
				)
			);
		default:
			return '';
	}
}

/**
 * The selection as the bulk change panel reads it (panels/bulk README):
 * `{ kind: 'snapshot', token, key, count, byType, waiting }`.
 *
 * @param {Object} state The session's state.
 * @return {Object} Panel selection.
 */
export function panelSelection( state ) {
	const current = state?.current;
	return {
		kind: 'snapshot',
		token: current?.token || '',
		key: current?.key || '',
		count: current?.count || 0,
		byType: current?.byType || { products: 0, variations: 0 },
		waiting: waitingReason( state ),
	};
}

/**
 * The sentence for a selection notice.
 *
 * @param {Object} notice `{ kind, … }` from the session.
 * @param {Object} state  The session's state.
 * @return {string} Sentence.
 */
export function noticeText( notice, state = {} ) {
	switch ( notice?.kind ) {
		case 'restored':
			return sprintf(
				/* translators: %s: what is selected, such as "25 products". */
				__(
					'Your selection is still here: %s.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				countPhrase( state.count, state.byType )
			);
		case 'searchCleared':
			return __(
				'The search and the selection were cleared.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'done':
			return notice.kept > 0
				? __(
						'The change finished, so the products it covered are no longer selected. Anything you selected after applying it is still selected.',
						'bulk-catalogue-manager-for-woocommerce'
					)
				: __(
						'The change finished, so your selection was cleared.',
						'bulk-catalogue-manager-for-woocommerce'
					);
		case 'kept':
			if ( notice.skipped ) {
				return __(
					'The change finished and some rows were skipped, so your selection is kept. History shows what was skipped and why.',
					'bulk-catalogue-manager-for-woocommerce'
				);
			}
			return __(
				'The change did not finish for every product, so your selection is kept. History shows what was not changed.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'cleanup':
			return __(
				'The change finished, but the products it covered could not be taken out of your selection because they are no longer kept. Your selection is unchanged; review it before your next change.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		default:
			return '';
	}
}

/**
 * The line when a lease had lapsed and the same products came back under a
 * new authorization (the drift notice).
 *
 * @return {string} Sentence.
 */
export function driftText() {
	return __(
		'This page was idle for a while, so your selection was checked again. The same products are still selected; check any change again before applying it.',
		'bulk-catalogue-manager-for-woocommerce'
	);
}

/**
 * The line for selected work the server no longer keeps: never an empty
 * selection, always what there was.
 *
 * @param {Object} lost `{ count, byType, message }`.
 * @return {string} Sentence.
 */
export function lostText( lost ) {
	const had = sprintf(
		/* translators: %s: what was selected, such as "25 products". */
		__( 'You had %s selected.', 'bulk-catalogue-manager-for-woocommerce' ),
		countPhrase( lost?.count, lost?.byType )
	);
	const why =
		lost?.message ||
		__(
			'These selected products are no longer kept. Choose them again before starting a new change.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	return `${ why } ${ had }`;
}

/**
 * The sentence for a selection error.
 *
 * @param {Object} error `{ kind, code, message, uncertain, retry }` from the session.
 * @return {string} Sentence.
 */
export function errorText( error ) {
	if ( error?.kind === 'unconfirmed' ) {
		return __(
			'Your last change to the selection was not confirmed before the page was reloaded. Try again to finish it, or clear the selection.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	if ( error?.message ) {
		return error.message;
	}
	if ( error?.uncertain ) {
		return __(
			'Your selection could not be saved just now. Try again: the same change is sent again, so nothing is added twice.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	if ( error?.retry ) {
		return __(
			'Your selection could not be saved because you need to sign in again. Reload the page, then try again.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	return __(
		'Your selection could not be changed. Check it and try again.',
		'bulk-catalogue-manager-for-woocommerce'
	);
}
