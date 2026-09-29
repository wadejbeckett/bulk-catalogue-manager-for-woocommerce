/**
 * The ordered operation list (SPEC §4.3 step 2, §6): each step is either a
 * one-line summary or, while it is being edited, the action chooser — the
 * descriptors' actions grouped as the registry groups them, each a card with
 * its plain description that expands in place to its generated controls.
 */
import { __, sprintf } from '@wordpress/i18n';

import { ArgControl } from './controls';
import {
	SPECIAL_ARGS,
	actionById,
	defaultArgs,
	defaultValue,
	fieldByKey,
	fieldsFor,
	groupedActions,
	isArgShown,
	mixedSentence,
	mixesKinds,
	offersVariations,
	operationProblem,
	unavailableReason,
	variationsDefault,
} from './descriptors';

/**
 * A new, unchosen step.
 *
 * @param {number} key Unique key.
 * @return {Object} Operation.
 */
export function emptyOperation( key ) {
	return { key, action: '', group: '', args: {}, variations: false };
}

/**
 * A step with an action chosen under a group, at its starting arguments.
 *
 * @param {Object} registry  The `/fields` payload.
 * @param {Object} settings  Settings.
 * @param {Object} operation The step.
 * @param {string} actionId  Action id.
 * @param {string} group     Group id.
 * @return {Object} Operation.
 */
export function chooseAction( registry, settings, operation, actionId, group ) {
	const action = actionById( registry, actionId );
	const args = defaultArgs( registry, action, group );
	return {
		...operation,
		action: actionId,
		group,
		args,
		variations: variationsDefault(
			fieldByKey( registry, args.field ),
			settings
		),
	};
}

/**
 * A step with one argument changed. Changing the field resets the value to
 * the new field's first value and the variations switch to its default.
 *
 * @param {Object} registry  The `/fields` payload.
 * @param {Object} settings  Settings.
 * @param {Object} operation The step.
 * @param {string} name      Argument name.
 * @param {*}      value     New value.
 * @return {Object} Operation.
 */
export function changeArg( registry, settings, operation, name, value ) {
	const args = { ...operation.args, [ name ]: value };
	if ( name !== 'field' ) {
		return { ...operation, args };
	}
	const field = fieldByKey( registry, value );
	const action = actionById( registry, operation.action );
	if ( action?.args?.properties?.value ) {
		args.value = defaultValue( field );
	}
	if ( action?.args?.properties?.terms ) {
		args.terms = [];
	}
	return {
		...operation,
		args,
		variations: variationsDefault( field, settings ),
	};
}

/**
 * The one-line name of a chosen step: "Increase or decrease — Regular price".
 *
 * @param {Object} registry  The `/fields` payload.
 * @param {Object} operation The step.
 * @return {string} Name.
 */
export function operationTitle( registry, operation ) {
	const action = actionById( registry, operation.action );
	if ( ! action ) {
		return __(
			'No change chosen yet',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	const field = fieldByKey( registry, operation.args?.field );
	return field
		? sprintf(
				/* translators: 1: an action, such as "Round prices"; 2: a field, such as "Regular price". */
				__( '%1$s — %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				action.label,
				field.label
			)
		: action.label;
}

/**
 * The controls of a chosen step, from its descriptor.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.registry     The `/fields` payload.
 * @param {Object}   props.operation    The step.
 * @param {string}   props.idBase       Id prefix.
 * @param {Object}   props.terms        Term options by source.
 * @param {Function} props.onArg        Receives ( name, value ).
 * @param {Function} props.onVariations Receives the switch position.
 * @return {Element} Controls.
 */
function OperationControls( {
	registry,
	operation,
	idBase,
	terms,
	onArg,
	onVariations,
} ) {
	const action = actionById( registry, operation.action );
	const properties = action?.args?.properties || {};
	const field = fieldByKey( registry, operation.args.field );
	const keys = fieldsFor( registry, action, operation.group );
	const problem = operationProblem( registry, operation );
	const reasonFor = ( name ) =>
		problem && problem.arg === name ? problem.reason : '';

	return (
		<div className="bulkcat-bulk__cbody">
			{ properties.field && (
				<div className="bulkcat-bulk__fg">
					<label
						className="bulkcat-bulk__lbl"
						htmlFor={ idBase + '-field' }
					>
						{ properties.field.title }
					</label>
					<select
						id={ idBase + '-field' }
						className="bulkcat-bulk__field"
						value={ operation.args.field || '' }
						onChange={ ( event ) =>
							onArg( 'field', event.target.value )
						}
					>
						{ keys.map( ( key ) => (
							<option key={ key } value={ key }>
								{ fieldByKey( registry, key )?.label || key }
							</option>
						) ) }
					</select>
				</div>
			) }
			{ Object.entries( properties )
				.filter(
					( [ name ] ) =>
						! SPECIAL_ARGS.includes( name ) &&
						isArgShown( name, action.args, operation.args )
				)
				.map( ( [ name, property ] ) => (
					<ArgControl
						key={ name }
						id={ idBase + '-' + name }
						name={ name }
						property={ property }
						value={ operation.args[ name ] }
						onChange={ ( value ) => onArg( name, value ) }
						field={ field }
						terms={ terms }
						reason={ reasonFor( name ) }
					/>
				) ) }
			{ properties.keep_changed_status &&
				field?.switchesOffOn === true &&
				operation.args.value === 'publish' && (
					// Keep current status (T91, SPEC §5.5): only a Published Status step can meet a conflict.
					<div className="bulkcat-bulk__fg is-full">
						<label
							className="bulkcat-bulk__check"
							htmlFor={ idBase + '-keep_changed_status' }
						>
							<input
								id={ idBase + '-keep_changed_status' }
								type="checkbox"
								checked={
									!! operation.args.keep_changed_status
								}
								onChange={ ( event ) =>
									onArg(
										'keep_changed_status',
										event.target.checked
									)
								}
							/>
							<span>
								{ properties.keep_changed_status.title }
							</span>
						</label>
					</div>
				) }
			<div className="bulkcat-bulk__varbox is-full">
				{ offersVariations( field ) ? (
					<>
						<label
							className="bulkcat-bulk__check"
							htmlFor={ idBase + '-variations' }
						>
							<input
								id={ idBase + '-variations' }
								type="checkbox"
								checked={ !! operation.variations }
								onChange={ ( event ) =>
									onVariations( event.target.checked )
								}
							/>
							<strong>
								{ __(
									'Also apply to variations',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</strong>
						</label>
						<p className="bulkcat-bulk__why">
							{ operation.variations
								? __(
										'Switched on: the variations inside these products change too.',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: __(
										'Switched off because variations often carry their own prices and stock. Switch it on to change them too.',
										'bulk-catalogue-manager-for-woocommerce'
									) }
						</p>
					</>
				) : (
					field?.variationNote && (
						<p className="bulkcat-bulk__why is-flush">
							{ field.variationNote }
						</p>
					)
				) }
				{ properties.skip_already && (
					<label
						className="bulkcat-bulk__check"
						htmlFor={ idBase + '-skip_already' }
					>
						<input
							id={ idBase + '-skip_already' }
							type="checkbox"
							role="switch"
							checked={ operation.args.skip_already !== false }
							onChange={ ( event ) =>
								onArg( 'skip_already', event.target.checked )
							}
						/>
						<span>{ properties.skip_already.title }</span>
					</label>
				) }
			</div>
		</div>
	);
}

/**
 * The action chooser for the step being edited.
 *
 * @param {Object}        props              Props.
 * @param {Object}        props.registry     The `/fields` payload.
 * @param {Object}        props.operation    The step.
 * @param {number}        props.index        Its position.
 * @param {Object}        props.terms        Term options by source.
 * @param {Function}      props.onChoose     Receives ( actionId, group ).
 * @param {Function}      props.onArg        Receives ( name, value ).
 * @param {Function}      props.onVariations Receives the switch position.
 * @param {Array<Object>} props.operations   Every step of the change, to say in place when kinds are mixed (T105).
 * @return {Element} Chooser.
 */
export function ActionChooser( {
	registry,
	operation,
	index,
	terms,
	onChoose,
	onArg,
	onVariations,
	operations = [],
} ) {
	const idBase = 'bulkcat-op-' + operation.key;
	// T105: a step of another kind than the rest of the change says so in place.
	const mixed =
		operation.action &&
		mixesKinds(
			registry,
			operations.map( ( each ) =>
				each.key === operation.key ? operation : each
			)
		)
			? mixedSentence()
			: '';
	return (
		<div
			className="bulkcat-bulk__choices"
			role="radiogroup"
			aria-label={ sprintf(
				/* translators: %d: the step's position in the list, such as 2. */
				__(
					'Choose the change for step %d',
					'bulk-catalogue-manager-for-woocommerce'
				),
				index + 1
			) }
		>
			{ groupedActions( registry ).map( ( group ) => (
				<div className="bulkcat-bulk__group" key={ group.id }>
					<h4 className="bulkcat-bulk__grouphead">{ group.label }</h4>
					{ group.actions.map( ( action ) => {
						const on =
							operation.action === action.id &&
							operation.group === group.id;
						const id = idBase + '-' + group.id + '-' + action.id;
						const unavailable = unavailableReason( action );
						return (
							<div
								key={ action.id }
								className={
									'bulkcat-bulk__choice' +
									( on ? ' is-on' : '' ) +
									( unavailable ? ' is-disabled' : '' )
								}
							>
								<label htmlFor={ id }>
									<input
										type="radio"
										id={ id }
										name={ idBase }
										value={ group.id + ':' + action.id }
										checked={ on }
										disabled={ !! unavailable && ! on }
										aria-describedby={
											unavailable
												? id + '-unavailable'
												: undefined
										}
										onChange={ () =>
											onChoose( action.id, group.id )
										}
									/>
									<span className="bulkcat-bulk__ct">
										{ action.label }
										<span className="bulkcat-bulk__cd">
											{ action.description }
										</span>
									</span>
								</label>
								{ unavailable && (
									<p
										className="bulkcat-bulk__reason"
										id={ id + '-unavailable' }
									>
										{ unavailable }
									</p>
								) }
								{ on && mixed && (
									<p
										className="bulkcat-bulk__reason"
										role="alert"
									>
										{ mixed }
									</p>
								) }
								{ on && (
									<OperationControls
										registry={ registry }
										operation={ operation }
										idBase={ idBase }
										terms={ terms }
										onArg={ onArg }
										onVariations={ onVariations }
									/>
								) }
							</div>
						);
					} ) }
				</div>
			) ) }
		</div>
	);
}

/**
 * A step not being edited: its name and its list controls.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.registry  The `/fields` payload.
 * @param {Object}   props.operation The step.
 * @param {number}   props.index     Its position.
 * @param {number}   props.total     Steps in the list.
 * @param {Function} props.onEdit    Edit it.
 * @param {Function} props.onMove    Receives -1 or 1.
 * @param {Function} props.onRemove  Remove it.
 * @param {boolean}  props.editing   Whether it is the step being edited.
 * @return {Element} Summary.
 */
export function OperationSummary( {
	registry,
	operation,
	index,
	total,
	editing,
	onEdit,
	onMove,
	onRemove,
} ) {
	const title = operationTitle( registry, operation );
	const problem = operationProblem( registry, operation );
	return (
		<div
			className={
				'bulkcat-bulk__opsum' + ( editing ? ' is-editing' : '' )
			}
		>
			<span className="bulkcat-bulk__n" aria-hidden="true">
				{ index + 1 }
			</span>
			<span className="bulkcat-bulk__optitle">
				{ title }
				{ ! editing && problem && problem.reason && (
					<span className="bulkcat-bulk__reason">
						{ problem.reason }
					</span>
				) }
			</span>
			<span className="bulkcat-bulk__opacts">
				{ ! editing && (
					<button
						type="button"
						className="button button-small"
						onClick={ onEdit }
						aria-label={ sprintf(
							/* translators: %d: the step's position in the list. */
							__(
								'Edit step %d',
								'bulk-catalogue-manager-for-woocommerce'
							),
							index + 1
						) }
					>
						{ __(
							'Edit',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</button>
				) }
				<button
					type="button"
					className="button button-small"
					disabled={ index === 0 }
					onClick={ () => onMove( -1 ) }
					aria-label={ sprintf(
						/* translators: %d: the step's position in the list. */
						__(
							'Move step %d up',
							'bulk-catalogue-manager-for-woocommerce'
						),
						index + 1
					) }
				>
					{ __( 'Up', 'bulk-catalogue-manager-for-woocommerce' ) }
				</button>
				<button
					type="button"
					className="button button-small"
					disabled={ index === total - 1 }
					onClick={ () => onMove( 1 ) }
					aria-label={ sprintf(
						/* translators: %d: the step's position in the list. */
						__(
							'Move step %d down',
							'bulk-catalogue-manager-for-woocommerce'
						),
						index + 1
					) }
				>
					{ __( 'Down', 'bulk-catalogue-manager-for-woocommerce' ) }
				</button>
				<button
					type="button"
					className="button button-small button-link-delete"
					onClick={ onRemove }
					aria-label={ sprintf(
						/* translators: %d: the step's position in the list. */
						__(
							'Remove step %d',
							'bulk-catalogue-manager-for-woocommerce'
						),
						index + 1
					) }
				>
					{ __( 'Remove', 'bulk-catalogue-manager-for-woocommerce' ) }
				</button>
			</span>
		</div>
	);
}
