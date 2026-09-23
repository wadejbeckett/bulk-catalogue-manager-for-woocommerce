/**
 * Filters (T41, SPEC §4.1, §12.1): the DataViews filter definitions built
 * from the registry's `filterBy` and choices, and the one mapping from a
 * view to GET /products arguments, `viewToQuery()`.
 *
 * A filter argument is named after its field and carries `{ operator,
 * value }` (RouteSchema::filter_args()). Every value is shaped here the way
 * Query\Criteria reads it; a filter that is not complete yet (a range with
 * no bound, a date that does not parse) sends nothing rather than a request
 * the route would refuse.
 */
import { __ } from '@wordpress/i18n';

import { toQuery } from '../query';

/** Units an "in the past" filter counts in (Criteria::PAST_UNITS). */
export const PAST_UNITS = [ 'days', 'weeks', 'months', 'years' ];

/** Arguments that page, sort or shape a read rather than choose its rows. */
const PAGING = [ 'page', 'per_page', 'fields', 'orderby', 'order' ];

/**
 * What kind of value a field's filter takes, from what the registry says
 * about the field.
 *
 * @param {Object} descriptor Registry descriptor.
 * @return {string} 'terms', 'choice', 'boolean', 'range', 'date' or ''.
 */
export function filterKind( descriptor ) {
	const type = descriptor?.dataviews?.type;
	if ( descriptor?.source ) {
		return 'terms';
	}
	if ( Array.isArray( descriptor?.dataviews?.elements ) ) {
		return 'choice';
	}
	if ( type === 'boolean' ) {
		return 'boolean';
	}
	if ( type === 'number' || type === 'integer' ) {
		return 'range';
	}
	if ( type === 'datetime' || type === 'date' ) {
		return 'date';
	}
	return '';
}

/**
 * The operators the registry offers a field's filter, or none.
 *
 * @param {Object} descriptor Registry descriptor.
 * @return {Array<string>} Operators.
 */
function operatorsOf( descriptor ) {
	const operators = descriptor?.dataviews?.filterBy?.operators;
	return Array.isArray( operators ) ? operators : [];
}

/**
 * The two choices of a switch filter.
 *
 * @return {Array<Object>} Elements.
 */
function switchElements() {
	return [
		{
			value: true,
			label: __( 'On', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		{
			value: false,
			label: __( 'Off', 'bulk-catalogue-manager-for-woocommerce' ),
		},
	];
}

/**
 * The choices of a term filter from the names the client knows, or none.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {Object} termNames  source => Map( id => name ).
 * @return {Array<Object>|null} Elements, or null when the terms cannot be named.
 */
function termElements( descriptor, termNames ) {
	const names = termNames?.[ descriptor.source ];
	if ( ! ( names instanceof Map ) || names.size === 0 ) {
		return null;
	}
	return Array.from( names, ( [ id, name ] ) => ( {
		value: Number( id ),
		label: name,
	} ) );
}

/**
 * The DataViews filter definition for one field: `filterBy` and, where the
 * owner picks from a list, `elements`. A field the registry does not make
 * filterable, or a term field whose terms the client cannot name yet, gets
 * `filterBy: false`.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {Object} termNames  source => Map( id => name ).
 * @return {Object} `{ filterBy, elements? }`.
 */
export function filterDefinition( descriptor, termNames = {} ) {
	const operators = operatorsOf( descriptor );
	const kind = filterKind( descriptor );
	if ( ! operators.length || ! kind ) {
		return { filterBy: false };
	}
	const filterBy = { operators };
	switch ( kind ) {
		case 'terms': {
			const elements = termElements( descriptor, termNames );
			return elements ? { filterBy, elements } : { filterBy: false };
		}
		case 'boolean':
			return { filterBy, elements: switchElements() };
		default:
			return { filterBy };
	}
}

/**
 * The fields a filter can be offered on, with their definitions applied.
 *
 * @param {Array<Object>} fields      DataViews fields (fieldsFromRegistry()).
 * @param {Array<Object>} descriptors Registry descriptors.
 * @param {Object}        termNames   source => Map( id => name ).
 * @return {Array<Object>} DataViews fields.
 */
export function withFilters( fields, descriptors, termNames = {} ) {
	const byKey = new Map(
		descriptors.map( ( descriptor ) => [ descriptor.key, descriptor ] )
	);
	return fields.map( ( field ) => {
		const descriptor = byKey.get( field.id );
		return descriptor
			? { ...field, ...filterDefinition( descriptor, termNames ) }
			: field;
	} );
}

/**
 * The term sources the filters need names for.
 *
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Array<string>} Sources.
 */
export function filterSources( descriptors ) {
	return descriptors
		.filter(
			( descriptor ) =>
				descriptor.source && operatorsOf( descriptor ).length
		)
		.map( ( descriptor ) => descriptor.source );
}

/**
 * A number from a filter bound, or null for an empty or malformed one.
 *
 * @param {*}       value   Bound.
 * @param {boolean} integer Whether it must be whole.
 * @return {number|null} Number.
 */
function bound( value, integer ) {
	if ( value === '' || value === null || value === undefined ) {
		return null;
	}
	const number = Number( value );
	if (
		! Number.isFinite( number ) ||
		( integer && ! Number.isInteger( number ) )
	) {
		return null;
	}
	return number;
}

/**
 * A date bound as Criteria reads it: `Y-m-d` kept as it is, anything else
 * that parses as a time turned into `Y-m-d H:i:s` in GMT.
 *
 * @param {*} value Date value (DataViews gives an ISO string).
 * @return {string|null} Date.
 */
export function dateBound( value ) {
	if ( typeof value !== 'string' || ! value ) {
		return null;
	}
	if ( /^\d{4}-\d{2}-\d{2}$/.test( value ) ) {
		return value;
	}
	const time = new Date( value );
	if ( Number.isNaN( time.getTime() ) ) {
		return null;
	}
	return time.toISOString().slice( 0, 19 ).replace( 'T', ' ' );
}

/**
 * One view filter as its route argument `{ operator, value }`, or null when
 * it is not complete. A range with only one bound becomes that one-sided
 * range, so what is sent always matches what the chip says.
 *
 * @param {Object} filter     View filter `{ field, operator, value }`.
 * @param {Object} descriptor Registry descriptor.
 * @return {Object|null} Argument.
 */
export function filterArg( filter, descriptor ) {
	const { operator, value } = filter;
	if ( ! descriptor || ! operatorsOf( descriptor ).includes( operator ) ) {
		return null;
	}
	const kind = filterKind( descriptor );
	const integer = descriptor.dataviews?.type === 'integer';
	switch ( kind ) {
		case 'terms':
		case 'choice': {
			const list = ( Array.isArray( value ) ? value : [ value ] ).filter(
				( each ) => each !== undefined && each !== null
			);
			if ( ! list.length ) {
				return null;
			}
			return {
				operator,
				value:
					kind === 'terms'
						? list.map( Number ).filter( ( id ) => id > 0 )
						: list.map( String ),
			};
		}
		case 'boolean':
			return typeof value === 'boolean' ? { operator, value } : null;
		case 'range': {
			if ( operator !== 'between' ) {
				const one = bound( value, integer );
				return one === null ? null : { operator, value: one };
			}
			const [ low, high ] = Array.isArray( value )
				? value.map( ( each ) => bound( each, integer ) )
				: [ null, null ];
			if ( low !== null && high !== null ) {
				return low <= high ? { operator, value: [ low, high ] } : null;
			}
			if ( low !== null ) {
				return { operator: 'greaterThanOrEqual', value: low };
			}
			if ( high !== null ) {
				return { operator: 'lessThanOrEqual', value: high };
			}
			return null;
		}
		case 'date': {
			if ( operator === 'inThePast' ) {
				const count = bound( value?.value, true );
				return count !== null &&
					count >= 1 &&
					PAST_UNITS.includes( value?.unit )
					? { operator, value: { value: count, unit: value.unit } }
					: null;
			}
			const date = dateBound( value );
			return date ? { operator, value: date } : null;
		}
		default:
			return null;
	}
}

/**
 * Every complete view filter as route arguments, keyed by field.
 *
 * @param {Array<Object>} filters     View filters.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Object} field => { operator, value }.
 */
export function filterArgs( filters, descriptors ) {
	const byKey = new Map(
		descriptors.map( ( descriptor ) => [ descriptor.key, descriptor ] )
	);
	const args = {};
	( filters || [] ).forEach( ( filter ) => {
		const arg = filterArg( filter, byKey.get( filter.field ) );
		if ( arg ) {
			args[ filter.field ] = arg;
		}
	} );
	return args;
}

/**
 * The view as GET /products arguments: paging, search, sort and fields
 * (toQuery()), every filter, then `extra` (the category panel's argument),
 * which wins over a filter on the same field.
 *
 * @param {Object}        view        DataViews view.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @param {Object}        extra       Further filter arguments.
 * @return {Object} Query arguments.
 */
export function viewToQuery( view, descriptors, extra = {} ) {
	return {
		...toQuery( view, descriptors ),
		...filterArgs( view.filters, descriptors ),
		...extra,
	};
}

/**
 * The conditions that choose the view's rows, for POST /selection: the
 * query without paging, sort and fields. Two views with the same conditions
 * select the same products.
 *
 * @param {Object}        view        DataViews view.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @param {Object}        extra       Further filter arguments.
 * @return {Object} Conditions.
 */
export function selectionQuery( view, descriptors, extra = {} ) {
	const query = viewToQuery( view, descriptors, extra );
	PAGING.forEach( ( key ) => delete query[ key ] );
	return query;
}

/**
 * The view without any filter on a field, back on page 1.
 *
 * @param {Object} view  DataViews view.
 * @param {string} field Field key.
 * @return {Object} View.
 */
export function withoutFilter( view, field ) {
	const filters = view.filters || [];
	if ( ! filters.some( ( filter ) => filter.field === field ) ) {
		return view;
	}
	return {
		...view,
		page: 1,
		filters: filters.filter( ( filter ) => filter.field !== field ),
	};
}
