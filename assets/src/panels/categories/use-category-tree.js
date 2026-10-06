/**
 * useCategoryTree(): the product category tree from GET /categories, with
 * counts under the grid's active filters, an index by id and a reload after
 * any write. One instance can be shared between the catalogue (to name its
 * chip) and the panel, by passing it to <CategoryPanel tree={ … } />.
 */
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import { fetchTree } from './requests';
import { indexTree } from './tree-model';

const phaseFor = ( creation, byId, failed ) => {
	if ( ! creation ) {
		return null;
	}
	if ( failed ) {
		return 'refresh_failed';
	}
	return byId.has( creation.id ) ? 'visible' : 'missing';
};

/**
 * The tree and its state.
 *
 * @param {Object}  query           The grid's filter arguments for the counts.
 * @param {Object}  options         Options.
 * @param {boolean} options.enabled False to skip loading (default true).
 * @return {Object} Tree, loading/error state, reload(), descriptions/remember(),
 *                 and creation/recordCreated()/dismissCreation().
 */
export default function useCategoryTree( query = {}, { enabled = true } = {} ) {
	const key = JSON.stringify( query || {} );
	const [ nodes, setNodes ] = useState( [] );
	const [ loading, setLoading ] = useState( enabled );
	const [ loaded, setLoaded ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ attempt, setAttempt ] = useState( 0 );
	const generation = useRef( 0 );
	const mounted = useRef( false );
	const revision = useRef( 0 );
	const receipt = useRef( null );
	const [ creation, setCreation ] = useState( null );
	// Descriptions are not in the tree's nodes; keep the ones writes return.
	const [ descriptions, setDescriptions ] = useState( () => new Map() );

	const updateCreation = useCallback( ( next ) => {
		receipt.current = next;
		setCreation( next );
	}, [] );

	// Invalidate on query changes/unmount before any promise can settle,
	// without waiting for the passive effect that starts the next read.
	useLayoutEffect( () => {
		mounted.current = true;
		generation.current += 1;
		return () => {
			mounted.current = false;
			generation.current += 1;
		};
	}, [ key, enabled ] );

	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}
		let live = true;
		const requestGeneration = generation.current;
		const ownsRead = () =>
			live && mounted.current && generation.current === requestGeneration;
		setLoading( true );
		if ( receipt.current ) {
			updateCreation( { ...receipt.current, phase: 'refreshing' } );
		}
		fetchTree( JSON.parse( key ) )
			.then( ( answer ) => {
				if ( ! ownsRead() ) {
					return;
				}
				const nextNodes = Array.isArray( answer ) ? answer : [];
				setNodes( nextNodes );
				setError( null );
				setLoaded( true );
				if ( receipt.current ) {
					updateCreation( {
						...receipt.current,
						phase: phaseFor(
							receipt.current,
							indexTree( nextNodes ),
							false
						),
					} );
				}
			} )
			.catch( ( failure ) => {
				if ( ! ownsRead() ) {
					return;
				}
				setError( failure || {} );
				if ( receipt.current ) {
					updateCreation( {
						...receipt.current,
						phase: phaseFor( receipt.current, null, true ),
					} );
				}
			} )
			.finally( () => ownsRead() && setLoading( false ) );
		return () => {
			live = false;
		};
	}, [ key, attempt, enabled, updateCreation ] );

	const byId = useMemo( () => indexTree( nodes ), [ nodes ] );
	const reload = useCallback( () => {
		generation.current += 1;
		if ( receipt.current ) {
			updateCreation( { ...receipt.current, phase: 'refreshing' } );
		}
		setAttempt( ( n ) => n + 1 );
	}, [ updateCreation ] );
	const remember = useCallback( ( item ) => {
		if ( item && item.id && typeof item.description === 'string' ) {
			setDescriptions( ( map ) =>
				new Map( map ).set( item.id, item.description )
			);
		}
	}, [] );
	const recordCreated = useCallback(
		( item, submittedName ) => {
			// The caller handles incomplete responses separately from confirmed saves.
			if ( ! Number.isInteger( item?.id ) || item.id <= 0 ) {
				return;
			}
			revision.current += 1;
			remember( item );
			updateCreation( {
				revision: revision.current,
				id: item.id,
				name:
					( typeof item.name === 'string' && item.name.trim() ) ||
					( typeof submittedName === 'string'
						? submittedName.trim()
						: '' ),
				phase: 'refreshing',
			} );
			reload();
		},
		[ remember, reload, updateCreation ]
	);
	const dismissCreation = useCallback(
		() => updateCreation( null ),
		[ updateCreation ]
	);

	return {
		nodes,
		byId,
		loading,
		loaded,
		error,
		reload,
		descriptions,
		remember,
		creation,
		recordCreated,
		dismissCreation,
	};
}
