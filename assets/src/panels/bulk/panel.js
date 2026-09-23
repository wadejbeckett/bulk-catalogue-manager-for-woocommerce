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
	isFilterSelection,
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
import { formatNumber, productsPhrase } from './words';
import './style.scss';

/** Elements a Tab press may land on inside the panel. */
const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * How many products a selection holds.
 *
 * @param {Object} selection Panel selection.
 * @return {number} Count.
 */
export function selectionCount( selection ) {
	if ( isFilterSelection( selection ) ) {
		return Number( selection.count ) || 0;
	}
	return Number( selection?.count ?? selection?.ids?.length ) || 0;
}

/**
 * The scope line: "24 selected products" or "All 4,312 products matching
 * the current filter".
 *
 * @param {Object} selection Panel selection.
 * @return {string} Line.
 */
export function scopeLine( selection ) {
	const count = selectionCount( selection );
	if ( isFilterSelection( selection ) ) {
		return sprintf(
			/* translators: %s: a number of products, such as "4,312". */
			_n(
				'All %s product matching the current filter',
				'All %s products matching the current filter',
				count,
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count )
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
 * @return {string} Reason.
 */
export function blockedReason( registry, operations, count ) {
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
 * @param {Object}        props.selection         `{ kind: 'ids', ids }` or `{ kind: 'filter', token, count, filters }`.
 * @param {Object}        props.registry          The `/fields` payload `{ fields, actions, groups }`.
 * @param {Object}        props.settings          The `bcm_settings` values (or the screen settings carrying them).
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
	const [ reran, setReran ] = useState( false );
	const [ details, setDetails ] = useState( false );
	const preview = usePreview();
	const applier = useApply();

	const values = settingsValues( settings );
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
	const reason = blockedReason( registry, operations, count );
	const response = preview.response;
	const current =
		preview.status === 'ready' && preview.signature === signature;
	const stale =
		( preview.status === 'ready' && ! current ) ||
		applier.status === 'stale';
	const changes = Number( response?.totals?.would_change ) || 0;
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
			// Fold the steps to their one-line summaries so the preview is in view.
			setActive( -1 );
			preview.run( {
				operations: requestOperations,
				selection,
				sampleSize: size,
				signature,
			} );
		},
		[ preview, requestOperations, selection, sampleSize, signature ]
	);

	// Bring the preview into view as it starts computing.
	useEffect( () => {
		if ( preview.status === 'computing' ) {
			checkRef.current?.scrollIntoView?.( { block: 'start' } );
		}
	}, [ preview.status ] );

	// A 409 bcm_preview_stale: the preview no longer matches, so work it out again.
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

	const apply = ( confirmChanged = false ) =>
		applier
			.apply( {
				operations: requestOperations,
				selection,
				preview: response,
				confirmChanged,
			} )
			.then( ( created ) => {
				if ( created?.batch_id ) {
					setBatch( created );
					onApplied( created.batch_id );
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
		? alwaysConfirmReasons( registry, requestOperations, changes )
		: [];
	const worthKnowing =
		current &&
		changes > 0 &&
		operations.some( ( operation ) => {
			const field = fieldByKey( registry, operation.args?.field );
			return offersVariations( field ) && ! operation.variations;
		} );
	const filters = Array.isArray( selection?.filters )
		? selection.filters
		: [];

	return (
		// The slide-over's own keys: Escape closes it and Tab stays inside it (§14).
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			ref={ panelRef }
			className="bcm-bulk"
			role="dialog"
			aria-modal="false"
			aria-labelledby="bcm-bulk-title"
			data-state={ state }
			onKeyDown={ onKeyDown }
		>
			<header className="bcm-bulk__head">
				<h2 id="bcm-bulk-title" ref={ headingRef } tabIndex={ -1 }>
					{ __(
						'Bulk change',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</h2>
				<button
					type="button"
					className="button-link bcm-bulk__close"
					onClick={ onClose }
					aria-label={ __(
						'Close the bulk change panel',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					<span aria-hidden="true">×</span>
				</button>
			</header>
			<ol className="bcm-bulk__steps">
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
							className={ 'bcm-bulk__step' + status }
							aria-current={
								step === number ? 'step' : undefined
							}
						>
							<span className="bcm-bulk__n" aria-hidden="true">
								{ step > number ? '✓' : number }
							</span>
							{ name }
						</li>
					);
				} ) }
			</ol>

			<div className="bcm-bulk__body">
				<section className="bcm-bulk__card bcm-bulk__scope">
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
					<p className="bcm-bulk__big">{ scopeLine( selection ) }</p>
					{ filters.length > 0 && (
						<dl className="bcm-bulk__filters">
							{ filters.map( ( filter, index ) => (
								<div className="bcm-bulk__kv" key={ index }>
									<dt>{ filter.label }</dt>
									<dd>{ filter.value }</dd>
								</div>
							) ) }
						</dl>
					) }
					{ ! batch && (
						<p>
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

				{ batch ? (
					<DoneStep
						batchId={ batch.batch_id }
						count={ batch.total_items ?? changes }
						interval={ pollInterval }
						onUndo={ onUndo }
						onViewHistory={ onViewHistory }
						renderStalled={ renderStalled }
					/>
				) : (
					<>
						<section className="bcm-bulk__section">
							<h3 className="bcm-bulk__h">
								{ __(
									'Choose the change',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</h3>
							<p className="bcm-bulk__sub">
								{ __(
									'Pick a change. You will see exactly what it does, on real products, before anything is saved.',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</p>
							<ol className="bcm-bulk__ops">
								{ operations.map( ( operation, index ) => (
									<li
										key={ operation.key }
										className="bcm-bulk__op"
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
													update( index, ( was ) =>
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
													update( index, ( was ) =>
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
							<p className="bcm-bulk__addrow">
								<button
									type="button"
									className="button"
									onClick={ add }
									disabled={
										operations.length >= MAX_OPERATIONS
									}
									aria-describedby={
										operations.length >= MAX_OPERATIONS
											? 'bcm-bulk-max'
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
										className="bcm-bulk__hint"
										id="bcm-bulk-max"
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
								className="bcm-bulk__section"
								aria-live="polite"
							>
								<h3 className="bcm-bulk__h">
									{ __(
										'Check it',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</h3>
								{ preview.status === 'computing' && (
									<p className="bcm-bulk__sub" role="status">
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
									/>
								) }
								{ reran && current && (
									<div className="bcm-bulk__notice is-warn">
										{ __(
											'The products or the settings changed after you checked, so the preview has been worked out again. Check it, then apply.',
											'bulk-catalogue-manager-for-woocommerce'
										) }
									</div>
								) }
								{ stale && preview.status === 'ready' && (
									<div
										className="bcm-bulk__notice is-warn"
										data-block="stale"
									>
										{ __(
											'The selection or the change has moved on since you checked it. Preview it again before applying.',
											'bulk-catalogue-manager-for-woocommerce'
										) }
									</div>
								) }
								{ response && preview.status !== 'error' && (
									<>
										{ current && changes === 0 && (
											<div className="bcm-bulk__notice is-warn">
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
											operations={ requestOperations }
											terms={ terms }
											sampleSize={ preview.sampleSize }
											busy={
												preview.status === 'computing'
											}
											onSampleSize={ ( size ) => {
												setSampleSize( size );
												check( size );
											} }
											changedOnly={ changedOnly }
											onChangedOnly={ setChangedOnly }
											onDownload={
												onDownloadSample
													? () =>
															onDownloadSample( {
																operations:
																	requestOperations,
																...selectionArgs(
																	selection
																),
																sample_size:
																	preview.sampleSize,
															} )
													: null
											}
										/>
										{ worthKnowing && (
											<div className="bcm-bulk__notice is-warn">
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

						{ applier.status === 'changed' && (
							<div
								className="bcm-bulk__notice is-warn"
								role="alert"
								data-block="selection-changed"
							>
								<strong>
									{ __(
										'The filter now matches a different number of products',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</strong>
								<p>
									{ sprintf(
										/* translators: 1: products when previewed, such as "1,284 products"; 2: products now, such as "1,290 products". */
										__(
											'When you checked the change it matched %1$s. It matches %2$s now.',
											'bulk-catalogue-manager-for-woocommerce'
										),
										productsPhrase( applier.previewed ),
										productsPhrase( applier.current )
									) }
								</p>
								<div className="bcm-bulk__acts">
									<button
										type="button"
										className="button button-primary button-small"
										onClick={ () => apply( true ) }
									>
										{ __(
											'Apply anyway',
											'bulk-catalogue-manager-for-woocommerce'
										) }
									</button>
									<button
										type="button"
										className="button button-small"
										onClick={ () => {
											applier.reset();
											check();
										} }
									>
										{ __(
											'Preview again',
											'bulk-catalogue-manager-for-woocommerce'
										) }
									</button>
								</div>
							</div>
						) }
						{ applier.status === 'error' && (
							<ErrorNotice
								error={ applier.error }
								details={ details }
								onDetails={ setDetails }
							/>
						) }
					</>
				) }

				<section className="bcm-bulk__card bcm-bulk__safety">
					<h3>
						{ __(
							'Safety',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</h3>
					<p className="bcm-bulk__hint">
						{ __(
							'Every change is saved with the old values. One click in History puts them back.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				</section>
			</div>

			<footer className="bcm-bulk__foot">
				{ ! batch && (
					<FooterAction
						state={ state }
						reason={ reason }
						check={ () => check() }
						apply={ () => apply( false ) }
						count={ changes }
						threshold={ threshold }
						reasons={ reasons }
						busy={ applier.status === 'applying' }
						changed={ applier.status === 'changed' }
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
				<span className="bcm-bulk__footnote">
					{ sprintf(
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
 * @param {boolean}       props.changed   Whether the apply is waiting on the selection-changed answer.
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
} ) {
	if ( state === 'ready' || state === 'empty' || state === 'applying' ) {
		return (
			<ConfirmApply
				count={ count }
				threshold={ threshold }
				reasons={ reasons }
				onApply={ apply }
				busy={ busy }
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
		<div className="bcm-confirm">
			<button
				type="button"
				className="button button-primary"
				disabled={ disabled }
				aria-describedby={ reason ? 'bcm-bulk-blocked' : undefined }
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
				<p className="bcm-bulk__hint" id="bcm-bulk-blocked">
					{ reason }
				</p>
			) }
		</div>
	);
}

/**
 * A §4.8 error: the plain sentence, the technical line behind Show details.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.error     The REST error.
 * @param {boolean}  props.details   Whether the details show.
 * @param {Function} props.onDetails Receives the toggle's position.
 * @return {Element} Notice.
 */
function ErrorNotice( { error, details, onDetails } ) {
	return (
		<div className="bcm-bulk__notice is-error" role="alert">
			<p>
				{ error?.message ||
					__(
						'Something went wrong. Try again in a moment.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
			</p>
			{ error?.code && (
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
						<p className="bcm-bulk__hint">
							<code>{ error.code }</code>
						</p>
					) }
				</>
			) }
		</div>
	);
}
