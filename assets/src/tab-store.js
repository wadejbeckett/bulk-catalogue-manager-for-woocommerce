/**
 * The tab's own copy of what a reload must not lose (T84, T85): the
 * selection's opaque handles and counts, and any intent or Apply whose answer
 * is not yet known with its grant (a pending selection intent keeps its own
 * ids or query; a snapshot's membership is never kept). It lives in the
 * tab's sessionStorage — never localStorage — under the site's REST root and
 * the user, so it ends with the tab and is never read by another user or
 * site.
 */

/** The prefix every key of the screen's tab copy starts with. */
export const TAB_PREFIX = 'bulkcat-selection';

/**
 * The key for one part of the tab's copy.
 *
 * @param {Object} settings Localised settings (`root`, `user.id`).
 * @param {string} part     '' for the selection, or a part's name.
 * @return {string} Storage key.
 */
export function tabScope( settings = {}, part = '' ) {
	const scope = `${ TAB_PREFIX }:${ settings?.root || '' }:${
		Number( settings?.user?.id ) || 0
	}`;
	return part ? `${ scope }:${ part }` : scope;
}

/**
 * The tab's own storage, or null where there is none. Every read and write is
 * guarded: a private window or blocked storage simply keeps nothing.
 *
 * @param {string} key Storage key.
 * @param {Window} win Window.
 * @return {Object|null} `{ read, write, clear }`.
 */
export function tabStore(
	key,
	win = typeof window === 'undefined' ? null : window
) {
	let storage = null;
	try {
		storage = win?.sessionStorage || null;
	} catch {
		storage = null;
	}
	if ( ! storage ) {
		return null;
	}
	return {
		read() {
			try {
				return JSON.parse( storage.getItem( key ) || 'null' );
			} catch {
				return null;
			}
		},
		write( value ) {
			try {
				storage.setItem( key, JSON.stringify( value ) );
			} catch {}
		},
		clear() {
			try {
				storage.removeItem( key );
			} catch {}
		},
	};
}
