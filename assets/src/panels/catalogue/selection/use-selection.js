/**
 * The selection's state (T42): ticked ids kept across pages of one view,
 * select all matching through POST /selection, and clearing with a notice
 * when the view's conditions change.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { request } from '../../../api';
import { isPageSelected, mergePageSelection } from './model';

const NONE = [];

/**
 * Selection state for a view.
 *
 * `conditions` are the view's row-choosing arguments (selectionQuery()):
 * while they stay the same, paging, sorting and columns keep the selection;
 * when they change, any selection is discarded, never carried to rows the
 * owner has not seen, and `notice` says so.
 *
 * @param {Object}        options            Options.
 * @param {Object}        options.conditions selectionQuery() of the view.
 * @param {Array<Object>} options.items      Rows on this page.
 * @return {Object} { ids, matching, count, error, notice, tableSelection,
 *                  pageSelected, onChangeSelection, selectAllMatching,
 *                  selectPage, clear, dismissNotice }.
 */
export default function useSelection( { conditions, items = NONE } ) {
	const key = JSON.stringify( conditions || {} );
	const [ ids, setIds ] = useState( NONE );
	const [ matching, setMatching ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const current = useRef( key );
	const sequence = useRef( 0 );
	const held = useRef( false );
	held.current = ids.length > 0 || !! matching;

	const pageIds = useMemo(
		() => items.map( ( item ) => String( item.id ) ),
		[ items ]
	);

	useEffect( () => {
		if ( current.current === key ) {
			return;
		}
		current.current = key;
		sequence.current++;
		setError( null );
		if ( held.current ) {
			setIds( NONE );
			setMatching( null );
			setNotice(
				__(
					'The selection was cleared because the filters changed.',
					'bulk-catalogue-manager-for-woocommerce'
				)
			);
		}
	}, [ key ] );

	// Variation rows the table has reported as selected (T67's second
	// argument): they are drawn on this page too, so DataViews' answer is
	// the word on them as it is on the page's products.
	const variations = useRef( new Set() );
	const onChangeSelection = useCallback(
		( next, split ) => {
			setNotice( '' );
			setError( null );
			( split?.selectedVariationIds || [] ).forEach( ( id ) =>
				variations.current.add( String( id ) )
			);
			const drawn = [ ...pageIds, ...variations.current ];
			if ( matching ) {
				// Unticking a row of an all-matching selection narrows it to
				// what is ticked on this page.
				sequence.current++;
				setMatching( null );
				setIds( next.filter( ( id ) => drawn.includes( id ) ) );
				return;
			}
			setIds( ( previous ) =>
				mergePageSelection( previous, next, drawn )
			);
		},
		[ matching, pageIds ]
	);

	const selectAllMatching = useCallback( () => {
		const ticket = ++sequence.current;
		setError( null );
		setNotice( '' );
		setMatching( { status: 'loading' } );
		request( 'selection', { method: 'POST', data: JSON.parse( key ) } )
			.then( ( answer ) => {
				if ( ticket !== sequence.current ) {
					return;
				}
				if ( ! answer?.token ) {
					throw new Error();
				}
				setMatching( {
					status: 'ready',
					token: String( answer.token ),
					count: Number( answer.count ) || 0,
				} );
			} )
			.catch( ( failure ) => {
				if ( ticket === sequence.current ) {
					setMatching( null );
					setError( failure || new Error() );
				}
			} );
	}, [ key ] );

	const selectPage = useCallback( () => {
		sequence.current++;
		setMatching( null );
		setError( null );
		setIds( pageIds );
	}, [ pageIds ] );

	const clear = useCallback( () => {
		sequence.current++;
		setIds( NONE );
		setMatching( null );
		setError( null );
		setNotice( '' );
	}, [] );

	const dismissNotice = useCallback( () => setNotice( '' ), [] );

	const ready = matching?.status === 'ready';
	return {
		ids,
		matching,
		count: ready ? matching.count : ids.length,
		error,
		notice,
		tableSelection: matching ? pageIds : ids,
		pageSelected: ! matching && isPageSelected( ids, pageIds ),
		onChangeSelection,
		selectAllMatching,
		selectPage,
		clear,
		dismissNotice,
	};
}
