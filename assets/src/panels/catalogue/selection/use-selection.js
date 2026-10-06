/**
 * The selection in the catalogue (T84, SPEC §4.1): the screen's selection
 * session (session.js) seen from the grid. The view — search, filters, sort,
 * page, mode, category — only decides which rows are on screen; their
 * checkboxes show actual membership, inspected on the server for those rows,
 * and ticking or unticking one adds or removes exactly that row. Nothing the
 * view does clears the selection.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import { readSettings } from '../../../settings';
import { cleanIds, getSelectionSession } from './session';
import { isPageSelected, panelSelection } from './model';

const NONE = [];

/**
 * Selection state for the grid.
 *
 * @param {Object}        options            Options.
 * @param {Object}        options.conditions selectionQuery() of the view, for Select all matching.
 * @param {Array<Object>} options.items      Rows on this page.
 * @param {Array<string>} options.drawn      Every row id drawn (the page plus opened variations).
 * @param {Object}        options.session    A session (tests); the screen's own by default.
 * @return {Object} The state plus `tableSelection`, `pageSelected`,
 *                  `offerAll`, `hasSelection`, `panel` and the actions.
 */
export default function useSelection( {
	conditions,
	items = NONE,
	drawn = NONE,
	session: given = null,
} ) {
	const session = useMemo(
		() => given || getSelectionSession( readSettings() ),
		[ given ]
	);
	const [ state, setState ] = useState( () => session.getState() );
	useEffect( () => {
		setState( session.getState() );
		return session.subscribe( setState );
	}, [ session ] );

	const pageIds = useMemo(
		() => items.map( ( item ) => String( item.id ) ),
		[ items ]
	);
	const onScreen = useMemo(
		() => [ ...new Set( [ ...pageIds, ...drawn.map( String ) ] ) ],
		[ pageIds, drawn ]
	);
	const onScreenKey = onScreen.join( ',' );

	// Which rows on screen are selected: asked again whenever the rows or
	// the selection's snapshot change. A failed read keeps the last answer.
	const current = state.current;
	const probeKey = `${ current?.key || '' }|${
		current?.token || ''
	}|${ onScreenKey }`;
	const rows = useRef( onScreen );
	rows.current = onScreen;
	useEffect( () => {
		session.probe( rows.current ).catch( () => {} );
	}, [ session, probeKey ] );

	const tableSelection = onScreen.filter( ( id ) =>
		session.isSelected( id )
	);

	const onChangeSelection = useCallback(
		( next ) => {
			const wanted = new Set( ( next || [] ).map( String ) );
			const shown = rows.current.filter( ( id ) =>
				session.isSelected( id )
			);
			const removed = shown.filter( ( id ) => ! wanted.has( id ) );
			const added = [ ...wanted ].filter(
				( id ) =>
					! session.isSelected( id ) && cleanIds( [ id ] ).length
			);
			if ( removed.length ) {
				session.remove( removed );
			}
			if ( added.length ) {
				session.add( added );
			}
		},
		[ session ]
	);

	const conditionsKey = JSON.stringify( conditions || {} );
	const selectAllMatching = useCallback(
		() => session.addMatching( JSON.parse( conditionsKey ) ),
		[ session, conditionsKey ]
	);

	const hasSelection = state.count > 0;
	return {
		...state,
		session,
		hasSelection,
		tableSelection,
		pageSelected: isPageSelected( tableSelection, pageIds ),
		offerAll: state.lastMatching !== conditionsKey,
		panel: panelSelection( state ),
		onChangeSelection,
		selectAllMatching,
		clear: useCallback( () => session.clear( 'selection' ), [ session ] ),
		clearWithSearch: useCallback(
			() => session.clear( 'search' ),
			[ session ]
		),
		restore: useCallback( () => session.restore(), [ session ] ),
		checkAgain: useCallback( () => session.checkAgain(), [ session ] ),
		retry: useCallback( () => session.retry(), [ session ] ),
		dismiss: useCallback(
			( what ) => session.dismiss( what ),
			[ session ]
		),
		remove: useCallback( ( ids ) => session.remove( ids ), [ session ] ),
		inspectPage: useCallback(
			( page, perPage ) => session.inspectPage( page, perPage ),
			[ session ]
		),
	};
}
