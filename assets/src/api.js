/**
 * Requests to the plugin's own REST namespace. apiFetch sends the wp_rest
 * nonce through its nonce middleware, kept fresh by the Heartbeat API and
 * recovered once after an invalid-nonce failure (./nonce.js); every path is
 * resolved against the localised bulkcat/v1 root, which also works with plain
 * permalinks (index.php?rest_route=), so no route is ever built by hand
 * elsewhere.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { createRetryMiddleware, fetchNonce, subscribeHeartbeat } from './nonce';

let root = '';

/**
 * Wire the nonce, the invalid-nonce retry, the Heartbeat refresh and the
 * namespace root. Registers exactly one middleware.
 *
 * On a WordPress admin page apiFetch is the host's, which already carries
 * core's nonce middleware (`apiFetch.nonceMiddleware`); that one is reused,
 * since a second nonce middleware would be overwritten by core's. Without it
 * a nonce middleware is created, published as `apiFetch.nonceMiddleware` and
 * run inside this screen's middleware.
 *
 * @param {Object} settings Localised settings.
 * @param {Window} win      The window (Heartbeat's jQuery and fetch).
 * @return {Object|null} The nonce middleware (its `nonce` is what is sent), or null when there is no nonce.
 */
export function configureApi( settings, win = window ) {
	root = settings.root || '';
	if ( ! settings.nonce ) {
		return null;
	}
	let nonceMiddleware = apiFetch.nonceMiddleware;
	let send = ( options, next ) => next( options );
	if ( ! nonceMiddleware || typeof nonceMiddleware !== 'function' ) {
		nonceMiddleware = apiFetch.createNonceMiddleware( settings.nonce );
		apiFetch.nonceMiddleware = nonceMiddleware;
		send = ( options, next ) => nonceMiddleware( options, next );
	}
	const install = ( nonce ) => {
		nonceMiddleware.nonce = nonce;
	};
	apiFetch.use(
		createRetryMiddleware( {
			send,
			install,
			refresh: () =>
				fetchNonce(
					win.fetch && win.fetch.bind( win ),
					apiFetch.nonceEndpoint
				),
		} )
	);
	subscribeHeartbeat( win, install );
	return nonceMiddleware;
}

/**
 * The full URL of a route under bulkcat/v1.
 *
 * @param {string} route Route below the namespace, e.g. "products".
 * @param {Object} query Query arguments.
 * @return {string} URL.
 */
export function routeUrl( route, query = {} ) {
	return addQueryArgs( root + route.replace( /^\/+/, '' ), query );
}

/**
 * Whether a request is a 1.0 write body (T84): a POST to a route that since
 * T83 takes only durable requests — capture (`selection`), Apply (`batches`)
 * or undo (`batches/{id}/undo`, other than its preview) — without a server
 * grant's `uuid`, `request_proof` and `intent`.
 *
 * @param {string} route   Route below the namespace.
 * @param {Object} options apiFetch options.
 * @return {boolean} True for a body the screen must never send.
 */
export function isLegacyWrite( route, options = {} ) {
	if ( String( options.method || 'GET' ).toUpperCase() !== 'POST' ) {
		return false;
	}
	const path = String( route ).replace( /^\/+/, '' ).split( '?' )[ 0 ];
	const data = options.data || {};
	const undo = /^batches\/\d+\/undo$/.test( path );
	if ( path !== 'selection' && path !== 'batches' && ! undo ) {
		return false;
	}
	if ( undo && data.preview === true ) {
		return false;
	}
	return ! ( data.uuid && data.request_proof && data.intent );
}

/**
 * Call a bulkcat/v1 route. A 1.0 write body is refused here, before
 * anything is sent (isLegacyWrite()).
 *
 * @param {string} route         Route below the namespace.
 * @param {Object} options       apiFetch options plus `query`.
 * @param {Object} options.query Query arguments.
 * @return {Promise<*>} The parsed answer.
 */
export function request( route, { query = {}, ...options } = {} ) {
	if ( isLegacyWrite( route, options ) ) {
		return Promise.reject( {
			code: 'bulkcat_legacy_request',
			message: __(
				'This page tried to send a change in a form the site no longer accepts, so nothing was sent. Reload the page, then try again.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			data: { status: 409 },
		} );
	}
	return apiFetch( { ...options, url: routeUrl( route, query ) } );
}
