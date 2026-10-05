/**
 * What the bulk panel says when it is opened with nothing selected
 * (FRONTEND-1, ticket 20): it never selects anything by itself. It asks, and
 * offers Select all matching, which adds the view's matches through the same
 * exact-selection capture as the selection bar's offer. The count is offered
 * only once the view on screen has settled (the saved default view applied,
 * its rows read), so the operator is never offered the unfiltered start view.
 */
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatNumber } from '../../bulk/words';
import { selectAllWords } from './selection-bar';

/**
 * The sentence for the prompt.
 *
 * @param {boolean} ready    Whether the view's count is known.
 * @param {number}  total    Rows matching the view.
 * @param {boolean} filtered Whether a search, filter or category narrows the view.
 * @return {string} Sentence.
 */
export function nothingSelectedText( ready, total, filtered ) {
	if ( ! ready ) {
		return __(
			'Nothing is selected. Select products in the table.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	if ( total < 1 ) {
		return filtered
			? __(
					'Nothing is selected, and no products match the current filters.',
					'bulk-catalogue-manager-for-woocommerce'
				)
			: __(
					'Nothing is selected, and there are no products to select.',
					'bulk-catalogue-manager-for-woocommerce'
				);
	}
	return filtered
		? sprintf(
				/* translators: %s: a number of rows, such as "1,284". */
				_n(
					'Nothing is selected. Select products in the table, or select the %s matching the current filters.',
					'Nothing is selected. Select products in the table, or select all %s matching the current filters.',
					total,
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( total )
			)
		: sprintf(
				/* translators: %s: a number of rows, such as "1,284". */
				_n(
					'Nothing is selected. Select products in the table, or select the %s in the catalogue.',
					'Nothing is selected. Select products in the table, or select all %s in the catalogue.',
					total,
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( total )
			);
}

/**
 * The prompt, with Select all matching once the count is known.
 *
 * @param {Object}   props          Props.
 * @param {boolean}  props.ready    Whether the view on screen has settled and its count is known.
 * @param {number}   props.total    Rows matching the view.
 * @param {boolean}  props.filtered Whether a search, filter or category narrows the view.
 * @param {string}   props.mode     The grid's mode: products, variations or products_and_variations.
 * @param {Function} props.onSelect Select all matching (the exact-selection capture).
 * @param {boolean}  props.disabled Whether the selection cannot take a change just now.
 * @return {Element} Prompt.
 */
export default function NothingSelected( {
	ready,
	total,
	filtered,
	mode = 'products',
	onSelect,
	disabled = false,
} ) {
	const count = Number( total ) || 0;
	return (
		<div className="bulkcat-bulk__nothing">
			<p>{ nothingSelectedText( ready, count, filtered ) }</p>
			{ ready && count > 0 && (
				<Button
					variant="secondary"
					size="compact"
					onClick={ onSelect }
					disabled={ disabled }
					accessibleWhenDisabled
				>
					{ selectAllWords( count, filtered, mode ) }
				</Button>
			) }
		</div>
	);
}
