/**
 * The Done step (SPEC §4.3 "After apply", §12.6): progress from
 * GET /batches/{id} until the batch finishes, then the result with Undo and
 * History. A stalled queue is handed to `renderStalled` (T44's notice) when
 * given, else the server's own sentence is shown.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import { FINISHED, useBatchProgress } from './hooks';
import { formatNumber, productsPhrase } from './words';

/**
 * The finished batch's headline.
 *
 * @param {Object} report Batch report.
 * @return {string} Sentence.
 */
export function doneSentence( report ) {
	const total = Number( report?.total_items ) || 0;
	switch ( report?.status ) {
		case 'failed':
			return __(
				'The change could not be applied. Nothing more will happen to these products.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'cancelled':
			return __(
				'The change was cancelled. Anything already applied is kept in History, where you can undo it.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'partial':
			return sprintf(
				/* translators: 1: products done, such as "1,280"; 2: products in all, such as "1,284 products". */
				__(
					'%1$s of %2$s updated. The rest are listed below.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( report.changed_items ?? report.done_items ),
				productsPhrase( total )
			);
		default:
			return sprintf(
				/* translators: %s: a number, such as "1,284". */
				_n(
					'%s product updated.',
					'%s products updated.',
					total,
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( total )
			);
	}
}

/**
 * The Done step.
 *
 * @param {Object}   props               Props.
 * @param {number}   props.batchId       The batch.
 * @param {number}   props.count         Products the preview said would change.
 * @param {number}   props.interval      Milliseconds between progress reads.
 * @param {Function} props.onUndo        Receives the batch id, or null.
 * @param {Function} props.onViewHistory Receives the batch id, or null.
 * @param {Function} props.renderStalled Receives the report, or null.
 * @return {Element} Done step.
 */
export function DoneStep( {
	batchId,
	count,
	interval,
	onUndo,
	onViewHistory,
	renderStalled,
} ) {
	const { report, error } = useBatchProgress( batchId, interval );
	const total = Number( report?.total_items ?? count ) || 0;
	const done = Number( report?.done_items ) || 0;
	const finished = FINISHED.includes( report?.status );
	const percent = total > 0 ? Math.min( 100, ( done / total ) * 100 ) : 0;

	if ( ! finished ) {
		return (
			<section className="bcm-bulk__section" data-step="applying">
				<h3 className="bcm-bulk__h">
					{ __(
						'Applying the change',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</h3>
				<p className="bcm-bulk__sub">
					{ sprintf(
						/* translators: %s: a number of products, such as "1,284 products". */
						__(
							'Working through %s in the background.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						productsPhrase( total )
					) }
				</p>
				{ report?.stalled ? (
					( renderStalled && renderStalled( report ) ) || (
						<div className="bcm-bulk__notice is-warn" role="alert">
							{ report.stalled_message }
						</div>
					)
				) : (
					<div
						className="bcm-bulk__progress"
						role="progressbar"
						aria-valuemin={ 0 }
						aria-valuemax={ total }
						aria-valuenow={ done }
						aria-label={ __(
							'Progress',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					>
						<i style={ { width: percent + '%' } } />
					</div>
				) }
				<p className="bcm-bulk__hint" aria-live="polite">
					{ sprintf(
						/* translators: 1: done, such as "312"; 2: in all, such as "1,284". */
						__(
							'%1$s of %2$s done',
							'bulk-catalogue-manager-for-woocommerce'
						),
						formatNumber( done ),
						formatNumber( total )
					) }
				</p>
				<p className="bcm-bulk__hint">
					{ __(
						'You can leave this page. The update carries on in the background and appears in History when it finishes.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
				{ error && (
					<p className="bcm-bulk__hint">
						{ __(
							'The progress could not be read just now. Trying again.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				) }
			</section>
		);
	}

	const ok = [ 'complete', 'partial' ].includes( report.status );
	return (
		<section className="bcm-bulk__section" data-step="done">
			<h3 className="bcm-bulk__h">
				{ __( 'Done', 'bulk-catalogue-manager-for-woocommerce' ) }
			</h3>
			<div
				className={ 'bcm-bulk__notice ' + ( ok ? 'is-ok' : 'is-warn' ) }
				role="status"
			>
				<strong>{ doneSentence( report ) }</strong>
				{ report.selection_difference && (
					<p>{ report.selection_difference }</p>
				) }
				{ ( report.summary || [] ).length > 0 && (
					<ul>
						{ report.summary.map( ( line ) => (
							<li key={ line.message }>
								{ line.sentence || line.message }
							</li>
						) ) }
					</ul>
				) }
				{ ok && (
					<p>
						{ __(
							'The old values are saved, so you can put this back at any time.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				) }
				<div className="bcm-bulk__acts">
					{ onUndo && ok && (
						<button
							type="button"
							className="button button-small"
							onClick={ () => onUndo( batchId ) }
						>
							{ __(
								'Undo this change',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</button>
					) }
					{ onViewHistory && (
						<button
							type="button"
							className="button button-small"
							onClick={ () => onViewHistory( batchId ) }
						>
							{ __(
								'View in History',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</button>
					) }
				</div>
			</div>
		</section>
	);
}
