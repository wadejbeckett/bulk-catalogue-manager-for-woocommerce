/**
 * What the registry says about editing one field on one row, as pure
 * functions (T45–T47): whether a cell edits in place, why a field does not
 * apply to a row, the client-side pre-check mirroring the registry's
 * validator (Fields\Field::validate() and rule_text(), SPEC §4.8), the value
 * a PATCH sends, and the sentence a refusal from the server gives for a field.
 *
 * The server stays the judge: a value the pre-check lets through can still be
 * refused 400 naming the field, and that sentence is shown the same way.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import { plainMessage } from '../../../errors';
import { choiceLabel } from '../cells';
import { REQUIRES, ROW, VARIABLE_TYPE, VISIBILITY } from '../defaults';

/** Registry value types (Fields\Field constants). */
const TEXT = 'text';
const ENUM = 'enum';
const BOOLEAN = 'boolean';
const MONEY = 'money';
const DECIMAL = 'decimal';
const INTEGER = 'integer';
const TERMS = 'terms';
const TERM = 'term';

/** Registry variation rules that keep a field off a variation. */
const NOT_ON_VARIATIONS = [ 'parent_only', 'derived' ];

/** Most terms a term list takes (Fields\Values::MAX_TERMS). */
const MAX_TERMS = 100;

export const isEmpty = ( value ) =>
	value === undefined ||
	value === null ||
	( typeof value === 'string' && value.trim() === '' );

/**
 * Whether a row is a variation.
 *
 * @param {Object} item Row.
 * @return {boolean} Variation.
 */
export function isVariation( item ) {
	return Number( item?.[ ROW.parentId ] ) > 0;
}

/**
 * Whether a field is the "Shown in shop and search" switch.
 *
 * @param {Object} descriptor Registry descriptor.
 * @return {boolean} The switch.
 */
export function isSwitch( descriptor ) {
	return descriptor?.key === VISIBILITY.field;
}

/**
 * Whether the registry marks a field editable in the grid.
 *
 * @param {Object} descriptor Registry descriptor.
 * @return {boolean} Inline-editable.
 */
export function isInline( descriptor ) {
	return descriptor?.inline === true && descriptor?.readOnly !== true;
}

/**
 * Why a field cannot be written on this row, in the registry's own words, or
 * '' when it can (Fields\Field::unavailable_reason(), SPEC §5.2, §8).
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {Object} item       Row.
 * @return {string} Reason, or ''.
 */
export function unavailableReason( descriptor, item ) {
	if ( descriptor?.readOnly ) {
		return sprintf(
			/* translators: %s: a field's name, such as "Total sales". */
			__(
				'%s cannot be changed here. It is shown for information only.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			descriptor.label
		);
	}
	if (
		isVariation( item ) &&
		NOT_ON_VARIATIONS.includes( descriptor.variations )
	) {
		return descriptor.variationNote || '';
	}
	if (
		! isVariation( item ) &&
		descriptor.variableParentNote &&
		item?.[ VARIABLE_TYPE.field ] === VARIABLE_TYPE.value
	) {
		return descriptor.variableParentNote;
	}
	const needs = REQUIRES[ descriptor.key ];
	if ( needs && item && needs in item && item[ needs ] !== true ) {
		return descriptor.variationNote || '';
	}
	return '';
}

/**
 * The choices a row may take for a choice field: a variation's own list, a
 * product's without the variation-only ones (Field::object_refusal()).
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {Object} item       Row.
 * @return {Array<{value: string, label: string}>} Choices.
 */
export function allowedChoices( descriptor, item ) {
	const choices = descriptor.choices || [];
	const writable = descriptor.writableChoices || [];
	const allowed = isVariation( item )
		? descriptor.variationChoices || writable
		: writable.filter(
				( value ) =>
					! ( descriptor.variationOnlyChoices || [] ).includes(
						value
					)
			);
	// Only value and label: a choice also carries the registry's flags
	// (hidesProduct, hidesAs), which must not reach an <option>.
	return choices
		.filter( ( choice ) => allowed.includes( choice.value ) )
		.map( ( { value, label } ) => ( { value, label } ) );
}

/**
 * What a field accepts, as the §4.8 sentence (Field::rule_text()).
 *
 * @param {Object} descriptor Registry descriptor.
 * @return {string} Sentence.
 */
export function ruleText( descriptor ) {
	const label = descriptor.label;
	const max = Number( descriptor.maxLength ) || 0;
	switch ( descriptor.type ) {
		case TEXT:
			return descriptor.nullable
				? sprintf(
						/* translators: 1: a field's name, such as "SKU", 2: the most characters it takes. */
						_n(
							'%1$s has to be text of up to %2$d character, or left empty.',
							'%1$s has to be text of up to %2$d characters, or left empty.',
							max,
							'bulk-catalogue-manager-for-woocommerce'
						),
						label,
						max
					)
				: sprintf(
						/* translators: 1: a field's name, such as "Name", 2: the most characters it takes. */
						_n(
							'%1$s cannot be empty and has to be up to %2$d character.',
							'%1$s cannot be empty and has to be up to %2$d characters.',
							max,
							'bulk-catalogue-manager-for-woocommerce'
						),
						label,
						max
					);
		case ENUM:
			return sprintf(
				/* translators: 1: a field's name, such as "Stock status", 2: the choices, separated by commas. */
				__(
					'%1$s has to be one of: %2$s.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label,
				( descriptor.writableChoices || [] )
					.map( ( value ) => choiceLabel( descriptor, value ) )
					.join( ', ' )
			);
		case BOOLEAN:
			return sprintf(
				/* translators: %s: a field's name, such as "Featured". */
				__(
					'%s has to be on or off.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label
			);
		case MONEY:
			return sprintf(
				/* translators: %s: a field's name, such as "Regular price". */
				__(
					'%s has to be a price of 0 or more, like 12.50, or left empty.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label
			);
		case DECIMAL:
			return sprintf(
				/* translators: %s: a field's name, such as "Weight". */
				__(
					'%s has to be a number of 0 or more, like 1.5, or left empty.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label
			);
		case INTEGER:
			return sprintf(
				/* translators: %s: a field's name, such as "Stock quantity". */
				__(
					'%s has to be a whole number. Enter a number like 12.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label
			);
		case TERMS:
			return sprintf(
				/* translators: 1: a field's name, such as "Categories", 2: the most items allowed. */
				_n(
					'%1$s has to be a list of up to %2$d that already exists. Choose it from the list.',
					'%1$s has to be a list of up to %2$d that already exist. Choose them from the list.',
					MAX_TERMS,
					'bulk-catalogue-manager-for-woocommerce'
				),
				label,
				MAX_TERMS
			);
		default:
			return sprintf(
				/* translators: %s: a field's name, such as "Shipping class". */
				__(
					'%s has to be one that already exists. Choose it from the list.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label
			);
	}
}

/**
 * A decimal as the server reads one: digits with an optional point or comma.
 * The comma is left for the server to judge against the shop's separator.
 *
 * @param {*} value Value.
 * @return {boolean} A decimal of zero or more.
 */
function nonNegativeDecimal( value ) {
	if ( typeof value === 'number' ) {
		return Number.isFinite( value ) && value >= 0;
	}
	return /^(?:\d+(?:[.,]\d*)?|[.,]\d+)$/.test( String( value ).trim() );
}

/**
 * The pre-check: the §4.8 sentence for a value the registry's validator
 * would refuse, or '' when it passes (Field::validate() plus the choice
 * rules that need the row).
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {*}      value      Value, as typed or chosen.
 * @param {Object} item       Row.
 * @return {string} Sentence, or ''.
 */
export function validate( descriptor, value, item ) {
	if ( descriptor.nullable && isEmpty( value ) ) {
		return '';
	}
	let ok;
	switch ( descriptor.type ) {
		case TEXT: {
			const text = isEmpty( value ) ? '' : String( value ).trim();
			const max = Number( descriptor.maxLength ) || 0;
			ok = text !== '' && ( max === 0 || [ ...text ].length <= max );
			break;
		}
		case ENUM: {
			if ( ! ( descriptor.writableChoices || [] ).includes( value ) ) {
				ok = false;
				break;
			}
			const allowed = allowedChoices( descriptor, item );
			if ( ! allowed.some( ( choice ) => choice.value === value ) ) {
				const labels = allowed
					.map( ( choice ) => choice.label )
					.join( ', ' );
				return isVariation( item )
					? sprintf(
							/* translators: 1: a field's name, such as "Status", 2: the choices, separated by commas. */
							__(
								'On a variation, %1$s can only be: %2$s.',
								'bulk-catalogue-manager-for-woocommerce'
							),
							descriptor.label,
							labels
						)
					: sprintf(
							/* translators: 1: a field's name, such as "Tax class", 2: the choices, separated by commas. */
							__(
								'On a product, %1$s can only be: %2$s.',
								'bulk-catalogue-manager-for-woocommerce'
							),
							descriptor.label,
							labels
						);
			}
			ok = true;
			break;
		}
		case BOOLEAN:
			ok = typeof value === 'boolean';
			break;
		case MONEY:
		case DECIMAL:
			ok = ! isEmpty( value ) && nonNegativeDecimal( value );
			break;
		case INTEGER:
			ok =
				( typeof value === 'number' && Number.isInteger( value ) ) ||
				/^\s*-?\d+\s*$/.test( String( value ?? '' ) );
			break;
		case TERMS:
			ok =
				Array.isArray( value ) &&
				value.length <= MAX_TERMS &&
				value.every( ( id ) => Number.isInteger( Number( id ) ) );
			break;
		case TERM:
			ok = Number.isInteger( Number( value ) ) && Number( value ) >= 0;
			break;
		default:
			ok = false;
	}
	return ok ? '' : ruleText( descriptor );
}

/**
 * The value a PATCH sends for what was typed or chosen: an empty nullable
 * value as the field's empty value, a whole number as a number, text
 * trimmed, anything else as it is.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {*}      value      Value.
 * @return {*} Value to send.
 */
export function toRequestValue( descriptor, value ) {
	if ( isEmpty( value ) ) {
		return descriptor.type === INTEGER ? null : '';
	}
	switch ( descriptor.type ) {
		case INTEGER:
		case TERM:
			return Number( String( value ).trim() );
		case MONEY:
		case DECIMAL:
		case TEXT:
			return String( value ).trim();
		default:
			return value;
	}
}

/**
 * A value in a form two values can be compared in: an empty value as '',
 * a number-like value by its number, a list by its sorted members.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {*}      value      Value.
 * @return {string} Comparable form.
 */
export function comparable( descriptor, value ) {
	if ( isEmpty( value ) ) {
		return '';
	}
	if ( Array.isArray( value ) ) {
		return value
			.map( Number )
			.sort( ( a, b ) => a - b )
			.join( ',' );
	}
	if (
		[ MONEY, DECIMAL, INTEGER, TERM ].includes( descriptor.type ) &&
		nonNegativeDecimal( String( value ).replace( /^-/, '' ) )
	) {
		const number = Number( String( value ).trim().replace( ',', '.' ) );
		return Number.isNaN( number ) ? String( value ) : String( number );
	}
	return String( value ).trim();
}

/**
 * The server's sentence for one field of a refused write: core's
 * `rest_invalid_param` carries it in `data.params`, the write's own refusal
 * names the field in `data.field` (or lists it in `data.fields`) with its
 * sentence as the message.
 *
 * @param {*}      error The rejection.
 * @param {string} key   Field key.
 * @return {string} Sentence, or '' when the refusal is not about this field.
 */
export function fieldError( error, key ) {
	const data = error?.data || {};
	if ( data.params && typeof data.params[ key ] === 'string' ) {
		return data.params[ key ];
	}
	if (
		data.field === key ||
		( Array.isArray( data.fields ) && data.fields.includes( key ) )
	) {
		return typeof error.message === 'string' ? error.message : '';
	}
	return '';
}

/**
 * Every field a refusal names, with its sentence.
 *
 * @param {*}             error The rejection.
 * @param {Array<string>} keys  Field keys that were sent.
 * @return {Object} key => sentence.
 */
export function fieldErrors( error, keys ) {
	const found = {};
	keys.forEach( ( key ) => {
		const message = fieldError( error, key );
		if ( message ) {
			found[ key ] = message;
		}
	} );
	return found;
}

/**
 * The plain sentence of a failed write that names no field (SPEC §4.8),
 * by the rule every panel follows (errors.js, T114): the plugin's own
 * refusal in its words, anything else — a server's or a proxy's page,
 * WordPress's own JSON message, whatever the status — as the fallback.
 *
 * @param {*}      error    The rejection.
 * @param {string} fallback The sentence for anything but the plugin's refusal.
 * @return {string} Sentence.
 */
export function failureText( error, fallback ) {
	return plainMessage( error, fallback );
}
