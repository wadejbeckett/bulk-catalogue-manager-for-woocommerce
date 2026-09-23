/**
 * Who made a change, by name. Batch and audit rows carry the server's own
 * `user_name`; the user filter's choices come from GET /batches/users,
 * `{ items: [ { id, name } ] }` of every user who owns a batch. A row
 * without a name reads the filter's name for its id, else "User 12"; the
 * current user reads as their own name from the page settings.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';

import { request } from '../../api';

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
 * Who a row names: the row's own `user_name`, else a name by its id.
 *
 * @param {Object} row   A batch or audit row.
 * @param {Object} names Names by id.
 * @return {string} Name.
 */
export function rowUserName( row, names ) {
	return row?.user_name
		? String( row.user_name )
		: userName( row?.user_id, names );
}

/**
 * Names by id of every batch owner (GET /batches/users), and the current user.
 *
 * @param {Object} currentUser `{ id, name }` from the page settings.
 * @return {Object} Names by id.
 */
export function useBatchUsers( currentUser ) {
	const [ names, setNames ] = useState( () =>
		currentUser?.id ? { [ currentUser.id ]: currentUser.name } : {}
	);

	useEffect( () => {
		let live = true;
		request( 'batches/users' )
			.then( ( answer ) => {
				if ( ! live || ! Array.isArray( answer?.items ) ) {
					return;
				}
				setNames( ( known ) => {
					const next = { ...known };
					answer.items.forEach( ( user ) => {
						if ( user?.id ) {
							next[ user.id ] = String( user.name ?? '' );
						}
					} );
					return next;
				} );
			} )
			.catch( () => {} );
		return () => {
			live = false;
		};
	}, [] );

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
