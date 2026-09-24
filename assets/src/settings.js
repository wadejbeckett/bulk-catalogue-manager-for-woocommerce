/**
 * What the screen was given at page load (src/Admin/Screen.php): the bulkcat/v1
 * root, the wp_rest nonce, the settings, the fields endpoint, the current
 * user and the host WordPress major. Read once, never trusted for access:
 * every route checks permissions itself.
 */
const EMPTY = {
	root: '',
	nonce: '',
	settings: {},
	fieldsEndpoint: '',
	user: { id: 0, name: '' },
	wpMajor: 0,
};

/**
 * The localised settings, with defaults for anything missing.
 *
 * @param {Window} win The window to read from.
 * @return {Object} Settings.
 */
export function readSettings( win = window ) {
	const given = win.bulkcatSettings;
	return given && typeof given === 'object' ? { ...EMPTY, ...given } : EMPTY;
}
