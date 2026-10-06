/**
 * An add-on's actions on categories (contract version 2, category target;
 * docs/EXTENSIONS-v2.md §5): which `/fields` actions act on categories,
 * what a preview and an Apply send, and the words for each category's
 * outcome. Pure: the dialog and the tests share it.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../../bulk/words';

/**
 * The add-on actions that act on categories, in the registry's order: those
 * whose `target` is not the default (products). Contract version 2 defines
 * one other target, product categories, and names the taxonomy there.
 *
 * @param {Object} registry The `/fields` payload.
 * @return {Array<Object>} Action descriptors.
 */
export function categoryActionsOf( registry ) {
	return ( registry?.actions || [] ).filter(
		( action ) =>
			action.provider && !! action.target && action.target !== 'product'
	);
}

/**
 * Whether any chosen category has subcategories, so Include subcategories
 * means something.
 *
 * @param {Object}        tree useCategoryTree() result.
 * @param {Array<number>} ids  Chosen category ids.
 * @return {boolean} True when one has a child.
 */
export function hasSubcategories( tree, ids ) {
	return ids.some(
		( id ) => ( tree?.byId?.get( id )?.node?.children || [] ).length > 0
	);
}

/**
 * The preview request body.
 *
 * @param {Array<Object>} operations    Request operations.
 * @param {Array<number>} ids           Chosen category ids.
 * @param {boolean}       subcategories Include subcategories.
 * @return {Object} Body for POST /categories/actions/preview.
 */
export function previewBody( operations, ids, subcategories ) {
	return {
		operations,
		categories: [ ...new Set( ids.map( Number ) ) ].sort(
			( a, b ) => a - b
		),
		include_subcategories: !! subcategories,
	};
}

/**
 * The Apply intent a preview leads to: exactly the categories it captured.
 *
 * @param {Object} preview The preview's answer.
 * @return {Object} Raw `batch.apply` intent.
 */
export function applyIntent( preview ) {
	return {
		source: 'bulk',
		operations: preview.operations,
		scope_hash: preview.scope_hash,
		categories: {
			chosen: preview.categories.chosen,
			subcategories: !! preview.categories.subcategories,
			ids: preview.categories.ids,
		},
	};
}

/**
 * The words for a category's outcome in the preview.
 *
 * @param {string} outcome change, already, skip, fail or unavailable.
 * @return {string} Words.
 */
export function outcomeLabel( outcome ) {
	switch ( outcome ) {
		case 'change':
			return __(
				'Will change',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'already':
			return __(
				'Already as asked',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'unavailable':
			return __(
				'No longer exists',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'fail':
			return __(
				'Cannot change',
				'bulk-catalogue-manager-for-woocommerce'
			);
		default:
			return __( 'Left alone', 'bulk-catalogue-manager-for-woocommerce' );
	}
}

/**
 * What one record row says: the add-on's change words, else before → after,
 * else the reason it is left alone.
 *
 * @param {Object} row A record's row.
 * @return {string} Text.
 */
export function rowText( row ) {
	if ( row?.state === 'changed' ) {
		return (
			row.change ||
			sprintf(
				/* translators: 1: the value now, such as "North wall"; 2: the value after, such as "North wall, South wall". */
				__( '%1$s → %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				row.current,
				row.new
			)
		);
	}
	if ( row?.state === 'already' ) {
		return row.current || '';
	}
	return row?.reason || '';
}

/**
 * How many categories the preview covers, and how many subcategories it added.
 *
 * @param {Object} preview The preview's answer.
 * @return {string} Sentence.
 */
export function scopeSentence( preview ) {
	const count = Number( preview?.categories?.count ) || 0;
	const added = Number( preview?.categories?.added ) || 0;
	const covers = sprintf(
		/* translators: %s: a number of categories, such as "12". */
		_n(
			'This change covers %s category.',
			'This change covers %s categories.',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( count )
	);
	if ( ! added ) {
		return covers;
	}
	return sprintf(
		/* translators: 1: "This change covers 12 categories."; 2: how many subcategories were added, such as "9". */
		_n(
			'%1$s That includes %2$s subcategory.',
			'%1$s That includes %2$s subcategories.',
			added,
			'bulk-catalogue-manager-for-woocommerce'
		),
		covers,
		formatNumber( added )
	);
}

/**
 * The sentence for a finished category change.
 *
 * @param {Object} report The batch report.
 * @return {string} Sentence.
 */
export function finishedSentence( report ) {
	const total = Number( report?.total_items ) || 0;
	const changed = Number( report?.changed_items ) || 0;
	if ( report?.status === 'failed' ) {
		return __(
			'The change could not be applied. Nothing more will happen to these categories.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	if ( report?.status === 'cancelled' ) {
		return __(
			'The change was cancelled. Anything already applied is kept in History, where you can undo it.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	return sprintf(
		/* translators: 1: categories changed, such as "11"; 2: categories in the change, such as "12". */
		_n(
			"%1$s of %2$s category changed. Each category's result is in History, where you can undo it.",
			"%1$s of %2$s categories changed. Each category's result is in History, where you can undo it.",
			total,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( changed ),
		formatNumber( total )
	);
}
