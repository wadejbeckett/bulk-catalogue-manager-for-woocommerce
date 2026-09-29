/**
 * The bulk action panel (SPEC §4.3, T49): a slide-over on the right, never a
 * modal hiding the rows it is about to change, holding the four steps on one
 * scrollable form — the scope, the ordered operation list built from the
 * action descriptors, the preview (§4.4, T50) and Apply under the shared
 * confirmation policy (§4.5, T48) — then the Done step with progress.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	ConfirmApply,
	DEFAULT_THRESHOLD,
	alwaysConfirmReasons,
} from './confirm';
import {
	MAX_OPERATIONS,
	fieldByKey,
	offersVariations,
	operationProblem,
	settingsValues,
	toRequestOperations,
} from './descriptors';
import {
	PREVIEW_STALE,
	RENEW_REQUIRED,
	selectionArgs,
	useApply,
	usePreview,
} from './hooks';
import {
	ActionChooser,
	OperationSummary,
	changeArg,
	chooseAction,
	emptyOperation,
} from './operations';
import { PreviewResult } from './preview';
import { DoneStep } from './progress';
import { formatNumber } from './words';
import { countPhrase } from '../catalogue/selection/model';
import './style.scss';

/** Elements a Tab press may land on inside the panel. */
const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * The sentence in place of the undo promise for a change larger than this
 * host can undo from the screen (T85; Undo\Capacity).
 *
 * @param {number} limit The most products and variations an undo here can cover.
 * @return {string} Sentence.
 */
export function undoTooLarge( limit ) {
	return sprintf(
		/* translators: %s: the most products and variations a change may cover and still be undone from this screen, such as "27,304". */
		__(
			'Every change is saved with the old values, but undoing a change to more than %s products and variations is not yet supported from this screen.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( limit )
	);
}

/**
 * How many products and variations a selection holds.
 *
 * @param {Object} selection Panel selection.
 * @return {number} Count.
 */
export function selectionCount( selection ) {
	return Number( selection?.count ?? selection?.ids?.length ) || 0;
}

/**
 * The scope line (SPEC §4.3): the total exact selection, "24 selected
 * products", or "1,200 products and 50 variations selected" when it holds
 * both. A combined selection is never called all matches of a filter.
 *
 * @param {Object} selection Panel selection.
 * @return {string} Line.
 */
export function scopeLine( selection ) {
	const count = selectionCount( selection );
	const byType = selection?.byType || {};
	if ( Number( byType.variations ) > 0 ) {
		return sprintf(
			/* translators: %s: what is selected, such as "1,200 products and 50 variations". */
			__( '%s selected', 'bulk-catalogue-manager-for-woocommerce' ),
			countPhrase( count, byType )
		);
	}
	return sprintf(
		/* translators: %s: a number of products, such as "24". */
		_n(
			'%s selected product',
			'%s selected products',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( count )
	);
}

/**
 * The first reason the change cannot be checked yet, or ''.
 *
 * @param {Object}        registry   The `/fields` payload.
 * @param {Array<Object>} operations Panel operations.
 * @param {number}        count      Products selected.
 * @param {string}        waiting    Why the selection is not settled yet (T84), or ''.
 * @return {string} Reason.
 */
export function blockedReason( registry, operations, count, waiting = '' ) {
	if ( waiting ) {
		return waiting;
	}
	if ( count < 1 ) {
		return __(
			'Nothing is selected. Go back to Products and tick what you want to change.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	if ( ! operations.some( ( operation ) => operation.action ) ) {
		return __(
			'Choose a change first.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	for ( let index = 0; index < operations.length; index++ ) {
		const operation = operations[ index ];
		if ( ! operation.action ) {
			return sprintf(
				/* translators: %d: the step's position in the list, such as 2. */
				__(
					'Step %d has no change chosen yet. Choose one or remove the step.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				index + 1
			);
		}
		const problem = operationProblem( registry, operation );
		if ( problem ) {
			return sprintf(
				/* translators: 1: the step's position, such as 2; 2: what to fix, such as "Enter how much to change it by, as a number above 0 like 10 or 2.5." */
				__(
					'Step %1$d: %2$s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				index + 1,
				problem.reason
			);
		}
	}
	return '';
}

/**
 * The panel state of §4.3.
 *
 * @param {Object} facts Facts about the panel.
 * @return {string} idle, configuring, blocked, previewing, ready, empty, stale, applying or done.
 */
export function panelState( facts ) {
	if ( facts.batchId ) {
		return 'done';
	}
	if ( facts.applying ) {
		return 'applying';
	}
	if ( facts.computing ) {
		return 'previewing';
	}
	if ( ! facts.chosen ) {
		return 'idle';
	}
	if ( facts.blocked ) {
		return 'blocked';
	}
	if ( facts.stale ) {
		return 'stale';
	}
	if ( facts.current ) {
		return facts.changes > 0 ? 'ready' : 'empty';
	}
	return 'configuring';
}

/**
 * The bulk action panel.
 *
 * @param {Object}        props                   Props (see README.md).
 * @param {Object}        props.selection         `{ kind: 'snapshot', token, key, count, byType, waiting }` or `{ kind: 'ids', ids }`.
 * @param {Object}        props.registry          The `/fields` payload `{ fields, actions, groups }`.
 * @param {Object}        props.settings          The `bulkcat_settings` values (or the screen settings carrying them).
 * @param {Function}      props.onClose           Close the panel.
 * @param {Function}      props.onApplied         Receives the new batch id.
 * @param {Array<Object>} props.categories        Category tree for the terms picker.
 * @param {Array<Object>} props.tags              Tags for the terms picker.
 * @param {Array<Object>} props.shippingClasses   Shipping classes for a set.
 * @param {Function}      props.onUndo            Receives a batch id: Undo this change.
 * @param {Function}      props.onViewHistory     Receives a batch id: View in History.
 * @param {Function}      props.onDownloadSample  Receives the preview request: Download sample CSV.
 * @param {Function}      props.renderStalled     Receives a stalled batch report (T44).
 * @param {number}        props.pollInterval      Milliseconds between progress reads.
 * @param {Array<Object>} props.initialOperations Steps to open with (the selection bar's quick actions, T42).
 * @param {Function}      props.onFinished        Receives the finished batch's report (T84).
 * @param {Function}      props.onReview          Open Review selected products, or null.
 * @param {Function}      props.onRenewSelection  Restore a selection whose lease lapsed, or null.
 * @return {Element} Panel.
 */
export default function BulkChangePanel( {
	selection,
	registry,
	settings,
	onClose = () => {},
	onApplied = () => {},
	categories = [],
	tags = [],
	shippingClasses = [],
	onUndo = null,
	onViewHistory = null,
	onDownloadSample = null,
	renderStalled = null,
	pollInterval = 2000,
	initialOperations = null,
	onFinished = null,
	onReview = null,
	onRenewSelection = null,
} ) {
	const panelRef = useRef( null );
	const headingRef = useRef( null );
	const checkRef = useRef( null );
	const opening = Array.isArray( initialOperations ) ? initialOperations : [];
	const nextKey = useRef( Math.max( opening.length, 1 ) + 1 );
	const [ operations, setOperations ] = useState( () =>
		opening.length
			? opening.map( ( operation, index ) => ( {
					...operation,
					key: index + 1,
				} ) )
			: [ emptyOperation( 1 ) ]
	);
	const [ active, setActive ] = useState( 0 );
	const [ sampleSize, setSampleSize ] = useState( 25 );
	const [ changedOnly, setChangedOnly ] = useState( false );
	const [ batch, setBatch ] = useState( null );
	// What the change was applied to: the live selection moves on (a change
	// that finished in full clears it), the Done step's scope does not.
	const [ applied, setApplied ] = useState( null );
	const [ reran, setReran ] = useState( false );
	const [ details, setDetails ] = useState( false );
	const preview = usePreview();
	const applier = useApply();

	const values = settingsValues( settings );
	// T85: an undo plans every item in one web request, so the screen
	// promises undo only up to the size this host can plan (Undo\Capacity).
	const undoLimit =
		typeof settings?.undoLimit === 'number' ? settings.undoLimit : Infinity;
	const terms = useMemo(
		() => ( {
			categories,
			tags,
			shipping_classes: shippingClasses,
		} ),
		[ categories, tags, shippingClasses ]
	);
	const count = selectionCount( selection );
	const requestOperations = useMemo(
		() => toRequestOperations( registry, operations ),
		[ registry, operations ]
	);
	const signature = JSON.stringify( {
		operations: requestOperations,
		selection: selectionArgs( selection ),
		settings: values,
	} );
	const reason = blockedReason(
		registry,
		operations,
		count,
		selection?.waiting || ''
	);
	const response = preview.response;
	const current =
		preview.status === 'ready' && preview.signature === signature;
	const stale =
		( preview.status === 'ready' && ! current ) ||
		applier.status === 'stale';
	const changes = Number( response?.totals?.would_change ) || 0;
	const reach =
		Number( batch?.total_items ?? response?.totals?.matched ?? count ) || 0;
	const undoNote = reach > undoLimit ? undoTooLarge( undoLimit ) : '';
	const state = panelState( {
		batchId: batch?.batch_id,
		applying: applier.status === 'applying',
		computing: preview.status === 'computing',
		chosen: operations.some( ( operation ) => operation.action ),
		blocked: reason !== '',
		stale,
		current,
		changes,
	} );

	// Focus: into the panel on open, back to whatever opened it on close (§14).
	useEffect( () => {
		const opener = panelRef.current?.ownerDocument?.activeElement;
		headingRef.current?.focus();
		return () => {
			if ( opener && typeof opener.focus === 'function' ) {
				opener.focus();
			}
		};
	}, [] );

	const check = useCallback(
		( size = sampleSize ) => {
			setReran( false );
			// A new preview starts a new attempt on screen; the grant of an
			// unresolved Apply stays held by useApply() (T84).
			if ( ! [ 'applying', 'applied' ].includes( applier.status ) ) {
				applier.reset();
			}
			// Fold the steps to their one-line summaries so the preview is in view.
			setActive( -1 );
			preview.run( {
				operations: requestOperations,
				selection,
				sampleSize: size,
				signature,
			} );
		},
		[
			preview,
			applier,
			requestOperations,
			selection,
			sampleSize,
			signature,
		]
	);

	// Bring the preview into view as it starts computing.
	useEffect( () => {
		if ( preview.status === 'computing' ) {
			checkRef.current?.scrollIntoView?.( { block: 'start' } );
		}
	}, [ preview.status ] );

	// A 409 bulkcat_preview_stale: the preview no longer matches, so work it out again.
	useEffect( () => {
		if (
			applier.status === 'stale' &&
			applier.error?.code === PREVIEW_STALE
		) {
			applier.reset();
			check();
			setReran( true );
		}
	}, [ applier, check ] );

	// T84: a durable request; a retry of the same change sends the same grant.
	const apply = () =>
		applier.apply( { selection, preview: response } ).then( ( created ) => {
			if ( created?.batch_id ) {
				setApplied( selection );
				setBatch( created );
				// An earlier attempt that turned out to have gone through
				// belongs to whatever it was applied to, not this selection.
				onApplied( created.batch_id, {
					selection: created.earlier ? null : selection,
					replayed: !! created.replayed,
				} );
			}
		} );

	const update = ( index, change ) =>
		setOperations( ( list ) =>
			list.map( ( operation, at ) =>
				at === index ? change( operation ) : operation
			)
		);

	const add = () => {
		if ( operations.length >= MAX_OPERATIONS ) {
			return;
		}
		setOperations( ( list ) => [
			...list,
			emptyOperation( nextKey.current++ ),
		] );
		setActive( operations.length );
	};

	const remove = ( index ) => {
		setOperations( ( list ) => {
			const next = list.filter( ( operation, at ) => at !== index );
			return next.length ? next : [ emptyOperation( nextKey.current++ ) ];
		} );
		const last = Math.max( 0, operations.length - 2 );
		setActive( ( was ) =>
			Math.min( last, Math.max( 0, was > index ? was - 1 : was ) )
		);
	};

	const move = ( index, by ) => {
		const to = index + by;
		if ( to < 0 || to >= operations.length ) {
			return;
		}
		setOperations( ( list ) => {
			const next = [ ...list ];
			[ next[ index ], next[ to ] ] = [ next[ to ], next[ index ] ];
			return next;
		} );
		setActive( ( was ) => {
			if ( was === index ) {
				return to;
			}
			return was === to ? index : was;
		} );
	};

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			onClose();
			return;
		}
		if ( event.key !== 'Tab' || ! panelRef.current ) {
			return;
		}
		const items = Array.from(
			panelRef.current.querySelectorAll( FOCUSABLE )
		);
		if ( ! items.length ) {
			return;
		}
		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		const at = panelRef.current.ownerDocument.activeElement;
		if ( event.shiftKey && ( at === first || at === headingRef.current ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && at === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	let step = 1;
	if ( [ 'applying', 'done' ].includes( state ) ) {
		step = 3;
	} else if ( preview.status !== 'idle' ) {
		step = 2;
	}
	const stepNames = [
		__( 'Choose the change', 'bulk-catalogue-manager-for-woocommerce' ),
		__( 'Check it', 'bulk-catalogue-manager-for-woocommerce' ),
		__( 'Done', 'bulk-catalogue-manager-for-woocommerce' ),
	];
	const threshold = values.confirm_threshold ?? DEFAULT_THRESHOLD;
	const reasons = current
		? alwaysConfirmReasons(
				registry,
				requestOperations,
				changes,
				response?.availability || null
			)
		: [];
	const worthKnowing =
		current &&
		changes > 0 &&
		operations.some( ( operation ) => {
			const field = fieldByKey( registry, operation.args?.field );
			return offersVariations( field ) && ! operation.variations;
		} );

	return (
		// The slide-over's own keys: Escape closes it and Tab stays inside it (§14).
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			ref={ panelRef }
			className="bulkcat-bulk"
			role="dialog"
			aria-modal="false"
			aria-labelledby="bulkcat-bulk-title"
			data-state={ state }
			onKeyDown={ onKeyDown }
		>
			<header className="bulkcat-bulk__head">
				<h2 id="bulkcat-bulk-title" ref={ headingRef } tabIndex={ -1 }>
					{ __(
						'Bulk change',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</h2>
				<button
					type="button"
					className="button-link bulkcat-bulk__close"
					onClick={ onClose }
					aria-label={ __(
						'Close the bulk change panel',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					<span aria-hidden="true">×</span>
				</button>
			</header>
			<ol className="bulkcat-bulk__steps">
				{ stepNames.map( ( name, index ) => {
					const number = index + 1;
					let status = '';
					if ( step === number ) {
						status = ' is-now';
					} else if ( step > number ) {
						status = ' is-done';
					}
					return (
						<li
							key={ name }
							className={ 'bulkcat-bulk__step' + status }
							aria-current={
								step === number ? 'step' : undefined
							}
						>
							<span
								className="bulkcat-bulk__n"
								aria-hidden="true"
							>
								{ step > number ? '✓' : number }
							</span>
							{ name }
						</li>
					);
				} ) }
			</ol>

			<div className="bulkcat-bulk__body">
				{ /* T75: the scope and Safety cards form a rail beside the steps
				   when the panel is wide enough, as in the prototype. */ }
				<div className="bulkcat-bulk__rail">
					<section className="bulkcat-bulk__card bulkcat-bulk__scope">
						<h3>
							{ batch
								? __(
										'What is being changed',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: __(
										'This change will affect',
										'bulk-catalogue-manager-for-woocommerce'
									) }
						</h3>
						<p className="bulkcat-bulk__big">
							{ scopeLine(
								batch && applied ? applied : selection
							) }
						</p>
						{ ! batch && (
							<p className="bulkcat-bulk__acts">
								{ onReview && (
									<button
										type="button"
										className="button button-small"
										onClick={ onReview }
									>
										{ __(
											'Review selected products',
											'bulk-catalogue-manager-for-woocommerce'
										) }
									</button>
								) }
								<button
									type="button"
									className="button button-small"
									onClick={ onClose }
								>
									{ __(
										'Change the selection',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</button>
							</p>
						) }
					</section>
					<section className="bulkcat-bulk__card bulkcat-bulk__safety">
						<h3>
							{ __(
								'Safety',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</h3>
						<p className="bulkcat-bulk__hint">
							{ undoNote ||
								__(
									'Every change is saved with the old values. One click in History puts them back.',
									'bulk-catalogue-manager-for-woocommerce'
								) }
						</p>
					</section>
				</div>
				<div className="bulkcat-bulk__main">
					{ batch ? (
						<DoneStep
							batchId={ batch.batch_id }
							count={ batch.total_items ?? changes }
							undoLimit={ undoLimit }
							interval={ pollInterval }
							onUndo={ onUndo }
							onViewHistory={ onViewHistory }
							renderStalled={ renderStalled }
							onFinished={ onFinished }
							replayed={ !! batch.replayed }
							earlier={ !! batch.earlier }
						/>
					) : (
						<>
							<section className="bulkcat-bulk__section">
								<h3 className="bulkcat-bulk__h">
									{ __(
										'Choose the change',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</h3>
								<p className="bulkcat-bulk__sub">
									{ __(
										'Pick a change. You will see exactly what it does, on real products, before anything is saved.',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</p>
								<ol className="bulkcat-bulk__ops">
									{ operations.map( ( operation, index ) => (
										<li
											key={ operation.key }
											className="bulkcat-bulk__op"
										>
											{ ( index !== active ||
												operations.length > 1 ) && (
												<OperationSummary
													registry={ registry }
													operation={ operation }
													index={ index }
													total={ operations.length }
													editing={ index === active }
													onEdit={ () =>
														setActive( index )
													}
													onMove={ ( by ) =>
														move( index, by )
													}
													onRemove={ () =>
														remove( index )
													}
												/>
											) }
											{ index === active && (
												<ActionChooser
													registry={ registry }
													operation={ operation }
													index={ index }
													terms={ terms }
													onChoose={ ( id, group ) =>
														update(
															index,
															( was ) =>
																chooseAction(
																	registry,
																	settings,
																	was,
																	id,
																	group
																)
														)
													}
													onArg={ ( name, value ) =>
														update(
															index,
															( was ) =>
																changeArg(
																	registry,
																	settings,
																	was,
																	name,
																	value
																)
														)
													}
													onVariations={ ( on ) =>
														update(
															index,
															( was ) => ( {
																...was,
																variations: on,
															} )
														)
													}
												/>
											) }
										</li>
									) ) }
								</ol>
								<p className="bulkcat-bulk__addrow">
									<button
										type="button"
										className="button"
										onClick={ add }
										disabled={
											operations.length >= MAX_OPERATIONS
										}
										aria-describedby={
											operations.length >= MAX_OPERATIONS
												? 'bulkcat-bulk-max'
												: undefined
										}
									>
										{ __(
											'Add another change',
											'bulk-catalogue-manager-for-woocommerce'
										) }
									</button>
									{ operations.length >= MAX_OPERATIONS && (
										<span
											className="bulkcat-bulk__hint"
											id="bulkcat-bulk-max"
										>
											{ sprintf(
												/* translators: %d: the most steps one change may hold, 20. */
												__(
													'One change can hold at most %d steps.',
													'bulk-catalogue-manager-for-woocommerce'
												),
												MAX_OPERATIONS
											) }
										</span>
									) }
								</p>
							</section>

							{ preview.status !== 'idle' && (
								<section
									ref={ checkRef }
									className="bulkcat-bulk__section"
									aria-live="polite"
								>
									<h3 className="bulkcat-bulk__h">
										{ __(
											'Check it',
											'bulk-catalogue-manager-for-woocommerce'
										) }
									</h3>
									{ preview.status === 'computing' && (
										<p
											className="bulkcat-bulk__sub"
											role="status"
										>
											{ __(
												'Working out what would change. Nothing is saved.',
												'bulk-catalogue-manager-for-woocommerce'
											) }
										</p>
									) }
									{ preview.status === 'error' && (
										<ErrorNotice
											error={ preview.error }
											details={ details }
											onDetails={ setDetails }
											onRenew={
												preview.error?.code ===
													RENEW_REQUIRED &&
												onRenewSelection
													? onRenewSelection
													: null
											}
										/>
									) }
									{ reran && current && (
										<div className="bulkcat-bulk__notice is-warn">
											{ __(
												'The products or the settings changed after you checked, so the preview has been worked out again. Check it, then apply.',
												'bulk-catalogue-manager-for-woocommerce'
											) }
										</div>
									) }
									{ stale && preview.status === 'ready' && (
										<div
											className="bulkcat-bulk__notice is-warn"
											data-block="stale"
										>
											{ __(
												'The selection or the change has moved on since you checked it. Preview it again before applying.',
												'bulk-catalogue-manager-for-woocommerce'
											) }
										</div>
									) }
									{ response &&
										preview.status !== 'error' && (
											<>
												{ current && changes === 0 && (
													<div className="bulkcat-bulk__notice is-warn">
														<strong>
															{ __(
																'Nothing would change',
																'bulk-catalogue-manager-for-woocommerce'
															) }
														</strong>
														<p>
															{ __(
																'None of the chosen products would be different after this change. Adjust it and check again.',
																'bulk-catalogue-manager-for-woocommerce'
															) }
														</p>
													</div>
												) }
												<PreviewResult
													response={ response }
													registry={ registry }
													operations={
														requestOperations
													}
													terms={ terms }
													sampleSize={
														preview.sampleSize
													}
													busy={
														preview.status ===
														'computing'
													}
													onSampleSize={ ( size ) => {
														setSampleSize( size );
														check( size );
													} }
													changedOnly={ changedOnly }
													onChangedOnly={
														setChangedOnly
													}
													onDownload={
														onDownloadSample
															? () =>
																	onDownloadSample(
																		{
																			operations:
																				requestOperations,
																			...selectionArgs(
																				selection
																			),
																			sample_size:
																				preview.sampleSize,
																		}
																	)
															: null
													}
												/>
												{ worthKnowing && (
													<div className="bulkcat-bulk__notice is-warn">
														<strong>
															{ __(
																'Worth knowing',
																'bulk-catalogue-manager-for-woocommerce'
															) }
														</strong>
														<p>
															{ __(
																'Variations carry their own prices and stock. Nothing happens to them until you switch on Also apply to variations for this change.',
																'bulk-catalogue-manager-for-woocommerce'
															) }
														</p>
													</div>
												) }
											</>
										) }
								</section>
							) }

							<ApplyOutcome
								applier={ applier }
								details={ details }
								onDetails={ setDetails }
								onRetry={ apply }
								onPreviewAgain={ () => {
									applier.reset();
									check();
								} }
								onViewHistory={ onViewHistory }
								onRenew={ onRenewSelection }
							/>
						</>
					) }
				</div>
			</div>

			<footer className="bulkcat-bulk__foot">
				{ ! batch && (
					<FooterAction
						state={ state }
						reason={ reason }
						check={ () => check() }
						apply={ apply }
						count={ changes }
						threshold={ threshold }
						reasons={ reasons }
						busy={ applier.status === 'applying' }
						changed={ [ 'changed', 'legacy', 'renew' ].includes(
							applier.status
						) }
						undoNote={ undoNote }
					/>
				) }
				<button type="button" className="button" onClick={ onClose }>
					{ batch
						? __(
								'Back to products',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: __(
								'Close',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</button>
				<span className="bulkcat-bulk__footnote">
					{ undoNote
						? sprintf(
								/* translators: %d: the current step, 1 to 3. */
								__(
									'Step %d of 3 · Too large to undo from this screen',
									'bulk-catalogue-manager-for-woocommerce'
								),
								step
							)
						: sprintf(
								/* translators: %d: the current step, 1 to 3. */
								__(
									'Step %d of 3 · You can undo this afterwards',
									'bulk-catalogue-manager-for-woocommerce'
								),
								step
							) }
				</span>
			</footer>
		</div>
	);
}

/**
 * The footer's main control for the panel state: Check the change, Preview
 * again, or Apply through the confirmation policy.
 *
 * @param {Object}        props           Props.
 * @param {string}        props.state     Panel state.
 * @param {string}        props.reason    Why the change cannot be checked, or ''.
 * @param {Function}      props.check     Runs the preview.
 * @param {Function}      props.apply     Runs the apply.
 * @param {number}        props.count     Products that would change.
 * @param {number}        props.threshold The confirm threshold.
 * @param {Array<string>} props.reasons   Always-confirm sentences.
 * @param {boolean}       props.busy      Whether an apply is running.
 * @param {boolean}       props.changed   Whether the last Apply was refused in a way only a new preview or a reload can fix.
 * @param {string}        props.undoNote  In place of the undo promise, when the change is too large to undo here (T85).
 * @return {Element} Control.
 */
function FooterAction( {
	state,
	reason,
	check,
	apply,
	count,
	threshold,
	reasons,
	busy,
	changed,
	undoNote = '',
} ) {
	if ( state === 'ready' || state === 'empty' || state === 'applying' ) {
		return (
			<ConfirmApply
				count={ count }
				threshold={ threshold }
				reasons={ reasons }
				onApply={ apply }
				busy={ busy }
				undoNote={ undoNote }
				disabled={ state === 'empty' || changed }
				disabledReason={
					state === 'empty'
						? __(
								'Nothing would change, so there is nothing to apply.',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: ''
				}
			/>
		);
	}
	const disabled = reason !== '' || state === 'previewing';
	return (
		<div className="bulkcat-confirm">
			<button
				type="button"
				className="button button-primary"
				disabled={ disabled }
				aria-describedby={ reason ? 'bulkcat-bulk-blocked' : undefined }
				onClick={ check }
			>
				{ state === 'stale'
					? __(
							'Preview again',
							'bulk-catalogue-manager-for-woocommerce'
						)
					: __(
							'Check the change',
							'bulk-catalogue-manager-for-woocommerce'
						) }
			</button>
			{ reason && (
				<p className="bulkcat-bulk__hint" id="bulkcat-bulk-blocked">
					{ reason }
				</p>
			) }
		</div>
	);
}

/**
 * What became of an Apply that did not give a batch (T84, SPEC §4.8): the
 * server's own sentence for each refusal, with what can be done next.
 *
 * - An unknown outcome (offline, a server error): Try again sends the same
 *   request, so the change can only be made once.
 * - `bulkcat_selection_changed`: the selection moved on; Preview again.
 * - `bulkcat_legacy_request`: an older page's request; History, then reload.
 * - `bulkcat_selection_renew_required`: Restore the selection, then preview.
 * - Anything else (`bulkcat_request_gone`, expired, conflicting): the
 *   sentence, and Show details for the code.
 *
 * @param {Object}   props                Props.
 * @param {Object}   props.applier        useApply().
 * @param {boolean}  props.details        Whether the details show.
 * @param {Function} props.onDetails      Receives the toggle's position.
 * @param {Function} props.onRetry        Send the same request again.
 * @param {Function} props.onPreviewAgain Preview the change again.
 * @param {Function} props.onViewHistory  Open a batch in History, or null.
 * @param {Function} props.onRenew        Restore the selection, or null.
 * @return {Element|null} Notice.
 */
function ApplyOutcome( {
	applier,
	details,
	onDetails,
	onRetry,
	onPreviewAgain,
	onViewHistory,
	onRenew,
} ) {
	const { status, error } = applier;
	if ( status === 'uncertain' ) {
		return (
			<div
				className="bulkcat-bulk__notice is-warn"
				role="alert"
				data-block="uncertain"
			>
				<strong>
					{ __(
						'We could not confirm whether the change was applied',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</strong>
				<p>
					{ __(
						'The connection dropped before the answer arrived. Try again: the same request is sent again, so the change can only be made once.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
				<div className="bulkcat-bulk__acts">
					<button
						type="button"
						className="button button-primary button-small"
						onClick={ onRetry }
					>
						{ __(
							'Try again',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</button>
				</div>
				<Details
					error={ error }
					details={ details }
					onDetails={ onDetails }
				/>
			</div>
		);
	}
	if ( status === 'changed' ) {
		return (
			<div
				className="bulkcat-bulk__notice is-warn"
				role="alert"
				data-block="selection-changed"
			>
				<p>{ error?.message }</p>
				<div className="bulkcat-bulk__acts">
					<button
						type="button"
						className="button button-small"
						onClick={ onPreviewAgain }
					>
						{ __(
							'Preview again',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</button>
				</div>
			</div>
		);
	}
	if ( status === 'legacy' ) {
		const earlier = Number( error?.data?.batch_ids?.[ 0 ] ) || 0;
		return (
			<div
				className="bulkcat-bulk__notice is-error"
				role="alert"
				data-block="legacy"
			>
				<p>{ error?.message }</p>
				{ earlier > 0 && onViewHistory && (
					<div className="bulkcat-bulk__acts">
						<button
							type="button"
							className="button button-small"
							onClick={ () => onViewHistory( earlier ) }
						>
							{ __(
								'View in History',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</button>
					</div>
				) }
				<Details
					error={ error }
					details={ details }
					onDetails={ onDetails }
				/>
			</div>
		);
	}
	if ( status === 'renew' || status === 'error' ) {
		return (
			<ErrorNotice
				error={ error }
				details={ details }
				onDetails={ onDetails }
				onRenew={ status === 'renew' ? onRenew : null }
			/>
		);
	}
	return null;
}

/**
 * Show details: the technical line behind a toggle.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.error     The REST error.
 * @param {boolean}  props.details   Whether the details show.
 * @param {Function} props.onDetails Receives the toggle's position.
 * @return {Element|null} Toggle.
 */
function Details( { error, details, onDetails } ) {
	if ( ! error?.code ) {
		return null;
	}
	return (
		<>
			<button
				type="button"
				className="button-link"
				aria-expanded={ details }
				onClick={ () => onDetails( ! details ) }
			>
				{ __(
					'Show details',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</button>
			{ details && (
				<p className="bulkcat-bulk__hint">
					<code>{ error.code }</code>
				</p>
			) }
		</>
	);
}

/**
 * A §4.8 error: the plain sentence, the technical line behind Show details.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.error     The REST error.
 * @param {boolean}  props.details   Whether the details show.
 * @param {Function} props.onDetails Receives the toggle's position.
 * @param {Function} props.onRenew   Restore the selection (a lapsed lease), or null.
 * @return {Element} Notice.
 */
function ErrorNotice( { error, details, onDetails, onRenew = null } ) {
	// Only the plugin's own refusals are sentences written for people
	// (§4.8); anything else — a server's error page, a proxy's text — is
	// the plain fallback, its code behind Show details (T85).
	const own =
		/^bulkcat_/.test( error?.code || '' ) &&
		typeof error?.message === 'string' &&
		error.message;
	return (
		<div className="bulkcat-bulk__notice is-error" role="alert">
			<p>
				{ own ||
					__(
						'Something went wrong. Try again in a moment.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
			</p>
			{ onRenew && (
				<div className="bulkcat-bulk__acts">
					<button
						type="button"
						className="button button-small"
						onClick={ onRenew }
					>
						{ __(
							'Restore the selection',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</button>
				</div>
			) }
			<Details
				error={ error }
				details={ details }
				onDetails={ onDetails }
			/>
		</div>
	);
}
