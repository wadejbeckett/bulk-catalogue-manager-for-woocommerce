/**
 * Pure helpers over the nested tree GET /bulkcat/v1/categories answers (SPEC §7):
 * an index by id, lineage and paths, the search filter, the visible rows the
 * ARIA tree walks, the filter value's sentence and the move payloads. No
 * React and no requests here, so every rule is tested on its own.
 */
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Index a nested tree by id.
 *
 * @param {Array<Object>} nodes Top-level nodes, each with `children`.
 * @return {Map<number, Object>} id => { node, parent, depth, children: ids }.
 */
export function indexTree( nodes ) {
	const byId = new Map();
	const walk = ( list, parent, depth ) => {
		( list || [] ).forEach( ( node ) => {
			byId.set( node.id, {
				node,
				parent,
				depth,
				children: ( node.children || [] ).map( ( child ) => child.id ),
			} );
			walk( node.children, node.id, depth + 1 );
		} );
	};
	walk( nodes, 0, 0 );
	return byId;
}

/**
 * The ids of a parent's children in order; 0 is the top level.
 *
 * @param {Array<Object>}       nodes  Top-level nodes.
 * @param {Map<number, Object>} byId   Index.
 * @param {number}              parent Parent id, 0 for the top level.
 * @return {number[]} Child ids.
 */
export function childrenOf( nodes, byId, parent ) {
	if ( ! parent ) {
		return ( nodes || [] ).map( ( node ) => node.id );
	}
	return byId.get( parent )?.children || [];
}

/**
 * A category's ancestors from the top down, then itself.
 *
 * @param {Map<number, Object>} byId Index.
 * @param {number}              id   Category id.
 * @return {number[]} Ids from the top-level ancestor to the category.
 */
export function lineage( byId, id ) {
	const out = [];
	let current = id;
	const seen = new Set();
	while ( current && byId.has( current ) && ! seen.has( current ) ) {
		seen.add( current );
		out.unshift( current );
		current = byId.get( current ).parent;
	}
	return out;
}

/**
 * Whether `target` is `id` itself or sits anywhere below it: the moves the
 * server refuses with "A category cannot be moved inside itself."
 *
 * @param {Map<number, Object>} byId   Index.
 * @param {number}              id     The category being moved.
 * @param {number}              target The proposed parent.
 * @return {boolean} True when the move would be a cycle.
 */
export function isInside( byId, id, target ) {
	return !! target && lineage( byId, target ).includes( id );
}

/**
 * A category's names from the top down.
 *
 * @param {Map<number, Object>} byId Index.
 * @param {number}              id   Category id; 0 for the top level.
 * @return {string[]} Names.
 */
export function pathNames( byId, id ) {
	return lineage( byId, id ).map( ( each ) => byId.get( each ).node.name );
}

/**
 * A path as one line, "Clothing › T-Shirts".
 *
 * @param {string[]} names Names from the top down.
 * @return {string} The path.
 */
export function joinPath( names ) {
	if ( ! names.length ) {
		return '';
	}
	return names.reduce( ( path, name ) =>
		sprintf(
			/* translators: 1: a category path so far, 2: the next category below it. */
			_x(
				'%1$s › %2$s',
				'category path',
				'bulk-catalogue-manager-for-woocommerce'
			),
			path,
			name
		)
	);
}

/**
 * The ids a search keeps: every category whose name contains the text, and
 * every ancestor of one, so a match is always shown in place. Null keeps all.
 *
 * @param {Map<number, Object>} byId   Index.
 * @param {string}              search Search text.
 * @return {Set<number>|null} Kept ids, or null without a search.
 */
export function searchMatches( byId, search ) {
	const text = ( search || '' ).trim().toLocaleLowerCase();
	if ( ! text ) {
		return null;
	}
	const kept = new Set();
	byId.forEach( ( entry, id ) => {
		if ( entry.node.name.toLocaleLowerCase().includes( text ) ) {
			lineage( byId, id ).forEach( ( each ) => kept.add( each ) );
		}
	} );
	return kept;
}

/**
 * The rows the tree shows, in display order: each visible node with its
 * depth, position among its shown siblings and whether it is open. While a
 * search is active every kept node is open.
 *
 * @param {Array<Object>} nodes    Top-level nodes.
 * @param {Set<number>}   expanded Open ids.
 * @param {Set|null}      kept     searchMatches() result.
 * @return {Array<Object>} Rows: { id, node, depth, hasChildren, open, setSize, posInSet, parent }.
 */
export function visibleRows( nodes, expanded, kept = null ) {
	const rows = [];
	const walk = ( list, depth, parent ) => {
		const shown = ( list || [] ).filter(
			( node ) => ! kept || kept.has( node.id )
		);
		shown.forEach( ( node, index ) => {
			const children = ( node.children || [] ).filter(
				( child ) => ! kept || kept.has( child.id )
			);
			const hasChildren = children.length > 0;
			const open = hasChildren && ( !! kept || expanded.has( node.id ) );
			rows.push( {
				id: node.id,
				node,
				depth,
				parent,
				hasChildren,
				open,
				setSize: shown.length,
				posInSet: index + 1,
			} );
			if ( open ) {
				walk( node.children, depth + 1, node.id );
			}
		} );
	};
	walk( nodes, 0, 0 );
	return rows;
}

/**
 * The count a node shows: its own, or including every category below it.
 *
 * @param {Object}  node            Tree node.
 * @param {boolean} includeChildren Whether the filter includes sub-categories.
 * @return {number} Count.
 */
export function nodeCount( node, includeChildren ) {
	const value = includeChildren ? node.countWithDescendants : node.count;
	return Number.isFinite( value ) ? value : 0;
}

/**
 * The ids a filter value names.
 *
 * @param {Object|null} value { id, ids, includeChildren }.
 * @return {number[]} Ids.
 */
export function valueIds( value ) {
	if ( ! value ) {
		return [];
	}
	if ( Array.isArray( value.ids ) && value.ids.length ) {
		return value.ids;
	}
	return value.id ? [ value.id ] : [];
}

/**
 * Names joined as a list: "Shoes", "Shoes or Hats", "Shoes, Hats or Bags".
 *
 * @param {string[]} names Names.
 * @return {string} The list.
 */
export function orList( names ) {
	if ( names.length < 2 ) {
		return names[ 0 ] || '';
	}
	const head = names.slice( 0, -1 ).reduce( ( list, name ) =>
		sprintf(
			/* translators: 1: the choices so far, 2: the next choice. */
			_x(
				'%1$s, %2$s',
				'list of choices',
				'bulk-catalogue-manager-for-woocommerce'
			),
			list,
			name
		)
	);
	return sprintf(
		/* translators: 1: every choice but the last, 2: the last choice. */
		__( '%1$s or %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
		head,
		names[ names.length - 1 ]
	);
}

/**
 * The filter chip's sentence (SPEC §4.1, §7; T62).
 *
 * @param {Object|null}         value { id, ids, includeChildren }.
 * @param {Map<number, Object>} byId  Index.
 * @return {string} The sentence, or '' without a value.
 */
export function categorySentence( value, byId ) {
	const ids = valueIds( value );
	if ( ! ids.length ) {
		return '';
	}
	const names = ids.map(
		( id ) =>
			byId?.get( id )?.node.name ||
			sprintf(
				/* translators: %d: a category id that is no longer in the tree. */
				__( 'category %d', 'bulk-catalogue-manager-for-woocommerce' ),
				id
			)
	);
	const list = orList( names );
	if ( ! value.includeChildren ) {
		return sprintf(
			/* translators: %s: one or more category names, such as "Shoes" or "Shoes or Hats". */
			__( 'Category is %s', 'bulk-catalogue-manager-for-woocommerce' ),
			list
		);
	}
	return names.length === 1
		? sprintf(
				/* translators: %s: a category name, such as "Shoes". */
				__(
					'Category is %s or its sub-categories',
					'bulk-catalogue-manager-for-woocommerce'
				),
				list
			)
		: sprintf(
				/* translators: %s: several category names, such as "Shoes or Hats". */
				__(
					'Category is %s, or their sub-categories',
					'bulk-catalogue-manager-for-woocommerce'
				),
				list
			);
}

/**
 * The filter value for a click: a plain click chooses one category, a Ctrl
 * or Cmd click adds it to or removes it from the current choice.
 *
 * @param {Object|null} value           Current value.
 * @param {number}      id              The category clicked.
 * @param {boolean}     multiple        Ctrl or Cmd was held.
 * @param {boolean}     includeChildren The toggle's state.
 * @return {Object|null} The new value; null when nothing is chosen.
 */
export function nextValue( value, id, multiple, includeChildren ) {
	let ids = [ id ];
	if ( multiple ) {
		const current = valueIds( value );
		ids = current.includes( id )
			? current.filter( ( each ) => each !== id )
			: [ ...current, id ];
	}
	if ( ! ids.length ) {
		return null;
	}
	return ids.length === 1
		? { id: ids[ 0 ], includeChildren }
		: { id: ids[ 0 ], ids, includeChildren };
}

/**
 * Where a drop lands: "before" or "after" a row near its top or bottom edge,
 * otherwise "inside" it (the prototype's 28 per cent bands).
 *
 * @param {number} offset Pointer distance from the row's top, in pixels.
 * @param {number} height Row height, in pixels.
 * @return {string} before, after or inside.
 */
export function dropZone( offset, height ) {
	if ( ! height ) {
		return 'inside';
	}
	const share = offset / height;
	if ( share < 0.28 ) {
		return 'before';
	}
	if ( share > 0.72 ) {
		return 'after';
	}
	return 'inside';
}

/**
 * The POST /categories/{id}/move payload for dropping `id` relative to
 * `target`, or null when the drop changes nothing or would be a cycle.
 * Positions count the new siblings without the category itself, as the
 * server does.
 *
 * @param {Array<Object>}       nodes  Top-level nodes.
 * @param {Map<number, Object>} byId   Index.
 * @param {number}              id     The category moved.
 * @param {number}              target The row dropped on; 0 is the top level.
 * @param {string}              zone   before, after or inside.
 * @return {{parent: number, position: (number|undefined), blocked: boolean}|null} Payload.
 */
export function movePayload( nodes, byId, id, target, zone ) {
	if ( ! byId.has( id ) || id === target ) {
		return null;
	}
	if ( zone === 'inside' || ! target ) {
		if ( isInside( byId, id, target ) ) {
			return { blocked: true };
		}
		const siblings = childrenOf( nodes, byId, target || 0 );
		if (
			( byId.get( id ).parent || 0 ) === ( target || 0 ) &&
			siblings[ siblings.length - 1 ] === id
		) {
			return null;
		}
		return { parent: target || 0, position: undefined, blocked: false };
	}
	const parent = byId.get( target )?.parent || 0;
	if ( isInside( byId, id, parent ) ) {
		return { blocked: true };
	}
	const siblings = childrenOf( nodes, byId, parent ).filter(
		( each ) => each !== id
	);
	const at = siblings.indexOf( target );
	const position = zone === 'after' ? at + 1 : at;
	if (
		( byId.get( id ).parent || 0 ) === parent &&
		childrenOf( nodes, byId, parent ).indexOf( id ) === position
	) {
		return null;
	}
	return { parent, position, blocked: false };
}

/**
 * The path a category would have after a move, for the live preview.
 *
 * @param {Map<number, Object>} byId   Index.
 * @param {number}              id     The category moved.
 * @param {number}              parent Its new parent, 0 for the top level.
 * @return {string[]} Names from the top down.
 */
export function previewPath( byId, id, parent ) {
	return [ ...pathNames( byId, parent ), byId.get( id )?.node.name || '' ];
}

/**
 * Every category a category may move into, as { value, label } options with
 * the full path, the top level first; itself and its descendants excluded.
 *
 * @param {Map<number, Object>} byId Index.
 * @param {number}              id   The category moved; 0 excludes nothing.
 * @return {Array<{value: string, label: string}>} Options.
 */
export function parentOptions( byId, id ) {
	const options = [
		{
			value: '0',
			label: __( 'Top level', 'bulk-catalogue-manager-for-woocommerce' ),
		},
	];
	byId.forEach( ( entry, each ) => {
		if ( id && isInside( byId, id, each ) ) {
			return;
		}
		options.push( {
			value: String( each ),
			label: joinPath( pathNames( byId, each ) ),
		} );
	} );
	return options;
}
