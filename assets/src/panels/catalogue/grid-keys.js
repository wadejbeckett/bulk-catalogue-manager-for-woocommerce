/**
 * The table's keyboard (SPEC §14, T75): the arrow keys move between the
 * controls of the grid's cells — left and right along a row, up and down
 * within a column — while Tab keeps its ordinary order, so nothing traps
 * focus. An arrow is left alone inside a text field, a list box or a menu,
 * where it already means something, and with a modifier key held.
 */

const ARROWS = [ 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight' ];

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex="0"]';

/**
 * The controls a keyboard can reach in an element, in document order.
 *
 * @param {Element} element Row or cell.
 * @return {Array<Element>} Controls.
 */
function controlsIn( element ) {
	return Array.from( element.querySelectorAll( FOCUSABLE ) ).filter(
		( node ) =>
			! node.closest( '[aria-hidden="true"], [hidden]' ) &&
			node.getAttribute( 'tabindex' ) !== '-1'
	);
}

/**
 * Whether the arrows already mean something where focus is.
 *
 * @param {Element} target Focused element.
 * @return {boolean} True to leave the key alone.
 */
function ownsArrows( target ) {
	if ( target.closest( '[role="menu"], [role="listbox"], select' ) ) {
		return true;
	}
	if ( target.tagName === 'TEXTAREA' || target.isContentEditable ) {
		return true;
	}
	return (
		target.tagName === 'INPUT' &&
		! [ 'checkbox', 'radio', 'button' ].includes( target.type )
	);
}

/**
 * Where an arrow key moves focus from a control in a table body cell, or null.
 *
 * @param {Element} target Focused control.
 * @param {string}  key    Arrow key.
 * @return {Element|null} The control to focus.
 */
export function arrowTarget( target, key ) {
	const cell = target.closest( 'td, th' );
	const row = cell?.closest( 'tr' );
	if (
		! row ||
		! row.parentElement ||
		row.parentElement.tagName !== 'TBODY'
	) {
		return null;
	}
	if ( key === 'ArrowLeft' || key === 'ArrowRight' ) {
		const controls = controlsIn( row );
		const at = controls.indexOf( target );
		const next = controls[ at + ( key === 'ArrowRight' ? 1 : -1 ) ];
		return at >= 0 && next ? next : null;
	}
	const rows = Array.from( row.parentElement.children );
	const step = key === 'ArrowDown' ? 1 : -1;
	for (
		let index = rows.indexOf( row ) + step;
		index >= 0 && index < rows.length;
		index += step
	) {
		const other = rows[ index ].children[ cell.cellIndex ];
		const controls = other ? controlsIn( other ) : [];
		if ( controls.length ) {
			return controls[ 0 ];
		}
	}
	return null;
}

/**
 * The table wrapper's keydown handler.
 *
 * @param {KeyboardEvent} event Key.
 */
export function onGridKeyDown( event ) {
	if (
		! ARROWS.includes( event.key ) ||
		event.altKey ||
		event.ctrlKey ||
		event.metaKey ||
		event.shiftKey ||
		ownsArrows( event.target )
	) {
		return;
	}
	const next = arrowTarget( event.target, event.key );
	if ( next ) {
		event.preventDefault();
		next.focus();
	}
}
