/**
 * The one rule for saying a failed request in plain words (SPEC §4.8), for
 * every panel: the bulk panel since T85, History, Categories, Settings, saved
 * views and export since ticket 11. Only the plugin's own refusals — a
 * `bulkcat_` code with a sentence written for people — are shown as
 * sentences. Anything else (a server's HTML error page, a proxy's text,
 * another program's message) is the panel's own plain sentence, with the
 * code behind Show details; its text is never shown.
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * The sentence for a failure the panel has no better words for.
 *
 * @return {string} Sentence.
 */
export function genericMessage() {
	return __(
		'Something went wrong. Try again in a moment.',
		'bulk-catalogue-manager-for-woocommerce'
	);
}

/**
 * Whether a failure is one of the plugin's own refusals, whose message is a
 * sentence written for people.
 *
 * @param {*} error apiFetch's rejection, or a refusal shaped like it.
 * @return {boolean} True for a `bulkcat_` code with a sentence.
 */
export function isOwnRefusal( error ) {
	return (
		/^bulkcat_/.test( String( error?.code || '' ) ) &&
		typeof error?.message === 'string' &&
		error.message.trim() !== ''
	);
}

/**
 * The sentence to show for a failed request.
 *
 * @param {*}      error    apiFetch's rejection.
 * @param {string} fallback The panel's sentence for anything else; the generic one when omitted.
 * @return {string} Sentence.
 */
export function plainMessage( error, fallback ) {
	if ( isOwnRefusal( error ) ) {
		return error.message;
	}
	return fallback || genericMessage();
}

/**
 * The technical line behind Show details: the code and the HTTP status,
 * such as "internal_server_error · HTTP 500"; never the server's own text.
 *
 * @param {*} error apiFetch's rejection.
 * @return {string} Line, or '' when there is neither.
 */
export function technicalLine( error ) {
	const parts = [];
	if ( error?.code ) {
		parts.push( String( error.code ) );
	}
	if ( error?.data?.status ) {
		parts.push(
			sprintf(
				/* translators: %d: an HTTP status code such as 400. */
				__( 'HTTP %d', 'bulk-catalogue-manager-for-woocommerce' ),
				error.data.status
			)
		);
	}
	return parts.join( ' · ' );
}
