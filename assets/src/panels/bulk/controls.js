/**
 * One control per argument, generated from the action descriptor's argument
 * schema (SPEC §6): a choice is a select of its `choices`, a number an input
 * in its range, a boolean a checkbox, a term list a picker over the options
 * the panel was given, and `value` is typed by the chosen field.
 */
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { propertyKind, writableChoices } from './descriptors';

/**
 * Flatten a category tree into options labelled with their path.
 *
 * @param {Array<Object>} nodes  Tree nodes `{ id, name, children }`.
 * @param {Array<string>} parent Names above.
 * @return {Array<{id: number, label: string}>} Options.
 */
export function flattenTerms( nodes, parent = [] ) {
	return ( nodes || [] ).flatMap( ( node ) => {
		const path = [ ...parent, node.name ];
		return [
			{ id: Number( node.id ), label: path.join( ' › ' ) },
			...flattenTerms( node.children, path ),
		];
	} );
}

/**
 * The options for a field's `source` from the panel's term props.
 *
 * @param {Object|null} field Field descriptor.
 * @param {Object}      terms `{ categories, tags, shipping_classes }`.
 * @return {Array<{id: number, label: string}>} Options.
 */
export function termOptions( field, terms ) {
	const source = field?.source;
	return source ? flattenTerms( terms?.[ source ] || [] ) : [];
}

/**
 * The shared frame: label, control, the reason in place when blocked.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.id       Control id.
 * @param {string}  props.label    Label.
 * @param {string}  props.reason   Reason it is blocked, or ''.
 * @param {string}  props.hint     Hint under the control.
 * @param {boolean} props.full     Whether it takes the full row.
 * @param {Object}  props.children The control.
 * @return {Element} Field group.
 */
function Group( { id, label, reason, hint, full, children } ) {
	return (
		<div className={ 'bulkcat-bulk__fg' + ( full ? ' is-full' : '' ) }>
			{ label && (
				<label className="bulkcat-bulk__lbl" htmlFor={ id }>
					{ label }
				</label>
			) }
			{ children }
			{ hint && (
				<p className="bulkcat-bulk__hint" id={ id + '-hint' }>
					{ hint }
				</p>
			) }
			{ reason && (
				<p className="bulkcat-bulk__reason" id={ id + '-reason' }>
					{ reason }
				</p>
			) }
		</div>
	);
}

/**
 * Accessibility attributes tying a control to its hint and reason.
 *
 * @param {string} id     Control id.
 * @param {string} reason Reason, or ''.
 * @param {string} hint   Hint, or ''.
 * @return {Object} Attributes.
 */
function described( id, reason, hint ) {
	const ids = [ hint && id + '-hint', reason && id + '-reason' ].filter(
		Boolean
	);
	return {
		'aria-invalid': reason ? true : undefined,
		'aria-describedby': ids.length ? ids.join( ' ' ) : undefined,
	};
}

/**
 * A checkbox list of terms with a search box.
 *
 * @param {Object}        props          Props.
 * @param {string}        props.id       Id base.
 * @param {string}        props.label    Legend.
 * @param {Array<Object>} props.options  Options `{ id, label }`.
 * @param {Array<number>} props.value    Chosen ids.
 * @param {Function}      props.onChange Receives the new id list.
 * @param {string}        props.reason   Reason it is blocked, or ''.
 * @return {Element} Picker.
 */
export function TermsPicker( { id, label, options, value, onChange, reason } ) {
	const [ search, setSearch ] = useState( '' );
	const chosen = Array.isArray( value ) ? value : [];
	const shown = useMemo( () => {
		const needle = search.trim().toLocaleLowerCase();
		return needle
			? options.filter( ( option ) =>
					option.label.toLocaleLowerCase().includes( needle )
				)
			: options;
	}, [ options, search ] );

	const toggle = ( termId, on ) =>
		onChange(
			on
				? [ ...chosen, termId ]
				: chosen.filter( ( each ) => each !== termId )
		);

	return (
		<fieldset
			className="bulkcat-bulk__fg is-full bulkcat-bulk__terms"
			{ ...described( id, reason, '' ) }
		>
			<legend className="bulkcat-bulk__lbl">{ label }</legend>
			{ options.length === 0 ? (
				<p className="bulkcat-bulk__hint">
					{ __(
						'There is nothing to choose from yet.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) : (
				<>
					<label
						className="screen-reader-text"
						htmlFor={ id + '-search' }
					>
						{ __(
							'Search the list',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</label>
					<input
						id={ id + '-search' }
						type="search"
						className="bulkcat-bulk__field"
						value={ search }
						aria-label={ __(
							'Search the list',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						placeholder={ __(
							'Search the list',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						onChange={ ( event ) =>
							setSearch( event.target.value )
						}
					/>
					<ul className="bulkcat-bulk__termlist">
						{ shown.map( ( option ) => (
							<li key={ option.id }>
								<label
									className="bulkcat-bulk__check"
									htmlFor={ id + '-term-' + option.id }
								>
									<input
										id={ id + '-term-' + option.id }
										type="checkbox"
										checked={ chosen.includes( option.id ) }
										onChange={ ( event ) =>
											toggle(
												option.id,
												event.target.checked
											)
										}
									/>
									<span>{ option.label }</span>
								</label>
							</li>
						) ) }
					</ul>
				</>
			) }
			{ reason && (
				<p className="bulkcat-bulk__reason" id={ id + '-reason' }>
					{ reason }
				</p>
			) }
		</fieldset>
	);
}

/**
 * The `value` control, typed by the chosen field.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.id       Control id.
 * @param {string}   props.label    Label.
 * @param {Object}   props.field    Field descriptor.
 * @param {*}        props.value    Value.
 * @param {Function} props.onChange Receives the new value.
 * @param {Object}   props.terms    Term options by source.
 * @param {string}   props.reason   Reason it is blocked, or ''.
 * @return {Element} Control.
 */
export function ValueControl( {
	id,
	label,
	field,
	value,
	onChange,
	terms,
	reason,
} ) {
	const hint = field?.nullable
		? __(
				'Leave it empty to clear this field.',
				'bulk-catalogue-manager-for-woocommerce'
			)
		: '';
	const aria = described( id, reason, hint );

	if ( field?.type === 'boolean' ) {
		return (
			<Group id={ id } label={ label } reason={ reason }>
				<select
					id={ id }
					className="bulkcat-bulk__field"
					value={ value ? '1' : '0' }
					onChange={ ( event ) =>
						onChange( event.target.value === '1' )
					}
					{ ...described( id, reason, '' ) }
				>
					<option value="1">
						{ __( 'On', 'bulk-catalogue-manager-for-woocommerce' ) }
					</option>
					<option value="0">
						{ __(
							'Off',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</option>
				</select>
			</Group>
		);
	}

	const choices = writableChoices( field );
	if ( choices.length ) {
		return (
			<Group id={ id } label={ label } reason={ reason }>
				<select
					id={ id }
					className="bulkcat-bulk__field"
					value={ String( value ?? '' ) }
					onChange={ ( event ) => onChange( event.target.value ) }
					{ ...described( id, reason, '' ) }
				>
					{ choices.map( ( choice ) => (
						<option key={ choice.value } value={ choice.value }>
							{ choice.label }
						</option>
					) ) }
				</select>
			</Group>
		);
	}

	if ( field?.type === 'term' ) {
		const options = termOptions( field, terms );
		return (
			<Group id={ id } label={ label } reason={ reason }>
				<select
					id={ id }
					className="bulkcat-bulk__field"
					value={ String( value ?? 0 ) }
					onChange={ ( event ) =>
						onChange( Number( event.target.value ) )
					}
					{ ...described( id, reason, '' ) }
				>
					<option value="0">
						{ __(
							'None',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</option>
					{ options.map( ( option ) => (
						<option key={ option.id } value={ option.id }>
							{ option.label }
						</option>
					) ) }
				</select>
			</Group>
		);
	}

	const numeric = [ 'money', 'decimal', 'integer' ].includes( field?.type );
	return (
		<Group id={ id } label={ label } reason={ reason } hint={ hint }>
			<input
				id={ id }
				type="text"
				className="bulkcat-bulk__field"
				inputMode={ numeric ? 'decimal' : undefined }
				maxLength={ field?.maxLength || undefined }
				value={ value ?? '' }
				onChange={ ( event ) => onChange( event.target.value ) }
				{ ...aria }
			/>
		</Group>
	);
}

/**
 * The control for one argument of an action.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.id       Control id.
 * @param {string}   props.name     Argument name.
 * @param {Object}   props.property JSON Schema property.
 * @param {*}        props.value    Value.
 * @param {Function} props.onChange Receives the new value.
 * @param {Object}   props.field    The chosen field's descriptor.
 * @param {Object}   props.terms    Term options by source.
 * @param {string}   props.reason   Reason it is blocked, or ''.
 * @return {Element} Control.
 */
export function ArgControl( {
	id,
	name,
	property,
	value,
	onChange,
	field,
	terms,
	reason,
} ) {
	const kind = propertyKind( name, property );
	const label = property.title || name;

	if ( kind === 'value' ) {
		return (
			<ValueControl
				id={ id }
				label={ label }
				field={ field }
				value={ value }
				onChange={ onChange }
				terms={ terms }
				reason={ reason }
			/>
		);
	}

	if ( kind === 'ids' ) {
		return (
			<TermsPicker
				id={ id }
				label={ field?.label || label }
				options={ termOptions( field, terms ) }
				value={ value }
				onChange={ onChange }
				reason={ reason }
			/>
		);
	}

	if ( kind === 'multichoice' ) {
		// An add-on's list of choices (T105): a checkbox per choice, sent as a list of values.
		return (
			<TermsPicker
				id={ id }
				label={ label }
				options={ ( property.choices || [] ).map( ( choice ) => ( {
					id: choice.value,
					label: choice.label,
				} ) ) }
				value={ value }
				onChange={ onChange }
				reason={ reason }
			/>
		);
	}

	if ( kind === 'boolean' ) {
		return (
			<div className="bulkcat-bulk__fg is-full">
				<label className="bulkcat-bulk__check" htmlFor={ id }>
					<input
						id={ id }
						type="checkbox"
						checked={ !! value }
						onChange={ ( event ) =>
							onChange( event.target.checked )
						}
					/>
					<span>{ label }</span>
				</label>
			</div>
		);
	}

	if ( kind === 'choice' ) {
		const choices =
			property.choices ||
			property.enum.map( ( each ) => ( { value: each, label: each } ) );
		return (
			<Group id={ id } label={ label } reason={ reason }>
				<select
					id={ id }
					className="bulkcat-bulk__field"
					value={ String( value ?? '' ) }
					onChange={ ( event ) => onChange( event.target.value ) }
					{ ...described( id, reason, '' ) }
				>
					{ ! property.required &&
						! property.enum.includes( String( value ?? '' ) ) && (
							<option value="">—</option>
						) }
					{ choices.map( ( choice ) => (
						<option key={ choice.value } value={ choice.value }>
							{ choice.label }
						</option>
					) ) }
				</select>
			</Group>
		);
	}

	const numeric = kind === 'integer' || kind === 'decimal';
	return (
		<Group id={ id } label={ label } reason={ reason }>
			<input
				id={ id }
				type={ kind === 'integer' ? 'number' : 'text' }
				className="bulkcat-bulk__field"
				inputMode={ numeric ? 'decimal' : undefined }
				min={ kind === 'integer' ? property.minimum : undefined }
				max={ kind === 'integer' ? property.maximum : undefined }
				step={ kind === 'integer' ? 1 : undefined }
				maxLength={ property.maxLength || undefined }
				value={ value ?? '' }
				onChange={ ( event ) => onChange( event.target.value ) }
				{ ...described( id, reason, '' ) }
			/>
		</Group>
	);
}
