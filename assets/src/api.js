/**
 * Requests to the plugin's own REST namespace. apiFetch sends the wp_rest
 * nonce through its nonce middleware, kept fresh by the Heartbeat API and
 * recovered once after an invalid-nonce failure (./nonce.js); every path is
 * resolved against the localised bulkcat/v1 root, which also works with plain
 * permalinks (index.php?rest_route=), so no route is ever built by hand
 * elsewhere.
 */
import apiFetch from '@wordpress/api-fetch';
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
 * Call a bulkcat/v1 route.
 *
 * @param {string} route         Route below the namespace.
 * @param {Object} options       apiFetch options plus `query`.
 * @param {Object} options.query Query arguments.
 * @return {Promise<*>} The parsed answer.
 */
export function request( route, { query = {}, ...options } = {} ) {
	return apiFetch( { ...options, url: routeUrl( route, query ) } );
}
