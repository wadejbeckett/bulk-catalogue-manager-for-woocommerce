/**
 * The sticky selection bar (T42, T84; SPEC §4.1): the whole selection's count
 * in words, from the server, whatever the view shows; the offer to add every
 * product matching the view; Review selected products; the Show on site and
 * Hide from site quick actions; Make a change to these; and Clear selection.
 * Above it, the selection's notices: restored after a reload, checked again
 * after an idle spell (drift), no longer kept (lost), a change that could
 * not be confirmed with Try again, and the result once a change finished.
 */
import { Button, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { SELECTION_DRAG_TYPE } from '../../categories/category-tree';
import { formatNumber } from '../../bulk/words';
import {
	driftText,
	errorText,
	lostText,
	noticeText,
	selectedWords,
	waitingReason,
} from './model';

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
 * @param {Object}        props               Props.
 * @param {Object}        props.selection     useSelection().
 * @param {number}        props.pageCount     Rows on this page.
 * @param {number}        props.total         Rows matching the view.
 * @param {boolean}       props.filtered      Whether the view narrows the catalogue.
 * @param {Function}      props.onMakeChange  Open the bulk change panel.
 * @param {Function}      props.onReview      Open the review of the selection.
 * @param {Function|null} props.onShow        Show on site, or null when the registry offers none.
 * @param {Function|null} props.onHide        Hide from site, or null.
 * @param {Function|null} props.onUndo        Undo a finished change by id, or null.
 * @param {Function|null} props.onViewHistory Open a change in History by id, or null.
 * @param {boolean}       props.draggable     The bar can be dragged onto a category (T65).
 * @return {Element|null} Bar.
 */
export default function SelectionBar( {
	selection,
	pageCount,
	total,
	filtered,
	onMakeChange,
	onReview = null,
	onShow = null,
	onHide = null,
	onUndo = null,
	onViewHistory = null,
	draggable = false,
} ) {
	const {
		count,
		byType,
		status,
		notice,
		drift,
		lost,
		error,
		blocked,
		hasSelection,
	} = selection;
	const showBar =
		hasSelection || status === 'saving' || status === 'uncertain';
	if ( ! showBar && ! notice && ! drift && ! lost && ! error && ! blocked ) {
		return null;
	}
	const waiting = waitingReason( selection );
	const busy = status !== 'idle';
	const canDrag = draggable && hasSelection && ! busy;
	const offerAll = hasSelection && selection.offerAll && total > 0;
	const doneBatch = notice?.kind === 'done' ? notice.batchId : 0;
	return (
		<div className="bulkcat-selection">
			{ notice && noticeText( notice, selection ) && (
				<Notice
					status={ notice.kind === 'cleanup' ? 'warning' : 'info' }
					className="bulkcat-selection__notice"
					onRemove={ () => selection.dismiss( 'notice' ) }
				>
					<p>{ noticeText( notice, selection ) }</p>
					{ doneBatch > 0 && ( onUndo || onViewHistory ) && (
						<p className="bulkcat-selection__acts">
							{ onUndo && (
								<Button
									variant="link"
									onClick={ () => onUndo( doneBatch ) }
								>
									{ __(
										'Undo this change',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</Button>
							) }
							{ onViewHistory && (
								<Button
									variant="link"
									onClick={ () => onViewHistory( doneBatch ) }
								>
									{ __(
										'View in History',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</Button>
							) }
						</p>
					) }
				</Notice>
			) }
			{ drift && (
				<Notice
					status="warning"
					className="bulkcat-selection__notice"
					onRemove={ () => selection.dismiss( 'drift' ) }
				>
					{ driftText() }
				</Notice>
			) }
			{ lost && (
				<Notice
					status="warning"
					className="bulkcat-selection__notice"
					onRemove={ () => selection.dismiss( 'lost' ) }
				>
					{ lostText( lost ) }
				</Notice>
			) }
			{ error && (
				<Notice
					status="error"
					className="bulkcat-selection__notice"
					isDismissible={ status !== 'uncertain' }
					onRemove={ () => selection.dismiss( 'error' ) }
				>
					<p>{ errorText( error ) }</p>
					{ status === 'uncertain' && (
						<p className="bulkcat-selection__acts">
							<Button
								variant="secondary"
								size="compact"
								onClick={ selection.retry }
							>
								{ __(
									'Try again',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
							<Button variant="link" onClick={ selection.clear }>
								{ __(
									'Clear selection',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						</p>
					) }
				</Notice>
			) }
			{ blocked && ! error && (
				<Notice
					status="warning"
					className="bulkcat-selection__notice"
					isDismissible={ false }
				>
					{ waiting }
				</Notice>
			) }
			{ showBar && (
				<div
					className={
						'bulkcat-selection__bar' +
						( canDrag ? ' is-draggable' : '' )
					}
					role="region"
					aria-label={ __(
						'Selection',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					draggable={ canDrag }
					title={
						canDrag
							? __(
									'Drag onto a category to add these products to it.',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: undefined
					}
					onDragStart={ ( event ) => {
						// The tree reads only the type; the catalogue keeps
						// the selection and its scope (categories/README.md).
						event.dataTransfer?.setData(
							SELECTION_DRAG_TYPE,
							String( count )
						);
						if ( event.dataTransfer ) {
							event.dataTransfer.effectAllowed = 'copy';
						}
					} }
				>
					<span
						className="bulkcat-selection__count"
						aria-live="polite"
					>
						{ hasSelection
							? selectedWords( count, byType )
							: __(
									'Saving your selection…',
									'bulk-catalogue-manager-for-woocommerce'
								) }
					</span>
					{ hasSelection && status === 'saving' && (
						<span className="bulkcat-selection__state">
							{ __(
								'Saving…',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</span>
					) }
					{ offerAll && (
						<span className="bulkcat-selection__line">
							{ selection.pageSelected && (
								<span>{ pageWords( pageCount ) } </span>
							) }
							<Button
								variant="link"
								onClick={ selection.selectAllMatching }
								disabled={ status === 'uncertain' }
								accessibleWhenDisabled
							>
								{ selectAllWords( total, filtered ) }
							</Button>
						</span>
					) }
					{ hasSelection && onReview && (
						<Button variant="link" onClick={ onReview }>
							{ __(
								'Review selected products',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					) }
					<span className="bulkcat-selection__actions">
						{ onShow && (
							<Button
								variant="secondary"
								size="compact"
								onClick={ onShow }
								disabled={ busy || ! hasSelection }
								accessibleWhenDisabled
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
								disabled={ busy || ! hasSelection }
								accessibleWhenDisabled
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
							disabled={ busy || ! hasSelection }
							accessibleWhenDisabled
							aria-describedby={
								waiting
									? 'bulkcat-selection-waiting'
									: undefined
							}
						>
							{ __(
								'Make a change to these',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
						<Button variant="link" onClick={ selection.clear }>
							{ __(
								'Clear selection',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					</span>
					{ waiting && (
						// Why the actions wait, for the disabled buttons; the
						// "Saving…" line and the notices above say it on screen.
						<span
							className="bulkcat-sr-only"
							id="bulkcat-selection-waiting"
						>
							{ waiting }
						</span>
					) }
				</div>
			) }
		</div>
	);
}
