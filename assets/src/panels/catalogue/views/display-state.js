/**
 * Personal display choices (ticket 19): the part of a view each user keeps
 * for themselves — visible columns in order, rows per page and row density.
 * Search, filters, category, sort, page and selection are never part of it.
 */
import { PER_PAGE_SIZES } from '../defaults';

const DENSITIES = [ 'compact', 'balanced', 'comfortable' ];

/**
 * The display choices of a view, as stored.
 *
 * @param {Object} view DataViews view.
 * @return {Object} { fields, perPage, density? }.
 */
export function displayOf( view ) {
	const display = {
		fields: [ ...( view?.fields || [] ) ],
		perPage: view?.perPage,
	};
	if ( DENSITIES.includes( view?.layout?.density ) ) {
		display.density = view.layout.density;
	}
	return display;
}

/**
 * Whether two displays are the same.
 *
 * @param {Object} a Display.
 * @param {Object} b Display.
 * @return {boolean} Same.
 */
export function sameDisplay( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * A view with stored display choices over it. Keys missing or not valid are
 * left as the view has them; columns this screen no longer offers are left
 * out of the view. The view returned starts at page 1.
 *
 * @param {Object}        view    DataViews view.
 * @param {Object|null}   display Stored display.
 * @param {Array<string>} known   Column keys this screen offers.
 * @return {Object} View.
 */
export function applyDisplay( view, display, known ) {
	if ( ! display || typeof display !== 'object' ) {
		return view;
	}
	const next = { ...view, page: 1 };
	if ( Array.isArray( display.fields ) ) {
		const offered = new Set( known );
		next.fields = display.fields.filter( ( key ) => offered.has( key ) );
	}
	if ( PER_PAGE_SIZES.includes( display.perPage ) ) {
		next.perPage = display.perPage;
	}
	if ( DENSITIES.includes( display.density ) ) {
		next.layout = { ...( view.layout || {} ), density: display.density };
	}
	return next;
}
