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

/**
 * The visible columns to store, keeping stored columns this screen does not
 * offer just now (a field an add-on stopped providing, for instance), so they
 * come back in place when it offers them again. Each run of such columns stays
 * just before the next stored column the screen offers; a run whose anchor is
 * not visible, or that ends the stored list, goes last.
 *
 * @param {Array<string>|undefined} stored  Stored column order.
 * @param {Array<string>}           visible The visible columns now, in order.
 * @param {Array<string>}           known   Column keys this screen offers.
 * @return {Array<string>} Columns to store.
 */
export function mergeAvailableFields( stored, visible, known ) {
	if ( ! Array.isArray( stored ) ) {
		return [ ...visible ];
	}
	const offered = new Set( known );
	const before = new Map();
	const trailing = [];
	let run = [];
	stored.forEach( ( key ) => {
		if ( offered.has( key ) ) {
			if ( run.length ) {
				before.set( key, run );
				run = [];
			}
		} else {
			run.push( key );
		}
	} );
	trailing.push( ...run );
	const out = [];
	visible.forEach( ( key ) => {
		out.push( ...( before.get( key ) || [] ), key );
		before.delete( key );
	} );
	before.forEach( ( keys ) => out.push( ...keys ) );
	out.push( ...trailing );
	return out;
}
