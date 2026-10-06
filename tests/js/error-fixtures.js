/**
 * Failures every panel must say in plain words (ticket 11 item 3; the bulk
 * panel since T85): what apiFetch rejects with when the server answers with
 * an HTML 500 (WordPress's fatal-error handler puts its HTML page in the JSON
 * `message`; a proxy's HTML page is apiFetch's `invalid_json`), and a JSON
 * error that is not the plugin's own refusal. Their text must never be shown;
 * only a `bulkcat_` refusal is shown in its own words.
 */

/* global expect */

/** The text none of these failures may put on screen. */
const RAW =
	/<p>|<a |critical error|troubleshooting|not a valid JSON|not allowed to do that/i;

/**
 * An Error shaped like apiFetch's rejection.
 *
 * @param {string} code    Code.
 * @param {string} message Message.
 * @param {Object} data    Data.
 * @return {Error} Rejection.
 */
function rejection( code, message, data = {} ) {
	return Object.assign( new Error( message ), { code, message, data } );
}

/** A 500 whose JSON message is WordPress's HTML critical-error page. */
export const html500 = () =>
	rejection(
		'internal_server_error',
		'<p>There has been a critical error on this website.</p><p><a href="https://wordpress.org/documentation/article/faq-troubleshooting/">Learn more about troubleshooting WordPress.</a></p>',
		{ status: 500 }
	);

/** A 502 page from a proxy: apiFetch cannot read it as JSON. */
export const htmlPage = () =>
	rejection( 'invalid_json', 'The response is not a valid JSON response.' );

/** A JSON error from WordPress itself, not one of the plugin's refusals. */
export const coreJson = () =>
	rejection( 'rest_forbidden', 'Sorry, you are not allowed to do that.', {
		status: 403,
	} );

/**
 * Expect a node to say the panel's plain sentence and none of the raw text.
 *
 * @param {HTMLElement} node     Where the failure is shown.
 * @param {string}      sentence The plain sentence.
 */
export function expectPlain( node, sentence ) {
	expect( node.textContent ).toContain( sentence );
	expect( node.textContent ).not.toMatch( RAW );
}

/**
 * Expect nothing anywhere in the document to show the raw text.
 */
export function expectNoRawText() {
	expect( document.body.textContent ).not.toMatch( RAW );
}
