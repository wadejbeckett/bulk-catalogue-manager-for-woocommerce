/**
 * The product grid opened from the Categories tab (SPEC §4.7): the page
 * URL's `category` (one id or several, comma separated) and `search`
 * arguments, read once when the grid opens and then taken out of the URL so
 * a reload or another panel does not bring them back.
 */

const ARGS = [ 'category', 'search' ];

/**
 * The category filter value and search a URL names.
 *
 * @param {string} search A location's search string.
 * @return {{category: Object|null, search: string}} The CategoryPanel value
 *         ({ id, ids?, includeChildren }) or null, and the search text.
 */
export function linkedFrom( search ) {
	const args = new URLSearchParams( search );
	const ids = [
		...new Set(
			( args.get( 'category' ) || '' )
				.split( ',' )
				.map( ( each ) => Number( each.trim() ) )
				.filter( ( id ) => Number.isInteger( id ) && id > 0 )
		),
	];
	let category = null;
	if ( ids.length === 1 ) {
		category = { id: ids[ 0 ], includeChildren: true };
	} else if ( ids.length > 1 ) {
		category = { id: ids[ 0 ], ids, includeChildren: true };
	}
	return { category, search: ( args.get( 'search' ) || '' ).trim() };
}

/**
 * Take the link's arguments out of the page URL, keeping everything else.
 */
export function forgetLinked() {
	const url = new URL( window.location.href );
	if ( ! ARGS.some( ( key ) => url.searchParams.has( key ) ) ) {
		return;
	}
	ARGS.forEach( ( key ) => url.searchParams.delete( key ) );
	window.history.replaceState( window.history.state, '', url.toString() );
}
