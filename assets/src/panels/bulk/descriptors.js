/**
 * Pure helpers over the `/fields` payload (`{ fields, actions, groups }`,
 * SPEC §6, §12.1): the chooser, the argument controls and the operation list
 * are all generated from the action descriptors, so no action, argument or
 * group is listed here by hand.
 *
 * External actions (T105, docs/EXTENSIONS.md §5.3) come from the same
 * descriptors: an entry with a `provider` belongs to a separately installed
 * add-on, one without is native. The add-on's groups follow the native ones
 * in the registry's `groups`, an action it cannot offer now carries
 * `available: false` with its reason, and one change holds either native
 * steps or one add-on's steps (mixesKinds()).
 */
import { __ } from '@wordpress/i18n';

/** Most steps one change may hold (Operations\Registry::validate_list()). */
export const MAX_OPERATIONS = 20;

/** Arguments with a control of their own rather than the generic one. */
export const SPECIAL_ARGS = [
	'field',
	'skip_already',
	'apply_to_variations',
	'keep_changed_status',
];

/**
 * A field descriptor by key.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {string} key      Field key.
 * @return {Object|null} The field.
 */
export function fieldByKey( registry, key ) {
	return (
		( registry?.fields || [] ).find( ( field ) => field.key === key ) ||
		null
	);
}

/**
 * An action descriptor by id.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {string} id       Action id.
 * @return {Object|null} The action.
 */
export function actionById( registry, id ) {
	return (
		( registry?.actions || [] ).find( ( action ) => action.id === id ) ||
		null
	);
}

/**
 * The chooser: each group in the registry's order with the actions offering
 * a field in it. An action whose fields span groups (Set a value) appears in
 * each, and choosing it under a group offers only that group's fields.
 *
 * @param {Object} registry The `/fields` payload.
 * @return {Array<{id: string, label: string, actions: Array<Object>}>} Groups with actions.
 */
export function groupedActions( registry ) {
	const actions = registry?.actions || [];
	return ( registry?.groups || [] )
		.map( ( group ) => ( {
			...group,
			actions: actions.filter( ( action ) =>
				( action.groups?.length
					? action.groups
					: [ action.group ]
				).includes( group.id )
			),
		} ) )
		.filter( ( group ) => group.actions.length > 0 );
}

/**
 * The field keys an action offers under a group, in the action's order.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {Object} action   Action descriptor.
 * @param {string} group    Group id, or '' for every field.
 * @return {Array<string>} Field keys.
 */
export function fieldsFor( registry, action, group ) {
	const keys = action?.fields || [];
	if ( ! group ) {
		return keys;
	}
	const inGroup = keys.filter(
		( key ) => fieldByKey( registry, key )?.group === group
	);
	return inGroup.length ? inGroup : keys;
}

/**
 * Whether a schema property is a choice list.
 *
 * @param {Object} property JSON Schema property.
 * @return {boolean} True for a choice.
 */
export function isChoice( property ) {
	return Array.isArray( property?.enum ) && property.enum.length > 0;
}

/**
 * The typed kind of a schema property, for its control.
 *
 * @param {string} name     Argument name.
 * @param {Object} property JSON Schema property.
 * @return {string} boolean, choice, integer, decimal, text, ids or value.
 */
export function propertyKind( name, property ) {
	const type = property?.type;
	const types = Array.isArray( type ) ? type : [ type ];
	if ( isChoice( property ) ) {
		return 'choice';
	}
	if ( types.includes( 'boolean' ) && types.length > 1 ) {
		return 'value';
	}
	if ( types.includes( 'boolean' ) ) {
		return 'boolean';
	}
	if ( types.includes( 'array' ) ) {
		return Array.isArray( property?.items?.enum ) ? 'multichoice' : 'ids';
	}
	if ( types.includes( 'integer' ) ) {
		return 'integer';
	}
	if ( types.includes( 'number' ) ) {
		return 'decimal';
	}
	return 'text';
}

/**
 * The argument names that are shown only for one value of a choice: an
 * optional argument named after a value of another choice argument in the
 * same schema (Round's `decimals`, `step` and `ending` for its `to`).
 *
 * @param {Object} schema The action's argument schema.
 * @return {Object<string, {on: string, value: string}>} Name => the choice and value revealing it.
 */
export function dependentArgs( schema ) {
	const properties = schema?.properties || {};
	const out = {};
	Object.entries( properties ).forEach( ( [ name, property ] ) => {
		if ( ! isChoice( property ) ) {
			return;
		}
		property.enum.forEach( ( value ) => {
			const other = properties[ value ];
			if ( other && value !== name && ! other.required ) {
				out[ value ] = { on: name, value };
			}
		} );
	} );
	return out;
}

/**
 * Whether an argument's control is showing for the arguments chosen so far.
 *
 * @param {string} name   Argument name.
 * @param {Object} schema Argument schema.
 * @param {Object} args   Arguments chosen so far.
 * @return {boolean} True when shown.
 */
export function isArgShown( name, schema, args ) {
	const rule = dependentArgs( schema )[ name ];
	return ! rule || args[ rule.on ] === rule.value;
}

/**
 * The starting arguments for an action and field: each default, and the
 * first choice of a required choice.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {Object} action   Action descriptor.
 * @param {string} group    Group the action was chosen under.
 * @return {Object} Arguments.
 */
export function defaultArgs( registry, action, group = '' ) {
	const args = {};
	const properties = action?.args?.properties || {};
	Object.entries( properties ).forEach( ( [ name, property ] ) => {
		if ( name === 'field' ) {
			args.field = fieldsFor( registry, action, group )[ 0 ] ?? '';
			return;
		}
		if ( property.default !== undefined ) {
			args[ name ] = property.default;
			return;
		}
		if ( isChoice( property ) && property.required ) {
			args[ name ] = property.enum[ 0 ];
		}
	} );
	const dependent = dependentArgs( action?.args );
	Object.keys( dependent ).forEach( ( name ) => {
		if ( args[ name ] === undefined ) {
			args[ name ] = '';
		}
	} );
	if ( properties.value ) {
		args.value = defaultValue( fieldByKey( registry, args.field ) );
	}
	return args;
}

/**
 * The first value a "set" control starts at for a field.
 *
 * @param {Object|null} field Field descriptor.
 * @return {*} The value.
 */
export function defaultValue( field ) {
	if ( ! field ) {
		return '';
	}
	if ( field.type === 'boolean' ) {
		return true;
	}
	const choices = writableChoices( field );
	if ( choices.length ) {
		return choices[ 0 ].value;
	}
	if ( field.type === 'term' ) {
		return 0;
	}
	return '';
}

/**
 * The choices a "set" may write for a field: its choices limited to
 * `writableChoices`.
 *
 * @param {Object|null} field Field descriptor.
 * @return {Array<{value: string, label: string}>} Choices.
 */
export function writableChoices( field ) {
	const choices = field?.choices || [];
	const writable = field?.writableChoices;
	return Array.isArray( writable )
		? choices.filter( ( choice ) => writable.includes( choice.value ) )
		: choices;
}

/**
 * Whether a value counts as empty for a required argument.
 *
 * @param {*} value Value.
 * @return {boolean} True when empty.
 */
function isEmpty( value ) {
	return (
		value === undefined ||
		value === null ||
		value === '' ||
		( Array.isArray( value ) && value.length === 0 )
	);
}

/**
 * Whether a number is inside a property's range.
 *
 * @param {number} number   The number.
 * @param {Object} property JSON Schema property.
 * @return {boolean} True when inside.
 */
function inRange( number, property ) {
	if ( property.minimum !== undefined ) {
		if (
			property.exclusiveMinimum
				? number <= property.minimum
				: number < property.minimum
		) {
			return false;
		}
	}
	if ( property.maximum !== undefined ) {
		if (
			property.exclusiveMaximum
				? number >= property.maximum
				: number > property.maximum
		) {
			return false;
		}
	}
	return true;
}

/**
 * The first problem with a step, as the argument it concerns and the
 * server's own sentence for it (the schema's `description` is the rule), or
 * null when the step can be checked.
 *
 * @param {Object} registry  The `/fields` payload.
 * @param {Object} operation Panel operation `{ action, args }`.
 * @return {{arg: string, reason: string}|null} The problem.
 */
export function operationProblem( registry, operation ) {
	const action = actionById( registry, operation?.action );
	if ( ! action ) {
		return { arg: '', reason: '' };
	}
	const properties = action.args?.properties || {};
	const args = operation.args || {};
	for ( const [ name, property ] of Object.entries( properties ) ) {
		if ( ! isArgShown( name, action.args, args ) ) {
			continue;
		}
		const value = args[ name ];
		const kind = propertyKind( name, property );
		const reason = property.description || '';
		const needed =
			property.required ||
			( dependentArgs( action.args )[ name ] && kind !== 'boolean' );
		if ( isEmpty( value ) ) {
			const clears =
				kind === 'value' &&
				!! fieldByKey( registry, args.field )?.nullable;
			if ( needed && ! clears ) {
				return { arg: name, reason };
			}
			continue;
		}
		if (
			kind === 'choice' &&
			! property.enum.includes( String( value ) )
		) {
			return { arg: name, reason };
		}
		if ( kind === 'integer' || kind === 'decimal' ) {
			const number = Number( value );
			if (
				! Number.isFinite( number ) ||
				( kind === 'integer' && ! Number.isInteger( number ) ) ||
				! inRange( number, property )
			) {
				return { arg: name, reason };
			}
		}
		if ( kind === 'text' ) {
			if (
				( property.minLength &&
					String( value ).length < property.minLength ) ||
				( property.maxLength &&
					String( value ).length > property.maxLength )
			) {
				return { arg: name, reason };
			}
		}
		if (
			kind === 'ids' &&
			property.maxItems &&
			value.length > property.maxItems
		) {
			return { arg: name, reason };
		}
		if (
			kind === 'multichoice' &&
			( ! Array.isArray( value ) ||
				( property.maxItems && value.length > property.maxItems ) ||
				( property.minItems && value.length < property.minItems ) ||
				value.some(
					( each ) => ! property.items.enum.includes( each )
				) )
		) {
			return { arg: name, reason };
		}
	}
	return null;
}

/**
 * The operation list as the REST routes take it: `[ { action, args } ]`,
 * each with only the arguments its schema knows and that are showing, and
 * `apply_to_variations` from the step's switch where the field offers it
 * (T69; left out, the server takes the same default).
 *
 * @param {Object}        registry   The `/fields` payload.
 * @param {Array<Object>} operations Panel operations.
 * @return {Array<{action: string, args: Object}>} Operations.
 */
export function toRequestOperations( registry, operations ) {
	return operations
		.filter( ( operation ) => operation.action )
		.map( ( operation ) => {
			const action = actionById( registry, operation.action );
			const properties = action?.args?.properties || {};
			const args = {};
			Object.keys( properties ).forEach( ( name ) => {
				const value = operation.args?.[ name ];
				if (
					value === undefined ||
					! isArgShown( name, action.args, operation.args || {} ) ||
					( value === '' &&
						propertyKind( name, properties[ name ] ) !== 'value' &&
						! properties[ name ].required )
				) {
					return;
				}
				args[ name ] = value;
			} );
			if (
				properties.apply_to_variations &&
				offersVariations(
					fieldByKey( registry, operation.args?.field )
				)
			) {
				args.apply_to_variations = !! operation.variations;
			}
			return { action: operation.action, args };
		} );
}

/**
 * The `bulkcat_settings` values, whether given the settings themselves or the
 * screen's localised object that carries them under `settings`.
 *
 * @param {Object} settings Settings.
 * @return {Object} The bulkcat_settings values.
 */
export function settingsValues( settings ) {
	if (
		settings &&
		typeof settings.settings === 'object' &&
		settings.settings !== null
	) {
		return settings.settings;
	}
	return settings || {};
}

/**
 * Whether **Also apply to variations** is offered for a field (SPEC §8):
 * absent for a parent-only or derived field.
 *
 * @param {Object|null} field Field descriptor.
 * @return {boolean} True when offered.
 */
export function offersVariations( field ) {
	return (
		!! field &&
		[ 'per_variation', 'conditional' ].includes( field.variations )
	);
}

/**
 * The setting that gives a field's starting position for **Also apply to
 * variations**, by the field's panel group as the server takes it
 * (BaseOperation::variation_default(), SPEC §6, §11): Publishing the status
 * switch, Pricing the price switch, Stock the stock switch; any other group
 * starts off.
 *
 * @param {Object|null} field Field descriptor.
 * @return {string|null} Setting key.
 */
export function variationsSetting( field ) {
	return VARIATION_SETTINGS[ field?.group ] || null;
}

/** Panel group => the setting its fields' variations switch starts from. */
const VARIATION_SETTINGS = {
	publishing: 'variations_status',
	pricing: 'variations_price',
	stock: 'variations_stock',
};

/**
 * The starting position of **Also apply to variations** for a field.
 *
 * @param {Object|null} field    Field descriptor.
 * @param {Object}      settings Settings (either shape settingsValues() takes).
 * @return {boolean} True when it starts on.
 */
export function variationsDefault( field, settings ) {
	if ( ! offersVariations( field ) ) {
		return false;
	}
	const key = variationsSetting( field );
	return !! key && !! settingsValues( settings )[ key ];
}

/**
 * Why an action cannot be chosen now: its add-on is unavailable, or the user
 * may not use it (the server's own sentence); '' when it can.
 *
 * @param {Object|null} action Action descriptor.
 * @return {string} Reason.
 */
export function unavailableReason( action ) {
	if ( ! action?.provider || action.available !== false ) {
		return '';
	}
	return (
		action.unavailableReason ||
		__(
			'This action is not available right now.',
			'bulk-catalogue-manager-for-woocommerce'
		)
	);
}

/**
 * The kind of change a step makes: '' for a native action, else its add-on's id.
 *
 * @param {Object} registry  The `/fields` payload.
 * @param {Object} operation Panel operation.
 * @return {string} Kind.
 */
export function changeKind( registry, operation ) {
	return actionById( registry, operation?.action )?.provider || '';
}

/**
 * Whether the chosen steps mix kinds of change: native steps with an
 * add-on's, or two add-ons' (version 1 of the contract keeps one kind per change).
 *
 * @param {Object}        registry   The `/fields` payload.
 * @param {Array<Object>} operations Panel operations.
 * @return {boolean} True when mixed.
 */
export function mixesKinds( registry, operations ) {
	const kinds = new Set(
		( operations || [] )
			.filter( ( operation ) => operation.action )
			.map( ( operation ) => changeKind( registry, operation ) )
	);
	return kinds.size > 1;
}

/**
 * The sentence shown in place when a change mixes kinds (the server's own words).
 *
 * @return {string} Sentence.
 */
export function mixedSentence() {
	return __(
		"This action can't be combined with other kinds of change. Make it a separate change.",
		'bulk-catalogue-manager-for-woocommerce'
	);
}
