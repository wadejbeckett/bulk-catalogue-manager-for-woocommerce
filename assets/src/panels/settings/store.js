/**
 * The shop's settings as every panel reads them (SPEC §11). They start as
 * the values localised at page load (window.bulkcatSettings.settings, from
 * src/Admin/Screen.php) and follow each save from the Settings tab, so a
 * panel reading useSettings() sees a change without a reload. Access is
 * never decided here: every route checks it on the server.
 *
 * The helpers below turn a setting into its observable effect, each with the
 * §11 default for a value that is missing or malformed.
 */
import { useSyncExternalStore } from '@wordpress/element';

import { readSettings } from '../../settings';

const PAGE_SIZES = [ 25, 50, 100 ];

const listeners = new Set();

let current = null;

/**
 * The current settings values, keyed by setting.
 *
 * @return {Object} Values.
 */
export function getSettings() {
	if ( current === null ) {
		const given = readSettings().settings;
		current = given && typeof given === 'object' ? { ...given } : {};
	}
	return current;
}

/**
 * Replace the known values with freshly saved ones and tell every reader.
 *
 * @param {Object} values Saved values, as PUT /settings answers them.
 */
export function setSettings( values ) {
	current = { ...getSettings(), ...values };
	listeners.forEach( ( listener ) => listener() );
}

/**
 * Follow changes to the settings.
 *
 * @param {Function} listener Called after each change.
 * @return {Function} Stops following.
 */
export function subscribeSettings( listener ) {
	listeners.add( listener );
	return () => listeners.delete( listener );
}

/**
 * The current settings values, re-rendering the caller when they are saved.
 *
 * @return {Object} Values keyed by setting.
 */
export function useSettings() {
	return useSyncExternalStore( subscribeSettings, getSettings, getSettings );
}

/**
 * The catalogue's starting page size: 25, 50 or 100.
 *
 * @param {Object} values Settings values.
 * @return {number} Rows per page.
 */
export function initialPerPage( values ) {
	return PAGE_SIZES.includes( values?.rows_per_page )
		? values.rows_per_page
		: 25;
}

/**
 * The row count above which Apply needs a second click.
 *
 * @param {Object} values Settings values.
 * @return {number} Threshold, 0 or more.
 */
export function confirmThreshold( values ) {
	const threshold = values?.confirm_threshold;
	return Number.isInteger( threshold ) && threshold >= 0 ? threshold : 200;
}

/**
 * Whether Apply restates the count and needs one more click (SPEC §4.5):
 * above the threshold, or always for a change that drafts, hides or empties.
 *
 * @param {number}  count         Rows the change will touch.
 * @param {Object}  values        Settings values.
 * @param {boolean} alwaysConfirm Whether the change always confirms.
 * @return {boolean} Whether to ask again.
 */
export function needsSecondClick( count, values, alwaysConfirm = false ) {
	return alwaysConfirm || count > confirmThreshold( values );
}

/**
 * Where the "Also apply to variations" switch starts for a field group:
 * status, visibility, price or stock. Status and visibility start on,
 * price, stock and anything else off.
 *
 * @param {string} group  Field group.
 * @param {Object} values Settings values.
 * @return {boolean} Starting position.
 */
export function applyToVariationsDefault( group, values ) {
	const defaults = {
		status: true,
		visibility: true,
		price: false,
		stock: false,
	};
	if ( ! ( group in defaults ) ) {
		return false;
	}
	const value = values?.[ `variations_${ group }` ];
	return typeof value === 'boolean' ? value : defaults[ group ];
}

/**
 * Whether the category panel starts open.
 *
 * @param {Object} values Settings values.
 * @return {boolean} Open.
 */
export function categoryPanelOpen( values ) {
	return values?.category_panel !== false;
}
