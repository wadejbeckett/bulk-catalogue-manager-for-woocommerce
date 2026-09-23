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
 * @param {Object}        view        DataViews view.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @param {Object}        extra       Further filter arguments (the category panel's).
 * @return {Object} { query, items, pagination, isLoading, hasLoaded, error, refetch, pending, setPending }.
 */
export function useCatalogue( view, descriptors, extra = NONE ) {
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
	const [ attempt, setAttempt ] = useState( 0 );
	const [ pending, setPendingIds ] = useState( () => new Set() );
	const latest = useRef( 0 );

	useEffect( () => {
		const ticket = ++latest.current;
		setLoading( true );
		request( 'products', { query: JSON.parse( key ) } )
			.then( ( answer ) => {
				if ( ticket !== latest.current ) {
					return;
				}
				setItems( Array.isArray( answer?.items ) ? answer.items : [] );
				setPagination( {
					totalItems: Number( answer?.pagination?.total ) || 0,
					totalPages: Number( answer?.pagination?.totalPages ) || 0,
				} );
				setError( null );
				setLoaded( true );
			} )
			.catch( ( failure ) => {
				if ( ticket === latest.current ) {
					setError( failure || new Error() );
				}
			} )
			.finally( () => {
				if ( ticket === latest.current ) {
					setLoading( false );
				}
			} );
	}, [ key, attempt ] );

	useEffect(
		() => () => {
			latest.current++;
		},
		[]
	);

	const refetch = useCallback( () => setAttempt( ( n ) => n + 1 ), [] );

	/**
	 * Mark rows pending (a change in flight) or clear them (T46).
	 *
	 * @param {Array<number>} ids Row ids.
	 * @param {boolean}       on  Pending or not.
	 */
	const setPending = useCallback( ( ids, on = true ) => {
		setPendingIds( ( current ) => {
			const next = new Set( current );
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
		error,
		refetch,
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
