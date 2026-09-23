/**
 * The panel's width and collapsed state, remembered per user in the
 * browser (T61). The first visit starts from the "Show the category panel by
 * default" setting (SPEC §11). Storage may be missing or full; every access
 * is guarded and the panel works without it.
 */

export const DEFAULT_WIDTH = 236;
export const MIN_WIDTH = 160;
export const MAX_WIDTH = 480;

/**
 * The storage key for a user.
 *
 * @param {number} userId User id.
 * @return {string} Key.
 */
export function storageKey( userId ) {
	return `bcm-category-panel:${ Number( userId ) || 0 }`;
}

/**
 * Keep a width within the panel's limits.
 *
 * @param {number} width Width in pixels.
 * @return {number} Clamped width.
 */
export function clampWidth( width ) {
	const value = Number( width );
	if ( ! Number.isFinite( value ) ) {
		return DEFAULT_WIDTH;
	}
	return Math.round( Math.min( MAX_WIDTH, Math.max( MIN_WIDTH, value ) ) );
}

/**
 * The stored state, or the defaults.
 *
 * @param {number}  userId      User id.
 * @param {boolean} openDefault The category panel default setting.
 * @param {Storage} storage     Storage; window.localStorage by default.
 * @return {{width: number, collapsed: boolean}} State.
 */
export function readPanelState( userId, openDefault = true, storage ) {
	const fallback = { width: DEFAULT_WIDTH, collapsed: ! openDefault };
	try {
		const store = storage || window.localStorage;
		const raw = store.getItem( storageKey( userId ) );
		if ( ! raw ) {
			return fallback;
		}
		const saved = JSON.parse( raw );
		return {
			width: clampWidth( saved?.width ),
			collapsed:
				typeof saved?.collapsed === 'boolean'
					? saved.collapsed
					: fallback.collapsed,
		};
	} catch {
		return fallback;
	}
}

/**
 * Store the state.
 *
 * @param {number}                              userId  User id.
 * @param {{width: number, collapsed: boolean}} state   State.
 * @param {Storage}                             storage Storage; window.localStorage by default.
 */
export function writePanelState( userId, state, storage ) {
	try {
		const store = storage || window.localStorage;
		store.setItem(
			storageKey( userId ),
			JSON.stringify( {
				width: clampWidth( state.width ),
				collapsed: !! state.collapsed,
			} )
		);
	} catch {
		// Private browsing or a full store: the panel still works this visit.
	}
}
