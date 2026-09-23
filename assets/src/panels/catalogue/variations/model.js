/**
 * The row tree: which variable rows are open, what each one's variations
 * request brought back, and the flat list of rows DataViews draws from the
 * page's items (SPEC §4.2, §8). Pure functions only; the hook in
 * use-variation-rows.js owns the requests.
 */
import {
	CAP,
	MODES,
	isVariable,
	isVariation,
	parentIdOf,
	countOf,
} from './registry';

/** Where a drawn row keeps what it is: { kind, level, parent, entry, shown, total }. */
export const META = Symbol( 'bcm-variation-row' );

/** Row kinds. */
export const KINDS = {
	product: 'product',
	parent: 'parent',
	variation: 'variation',
	footer: 'footer',
};

/** Nothing open, nothing fetched. */
export const INITIAL = { open: [], entries: {} };

const without = ( list, id ) => list.filter( ( each ) => each !== id );
const withId = ( list, id ) => ( list.includes( id ) ? list : [ ...list, id ] );

/**
 * The row tree's reducer.
 *
 * - `open` { id } shows a product's variations (fetched by the hook when
 *   there is no entry yet); `open_all` { ids } does so for many.
 * - `request` { id } marks a fetch in flight; `receive` { id, items, total }
 *   and `fail` { id, error } settle it.
 * - `retry` { id } forgets a failed entry so it is fetched again.
 * - `close` { id }; `close_all`.
 * - `refresh` { ids } forgets every fetched entry (the page was read again)
 *   and keeps open only the given products still on the page.
 * - `reset` goes back to nothing open (a mode change).
 *
 * @param {Object} state  State.
 * @param {Object} action Action.
 * @return {Object} State.
 */
export function reducer( state, action ) {
	switch ( action.type ) {
		case 'open':
			return { ...state, open: withId( state.open, action.id ) };
		case 'open_all':
			return {
				...state,
				open: action.ids.reduce( withId, state.open ),
			};
		case 'close':
			return { ...state, open: without( state.open, action.id ) };
		case 'close_all':
			return { ...state, open: [] };
		case 'request':
			return {
				...state,
				entries: {
					...state.entries,
					[ action.id ]: { status: 'loading', items: [], total: 0 },
				},
			};
		case 'receive':
			return {
				...state,
				entries: {
					...state.entries,
					[ action.id ]: {
						status: 'loaded',
						items: action.items,
						total: action.total,
					},
				},
			};
		case 'fail':
			return {
				...state,
				entries: {
					...state.entries,
					[ action.id ]: {
						status: 'error',
						items: [],
						total: 0,
						error: action.error,
					},
				},
			};
		case 'retry': {
			const entries = { ...state.entries };
			delete entries[ action.id ];
			return { ...state, entries };
		}
		case 'refresh':
			return {
				open: state.open.filter( ( id ) => action.ids.includes( id ) ),
				entries: {},
			};
		case 'reset':
			return INITIAL;
		default:
			return state;
	}
}

/**
 * The open products that still need their variations fetched: no entry yet.
 * A failed entry waits for Try again (`retry` drops it).
 *
 * @param {Object} state Row tree state.
 * @return {Array<number>} Product ids.
 */
export function toFetch( state ) {
	return state.open.filter( ( id ) => ! state.entries[ id ] );
}

/**
 * The variation rows a product shows once open: at most CAP, in the order
 * the route gave them.
 *
 * @param {Object} entry A product's entry.
 * @return {Array<Object>} Rows.
 */
export function shownOf( entry ) {
	return entry?.status === 'loaded' ? entry.items.slice( 0, CAP ) : [];
}

/**
 * A drawn copy of a row carrying its place in the tree.
 *
 * @param {Object} row  Row.
 * @param {Object} meta META value.
 * @return {Object} Row.
 */
const mark = ( row, meta ) => ( {
	...row,
	[ META ]: { ...meta, source: row },
} );

/**
 * The row as the route gave it, without its place in the tree (what row
 * actions receive).
 *
 * @param {Object} row Drawn row.
 * @return {Object} Row.
 */
export const sourceOf = ( row ) => row?.[ META ]?.source || row;

/**
 * The id of a product's footer row. Not a number, so it can never be taken
 * for an object id.
 *
 * @param {number} id Product id.
 * @return {string} Row id.
 */
export const footerId = ( id ) => `options-of-${ id }`;

/**
 * The rows to draw for a page: each product, then under each open variable
 * product its variation rows (indented, at most CAP) and a footer row. In
 * Products and variations mode a variation row of the page whose product is
 * open moves under it rather than showing twice; in Variations mode every
 * row is a flat variation row and nothing opens.
 *
 * @param {Array<Object>} items Page rows.
 * @param {Object}        state Row tree state.
 * @param {Object}        roles variationRoles().
 * @param {string}        mode  MODES value.
 * @return {Array<Object>} Rows to draw.
 */
export function buildRows( items, state, roles, mode ) {
	const open = new Set(
		mode === MODES.variations ? [] : state.open.map( Number )
	);
	const page = items.filter(
		( row ) => ! ( isVariation( row ) && open.has( parentIdOf( row ) ) )
	);
	const byId = new Map( items.map( ( row ) => [ Number( row.id ), row ] ) );
	const onPage = new Set( page.map( ( row ) => Number( row.id ) ) );
	const rows = [];
	page.forEach( ( row ) => {
		if ( isVariation( row ) ) {
			rows.push(
				mark( row, {
					kind: KINDS.variation,
					level: 0,
					parent: byId.get( parentIdOf( row ) ) || null,
				} )
			);
			return;
		}
		const variable = mode !== MODES.variations && isVariable( row, roles );
		const id = Number( row.id );
		const isOpen = variable && open.has( id );
		const entry = state.entries[ id ];
		if ( ! variable ) {
			rows.push( row );
			return;
		}
		rows.push(
			mark( row, {
				kind: KINDS.parent,
				level: 0,
				open: isOpen,
				entry,
			} )
		);
		if ( ! isOpen ) {
			return;
		}
		const shown = shownOf( entry ).filter(
			( child ) => ! onPage.has( Number( child.id ) )
		);
		shown.forEach( ( child ) =>
			rows.push(
				mark( child, { kind: KINDS.variation, level: 1, parent: row } )
			)
		);
		rows.push( {
			id: footerId( id ),
			[ META ]: {
				kind: KINDS.footer,
				level: 1,
				parent: row,
				entry,
				shown: shown.length,
				total:
					entry?.status === 'loaded'
						? entry.total
						: countOf( row, roles ) || 0,
			},
		} );
	} );
	return rows;
}

/**
 * A drawn row's place in the tree, or a plain product's.
 *
 * @param {Object} row Row.
 * @return {Object} META value.
 */
export function metaOf( row ) {
	return (
		row?.[ META ] || {
			kind: isVariation( row ) ? KINDS.variation : KINDS.product,
			level: 0,
			parent: null,
		}
	);
}

/**
 * Whether a drawn row is a footer row (no object behind it).
 *
 * @param {Object} row Row.
 * @return {boolean} True for a footer.
 */
export const isFooter = ( row ) => row?.[ META ]?.kind === KINDS.footer;
