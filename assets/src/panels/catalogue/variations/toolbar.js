/**
 * The controls around the table for variations: the Products | Variations
 * mode switch, Open all with variations, the notice while one product's
 * options are listed (Show all), and the count line under the table, which
 * counts options in Variations mode.
 */
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { MODES } from './registry';
import { formatNumber } from '../../bulk/words';

/**
 * The mode switch and Open all.
 *
 * @param {Object}   props              Props.
 * @param {string}   props.mode         MODES value.
 * @param {Function} props.onChangeMode Mode change.
 * @param {boolean}  props.canOpenAll   The page has a variable row.
 * @param {boolean}  props.anyClosed    Some variable row is closed.
 * @param {Function} props.onOpenAll    Open all, or close all.
 * @return {Element} Bar.
 */
export function ModeBar( {
	mode,
	onChangeMode,
	canOpenAll,
	anyClosed,
	onOpenAll,
} ) {
	const flat = mode === MODES.variations;
	return (
		<div className="bulkcat-variations__bar">
			<div
				className="bulkcat-segmented"
				role="group"
				aria-label={ __(
					'What the list shows',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				<button
					type="button"
					aria-pressed={ ! flat }
					onClick={ () => onChangeMode( MODES.products ) }
				>
					{ __(
						'Products',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</button>
				<button
					type="button"
					aria-pressed={ flat }
					onClick={ () => onChangeMode( MODES.variations ) }
				>
					{ __(
						'Variations',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</button>
			</div>
			{ canOpenAll && (
				<Button variant="tertiary" size="compact" onClick={ onOpenAll }>
					{ anyClosed
						? __(
								'Open all with variations',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: __(
								'Close all options',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</Button>
			) }
		</div>
	);
}

/**
 * The notice while Variations mode lists one product's options.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.focus   { id, name, total }.
 * @param {boolean}  props.failed  The read failed.
 * @param {Function} props.onClear Back to every option.
 * @return {Element} Notice.
 */
export function FocusNotice( { focus, failed, onClear } ) {
	return (
		<p className="bulkcat-catalogue__chip bulkcat-variations__focus">
			<span>
				{ failed
					? __(
							'The options could not be loaded.',
							'bulk-catalogue-manager-for-woocommerce'
						)
					: sprintf(
							/* translators: %s: product name. */
							__(
								'Showing the options of %s.',
								'bulk-catalogue-manager-for-woocommerce'
							),
							focus.name
						) }
			</span>
			<Button variant="link" onClick={ onClear }>
				{ __(
					'Show every option',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</Button>
		</p>
	);
}

/**
 * The count under the table: products, or options in Variations mode.
 *
 * @param {Object} props       Props.
 * @param {string} props.mode  MODES value.
 * @param {number} props.total Matching rows.
 * @return {Element} Line.
 */
export function CountLine( { mode, total } ) {
	const shown = formatNumber( total );
	let text;
	if ( mode === MODES.variations ) {
		text = sprintf(
			/* translators: %s: how many options (variations) match. */
			_n(
				'%s option',
				'%s options',
				total,
				'bulk-catalogue-manager-for-woocommerce'
			),
			shown
		);
	} else if ( mode === MODES.both ) {
		text = sprintf(
			/* translators: %s: how many products and options match. */
			_n(
				'%s product or option',
				'%s products and options',
				total,
				'bulk-catalogue-manager-for-woocommerce'
			),
			shown
		);
	} else {
		text = sprintf(
			/* translators: %s: how many products match. */
			_n(
				'%s product',
				'%s products',
				total,
				'bulk-catalogue-manager-for-woocommerce'
			),
			shown
		);
	}
	return <p className="bulkcat-variations__count">{ text }</p>;
}
