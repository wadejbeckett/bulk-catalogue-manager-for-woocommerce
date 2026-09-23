/**
 * The sticky selection bar (T42, SPEC §4.1): the count in words, the line
 * offering every product matching the view once the page is selected, the
 * Show on site and Hide from site quick actions, Make a change to these and
 * Clear. It renders only while something is selected, or while the notice
 * that a view change cleared the selection is showing.
 */
import { Button, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatNumber } from '../../bulk/words';

/**
 * "1 product selected", "25 products selected", or for a matching
 * selection "All 1,284 products matching these filters are selected".
 *
 * @param {number}  count    Selected count.
 * @param {boolean} matching Whether every matching row is selected.
 * @param {boolean} filtered Whether the view narrows the catalogue.
 * @return {string} Words.
 */
export function countWords( count, matching, filtered ) {
	const number = formatNumber( count );
	if ( matching && filtered ) {
		return sprintf(
			/* translators: %s: a number of products, such as "1,284". */
			_n(
				'The %s product matching these filters is selected.',
				'All %s products matching these filters are selected.',
				count,
				'bulk-catalogue-manager-for-woocommerce'
			),
			number
		);
	}
	if ( matching ) {
		return sprintf(
			/* translators: %s: a number of products, such as "1,284". */
			_n(
				'The %s product in the catalogue is selected.',
				'All %s products in the catalogue are selected.',
				count,
				'bulk-catalogue-manager-for-woocommerce'
			),
			number
		);
	}
	return sprintf(
		/* translators: %s: a number of products, such as "25". */
		_n(
			'%s product selected',
			'%s products selected',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		number
	);
}

/**
 * "All 25 on this page are selected."
 *
 * @param {number} count Rows on the page.
 * @return {string} Line.
 */
export function pageWords( count ) {
	return sprintf(
		/* translators: %s: the number of products on this page, such as "25". */
		_n(
			'The %s product on this page is selected.',
			'All %s on this page are selected.',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( count )
	);
}

/**
 * "Select all 1,284 products matching these filters."
 *
 * @param {number}  total    Rows matching the view.
 * @param {boolean} filtered Whether the view narrows the catalogue.
 * @return {string} Label.
 */
export function selectAllWords( total, filtered ) {
	if ( filtered ) {
		return sprintf(
			/* translators: %s: a number of products, such as "1,284". */
			_n(
				'Select the %s product matching these filters.',
				'Select all %s products matching these filters.',
				total,
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( total )
		);
	}
	return sprintf(
		/* translators: %s: a number of products, such as "1,284". */
		_n(
			'Select the %s product in the catalogue.',
			'Select all %s products in the catalogue.',
			total,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( total )
	);
}

/**
 * The bar.
 *
 * @param {Object}        props              Props.
 * @param {Object}        props.selection    useSelection().
 * @param {number}        props.pageCount    Rows on this page.
 * @param {number}        props.total        Rows matching the view.
 * @param {boolean}       props.filtered     Whether the view narrows the catalogue.
 * @param {Function}      props.onMakeChange Open the bulk change panel.
 * @param {Function|null} props.onShow       Show on site, or null when the registry offers none.
 * @param {Function|null} props.onHide       Hide from site, or null.
 * @return {Element|null} Bar.
 */
export default function SelectionBar( {
	selection,
	pageCount,
	total,
	filtered,
	onMakeChange,
	onShow = null,
	onHide = null,
} ) {
	const { count, matching, error, notice } = selection;
	const loading = matching?.status === 'loading';
	const ready = matching?.status === 'ready';
	if ( ! count && ! matching && ! notice && ! error ) {
		return null;
	}
	const offerAll =
		selection.pageSelected && ! matching && total > selection.ids.length;
	return (
		<div className="bcm-selection">
			{ notice && (
				<Notice
					status="info"
					className="bcm-selection__notice"
					onRemove={ selection.dismissNotice }
				>
					{ notice }
				</Notice>
			) }
			{ error && (
				<Notice
					status="error"
					className="bcm-selection__notice"
					isDismissible={ false }
				>
					{ /^bcm_/.test( error?.code || '' ) && error.message
						? error.message
						: __(
								'All the matching products could not be selected. Try again.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</Notice>
			) }
			{ ( count > 0 || matching ) && (
				<div
					className="bcm-selection__bar"
					role="region"
					aria-label={ __(
						'Selection',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					<span className="bcm-selection__count" aria-live="polite">
						{ loading
							? __(
									'Selecting every matching product…',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: countWords( count, ready, filtered ) }
					</span>
					{ offerAll && (
						<span className="bcm-selection__line">
							<span>{ pageWords( pageCount ) }</span>{ ' ' }
							<Button
								variant="link"
								onClick={ selection.selectAllMatching }
							>
								{ selectAllWords( total, filtered ) }
							</Button>
						</span>
					) }
					{ ready && (
						<Button variant="link" onClick={ selection.selectPage }>
							{ __(
								'Select only this page',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					) }
					<span className="bcm-selection__actions">
						{ onShow && (
							<Button
								variant="secondary"
								size="compact"
								onClick={ onShow }
								disabled={ loading }
							>
								{ __(
									'Show on site',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						) }
						{ onHide && (
							<Button
								variant="secondary"
								size="compact"
								onClick={ onHide }
								disabled={ loading }
							>
								{ __(
									'Hide from site',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						) }
						<Button
							variant="primary"
							size="compact"
							onClick={ onMakeChange }
							disabled={ loading }
						>
							{ __(
								'Make a change to these',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
						<Button variant="link" onClick={ selection.clear }>
							{ __(
								'Clear',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					</span>
				</div>
			) }
		</div>
	);
}
