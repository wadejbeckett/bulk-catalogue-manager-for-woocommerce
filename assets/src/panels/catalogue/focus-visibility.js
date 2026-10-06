/** Keep focused catalogue controls clear of the sticky selection actions. */

/**
 * Scroll a control out from beneath the bar, using its current height.
 *
 * @param {Element} target Focused control.
 */
export function revealCatalogueFocus( target ) {
	const main = target?.closest( '.bulkcat-catalogue__main' );
	const bar = main?.querySelector( '.bulkcat-selection' );
	if ( ! bar || bar.contains( target ) ) {
		return;
	}
	const view = target.ownerDocument.defaultView;
	const edge = bar.getBoundingClientRect();
	const stickyTop = parseFloat( view.getComputedStyle( bar ).top ) || 0;
	const box = target.getBoundingClientRect();
	if (
		edge.top <= stickyTop + 1 &&
		box.top < edge.bottom + 8 &&
		box.bottom > edge.top
	) {
		view.scrollBy( 0, box.top - edge.bottom - 8 );
	}
}

/**
 * Let the browser finish its ordinary focus scroll before adjusting it.
 *
 * @param {FocusEvent} event Focus in the catalogue.
 */
export function onCatalogueFocus( event ) {
	const target = event.target;
	window.requestAnimationFrame( () => {
		if ( target === target.ownerDocument.activeElement ) {
			revealCatalogueFocus( target );
		}
	} );
}
