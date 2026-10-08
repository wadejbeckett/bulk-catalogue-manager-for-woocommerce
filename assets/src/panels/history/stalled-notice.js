/**
 * The stalled queue notice (SPEC §4.8, §12.6; T44). When GET
 * /batches/{id} reports `stalled`, the background queue has not run for
 * five minutes while work is pending: the screen says so, says how much
 * has been applied (nothing, when `done_items` is still zero), and points
 * at WooCommerce → Status → Scheduled Actions instead of showing progress
 * that is not happening. The count is static text, never animated.
 *
 * The queued state (1.4.3, delivery ticket 30): until the first item is
 * done the report says `queued`, and the progress reads "Queued, starts
 * within a minute" with an indeterminate bar instead of "0 of N done",
 * which read as stuck during the up-to-a-minute wait for the background
 * queue's next run. The count takes over with the first item done, and
 * the stalled notice still takes over after five minutes without a claim.
 *
 * Exported for the bulk panel's progress banner as well as the History
 * row: `BatchProgress` shows the queued line, the progress bar or the
 * stalled notice.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatNumber } from '../bulk/words';

/** WooCommerce → Status → Scheduled Actions, relative to wp-admin. */
export const SCHEDULED_ACTIONS_URL =
	'admin.php?page=wc-status&tab=action-scheduler';

/**
 * Inline work has no queue executor or timeout takeover, even in legacy rows.
 *
 * @param {Object} report Batch report.
 * @return {boolean} Whether its original invocation is still unconfirmed.
 */
export function isUnclosedInline( report ) {
	if ( report?.inline_receipt ) {
		return report.inline_receipt.receipt_state !== 'closed';
	}
	return (
		report?.source === 'inline' &&
		( ! report.finished_gmt ||
			[ 'queued', 'running' ].includes( report.status ) )
	);
}

function InlineNotice() {
	return (
		<div className="notice notice-warning inline">
			<p>
				{ __(
					'The save outcome is unconfirmed. This was an individual save, so background tasks cannot resume it. Check the save outcome in the catalogue or review the recorded changes here. Do not resend, cancel or undo it while its outcome is unconfirmed.',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</p>
		</div>
	);
}

/**
 * Whether a batch report says the queue has stalled.
 *
 * @param {Object} report A GET /batches/{id} answer.
 * @return {boolean} Stalled.
 */
export function isStalled( report ) {
	return ! isUnclosedInline( report ) && report?.stalled === true;
}

/**
 * Whether a batch report says the background queue has not picked the
 * change up yet (no item done, not stalled).
 *
 * @param {Object} report A GET /batches/{id} answer.
 * @return {boolean} Queued.
 */
export function isQueued( report ) {
	return (
		! isUnclosedInline( report ) &&
		report?.queued === true &&
		report?.stalled !== true
	);
}

/**
 * The queued line: the server's sentence, or the same words when a report
 * carries the flag without it.
 *
 * @param {Object} report A GET /batches/{id} answer.
 * @return {string} Sentence.
 */
export function queuedSentence( report ) {
	return (
		report?.queued_message ||
		__(
			'Queued, starts within a minute. Nothing has changed yet: the site’s background tasks pick new changes up on their next run.',
			'bulk-catalogue-manager-for-woocommerce'
		)
	);
}

/**
 * The short queued label, for the bar's text.
 *
 * @return {string} Label.
 */
export function queuedLabel() {
	return __(
		'Queued, starts within a minute',
		'bulk-catalogue-manager-for-woocommerce'
	);
}

/**
 * The notice for a stalled batch.
 *
 * @param {Object} props        Props.
 * @param {Object} props.report The batch report (`stalled`, `stalled_message`, `done_items`, `total_items`).
 * @return {Element} Notice.
 */
export function StalledNotice( { report } ) {
	if ( isUnclosedInline( report ) ) {
		return <InlineNotice />;
	}
	const done = Number( report?.done_items ) || 0;
	const total = Number( report?.total_items ) || 0;
	return (
		<div className="notice notice-warning inline bulkcat-stalled">
			<p>
				<strong>
					{ __(
						'The background queue has not run.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</strong>{ ' ' }
				{ report?.stalled_message ||
					__(
						'This change has not moved for five minutes because the site’s background tasks are not running.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
			</p>
			<p className="bulkcat-stalled__count">
				{ done === 0
					? __(
							'Nothing has been applied yet.',
							'bulk-catalogue-manager-for-woocommerce'
						)
					: sprintf(
							/* translators: 1: products done so far, such as "40"; 2: products in the change, such as "1,248". */
							_n(
								'%1$s of %2$s product has been applied; nothing more will change until the queue runs.',
								'%1$s of %2$s products have been applied; nothing more will change until the queue runs.',
								total,
								'bulk-catalogue-manager-for-woocommerce'
							),
							formatNumber( done ),
							formatNumber( total )
						) }
			</p>
			<p>
				<a href={ SCHEDULED_ACTIONS_URL }>
					{ __(
						'Open WooCommerce → Status → Scheduled Actions',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</a>
			</p>
		</div>
	);
}

/**
 * A running batch's progress: "Queued, starts within a minute" with an
 * indeterminate bar until the first item is done, then a bar with
 * "40 of 1,248 done", or the stalled notice in its place.
 *
 * @param {Object} props        Props.
 * @param {Object} props.report The batch report.
 * @return {Element} Progress.
 */
export function BatchProgress( { report } ) {
	if ( isUnclosedInline( report ) ) {
		return <InlineNotice />;
	}
	if ( isStalled( report ) ) {
		return <StalledNotice report={ report } />;
	}
	const done = Number( report?.done_items ) || 0;
	const total = Number( report?.total_items ) || 0;
	if ( isQueued( report ) ) {
		const label = queuedLabel();
		return (
			<div className="bulkcat-progress is-queued">
				<progress
					className="bulkcat-progress__bar"
					max={ Math.max( 1, total ) }
					aria-valuetext={ label }
					aria-label={ __(
						'Progress of the change',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				/>
				<span
					className="bulkcat-progress__text"
					title={ queuedSentence( report ) }
				>
					{ label }
				</span>
			</div>
		);
	}
	const text = sprintf(
		/* translators: 1: products done so far, such as "40"; 2: products in the change, such as "1,248". */
		__( '%1$s of %2$s done', 'bulk-catalogue-manager-for-woocommerce' ),
		formatNumber( done ),
		formatNumber( total )
	);
	return (
		<div className="bulkcat-progress">
			<progress
				className="bulkcat-progress__bar"
				max={ Math.max( 1, total ) }
				value={ done }
				aria-valuetext={ text }
				aria-label={ __(
					'Progress of the change',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			/>
			<span className="bulkcat-progress__text">{ text }</span>
		</div>
	);
}

export default StalledNotice;
