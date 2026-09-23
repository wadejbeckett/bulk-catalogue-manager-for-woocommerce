/**
 * Announcements for screen readers through the admin's own live regions,
 * `wp.a11y.speak` (SPEC §14). The host's a11y script is read from the window
 * rather than bundled; without it the message goes to a live region of our
 * own, so nothing is ever silently lost.
 */

let fallback = null;

/**
 * The fallback live region, created once on first use.
 *
 * @param {Document} doc Document.
 * @return {HTMLElement} The region.
 */
function region( doc ) {
	if ( fallback && doc.body.contains( fallback ) ) {
		return fallback;
	}
	fallback = doc.createElement( 'div' );
	fallback.className = 'bcm-sr-only';
	fallback.setAttribute( 'aria-live', 'polite' );
	fallback.setAttribute( 'data-bcm-announcer', '' );
	doc.body.appendChild( fallback );
	return fallback;
}

/**
 * Say something to screen reader users.
 *
 * @param {string} message    Plain sentence.
 * @param {string} politeness polite (default) or assertive for errors.
 */
export function announce( message, politeness = 'polite' ) {
	if ( ! message ) {
		return;
	}
	const speak =
		typeof window !== 'undefined' ? window.wp?.a11y?.speak : undefined;
	if ( typeof speak === 'function' ) {
		speak( message, politeness );
		return;
	}
	if ( typeof document === 'undefined' || ! document.body ) {
		return;
	}
	const live = region( document );
	live.setAttribute( 'aria-live', politeness );
	live.textContent = '';
	live.textContent = message;
}
