/**
 * Editing in the catalogue (T45–T47): the "Shown in shop and search" switch
 * (switch.js), inline cell editing (cell.js) and the detail panel
 * (drawer.js), held together by the editing scope around the table
 * (scope.js). fields.js renders every cell through renderEditable() and
 * table.js wraps the table in useEditScope()'s `wrap()`; nothing else in the
 * catalogue knows about editing.
 */
import { useContext } from '@wordpress/element';

import { LockedContext, cellState } from '../variations';
import InlineCell from './cell';
import { isInline, isSwitch } from './rules';
import { EditContext } from './scope';
import VisibleSwitch from './switch';
import './style.scss';

export { useEditScope, EditContext } from './scope';
export { default as DetailDrawer } from './drawer';
export { default as VisibleSwitch, positionOf } from './switch';
export { default as InlineCell } from './cell';

/**
 * A cell that edits when the registry says it may and a scope is present,
 * otherwise the plain cell.
 *
 * @param {Object} props            Props.
 * @param {Object} props.cell       The plain cell's props.
 * @param {Object} props.renderCell The plain renderer (cells.js).
 * @return {Element} Cell.
 */
function EditableField( { cell, renderCell } ) {
	const scope = useContext( EditContext );
	const locked = useContext( LockedContext );
	// T67 decides first: a locked cell (derived, range, summary, mixed,
	// parent-only, not applicable) or one cellState() calls not editable
	// is the plain value.
	if (
		! scope ||
		locked ||
		! cellState( cell.item, cell.descriptor, scope.descriptors ).editable
	) {
		return renderCell( cell );
	}
	if ( scope && isSwitch( cell.descriptor ) ) {
		return <VisibleSwitch { ...cell } />;
	}
	if ( scope && isInline( cell.descriptor ) && ! cell.role ) {
		return <InlineCell { ...cell } display={ renderCell( cell ) } />;
	}
	return renderCell( cell );
}

/**
 * Render a cell through the editing layer.
 *
 * @param {Object}   cell       { item, descriptor, companion, role }.
 * @param {Function} renderCell The plain renderer.
 * @return {Element} Cell.
 */
export function renderEditable( cell, renderCell ) {
	return <EditableField cell={ cell } renderCell={ renderCell } />;
}
