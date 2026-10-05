/**
 * Data hooks for the catalogue: the field registry, one page of products for
 * a view, and the names of the terms the visible columns show.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import { request } from '../../api';
import { TERM_ROUTES } from './defaults';
import { viewToQuery } from './toolbar/filters'; // T41: filters join the query.

const NONE = {};

/**
 * GET /fields, once per mount and again on retry.
 *
 * @return {{registry: Object|null, error: Error|null, retry: Function}} State.
 */
export function useRegistry() {
	const [ registry, setRegistry ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		let live = true;
		setError( null );
		request( 'fields' )
			.then( ( answer ) => live && setRegistry( answer || {} ) )
			.catch( ( failure ) => live && setError( failure || new Error() ) );
		return () => {
			live = false;
		};
	}, [ attempt ] );

	const retry = useCallback( () => setAttempt( ( n ) => n + 1 ), [] );
	return { registry, error, retry };
}

/**
 * One page of GET /products for a view, re-queried whenever the view's route
 * arguments change. A failed read keeps the rows already on screen (SPEC §4.1
 * Error state); an answer to a superseded request is dropped.
 *
 * @param {Object}        view         DataViews view.
 * @param {Array<Object>} descriptors  Registry descriptors.
 * @param {Object}        extra        Further filter arguments (the category panel's).
 * @param {Function}      onChangeView Update the current page after it empties.
 * @return {Object} Current page state (`loadedKey`: the query, as JSON, the rows on screen answer), read-only refetch/reconcile and pending-row controls.
 */
export function useCatalogue( view, descriptors, extra = NONE, onChangeView ) {
	const query = useMemo(
		() => viewToQuery( view, descriptors, extra ),
		[ view, descriptors, extra ]
	);
	const key = JSON.stringify( query );
	const [ items, setItems ] = useState( [] );
	const [ pagination, setPagination ] = useState( {
		totalItems: 0,
		totalPages: 0,
	} );
	const [ isLoading, setLoading ] = useState( true );
	const [ hasLoaded, setLoaded ] = useState( false );
	const [ error, setError ] = useState( null );
	// The query the rows and total on screen answer (FRONTEND-1).
	const [ loadedKey, setLoadedKey ] = useState( null );
	const [ pending, setPendingIds ] = useState( () => new Set() );
	const latest = useRef( 0 );
	const current = useRef( { key, onChangeView } );
	const active = useRef( null );
	// Invalidate at render, before an old response can publish in the gap
	// between a changed query and its effect. Never capture a write's query.
	if ( current.current.key !== key ) {
		latest.current++;
	}
	current.current = { key, onChangeView };

	const read = useCallback( function startRead( wanted ) {
		const ticket = ++latest.current;
		setLoading( true );
		const promise = request( 'products', { query: JSON.parse( wanted ) } )
			.then(
				( answer ) => {
					if ( ticket !== latest.current ) {
						return { status: 'superseded' };
					}
					const pages = Number( answer?.pagination?.totalPages ) || 0;
					const queryPage = JSON.parse( wanted );
					const last = Math.max( 1, pages );
					if (
						queryPage.page > last &&
						current.current.onChangeView
					) {
						const next = JSON.stringify( {
							...queryPage,
							page: last,
						} );
						current.current.key = next;
						current.current.onChangeView( ( viewNow ) => ( {
							...viewNow,
							page: last,
						} ) );
						return startRead( next );
					}
					const paginationNow = {
						totalItems: Number( answer?.pagination?.total ) || 0,
						totalPages: pages,
					};
					setItems(
						Array.isArray( answer?.items ) ? answer.items : []
					);
					setPagination( paginationNow );
					setLoadedKey( wanted );
					setError( null );
					setLoaded( true );
					return { status: 'read', pagination: paginationNow };
				},
				( failure ) => {
					if ( ticket !== latest.current ) {
						return { status: 'superseded' };
					}
					setError( failure || new Error() );
					throw failure;
				}
			)
			.finally( () => {
				if ( ticket === latest.current ) {
					setLoading( false );
				}
			} );
		active.current = { key: wanted, promise };
		return promise;
	}, [] );

	const reconcile = useCallback(
		() => read( current.current.key ),
		[ read ]
	);
	useEffect( () => {
		// A clamped page has already started its authoritative read.
		if ( active.current?.key !== key ) {
			read( key ).catch( () => {} );
		}
	}, [ key, read ] );
	useEffect(
		() => () => {
			latest.current++;
			active.current = null;
		},
		[]
	);
	const refetch = useCallback( () => {
		reconcile().catch( () => {} );
	}, [ reconcile ] );

	/**
	 * Mark rows pending (a change in flight) or clear them (T46).
	 *
	 * @param {Array<number>} ids Row ids.
	 * @param {boolean}       on  Pending or not.
	 */
	const setPending = useCallback( ( ids, on = true ) => {
		setPendingIds( ( held ) => {
			const next = new Set( held );
			ids.forEach( ( id ) =>
				on ? next.add( id ) : next.delete( id )
			);
			return next;
		} );
	}, [] );

	return {
		query,
		items,
		pagination,
		isLoading,
		hasLoaded,
		loadedKey,
		error,
		refetch,
		reconcile,
		pending,
		setPending,
	};
}

/**
 * Every node of a nested term tree as id => name.
 *
 * @param {Array<Object>} nodes Tree nodes with `children`.
 * @param {Map}           into  Map to fill.
 * @return {Map} The map.
 */
export function flattenTree( nodes, into = new Map() ) {
	( Array.isArray( nodes ) ? nodes : [] ).forEach( ( node ) => {
		if ( node && node.id !== undefined ) {
			into.set( Number( node.id ), String( node.name ?? '' ) );
			flattenTree( node.children, into );
		}
	} );
	return into;
}

/**
 * Names for the term sources the visible columns show, fetched once per
 * source that has a route. A source without one renders as a count.
 *
 * @param {Array<string>} sources Term sources (a descriptor's `source`).
 * @return {Object} source => Map( id => name ).
 */
export function useTermNames( sources ) {
	const [ names, setNames ] = useState( {} );
	const wanted = sources
		.filter( ( source ) => TERM_ROUTES[ source ] )
		.sort()
		.join( ',' );

	useEffect( () => {
		let live = true;
		wanted
			.split( ',' )
			.filter( Boolean )
			.forEach( ( source ) => {
				request( TERM_ROUTES[ source ] )
					.then( ( answer ) => {
						if ( live ) {
							const tree = Array.isArray( answer )
								? answer
								: answer?.items;
							setNames( ( current ) => ( {
								...current,
								[ source ]: flattenTree( tree ),
							} ) );
						}
					} )
					.catch( () => {} );
			} );
		return () => {
			live = false;
		};
	}, [ wanted ] );

	return names;
}
