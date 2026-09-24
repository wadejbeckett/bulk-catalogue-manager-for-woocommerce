/**
 * What the field registry (GET /fields) says about variations, read by what
 * each descriptor declares rather than by key (SPEC §5.1, §8): its variation
 * rule (`variations`: per_variation, parent_only, derived or conditional),
 * the reason a variation cannot take it (`variationNote`) and the reason a
 * variable parent has no value of its own (`variableParentNote`).
 */
import { COMPANIONS, ROW } from '../defaults';

/** Grid modes of GET /products (Query\Criteria::MODES). */
export const MODES = {
	products: 'products',
	both: 'products_and_variations',
	variations: 'variations',
};

/** The product type whose rows have variations (the type field's choice). */
export const VARIABLE = 'variable';

/** Most variation rows inserted under one product (SPEC §8 rule 4). */
export const CAP = 50;

const choiceValues = ( descriptor ) =>
	( descriptor.choices || descriptor.dataviews?.elements || [] ).map(
		( choice ) => choice.value
	);

/**
 * The fields with a role in the variation display: the parent's variation
 * count (read-only, whole number, parent-only), its product type (the
 * read-only choice field that offers the variable type) and the rendered
 * price (the one html field), each a key or undefined.
 *
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {{count: string|undefined, type: string|undefined, price: string|undefined}} Keys.
 */
export function variationRoles( descriptors ) {
	const find = ( test ) => descriptors.find( test )?.key;
	return {
		count: find(
			( field ) =>
				field.readOnly &&
				field.type === 'integer' &&
				field.variations === 'parent_only'
		),
		type: find(
			( field ) =>
				field.readOnly &&
				field.type === 'enum' &&
				choiceValues( field ).includes( VARIABLE )
		),
		price: find( ( field ) => field.type === 'html' ),
	};
}

/**
 * The field a conditional field depends on: the on/off field of its own
 * group that each variation carries (stock quantity applies where stock is
 * managed). A conditional field without one always applies.
 *
 * @param {Object}        descriptor  Conditional descriptor.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Object|undefined} The condition's descriptor.
 */
export function conditionOf( descriptor, descriptors ) {
	if ( descriptor?.variations !== 'conditional' || ! descriptor.group ) {
		return undefined;
	}
	return descriptors.find(
		( field ) =>
			field.type === 'boolean' &&
			field.variations === 'per_variation' &&
			field.group === descriptor.group &&
			! field.readOnly
	);
}

/**
 * Whether a variable parent's value of this field is worked out from its
 * variations: the registry gives it a reason (a price), or it is the stock
 * cell, which carries its companion quantity.
 *
 * @param {Object} descriptor Descriptor.
 * @return {boolean} True for a derived parent cell.
 */
export function derivedOnParent( descriptor ) {
	return !! descriptor?.variableParentNote || isStockSummary( descriptor );
}

/**
 * Whether this is the stock cell, which summarises a variable parent's
 * variations: the choice field shown with a companion (defaults.js
 * COMPANIONS); the regular price's companion is a second line, not a summary.
 *
 * @param {Object} descriptor Descriptor.
 * @return {boolean} True for the stock cell.
 */
export function isStockSummary( descriptor ) {
	return (
		!! descriptor &&
		descriptor.type === 'enum' &&
		!! COMPANIONS[ descriptor.key ]
	);
}

/**
 * The id of a row's parent product, 0 for a product.
 *
 * @param {Object} row Row.
 * @return {number} Parent id.
 */
export const parentIdOf = ( row ) => Number( row?.[ ROW.parentId ] ) || 0;

/**
 * Whether a row is a variation.
 *
 * @param {Object} row Row.
 * @return {boolean} True for a variation row.
 */
export const isVariation = ( row ) => parentIdOf( row ) > 0;

/**
 * How many variations a product row says it has, or null when the row does
 * not say.
 *
 * @param {Object} row   Row.
 * @param {Object} roles variationRoles().
 * @return {number|null} Count.
 */
export function countOf( row, roles ) {
	if ( ! roles.count || row?.[ roles.count ] === undefined ) {
		return null;
	}
	return Number( row[ roles.count ] ) || 0;
}

/**
 * Whether a product row has (or can have) variations: a variable product or
 * any row counting variations. A variation never does.
 *
 * @param {Object} row   Row.
 * @param {Object} roles variationRoles().
 * @return {boolean} True for a variable parent.
 */
export function isVariable( row, roles ) {
	if ( ! row || isVariation( row ) ) {
		return false;
	}
	return (
		( roles.type && row[ roles.type ] === VARIABLE ) ||
		( countOf( row, roles ) || 0 ) > 0
	);
}

/**
 * The route arguments a view needs for its variation display, added to
 * toQuery()'s: `mode` when the view is not in the default Products mode, and
 * in the modes that show products, the variation count and type fields (so a
 * variable row knows it is one), the rendered price when a column derived on
 * the parent is shown (its range) and each shown conditional field's
 * condition.
 *
 * @param {Object}        query       toQuery()'s arguments.
 * @param {Object}        view        DataViews view (its `mode`).
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Object} The arguments.
 */
export function withVariations( query, view, descriptors ) {
	const mode = Object.values( MODES ).includes( view?.mode )
		? view.mode
		: MODES.products;
	const next = { ...query };
	if ( mode !== MODES.products ) {
		next.mode = mode;
	}
	// Show all: one product's options (the route's `parent`, Variations only).
	if ( mode === MODES.variations && Number( view?.parent ) > 0 ) {
		next.parent = Number( view.parent );
	}
	const shown = new Set( query.fields || [] );
	const visible = descriptors.filter( ( field ) => shown.has( field.key ) );
	const roles = variationRoles( descriptors );
	const extra = [
		...( mode !== MODES.variations ? [ roles.count, roles.type ] : [] ),
		...( visible.some( ( field ) => field.variableParentNote )
			? [ roles.price ]
			: [] ),
		...visible.map( ( field ) => conditionOf( field, descriptors )?.key ),
	].filter( ( key ) => key && ! shown.has( key ) );
	if ( extra.length ) {
		const wanted = new Set( [ ...shown, ...extra ] );
		next.fields = descriptors
			.map( ( field ) => field.key )
			.filter( ( key ) => wanted.has( key ) );
	}
	return next;
}
