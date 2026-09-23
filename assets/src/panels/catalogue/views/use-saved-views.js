/**
 * The current user's saved views through /bcm/v1/views (T52). Every write
 * answers the whole list, which replaces the one held here, so the chips
 * always show what the server stored.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { request } from '../../../api';
import { toSaved } from './serialize';

/**
 * A name not already used: the wanted one, else "Name (copy)", "Name (copy 2)", …
 *
 * @param {string}        wanted Name.
 * @param {Array<Object>} items  Saved views.
 * @return {string} Free name.
 */
export function freeName( wanted, items ) {
	const taken = new Set(
		items.map( ( item ) => String( item.name ).toLowerCase() )
	);
	const copy = sprintf(
		/* translators: %s: a saved view's name. */
		__( '%s (copy)', 'bulk-catalogue-manager-for-woocommerce' ),
		wanted
	);
	let name = copy;
	for ( let n = 2; taken.has( name.toLowerCase() ); n++ ) {
		name = sprintf(
			/* translators: 1: a saved view's name, 2: a number from 2 up. */
			__( '%1$s (copy %2$d)', 'bulk-catalogue-manager-for-woocommerce' ),
			wanted,
			n
		);
	}
	return name;
}

/**
 * The list and the calls that change it.
 *
 * @return {Object} { items, defaultId, isLoading, hasLoaded, error, busy, save, update, rename, duplicate, remove, reorder, setDefault, clearError }.
 */
export function useSavedViews() {
	const [ items, setItems ] = useState( [] );
	const [ defaultId, setDefaultId ] = useState( null );
	const [ isLoading, setLoading ] = useState( true );
	const [ hasLoaded, setLoaded ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const take = useCallback( ( answer ) => {
		setItems( Array.isArray( answer?.items ) ? answer.items : [] );
		setDefaultId( answer?.default || null );
		return answer;
	}, [] );

	useEffect( () => {
		let live = true;
		request( 'views' )
			.then( ( answer ) => live && take( answer ) )
			.catch( ( failure ) => live && setError( failure || new Error() ) )
			.finally( () => {
				if ( live ) {
					setLoading( false );
					setLoaded( true );
				}
			} );
		return () => {
			live = false;
		};
	}, [ take ] );

	/**
	 * Run a write; a refusal is held in `error` and rethrown.
	 *
	 * @param {string} route   Route below the namespace.
	 * @param {Object} options apiFetch options.
	 * @return {Promise<Object>} The answer.
	 */
	const write = useCallback(
		( route, options ) => {
			setBusy( true );
			setError( null );
			return request( route, options )
				.then( take )
				.catch( ( failure ) => {
					setError( failure || new Error() );
					throw failure;
				} )
				.finally( () => setBusy( false ) );
		},
		[ take ]
	);

	const save = useCallback(
		( name, view, category, isDefault = false ) =>
			write( 'views', {
				method: 'POST',
				data: { name, ...toSaved( view, category ), isDefault },
			} ),
		[ write ]
	);
	const update = useCallback(
		( id, view, category ) =>
			write( `views/${ id }`, {
				method: 'PATCH',
				data: toSaved( view, category ),
			} ),
		[ write ]
	);
	const rename = useCallback(
		( id, name ) =>
			write( `views/${ id }`, { method: 'PATCH', data: { name } } ),
		[ write ]
	);
	const duplicate = useCallback(
		( id ) => {
			const source = items.find( ( item ) => item.id === id );
			if ( ! source ) {
				return Promise.resolve( null );
			}
			return write( 'views', {
				method: 'POST',
				data: {
					name: freeName( source.name, items ),
					view: source.view,
					category: source.category || null,
				},
			} );
		},
		[ items, write ]
	);
	const remove = useCallback(
		( id ) => write( `views/${ id }`, { method: 'DELETE' } ),
		[ write ]
	);
	const reorder = useCallback(
		( ids ) => write( 'views/reorder', { method: 'POST', data: { ids } } ),
		[ write ]
	);
	const setDefault = useCallback(
		( id, on = true ) =>
			write( `views/${ id }/default`, {
				method: 'POST',
				data: { default: on },
			} ),
		[ write ]
	);
	const clearError = useCallback( () => setError( null ), [] );

	return {
		items,
		defaultId,
		isLoading,
		hasLoaded,
		error,
		busy,
		save,
		update,
		rename,
		duplicate,
		remove,
		reorder,
		setDefault,
		clearError,
	};
}

/**
 * The ids with one moved: by an offset (Move earlier / later) or before
 * another id (a drop).
 *
 * @param {Array<string>} ids    Current order.
 * @param {string}        id     The one moved.
 * @param {number|string} target An offset, or the id to land before.
 * @return {Array<string>} New order (the same array when nothing moves).
 */
export function moved( ids, id, target ) {
	const from = ids.indexOf( id );
	if ( from < 0 ) {
		return ids;
	}
	const rest = ids.filter( ( each ) => each !== id );
	let to;
	if ( typeof target === 'number' ) {
		to = from + target;
	} else {
		to = rest.indexOf( target );
		if ( to < 0 || target === id ) {
			return ids;
		}
		if ( ids.indexOf( target ) > from ) {
			to += 1;
		}
	}
	if ( to < 0 || to > rest.length || to === from ) {
		return ids;
	}
	return [ ...rest.slice( 0, to ), id, ...rest.slice( to ) ];
}
