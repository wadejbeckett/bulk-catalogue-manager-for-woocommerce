/**
 * One batch in the history list (SPEC §4.6, §9.4; T57): what, when, who,
 * how many, outcome and reversibility, with its changes expanding in place
 * and the undo flow — preview first, conflicts above everything else and
 * the three-way choice when some products changed since, a plain
 * confirmation sentence, then apply and follow the undo batch to its end.
 *
 * Every state renders from the route's fields: `reversibility.state`
 * (reversible, partially reversible with `conflicts`, in progress,
 * completed with errors, not reversible with `reason`), `stalled` from the
 * single read, and `undo` for an undo batch ("Undo of #418", offered as
 * Redo).
 */
import { Button, Modal } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import Changes from './changes';
import {
	ACTIVE_STATUSES,
	STATES,
	fieldLabel,
	formatAgo,
	formatValue,
	formatWhen,
	reasonSentence,
	rowsLabel,
	sourceLabel,
	statusLabel,
} from './describe';
import ErrorNotice from './error-notice';
import { BatchProgress } from './stalled-notice';
import { userName } from './users';
import {
	applyUndo,
	confirmSentence,
	previewUndo,
	putBackCount,
	readBatch,
	undoHolder,
	undoneNotice,
	valueKind,
} from './undo';
import { isUncertain } from '../../requests';

/** How often a running batch is read again, in milliseconds. */
export const POLL_MS = 3000;

/**
 * Follow a batch while it is being worked: read it every POLL_MS until
 * its status is no longer active, then call onDone with the last report.
 *
 * @param {number|null} batchId Batch to follow, or null.
 * @param {Function}    onDone  Called with the finished report.
 * @return {Object|null} The latest report.
 */
function useFollow( batchId, onDone ) {
	const [ report, setReport ] = useState( null );
	const done = useRef( onDone );
	done.current = onDone;

	useEffect( () => {
		if ( ! batchId ) {
			setReport( null );
			return;
		}
		let live = true;
		let timer = null;
		const read = () => {
			readBatch( batchId )
				.then( ( next ) => {
					if ( ! live ) {
						return;
					}
					setReport( next );
					if ( ACTIVE_STATUSES.includes( next?.status ) ) {
						timer = setTimeout( read, POLL_MS );
					} else {
						done.current( next );
					}
				} )
				.catch( () => {
					if ( live ) {
						timer = setTimeout( read, POLL_MS );
					}
				} );
		};
		read();
		return () => {
			live = false;
			clearTimeout( timer );
		};
	}, [ batchId ] );

	return report;
}

/**
 * The preview's conflicted items: product, field, what this change left
 * and what is there now.
 *
 * @param {Object} props       Props.
 * @param {Object} props.plan  The undo preview.
 * @param {Object} props.byKey Field descriptors by key.
 * @return {Element} Table.
 */
function ConflictTable( { plan, byKey } ) {
	const rows = [];
	( plan?.items || [] )
		.filter( ( item ) => item.state === 'conflicted' )
		.forEach( ( item ) => {
			( item.conflicts || [] ).forEach( ( conflict, index ) => {
				rows.push( {
					key: `${ item.object_id }-${ index }`,
					name: item.name,
					...conflict,
				} );
			} );
		} );
	return (
		<div className="bulkcat-changes bulkcat-conflict-table">
			<div className="bulkcat-changes__scroll">
				<table className="bulkcat-changes__table">
					<caption>
						{ __(
							'The products edited after this change',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __(
									'Product',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'Field',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'This change left',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'Now',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.key }>
								<th scope="row">{ row.name }</th>
								<td>
									{ fieldLabel(
										row.field,
										byKey,
										row.field_label
									) }
								</td>
								<td>
									{ formatValue(
										row.expected,
										byKey[ row.field ]
									) }
								</td>
								<td>
									{ formatValue(
										row.current,
										byKey[ row.field ]
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}

/**
 * Who put an undone batch back, and in which change: from the row's
 * `undone_by` `{ batch_id, user_id, user_name }`.
 *
 * @param {Object|null} undoneBy The row's `undone_by`.
 * @return {string} Sentence, or '' when the row was not put back.
 */
export function putBackBy( undoneBy ) {
	if ( ! undoneBy ) {
		return '';
	}
	const who = undoneBy.user_name
		? String( undoneBy.user_name )
		: userName( undoneBy.user_id, {} );
	if ( ! undoneBy.batch_id ) {
		return sprintf(
			/* translators: %s: a user's name. */
			__( 'Put back by %s', 'bulk-catalogue-manager-for-woocommerce' ),
			who
		);
	}
	return sprintf(
		/* translators: 1: a user's name; 2: the undo change's number, such as "42". */
		__(
			'Put back by %1$s in change #%2$s',
			'bulk-catalogue-manager-for-woocommerce'
		),
		who,
		String( undoneBy.batch_id )
	);
}

/**
 * The card.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.batch     A GET /batches row.
 * @param {string}   props.what      What it did, in words.
 * @param {string}   props.who       Who did it.
 * @param {Object}   props.byKey     Field descriptors by key.
 * @param {Function} props.onChanged The list should be read again.
 * @param {Function} props.onNotice  Show a screen notice `{ status, text }`.
 * @param {Object}   props.linked    `{ id, undo }` when the URL names this batch (T75).
 * @return {Element} Card.
 */
export default function BatchCard( {
	batch,
	what,
	who,
	byKey,
	onChanged,
	onNotice,
	linked = null,
} ) {
	const state = batch.reversibility?.state || STATES.REVERSIBLE;
	const isRedo = batch.source === 'undo';
	const [ open, setOpen ] = useState( false );
	const [ plan, setPlan ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ seeConflicts, setSeeConflicts ] = useState( false );
	const [ confirm, setConfirm ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ running, setRunning ] = useState( null );
	const [ details, setDetails ] = useState( null );
	const undoButton = useRef( null );
	const cardRef = useRef( null );
	// T84: the undo's grant, kept until its answer is definite.
	const grant = undoHolder( batch.batch_id );
	const [ uncertain, setUncertain ] = useState( null );

	// A batch in progress is followed until it finishes, then the list is read again.
	const progress = useFollow(
		state === STATES.IN_PROGRESS && ! running ? batch.batch_id : null,
		useCallback( () => onChanged(), [ onChanged ] )
	);

	// The undo batch this card started, followed to its end.
	const undoReport = useFollow(
		running,
		useCallback(
			( finished ) => {
				setRunning( null );
				const ok = [ 'complete', 'partial' ].includes(
					finished?.status
				);
				onNotice( {
					status: ok ? 'success' : 'error',
					text: ok
						? undoneNotice( what, finished, isRedo )
						: __(
								'The undo did not finish, so nothing more was put back. Open its row in the history to see why.',
								'bulk-catalogue-manager-for-woocommerce'
							),
				} );
				onChanged();
			},
			[ what, isRedo, onChanged, onNotice ]
		)
	);

	// A batch with errors shows its grouped reasons, from the single read.
	useEffect( () => {
		if ( state !== STATES.WITH_ERRORS || ! open || details ) {
			return;
		}
		let live = true;
		readBatch( batch.batch_id )
			.then( ( report ) => live && setDetails( report ) )
			.catch( () => {} );
		return () => {
			live = false;
		};
	}, [ state, open, details, batch.batch_id ] );

	const startUndo = async () => {
		setBusy( true );
		setError( null );
		try {
			const preview = await previewUndo( batch.batch_id );
			setPlan( preview );
			if ( ( Number( preview?.counts?.conflicted ) || 0 ) === 0 ) {
				setConfirm( { force: false } );
			}
		} catch ( caught ) {
			setError( caught );
		} finally {
			setBusy( false );
		}
	};

	const cancelUndo = () => {
		setPlan( null );
		setConfirm( null );
		setSeeConflicts( false );
		setUncertain( null );
		undoButton.current?.focus();
	};

	// The durable undo (T84). An unknown outcome keeps the plan and offers
	// Try again, which sends the same grant; a refusal ends this attempt, so
	// Undo starts again from a new preview.
	const send = async ( force ) => {
		setConfirm( null );
		setBusy( true );
		setError( null );
		setUncertain( null );
		try {
			const answer = await applyUndo(
				grant,
				batch.batch_id,
				plan,
				force
			);
			setPlan( null );
			setSeeConflicts( false );
			setRunning( answer?.batch_id || null );
			// Undo gives way to the undo's progress, so focus goes to the
			// batch's card, which shows it, rather than to the page (§14, T75).
			window.requestAnimationFrame( () => cardRef.current?.focus() );
		} catch ( caught ) {
			setError( caught );
			if ( isUncertain( caught ) ) {
				setUncertain( { force } );
			} else {
				setPlan( null );
				setSeeConflicts( false );
			}
			undoButton.current?.focus();
		} finally {
			setBusy( false );
		}
	};
	const apply = () => send( !! confirm?.force );

	const conflicted = Number( plan?.counts?.conflicted ) || 0;
	const revertible = Number( plan?.counts?.revertible ) || 0;
	const knownConflicts =
		plan !== null ? conflicted : Number( batch.reversibility?.conflicts );
	const inProgress = state === STATES.IN_PROGRESS || !! running;
	const settled =
		batch.status === 'undone' ||
		batch.reversibility?.reason === 'already_undone';
	const undoable =
		! inProgress &&
		state !== STATES.NOT_REVERSIBLE &&
		batch.status !== 'undone';

	// T75: the batch the bulk panel linked to is brought into view and
	// focused; with `undo`, its undo preview starts once it can be undone.
	const autoUndone = useRef( false );
	useEffect( () => {
		if ( linked ) {
			cardRef.current?.focus();
		}
	}, [ linked ] );
	useEffect( () => {
		if ( linked?.undo && undoable && ! autoUndone.current ) {
			autoUndone.current = true;
			startUndo();
		}
		// startUndo reads the batch as it is now.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ linked, undoable ] );

	return (
		<article
			ref={ cardRef }
			tabIndex={ -1 }
			className={
				'bulkcat-batch' +
				( settled ? ' is-undone' : '' ) +
				( linked ? ' is-linked' : '' )
			}
			aria-labelledby={ `bulkcat-batch-${ batch.batch_id }` }
		>
			<div className="bulkcat-batch__top">
				<div className="bulkcat-batch__what">
					<h3 id={ `bulkcat-batch-${ batch.batch_id }` }>{ what }</h3>
					<p className="bulkcat-batch__meta">
						{ formatWhen( batch.created_gmt ) }
						{ ' · ' }
						<span className="bulkcat-batch__who">{ who }</span>
						{ ' · ' }
						{ sourceLabel( batch.source ) }
						{ ' · ' }
						{ sprintf(
							/* translators: %s: the change's number, such as "418". */
							__(
								'#%s',
								'bulk-catalogue-manager-for-woocommerce'
							),
							String( batch.batch_id )
						) }
						{ formatAgo( batch.created_gmt ) &&
							' · ' + formatAgo( batch.created_gmt ) }
					</p>
				</div>
				<div className="bulkcat-batch__actions">
					<Button
						variant="secondary"
						size="small"
						className="bulkcat-batch__see"
						aria-expanded={ open }
						onClick={ () => setOpen( ( was ) => ! was ) }
					>
						{ open
							? __(
									'Hide the changes',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: __(
									'See every change',
									'bulk-catalogue-manager-for-woocommerce'
								) }
					</Button>
					{ ! inProgress && (
						<Button
							ref={ undoButton }
							variant="secondary"
							size="small"
							disabled={ ! undoable || busy || plan !== null }
							accessibleWhenDisabled
							onClick={ startUndo }
						>
							{ settled &&
								__(
									'Undone',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							{ ! settled &&
								isRedo &&
								__(
									'Redo',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							{ ! settled &&
								! isRedo &&
								__(
									'Undo',
									'bulk-catalogue-manager-for-woocommerce'
								) }
						</Button>
					) }
				</div>
			</div>

			<div className="bulkcat-batch__tags">
				<span className="bulkcat-pill">
					{ rowsLabel( batch.total_items ) }
				</span>
				<span
					className={ `bulkcat-pill bulkcat-pill--${ batch.status }` }
				>
					{ statusLabel( batch.status ) }
				</span>
				{ state === STATES.PARTIAL && knownConflicts > 0 && (
					<span className="bulkcat-pill bulkcat-pill--warn">
						{ sprintf(
							/* translators: %s: number of products changed again since, such as "3". */
							__(
								'%s changed since',
								'bulk-catalogue-manager-for-woocommerce'
							),
							knownConflicts.toLocaleString()
						) }
					</span>
				) }
				{ state === STATES.WITH_ERRORS && (
					<span className="bulkcat-pill bulkcat-pill--warn">
						{ sprintf(
							/* translators: %s: number of products that failed, such as "3". */
							_n(
								'%s failed',
								'%s failed',
								Number( batch.failed_items ) || 0,
								'bulk-catalogue-manager-for-woocommerce'
							),
							(
								Number( batch.failed_items ) || 0
							).toLocaleString()
						) }
					</span>
				) }
				{ batch.variations_included && (
					<span className="bulkcat-pill">
						{ __(
							'Includes variations',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</span>
				) }
				{ putBackBy( batch.undone_by ) && (
					<span className="bulkcat-pill bulkcat-batch__put-back">
						{ putBackBy( batch.undone_by ) }
					</span>
				) }
				{ batch.undo?.sentence && (
					<span className="bulkcat-pill bulkcat-pill--warn">
						{ batch.undo.sentence }
					</span>
				) }
			</div>

			{ state === STATES.NOT_REVERSIBLE && ! settled && (
				<p className="bulkcat-batch__reason">
					{ reasonSentence(
						batch.reversibility?.reason,
						batch.reversibility?.provider || ''
					) }
				</p>
			) }

			{ batch.selection_difference && (
				<p className="bulkcat-batch__reason">
					{ batch.selection_difference }
				</p>
			) }

			{ inProgress && (
				<div className="bulkcat-batch__progress">
					<BatchProgress
						report={ ( running ? undoReport : progress ) || batch }
					/>
				</div>
			) }

			{ error && (
				<div className="bulkcat-batch__error">
					<ErrorNotice
						error={ error }
						message={
							uncertain
								? __(
										'We could not confirm whether the undo started. Try again: the same request is sent again, so it can only happen once.',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: ''
						}
						fallback={ __(
							'The undo could not be started. Reload the page and try again.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						onRemove={ uncertain ? null : () => setError( null ) }
					/>
					{ uncertain && plan && (
						<p className="bulkcat-batch__retry">
							<Button
								variant="secondary"
								size="small"
								disabled={ busy }
								onClick={ () => send( uncertain.force ) }
							>
								{ __(
									'Try again',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
							<Button variant="link" onClick={ cancelUndo }>
								{ __(
									'Cancel',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						</p>
					) }
				</div>
			) }

			{ plan && conflicted > 0 && ! confirm && (
				<div
					className="bulkcat-conflict"
					role="region"
					aria-label={ __(
						'Products changed since',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					<h4>
						{ sprintf(
							/* translators: %s: number of products changed again since, such as "3". */
							_n(
								'%s of these products has changed since',
								'%s of these products have changed since',
								conflicted,
								'bulk-catalogue-manager-for-woocommerce'
							),
							conflicted.toLocaleString()
						) }
					</h4>
					<p>
						{ plan.sentence ||
							__(
								'Someone edited some of these products after this change was made. Undoing them would overwrite that later work.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
					</p>
					<div className="bulkcat-conflict__choices">
						<Button
							variant="primary"
							size="small"
							disabled={ revertible === 0 }
							accessibleWhenDisabled
							onClick={ () => setConfirm( { force: false } ) }
						>
							{ sprintf(
								/* translators: %s: number of products that can go back safely, such as "117". */
								__(
									'Undo the other %s',
									'bulk-catalogue-manager-for-woocommerce'
								),
								revertible.toLocaleString()
							) }
						</Button>
						<Button
							variant="secondary"
							size="small"
							aria-expanded={ seeConflicts }
							onClick={ () =>
								setSeeConflicts( ( was ) => ! was )
							}
						>
							{ sprintf(
								/* translators: %s: number of products changed again since, such as "3". */
								__(
									'See the %s that changed',
									'bulk-catalogue-manager-for-woocommerce'
								),
								conflicted.toLocaleString()
							) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							size="small"
							onClick={ () => setConfirm( { force: true } ) }
						>
							{ sprintf(
								/* translators: %s: number of products, such as "120". */
								__(
									'Undo all %s anyway',
									'bulk-catalogue-manager-for-woocommerce'
								),
								( revertible + conflicted ).toLocaleString()
							) }
						</Button>
						<Button variant="link" onClick={ cancelUndo }>
							{ __(
								'Cancel',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					</div>
				</div>
			) }
			{ plan && conflicted > 0 && seeConflicts && (
				<ConflictTable plan={ plan } byKey={ byKey } />
			) }

			{ confirm && plan && (
				<Modal
					title={
						isRedo
							? __(
									'Redo this change?',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: __(
									'Undo this change?',
									'bulk-catalogue-manager-for-woocommerce'
								)
					}
					onRequestClose={ cancelUndo }
					size="small"
				>
					<p>
						{ confirmSentence(
							putBackCount( plan, confirm.force ),
							valueKind( batch, byKey )
						) }
					</p>
					{ confirm.force && conflicted > 0 && (
						<p className="bulkcat-confirm__warning">
							{ sprintf(
								/* translators: %s: number of products changed again since, such as "3". */
								_n(
									'This includes %s product someone edited after this change; that later edit will be overwritten.',
									'This includes %s products someone edited after this change; those later edits will be overwritten.',
									conflicted,
									'bulk-catalogue-manager-for-woocommerce'
								),
								conflicted.toLocaleString()
							) }
						</p>
					) }
					{ ! confirm.force && conflicted > 0 && (
						<p>
							{ sprintf(
								/* translators: %s: number of products changed again since, such as "3". */
								_n(
									'The %s product that changed since will be left alone.',
									'The %s products that changed since will be left alone.',
									conflicted,
									'bulk-catalogue-manager-for-woocommerce'
								),
								conflicted.toLocaleString()
							) }
						</p>
					) }
					<div className="bulkcat-confirm__buttons">
						<Button variant="tertiary" onClick={ cancelUndo }>
							{ __(
								'Cancel',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
						<Button
							variant="primary"
							isDestructive={ confirm.force }
							onClick={ apply }
						>
							{ isRedo
								? __(
										'Redo',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: __(
										'Undo',
										'bulk-catalogue-manager-for-woocommerce'
									) }
						</Button>
					</div>
				</Modal>
			) }

			{ open &&
				state === STATES.WITH_ERRORS &&
				details?.summary?.length > 0 && (
					<ul className="bulkcat-batch__reasons">
						{ details.summary.map( ( reason ) => (
							<li key={ reason.message }>{ reason.sentence }</li>
						) ) }
					</ul>
				) }
			{ open && (
				<Changes
					batch={ batch }
					byKey={ byKey }
					problemsOnly={ state === STATES.WITH_ERRORS }
					onNotice={ onNotice }
				/>
			) }
		</article>
	);
}
