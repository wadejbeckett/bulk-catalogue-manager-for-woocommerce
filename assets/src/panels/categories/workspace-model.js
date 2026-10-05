/**
 * Pure helpers for the Categories tab's workspace (SPEC §4.7): which branches
 * Expand all opens, where a name matches the search, how a click, a Ctrl or
 * Cmd click and a Shift click change the chosen categories, the top of each
 * chosen branch, and the product grid's link arguments. No React and no
 * requests here.
 */
import { lineage } from './tree-model';

/**
 * Every category that has subcategories: what Expand all opens.
 *
 * @param {Map<number, Object>} byId Index (indexTree()).
 * @return {Set<number>} Ids.
 */
export function branchIds( byId ) {
	const ids = new Set();
	byId.forEach( ( entry, id ) => {
		if ( entry.children.length ) {
			ids.add( id );
		}
	} );
	return ids;
}

/**
 * The ancestors to open so every given category is in view.
 *
 * @param {Map<number, Object>} byId Index.
 * @param {number[]}            ids  Categories to reveal.
 * @return {number[]} Ancestor ids.
 */
export function ancestorsOf( byId, ids ) {
	const out = new Set();
	ids.forEach( ( id ) =>
		lineage( byId, id )
			.slice( 0, -1 )
			.forEach( ( each ) => out.add( each ) )
	);
	return [ ...out ];
}

/**
 * A name cut into the parts that match the search and the parts that do not,
 * ignoring case, so each match can be marked.
 *
 * @param {string} name   Category name.
 * @param {string} search Search text.
 * @return {Array<{text: string, match: boolean}>} Parts in order.
 */
export function markParts( name, search ) {
	const text = ( search || '' ).trim().toLocaleLowerCase();
	const whole = String( name ?? '' );
	if ( ! text ) {
		return [ { text: whole, match: false } ];
	}
	const lower = whole.toLocaleLowerCase();
	const parts = [];
	let from = 0;
	let at = lower.indexOf( text, from );
	while ( at !== -1 ) {
		if ( at > from ) {
			parts.push( { text: whole.slice( from, at ), match: false } );
		}
		parts.push( {
			text: whole.slice( at, at + text.length ),
			match: true,
		} );
		from = at + text.length;
		at = lower.indexOf( text, from );
	}
	if ( from < whole.length ) {
		parts.push( { text: whole.slice( from ), match: false } );
	}
	return parts;
}

/**
 * Whether a category's own name matches the search (not only one of its
 * descendants').
 *
 * @param {string} name   Category name.
 * @param {string} search Search text.
 * @return {boolean} True for a match.
 */
export function nameMatches( name, search ) {
	const text = ( search || '' ).trim().toLocaleLowerCase();
	return (
		!! text &&
		String( name ?? '' )
			.toLocaleLowerCase()
			.includes( text )
	);
}

/**
 * How many categories' own names match the search.
 *
 * @param {Map<number, Object>} byId   Index.
 * @param {string}              search Search text.
 * @return {number} Count.
 */
export function matchCount( byId, search ) {
	let count = 0;
	byId.forEach( ( entry ) => {
		if ( nameMatches( entry.node.name, search ) ) {
			count++;
		}
	} );
	return count;
}

/**
 * The chosen categories after a click on one: a plain click chooses only it,
 * Ctrl or Cmd (or its checkbox) adds or removes it, and Shift takes every
 * row shown from the last one clicked to it.
 *
 * @param {number[]} selected         Chosen ids, in the order chosen.
 * @param {number}   id               The category clicked.
 * @param {Object}   options          Options.
 * @param {boolean}  options.multiple Add or remove this one.
 * @param {boolean}  options.range    Take the rows between the anchor and this one.
 * @param {number}   options.anchor   The last category clicked without Shift.
 * @param {Array}    options.rows     The rows on screen, in order ({ id }).
 * @return {number[]} The new choice.
 */
export function nextSelection(
	selected,
	id,
	{ multiple = false, range = false, anchor = null, rows = [] } = {}
) {
	if ( range && anchor !== null ) {
		const order = rows.map( ( row ) => row.id );
		const from = order.indexOf( anchor );
		const to = order.indexOf( id );
		if ( from !== -1 && to !== -1 ) {
			const [ low, high ] = from < to ? [ from, to ] : [ to, from ];
			const span = order.slice( low, high + 1 );
			return [
				...selected.filter( ( each ) => ! span.includes( each ) ),
				...span,
			];
		}
	}
	if ( multiple ) {
		return selected.includes( id )
			? selected.filter( ( each ) => each !== id )
			: [ ...selected, id ];
	}
	return [ id ];
}

/**
 * The chosen categories without any that sit inside another chosen one: the
 * top of each chosen branch.
 *
 * @param {Map<number, Object>} byId Index.
 * @param {number[]}            ids  Chosen ids.
 * @return {number[]} Ids, in the order given.
 */
export function topmost( byId, ids ) {
	const chosen = new Set( ids );
	return ids.filter( ( id ) =>
		lineage( byId, id )
			.slice( 0, -1 )
			.every( ( each ) => ! chosen.has( each ) )
	);
}

/**
 * The URL arguments that open the product grid filtered to categories (and,
 * optionally, searched for a product), read once by the Products tab.
 *
 * @param {number[]} ids            Category ids.
 * @param {Object}   options        Options.
 * @param {string}   options.search Text to search the products for.
 * @return {Object} { category, search? }.
 */
export function catalogueParams( ids, { search = '' } = {} ) {
	const params = { category: ids.map( Number ).join( ',' ) };
	if ( search ) {
		params.search = search;
	}
	return params;
}
