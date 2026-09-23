/**
 * Who made a change, by name. The history and audit routes report the
 * batch's `user_id` only, so names come from core's own users route: one
 * request for the people who can use this screen (the user filter), and
 * one batched lookup per page for any other id. A name that cannot be read
 * (the user was deleted, or this user may not list users) reads "User 12";
 * the current user reads as their own name from the page settings.
 *
 * Follow-up (PHP): adding `user_name` to the batch and audit rows would
 * remove the second request; see docs/TICKETS.md T57.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { useEffect, useState } from '@wordpress/element';

import { routeUrl } from '../../api';

/**
 * Core's users route, beside this plugin's namespace root (so it works
 * with plain permalinks as well as pretty ones).
 *
 * @param {Object} query Query arguments.
 * @return {string} URL.
 */
export function usersUrl( query ) {
	const base = routeUrl( '' ).replace( /bcm\/v1\/?$/, 'wp/v2/users' );
	return addQueryArgs( base, query );
}

/**
 * A user's name, or a plain stand-in.
 *
 * @param {number} id    User id.
 * @param {Object} names Names by id.
 * @return {string} Name.
 */
export function userName( id, names ) {
	if ( names?.[ id ] ) {
		return names[ id ];
	}
	if ( ! id ) {
		return __( 'Someone', 'bulk-catalogue-manager-for-woocommerce' );
	}
	return sprintf(
		/* translators: %d: a user id. */
		__( 'User %d', 'bulk-catalogue-manager-for-woocommerce' ),
		id
	);
}

/**
 * Names by id for the screen's users and any id seen on the page.
 *
 * @param {Object}        currentUser `{ id, name }` from the page settings.
 * @param {Array<number>} seen        User ids on the current page.
 * @return {Object} Names by id.
 */
export function useUserNames( currentUser, seen ) {
	const [ names, setNames ] = useState( () =>
		currentUser?.id ? { [ currentUser.id ]: currentUser.name } : {}
	);

	useEffect( () => {
		let live = true;
		apiFetch( {
			url: usersUrl( {
				per_page: 100,
				capabilities: 'manage_woocommerce',
				_fields: 'id,name',
			} ),
		} )
			.then( ( users ) => {
				if ( live && Array.isArray( users ) ) {
					setNames( ( known ) => {
						const next = { ...known };
						users.forEach( ( user ) => {
							next[ user.id ] = user.name;
						} );
						return next;
					} );
				}
			} )
			.catch( () => {} );
		return () => {
			live = false;
		};
	}, [] );

	const missing = ( seen || [] )
		.filter( ( id ) => id && ! names[ id ] )
		.filter( ( id, index, all ) => all.indexOf( id ) === index )
		.sort( ( a, b ) => a - b );
	const key = missing.join( ',' );

	useEffect( () => {
		if ( ! key ) {
			return;
		}
		let live = true;
		apiFetch( {
			url: usersUrl( {
				include: key,
				per_page: 100,
				_fields: 'id,name',
			} ),
		} )
			.then( ( users ) => {
				if ( ! live ) {
					return;
				}
				setNames( ( known ) => {
					const next = { ...known };
					( Array.isArray( users ) ? users : [] ).forEach(
						( user ) => {
							next[ user.id ] = user.name;
						}
					);
					// Remember the ones core would not name, so they are not asked for again.
					key.split( ',' ).forEach( ( id ) => {
						if ( ! next[ id ] ) {
							next[ id ] = userName( Number( id ), {} );
						}
					} );
					return next;
				} );
			} )
			.catch( () => {} );
		return () => {
			live = false;
		};
	}, [ key ] );

	return names;
}

/**
 * The user filter's choices: every named user, the current user first.
 *
 * @param {Object} names       Names by id.
 * @param {Object} currentUser `{ id, name }`.
 * @return {Array<{value: number, label: string}>} Elements.
 */
export function userElements( names, currentUser ) {
	return Object.keys( names )
		.map( Number )
		.sort( ( a, b ) => {
			if ( a === currentUser?.id ) {
				return -1;
			}
			if ( b === currentUser?.id ) {
				return 1;
			}
			return String( names[ a ] ).localeCompare( String( names[ b ] ) );
		} )
		.map( ( id ) => ( { value: id, label: names[ id ] } ) );
}
