/**
 * The selection (T42, SPEC §3): an explicit list of row ids that survives
 * paging, or every row matching the view, held as the token POST /selection
 * gave for the view's conditions. These are the pure parts; the hook is
 * use-selection.js.
 */

/**
 * The selection after DataViews reports a change on one page. DataViews
 * knows only the rows on screen (its header checkbox answers with the page's
 * ids or none), so ids from other pages are kept as they were and the page's
 * part is taken from DataViews' answer.
 *
 * @param {Array<string>} previous Selected ids before the change.
 * @param {Array<string>} next     DataViews' new selection.
 * @param {Array<string>} pageIds  Ids of the rows on this page.
 * @return {Array<string>} Selected ids.
 */
export function mergePageSelection( previous, next, pageIds ) {
	const onPage = new Set( pageIds );
	const kept = previous.filter( ( id ) => ! onPage.has( id ) );
	const chosen = next.filter( ( id ) => onPage.has( id ) );
	return [ ...kept, ...chosen ];
}

/**
 * Whether every row on the page is selected.
 *
 * @param {Array<string>} ids     Selected ids.
 * @param {Array<string>} pageIds Ids of the rows on this page.
 * @return {boolean} True when the page is fully selected.
 */
export function isPageSelected( ids, pageIds ) {
	return pageIds.length > 0 && pageIds.every( ( id ) => ids.includes( id ) );
}

/**
 * The selection as the bulk change panel reads it (panels/bulk README):
 * `{ kind: 'ids', ids }` for ticked rows, or `{ kind: 'filter', token,
 * count, filters }` for every row matching the view.
 *
 * @param {Object}        state `{ ids, matching }` from useSelection().
 * @param {Array<Object>} chips filterChips() of the view, listed under the panel's scope line.
 * @return {Object} Panel selection.
 */
export function panelSelection( state, chips = [] ) {
	if ( state.matching?.status === 'ready' ) {
		return {
			kind: 'filter',
			token: state.matching.token,
			count: state.matching.count,
			filters: chips.map( ( chip ) => ( {
				label: chip.label,
				value: chip.sentence,
			} ) ),
		};
	}
	return { kind: 'ids', ids: state.ids.map( Number ) };
}
