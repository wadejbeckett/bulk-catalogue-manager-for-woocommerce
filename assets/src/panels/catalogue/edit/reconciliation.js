/** Single-write outcomes. Current-state reads never stand in for a lost receipt. */
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { panelHref } from '../../../router';
import { productName } from './switch';

/**
 * History is the existing supported Undo entry; following this link only reads.
 *
 * @param {number} id    Product identity.
 * @param {number} batch Known batch identity, if any.
 * @return {string} History URL.
 */
export function historyHref( id, batch ) {
	const url = new URL( panelHref( 'history', window.location.href ) );
	url.searchParams.set( batch ? 'batch' : 'product', String( batch || id ) );
	return String( url );
}

/**
 * Safe, translated mutation outcome, separate from a read failure.
 *
 * @param {Object} outcome     Held outcome.
 * @param {Array}  descriptors Native field registry.
 * @return {string} Sentence.
 */
export function outcomeText( outcome, descriptors ) {
	const name = productName( outcome.item, descriptors );
	if ( outcome.state === 'uncertain' ) {
		return sprintf(
			/* translators: %s: a product name. */
			__(
				'The save outcome for %s is unconfirmed. Check the save outcome or review History before making another change. This page will not resend it.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			name
		);
	}
	if ( outcome.state === 'refused' ) {
		return sprintf(
			/* translators: %s: a product name. */
			__(
				'The change to %s was not saved. Review your input before saving again.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			name
		);
	}
	if ( outcome.state === 'partial' ) {
		return sprintf(
			/* translators: %s: a product name. */
			__(
				'Only part of the change to %s was saved. Review the change in History before continuing.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			name
		);
	}
	if ( outcome.state === 'failed' ) {
		return sprintf(
			/* translators: %s: a product name. */
			__(
				'The change to %s did not complete. Review the change in History before continuing.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			name
		);
	}
	return outcome.refresh?.state === 'failed'
		? sprintf(
				/* translators: %s: a product name. */
				__(
					'Saved changes to %s, but could not refresh the results.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				name
			)
		: sprintf(
				/* translators: %s: a product name. */
				__(
					'Saved changes to %s.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				name
			);
}

/**
 * Native notice controls; recovery never invokes a mutation callback.
 *
 * @param {Object}   props             Properties.
 * @param {Object}   props.outcome     Mutation and read outcomes.
 * @param {Array}    props.descriptors Native registry.
 * @param {Function} props.onRefresh   Read current results/product.
 * @param {Function} props.onRemove    Dismiss a definite result.
 * @return {Element} Notice.
 */
export function SaveNotice( { outcome, descriptors, onRefresh, onRemove } ) {
	const uncertain = outcome.state === 'uncertain';
	const needsRead =
		uncertain || outcome.clearFailed || outcome.refresh?.state === 'failed';
	return (
		<Notice
			spokenMessage={ outcomeText( outcome, descriptors ) }
			status={
				outcome.state === 'saved' && ! needsRead ? 'success' : 'warning'
			}
			isDismissible={ ! uncertain && ! outcome.held }
			onRemove={ onRemove }
		>
			<p>{ outcomeText( outcome, descriptors ) }</p>
			{ outcome.clearFailed && (
				<p>
					{ __(
						'The save outcome is known, but this tab could not release its saved identity. Check the save outcome again before another edit.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ outcome.readFailed && (
				<p>
					{ __(
						'The save outcome or current results could not be read. You can try this read again.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ needsRead && (
				<Button
					variant="secondary"
					isBusy={ outcome.reading }
					aria-disabled={ outcome.reading || undefined }
					onClick={ onRefresh }
				>
					{ uncertain || outcome.clearFailed
						? __(
								'Check save outcome',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: __(
								'Refresh results',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</Button>
			) }{ ' ' }
			<Button
				variant="link"
				href={ historyHref(
					outcome.item.id,
					outcome.answer?.batch_id
				) }
			>
				{ outcome.answer?.batch_id
					? __(
							'View change in History',
							'bulk-catalogue-manager-for-woocommerce'
						)
					: __(
							'Review product History',
							'bulk-catalogue-manager-for-woocommerce'
						) }
			</Button>
		</Notice>
	);
}
