/**
 * useCategoryTree(): the product category tree from GET /categories, with
 * counts under the grid's active filters, an index by id and a reload after
 * any write. One instance can be shared between the catalogue (to name its
 * chip) and the panel, by passing it to <CategoryPanel tree={ … } />.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

import { fetchTree } from './requests';
import { indexTree } from './tree-model';

/**
 * The tree and its state.
 *
 * @param {Object}  query           The grid's filter arguments for the counts.
 * @param {Object}  options         Options.
 * @param {boolean} options.enabled False to skip loading (default true).
 * @return {Object} { nodes, byId, loading, loaded, error, reload, descriptions, remember }.
 */
export default function useCategoryTree( query = {}, { enabled = true } = {} ) {
	const key = JSON.stringify( query || {} );
	const [ nodes, setNodes ] = useState( [] );
	const [ loading, setLoading ] = useState( enabled );
	const [ loaded, setLoaded ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ attempt, setAttempt ] = useState( 0 );
	// Descriptions are not in the tree's nodes; keep the ones writes return.
	const [ descriptions, setDescriptions ] = useState( () => new Map() );

	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}
		let live = true;
		setLoading( true );
		fetchTree( JSON.parse( key ) )
			.then( ( answer ) => {
				if ( ! live ) {
					return;
				}
				setNodes( Array.isArray( answer ) ? answer : [] );
				setError( null );
				setLoaded( true );
			} )
			.catch( ( failure ) => live && setError( failure || {} ) )
			.finally( () => live && setLoading( false ) );
		return () => {
			live = false;
		};
	}, [ key, attempt, enabled ] );

	const byId = useMemo( () => indexTree( nodes ), [ nodes ] );
	const reload = useCallback( () => setAttempt( ( n ) => n + 1 ), [] );
	const remember = useCallback( ( item ) => {
		if ( item && item.id && typeof item.description === 'string' ) {
			setDescriptions( ( map ) =>
				new Map( map ).set( item.id, item.description )
			);
		}
	}, [] );

	return {
		nodes,
		byId,
		loading,
		loaded,
		error,
		reload,
		descriptions,
		remember,
	};
}
