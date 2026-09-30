/**
 * The Done step (SPEC §4.3 "After apply", §12.6): progress from
 * GET /batches/{id} until the batch finishes, then the result with Undo and
 * History. A stalled queue is handed to `renderStalled` (T44's notice) when
 * given, else the server's own sentence is shown.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { FINISHED, useBatchProgress } from './hooks';
import { formatNumber, itemsPhrase, productsPhrase } from './words';
import { useProgressAnnouncer } from './announce';

/**
 * The finished batch's headline. Its counts are of products and variations
 * together, so they are items unless the change is known to hold products
 * only (ticket 11).
 *
 * @param {Object}      report Batch report.
 * @param {Object|null} byType `{ products, variations }` the change reached, from the preview, or null.
 * @return {string} Sentence.
 */
export function doneSentence( report, byType = null ) {
	const total = Number( report?.total_items ) || 0;
	const products = !! byType && ! ( Number( byType.variations ) > 0 );
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
				/* translators: 1: products done, such as "1,280"; 2: products in all, such as "1,284 products" or "1,284 items". */
				__(
					'%1$s of %2$s updated. The rest are listed below.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( report.changed_items ?? report.done_items ),
				products ? productsPhrase( total ) : itemsPhrase( total )
			);
		default:
			return products
				? sprintf(
						/* translators: %s: a number, such as "1,284". */
						_n(
							'%s product updated.',
							'%s products updated.',
							total,
							'bulk-catalogue-manager-for-woocommerce'
						),
						formatNumber( total )
					)
				: sprintf(
						/* translators: %s: a number of products and variations together, such as "1,284". */
						_n(
							'%s item updated.',
							'%s items updated.',
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
 * @param {Object}   props.byType        `{ products, variations }` the change reached, from the preview, or null (ticket 11).
 * @param {number}   props.interval      Milliseconds between progress reads.
 * @param {Function} props.onUndo        Receives the batch id, or null.
 * @param {Function} props.onViewHistory Receives the batch id, or null.
 * @param {Function} props.renderStalled Receives the report, or null.
 * @param {Function} props.onFinished    Receives the finished report, once (T84).
 * @param {boolean}  props.replayed      The server answered a change it had already accepted.
 * @param {boolean}  props.earlier       That change was an earlier attempt, resolved before a new one.
 * @return {Element} Done step.
 */
export function DoneStep( {
	batchId,
	count,
	byType = null,
	interval,
	onUndo,
	onViewHistory,
	renderStalled,
	onFinished = null,
	replayed = false,
	earlier = false,
} ) {
	const { report, error } = useBatchProgress( batchId, interval );
	const total = Number( report?.total_items ?? count ) || 0;
	const done = Number( report?.done_items ) || 0;
	const finished = FINISHED.includes( report?.status );
	const percent = total > 0 ? Math.min( 100, ( done / total ) * 100 ) : 0;
	// T75: milestones, not every read; the result below is a status, said once.
	useProgressAnnouncer( done, total, finished );
	const tell = useRef( onFinished );
	tell.current = onFinished;
	const told = useRef( false );
	useEffect( () => {
		if ( finished && ! told.current && tell.current ) {
			told.current = true;
			tell.current( report );
		}
	}, [ finished, report ] );
	let receipt = '';
	if ( earlier ) {
		receipt = __(
			'An earlier attempt to apply a change had gone through, so nothing new was sent. This is that change.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	} else if ( replayed ) {
		receipt = __(
			'This change had already been accepted, so it was not made again. This is its result.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}

	if ( ! finished ) {
		return (
			<section className="bulkcat-bulk__section" data-step="applying">
				<h3 className="bulkcat-bulk__h">
					{ __(
						'Applying the change',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</h3>
				{ receipt && <p className="bulkcat-bulk__hint">{ receipt }</p> }
				<p className="bulkcat-bulk__sub">
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
						<div
							className="bulkcat-bulk__notice is-warn"
							role="alert"
						>
							{ report.stalled_message }
						</div>
					)
				) : (
					<div
						className="bulkcat-bulk__progress"
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
				<p className="bulkcat-bulk__hint">
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
				<p className="bulkcat-bulk__hint">
					{ __(
						'You can leave this page. The update carries on in the background and appears in History when it finishes.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
				{ error && (
					<p className="bulkcat-bulk__hint">
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
		<section className="bulkcat-bulk__section" data-step="done">
			<h3 className="bulkcat-bulk__h">
				{ __( 'Done', 'bulk-catalogue-manager-for-woocommerce' ) }
			</h3>
			<div
				className={
					'bulkcat-bulk__notice ' + ( ok ? 'is-ok' : 'is-warn' )
				}
				role="status"
			>
				<strong>{ doneSentence( report, byType ) }</strong>
				{ receipt && <p>{ receipt }</p> }
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
				<div className="bulkcat-bulk__acts">
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
