/**
 * The selection bar's Show on site and Hide from site (T42, SPEC §4.1): each
 * opens the bulk change panel with one step already chosen — set the
 * Visible on site switch on or off — so it is previewed, confirmed and
 * journalled like any other change. The step is built with the bulk panel's
 * own operation helpers, and the action is the registry's "set" action for
 * that field rather than a named one.
 */
import { fieldByKey } from '../../bulk/descriptors';
import { changeArg, chooseAction, emptyOperation } from '../../bulk/operations';
import { QUICK_VISIBILITY } from '../defaults';

/**
 * The registry action that sets a field's value, or undefined.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {string} key      Field key.
 * @return {Object|undefined} Action.
 */
function setAction( registry, key ) {
	return ( registry?.actions || [] ).find(
		( action ) =>
			( action.editKinds || [] ).includes( 'set' ) &&
			( action.fields || [] ).includes( key ) &&
			action.args?.properties?.value
	);
}

/**
 * The prefilled step for Show on site (`show` true) or Hide from site, or
 * null when the registry offers no such field, value or action.
 *
 * @param {Object}  registry The `/fields` payload.
 * @param {Object}  settings Settings.
 * @param {boolean} show     Show on site, or hide from it.
 * @return {Object|null} Panel operation.
 */
export function visibilityOperation( registry, settings, show ) {
	const key = QUICK_VISIBILITY.field;
	const value = show ? QUICK_VISIBILITY.show : QUICK_VISIBILITY.hide;
	const field = fieldByKey( registry, key );
	const action = setAction( registry, key );
	if (
		! field ||
		! action ||
		! ( field.writableChoices || [] ).includes( value )
	) {
		return null;
	}
	const group = ( action.groups || [] ).includes( field.group )
		? field.group
		: action.group;
	let operation = chooseAction(
		registry,
		settings,
		emptyOperation( 1 ),
		action.id,
		group
	);
	operation = changeArg( registry, settings, operation, 'field', key );
	return changeArg( registry, settings, operation, 'value', value );
}
