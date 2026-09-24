/**
 * Small repairs to markup the bundled WordPress components draw without an
 * accessible name or with a stray form control (T75, found by axe-core):
 *
 * - DataViews' filter chip remove button is an icon with no name: it is
 *   named "Remove filter: <the chip's words>".
 * - The validated form controls add a hidden `select` or `input` that only
 *   carries the browser's validity message (tabindex -1, focus handed to the
 *   real control): it is hidden from assistive technology, which otherwise
 *   meets an unlabelled field.
 *
 * The components render into the screen and into portals on the body, so
 * the body is watched while the screen is open.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Repair what is under a node.
 *
 * @param {Element} root Node to repair under.
 */
export function repair( root ) {
	root.querySelectorAll(
		'.dataviews-filters__summary-chip-remove:not([aria-label])'
	).forEach( ( button ) => {
		const chip = button
			.closest( '.dataviews-filters__summary-chip-container' )
			?.querySelector( '.dataviews-filters__summary-chip' );
		const words = ( chip?.textContent || '' ).trim();
		button.setAttribute(
			'aria-label',
			words
				? sprintf(
						/* translators: %s: a filter as shown on its chip, such as "Date is in the past: 30 days". */
						__(
							'Remove filter: %s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						words
					)
				: __(
						'Remove filter',
						'bulk-catalogue-manager-for-woocommerce'
					)
		);
	} );
	root.querySelectorAll(
		'.components-validated-control__error-delegate:not([aria-hidden])'
	).forEach( ( delegate ) => delegate.setAttribute( 'aria-hidden', 'true' ) );
}

/**
 * Watch the document and repair as the components draw.
 *
 * @param {Document} doc Document.
 * @return {Function} Stop watching.
 */
export function watch( doc = document ) {
	if ( ! doc?.body || typeof window.MutationObserver !== 'function' ) {
		return () => {};
	}
	repair( doc.body );
	let queued = false;
	const observer = new window.MutationObserver( () => {
		if ( queued ) {
			return;
		}
		queued = true;
		window.requestAnimationFrame( () => {
			queued = false;
			repair( doc.body );
		} );
	} );
	observer.observe( doc.body, { childList: true, subtree: true } );
	return () => observer.disconnect();
}
