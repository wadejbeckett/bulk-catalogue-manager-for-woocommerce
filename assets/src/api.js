/**
 * Requests to the plugin's own REST namespace. apiFetch sends the wp_rest
 * nonce through its nonce middleware; every path is resolved against the
 * localised bcm/v1 root, which also works with plain permalinks
 * (index.php?rest_route=), so no route is ever built by hand elsewhere.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

let root = '';

/**
 * Wire the nonce middleware and the namespace root. Idempotent per page.
 *
 * @param {Object} settings Localised settings.
 * @return {Function|null} The nonce middleware (its `nonce` can be refreshed), or null when there is no nonce.
 */
export function configureApi( settings ) {
	root = settings.root || '';
	if ( ! settings.nonce ) {
		return null;
	}
	const nonceMiddleware = apiFetch.createNonceMiddleware( settings.nonce );
	apiFetch.use( nonceMiddleware );
	return nonceMiddleware;
}

/**
 * The full URL of a route under bcm/v1.
 *
 * @param {string} route Route below the namespace, e.g. "products".
 * @param {Object} query Query arguments.
 * @return {string} URL.
 */
export function routeUrl( route, query = {} ) {
	return addQueryArgs( root + route.replace( /^\/+/, '' ), query );
}

/**
 * Call a bcm/v1 route.
 *
 * @param {string} route         Route below the namespace.
 * @param {Object} options       apiFetch options plus `query`.
 * @param {Object} options.query Query arguments.
 * @return {Promise<*>} The parsed answer.
 */
export function request( route, { query = {}, ...options } = {} ) {
	return apiFetch( { ...options, url: routeUrl( route, query ) } );
}
