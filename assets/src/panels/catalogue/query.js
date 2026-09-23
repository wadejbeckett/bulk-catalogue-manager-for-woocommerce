/**
 * The GET /products arguments for a DataViews view (SPEC §12.1): paging,
 * search, the sort as the route's `orderby[]` and `order[]`, and `fields[]`.
 */
import { CATEGORY_FILTER } from './defaults';
import { queryFields } from './fields';
import { withVariations } from './variations/registry';

/**
 * Route arguments for a view.
 *
 * @param {Object}        view        DataViews view.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @param {Object}        extra       Further filter arguments, such as categoryArgs().
 * @return {Object} Query arguments.
 */
export function toQuery( view, descriptors, extra = {} ) {
	const query = {
		page: view.page || 1,
		per_page: view.perPage,
		fields: queryFields( view, descriptors ),
	};
	const search = ( view.search || '' ).trim();
	if ( search ) {
		query.search = search;
	}
	if ( view.sort?.field ) {
		query.orderby = [ view.sort.field ];
		query.order = [ view.sort.direction === 'desc' ? 'desc' : 'asc' ];
	}
	return { ...withVariations( query, view, descriptors ), ...extra };
}

/**
 * The GET /products filter argument for the category panel's value (T62):
 * `{ operator: 'isAny', value: ids }` under the category field, or nothing
 * for all products. The server includes each category's descendants; the
 * panel's "this category only" choice waits for its include-children flag.
 *
 * @param {Object|null} value CategoryPanel value `{ id, ids?, includeChildren }`.
 * @return {Object} Filter arguments.
 */
export function categoryArgs( value ) {
	const ids = ( value?.ids?.length ? value.ids : [ value?.id ] )
		.map( Number )
		.filter( ( id ) => id > 0 );
	return ids.length
		? { [ CATEGORY_FILTER ]: { operator: 'isAny', value: ids } }
		: {};
}

/**
 * Whether the view narrows the catalogue: a search or any filter with a value.
 *
 * @param {Object} view DataViews view.
 * @return {boolean} True when something is filtering the rows.
 */
export function isFiltered( view ) {
	return (
		!! ( view.search || '' ).trim() ||
		( view.filters || [] ).some( ( filter ) => hasValue( filter.value ) )
	);
}

/**
 * Whether a filter value holds anything.
 *
 * @param {*} value Filter value.
 * @return {boolean} True for a non-empty value.
 */
export function hasValue( value ) {
	if ( Array.isArray( value ) ) {
		return value.length > 0;
	}
	return value !== undefined && value !== null && value !== '';
}

/**
 * The view with every filter and the search cleared, back on page 1.
 *
 * @param {Object} view DataViews view.
 * @return {Object} View.
 */
export function clearFilters( view ) {
	return { ...view, search: '', filters: [], page: 1 };
}
