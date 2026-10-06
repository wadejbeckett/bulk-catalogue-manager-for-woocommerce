/**
 * The selection bar's Show in shop and search and Hide from shop and search
 * (T42, T93, SPEC §4.1, §5.3): each opens the bulk change panel with one
 * step already chosen — set the Shown in shop and search switch on or off —
 * so it is previewed, confirmed and journalled like any other change. They
 * change listing only; Status is the Off/On control. The step is built with the bulk panel's
 * own operation helpers, and the action is the registry's "set" action for
 * that field rather than a named one.
 */
import { fieldByKey } from '../../bulk/descriptors';
import { changeArg, chooseAction, emptyOperation } from '../../bulk/operations';
import { CATEGORY_FILTER, QUICK_VISIBILITY } from '../defaults';

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
 * The prefilled step for Show in shop and search (`show` true) or Hide from shop and search, or
 * null when the registry offers no such field, value or action.
 *
 * @param {Object}  registry The `/fields` payload.
 * @param {Object}  settings Settings.
 * @param {boolean} show     Show in shop and search, or hide from it.
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

/**
 * The prefilled step for a selection dropped on a category (T65, SPEC §4.3):
 * the registry's action that adds terms to the category field, with that
 * category chosen, or null when the registry offers no such action.
 *
 * @param {Object} registry   The `/fields` payload.
 * @param {Object} settings   Settings.
 * @param {number} categoryId The category dropped on.
 * @return {Object|null} Panel operation.
 */
export function addCategoryOperation( registry, settings, categoryId ) {
	const id = Number( categoryId );
	const field = fieldByKey( registry, CATEGORY_FILTER );
	const action = ( registry?.actions || [] ).find(
		( each ) =>
			( each.editKinds || [] ).includes( 'add' ) &&
			( each.fields || [] ).includes( CATEGORY_FILTER ) &&
			each.args?.properties?.terms
	);
	if ( ! id || ! field || ! action ) {
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
	operation = changeArg(
		registry,
		settings,
		operation,
		'field',
		CATEGORY_FILTER
	);
	return changeArg( registry, settings, operation, 'terms', [ id ] );
}
