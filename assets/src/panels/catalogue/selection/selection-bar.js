/**
 * The sticky selection bar (T42, T84; SPEC §4.1): the whole selection's count
 * in words, from the server, whatever the view shows; the offer to add every
 * product matching the view; Review selected products; the Show on site and
 * Hide from site quick actions; Make a change to these; and Clear selection.
 * Above it, the selection's notices: restored after a reload, checked again
 * after an idle spell (drift), no longer kept (lost), a change that could
 * not be confirmed with Try again, and the result once a change finished.
 *
 * The actions stay available while the selection is being saved: the bulk
 * panel reads the live selection and holds Check the change, with the
 * reason, until it has settled, so a quick Make a change is never lost.
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
 * "All 25 on this page are selected."; one row is named by what the grid
 * lists in its mode (ticket 11).
 *
 * @param {number} count Rows on the page.
 * @param {string} mode  The grid's mode: products, variations or products_and_variations.
 * @return {string} Line.
 */
export function pageWords( count, mode = 'products' ) {
	if ( mode === 'variations' ) {
		return sprintf(
			/* translators: %s: the number of variations on this page, such as "25". */
			_n(
				'The %s variation on this page is selected.',
				'All %s on this page are selected.',
				count,
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count )
		);
	}
	if ( mode === 'products_and_variations' ) {
		return sprintf(
			/* translators: %s: the number of products and variations on this page, such as "25". */
			_n(
				'The %s item on this page is selected.',
				'All %s on this page are selected.',
				count,
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count )
		);
	}
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
 * "Select all 1,284 products matching these filters.": the count is of what
 * the grid lists in its mode, so it names variations in the Variations mode
 * and items where products and variations are listed together (ticket 11).
 *
 * @param {number}  total    Rows matching the view.
 * @param {boolean} filtered Whether the view narrows the catalogue.
 * @param {string}  mode     The grid's mode: products, variations or products_and_variations.
 * @return {string} Label.
 */
export function selectAllWords( total, filtered, mode = 'products' ) {
	const words = formatNumber( total );
	if ( mode === 'variations' ) {
		return filtered
			? sprintf(
					/* translators: %s: a number of variations, such as "1,284". */
					_n(
						'Select the %s variation matching these filters.',
						'Select all %s variations matching these filters.',
						total,
						'bulk-catalogue-manager-for-woocommerce'
					),
					words
				)
			: sprintf(
					/* translators: %s: a number of variations, such as "1,284". */
					_n(
						'Select the %s variation in the catalogue.',
						'Select all %s variations in the catalogue.',
						total,
						'bulk-catalogue-manager-for-woocommerce'
					),
					words
				);
	}
	if ( mode === 'products_and_variations' ) {
		return filtered
			? sprintf(
					/* translators: %s: a number of products and variations together, such as "1,284". */
					_n(
						'Select the %s item matching these filters.',
						'Select all %s items matching these filters.',
						total,
						'bulk-catalogue-manager-for-woocommerce'
					),
					words
				)
			: sprintf(
					/* translators: %s: a number of products and variations together, such as "1,284". */
					_n(
						'Select the %s item in the catalogue.',
						'Select all %s items in the catalogue.',
						total,
						'bulk-catalogue-manager-for-woocommerce'
					),
					words
				);
	}
	if ( filtered ) {
		return sprintf(
			/* translators: %s: a number of products, such as "1,284". */
			_n(
				'Select the %s product matching these filters.',
				'Select all %s products matching these filters.',
				total,
				'bulk-catalogue-manager-for-woocommerce'
			),
			words
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
		words
	);
}

/**
 * The line when the session stopped following the change it applied.
 *
 * @return {string} Sentence.
 */
function unwatchedText() {
	return __(
		'We could not check whether your last change has finished, so your selection is kept as it was. Check again, or see the change in History.',
		'bulk-catalogue-manager-for-woocommerce'
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
 * @param {string}        props.mode          The grid's mode, which the count of rows is of (ticket 11).
 * @param {Function}      props.onMakeChange  Open the bulk change panel.
 * @param {Function}      props.onReview      Open the review of the selection.
 * @param {Function|null} props.onShow        Show on site, or null when the registry offers none.
 * @param {Function|null} props.onHide        Hide from site, or null.
 * @param {Function|null} props.onUndo        Undo a finished change by id, or null.
 * @param {Function|null} props.onViewHistory Open a change in History by id, or null.
 * @param {Function|null} props.onRestore     Bring back the selection Clear search released, or null.
 * @param {boolean}       props.draggable     The bar can be dragged onto a category (T65).
 * @return {Element|null} Bar.
 */
export default function SelectionBar( {
	selection,
	pageCount,
	total,
	filtered,
	mode = 'products',
	onMakeChange,
	onReview = null,
	onShow = null,
	onHide = null,
	onUndo = null,
	onViewHistory = null,
	onRestore = null,
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
		unwatched = null,
	} = selection;
	const showBar =
		hasSelection || status === 'saving' || status === 'uncertain';
	if (
		! showBar &&
		! notice &&
		! drift &&
		! lost &&
		! error &&
		! blocked &&
		! unwatched
	) {
		return null;
	}
	const waiting = waitingReason( selection );
	const busy = status !== 'idle';
	const canDrag = draggable && hasSelection && ! busy;
	const offerAll = hasSelection && selection.offerAll && total > 0;
	const doneBatch = notice?.kind === 'done' ? notice.batchId : 0;
	const restorable =
		notice?.kind === 'searchCleared' && notice.restorable && onRestore;
	return (
		<div className="bulkcat-selection">
			{ notice && noticeText( notice, selection ) && (
				// Actions go through `actions` and the words through
				// `spokenMessage`: Notice renders element children to a
				// string, which would run the buttons' hooks inside it.
				<Notice
					key={ `notice-${ notice.kind }-${ !! restorable }` }
					status={ notice.kind === 'cleanup' ? 'warning' : 'info' }
					className="bulkcat-selection__notice"
					spokenMessage={ noticeText( notice, selection ) }
					onRemove={ () => selection.dismiss( 'notice' ) }
					actions={ [
						...( restorable
							? [
									{
										label: __(
											'Restore selection',
											'bulk-catalogue-manager-for-woocommerce'
										),
										onClick: onRestore,
										variant: 'link',
									},
								]
							: [] ),
						...( doneBatch > 0 && onUndo
							? [
									{
										label: __(
											'Undo this change',
											'bulk-catalogue-manager-for-woocommerce'
										),
										onClick: () => onUndo( doneBatch ),
										variant: 'link',
									},
								]
							: [] ),
						...( doneBatch > 0 && onViewHistory
							? [
									{
										label: __(
											'View in History',
											'bulk-catalogue-manager-for-woocommerce'
										),
										onClick: () =>
											onViewHistory( doneBatch ),
										variant: 'link',
									},
								]
							: [] ),
					] }
				>
					{ noticeText( notice, selection ) }
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
			{ unwatched && (
				// T85: the session stopped reading the change it applied after
				// repeated failed reads; it says so and waits to be asked.
				<Notice
					status="warning"
					className="bulkcat-selection__notice"
					isDismissible={ false }
					spokenMessage={ unwatchedText() }
					actions={ [
						{
							label: __(
								'Check again',
								'bulk-catalogue-manager-for-woocommerce'
							),
							onClick: () => selection.checkAgain(),
							variant: 'link',
						},
						...( onViewHistory
							? [
									{
										label: __(
											'View in History',
											'bulk-catalogue-manager-for-woocommerce'
										),
										onClick: () =>
											onViewHistory( unwatched.batchId ),
										variant: 'link',
									},
								]
							: [] ),
					] }
				>
					{ unwatchedText() }
				</Notice>
			) }
			{ error && (
				<Notice
					key={ `error-${ status === 'uncertain' }` }
					status="error"
					className="bulkcat-selection__notice"
					isDismissible={ status !== 'uncertain' }
					spokenMessage={ errorText( error ) }
					onRemove={ () => selection.dismiss( 'error' ) }
					actions={
						status === 'uncertain'
							? [
									{
										label: __(
											'Try again',
											'bulk-catalogue-manager-for-woocommerce'
										),
										onClick: selection.retry,
										variant: 'secondary',
									},
									{
										label: __(
											'Clear selection',
											'bulk-catalogue-manager-for-woocommerce'
										),
										onClick: selection.clear,
										variant: 'link',
									},
								]
							: []
					}
				>
					{ errorText( error ) }
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
								<span>{ pageWords( pageCount, mode ) } </span>
							) }
							<Button
								variant="link"
								onClick={ selection.selectAllMatching }
								disabled={ status === 'uncertain' }
								accessibleWhenDisabled
							>
								{ selectAllWords( total, filtered, mode ) }
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
						// What the panel will wait for; the "Saving…" line and
						// the notices above say it on screen.
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
