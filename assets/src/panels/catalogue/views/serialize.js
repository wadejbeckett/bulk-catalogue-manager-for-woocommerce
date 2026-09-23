/**
 * A saved view as it is stored and read back (SPEC §10, T52): the DataViews
 * view object plus the category panel's selection. Everything in the view is
 * kept as it is, including keys this client does not know, so a later
 * version's key survives a round trip; only the page is left out, so a saved
 * view always opens on page 1.
 */

/** View keys never saved. */
const TRANSIENT = [ 'page' ];

/**
 * The stored form of the current state.
 *
 * @param {Object}      view     DataViews view.
 * @param {Object|null} category CategoryPanel value, or null for all products.
 * @return {{view: Object, category: Object|null}} What POST /views takes besides the name.
 */
export function toSaved( view, category = null ) {
	const kept = { ...( view || {} ) };
	TRANSIENT.forEach( ( key ) => delete kept[ key ] );
	return { view: kept, category: category || null };
}

/**
 * The state a stored view opens: its view over the base view (so a key the
 * saved view lacks takes the screen's default), on page 1, and its category.
 *
 * @param {Object} saved A GET /views item, or toSaved()'s result.
 * @param {Object} base  The view the screen starts from (defaultView()).
 * @return {{view: Object, category: Object|null}} State to apply.
 */
export function fromSaved( saved, base = {} ) {
	return {
		view: { ...base, ...( saved?.view || {} ), page: 1 },
		category: saved?.category || null,
	};
}

/**
 * JSON with object keys sorted, so two equal views compare equal whatever
 * order their keys arrived in.
 *
 * @param {*} value Value.
 * @return {string} Canonical JSON.
 */
export function canonical( value ) {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( canonical ).join( ',' ) }]`;
	}
	if ( value && typeof value === 'object' ) {
		return `{${ Object.keys( value )
			.filter( ( key ) => value[ key ] !== undefined )
			.sort()
			.map(
				( key ) =>
					`${ JSON.stringify( key ) }:${ canonical( value[ key ] ) }`
			)
			.join( ',' ) }}`;
	}
	return JSON.stringify( value ?? null );
}

/**
 * Whether the screen shows a saved view: the same view (page aside) and
 * the same category.
 *
 * @param {Object}      saved    A GET /views item.
 * @param {Object}      view     Current DataViews view.
 * @param {Object|null} category Current category.
 * @return {boolean} True when they match.
 */
export function showsSaved( saved, view, category ) {
	const now = toSaved( view, category );
	return (
		canonical( { ...view, ...saved.view, page: undefined } ) ===
			canonical( now.view ) &&
		canonical( saved.category || null ) === canonical( now.category )
	);
}
