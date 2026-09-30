/**
 * The editing scope around the table (T45–T47): the rows as last written
 * (a PATCH answer replaces its row until the page is read again), the rows
 * with a write in flight, the notice for a write that failed outside a cell,
 * and the detail panel with the control that opened it.
 *
 * table.js calls useEditScope() and wraps the table in its `wrap()`; the
 * cells reach the scope through EditContext.
 */
import {
	createContext,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import ErrorNotice from '../../history/error-notice';
import DetailDrawer from './drawer';
import { patchProduct } from './requests';

/** The row action that opens the detail panel (actions.js). */
const EDIT_DETAILS = 'edit-details';

export const EditContext = createContext( null );

const NONE = new Set();

/**
 * The fields a row's replacement should carry: the ones it has now.
 *
 * @param {Object}        item        Row.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @param {Array<string>} extra       Further keys to ask for.
 * @return {Array<string>} Keys.
 */
export function rowFields( item, descriptors, extra = [] ) {
	const has = new Set( [ ...Object.keys( item || {} ), ...extra ] );
	return descriptors
		.map( ( field ) => field.key )
		.filter( ( key ) => has.has( key ) );
}

/**
 * The scope.
 *
 * @param {Object}        props                    Props.
 * @param {Array<Object>} props.items              The page's rows.
 * @param {Array<Object>} props.fields             DataViews fields (each carries its `descriptor`).
 * @param {Array<Object>} props.actions            Row actions.
 * @param {Set}           props.pending            Rows the catalogue already holds pending.
 * @param {Function}      props.onExpandVariations Show a product's variations in the grid (T67), if wired.
 * @return {Object} { items, actions, pending, wrap }.
 */
export function useEditScope( {
	items,
	fields,
	actions,
	pending,
	onExpandVariations,
} ) {
	const [ written, setWritten ] = useState( () => new Map() );
	const [ busy, setBusy ] = useState( () => new Set() );
	const [ failure, setFailure ] = useState( null );
	const [ drawer, setDrawer ] = useState( null );
	const opener = useRef( null );
	const returnFocus = useRef( null );

	const descriptors = useMemo(
		() =>
			( fields || [] )
				.map( ( field ) => field.descriptor )
				.filter( Boolean ),
		[ fields ]
	);

	// A fresh page is the truth again.
	useEffect( () => {
		setWritten( new Map() );
	}, [ items ] );

	const rows = useMemo(
		() =>
			written.size
				? ( items || [] ).map( ( item ) =>
						written.has( item.id )
							? { ...item, ...written.get( item.id ) }
							: item
					)
				: items || [],
		[ items, written ]
	);

	const allPending = useMemo(
		() =>
			busy.size
				? new Set( [ ...( pending || [] ), ...busy ] )
				: pending || NONE,
		[ pending, busy ]
	);

	const mark = useCallback( ( id, on ) => {
		setBusy( ( current ) => {
			const next = new Set( current );
			if ( on ) {
				next.add( id );
			} else {
				next.delete( id );
			}
			return next;
		} );
	}, [] );

	const replace = useCallback( ( item ) => {
		if ( ! item || item.id === undefined ) {
			return;
		}
		setWritten( ( current ) => {
			const next = new Map( current );
			next.set( item.id, {
				...( current.get( item.id ) || {} ),
				...item,
			} );
			return next;
		} );
	}, [] );

	/**
	 * Write values to a row: pending until the answer, whose item replaces
	 * the row. Rejects with the refusal, leaving the row as it was.
	 *
	 * @param {Object}        item         Row.
	 * @param {Object}        values       Field key => value.
	 * @param {Array<string>} extra        Further fields the answer should carry.
	 * @param {Object}        options      Options.
	 * @param {boolean}       options.keep Legacy intent only; current controls never send it.
	 * @return {Promise<Object>} The answer.
	 */
	const commit = useCallback(
		( item, values, extra = [], options = {} ) => {
			mark( item.id, true );
			return patchProduct(
				item.id,
				values,
				rowFields( item, descriptors, extra ),
				options
			)
				.then( ( answer ) => {
					replace( answer?.item );
					return answer;
				} )
				.finally( () => mark( item.id, false ) );
		},
		[ descriptors, mark, replace ]
	);

	const openDrawer = useCallback( ( item ) => {
		const doc = typeof document !== 'undefined' ? document : null;
		opener.current = {
			element: doc?.activeElement || null,
			id: item.id,
		};
		setDrawer( item );
	}, [] );

	const closeDrawer = useCallback( () => {
		returnFocus.current = opener.current;
		setDrawer( null );
	}, [] );

	// Focus back to whatever opened the panel (§14): the control itself, or
	// the row's action menu button when the menu item has gone.
	useEffect( () => {
		if ( drawer !== null || ! returnFocus.current ) {
			return;
		}
		const { element, id } = returnFocus.current;
		returnFocus.current = null;
		if ( element && element.isConnected && element.focus ) {
			element.focus();
			return;
		}
		const cell = document.querySelector(
			`[data-bulkcat-row="${ String( id ).replace( /[^\d]/g, '' ) }"]`
		);
		// The row's action menu button is its last menu button.
		const buttons = cell
			?.closest( 'tr' )
			?.querySelectorAll( 'button[aria-haspopup]' );
		buttons?.[ buttons.length - 1 ]?.focus();
	}, [ drawer ] );

	const withDrawer = useMemo(
		() =>
			( actions || [] ).map( ( action ) =>
				action.id === EDIT_DETAILS
					? {
							...action,
							callback: ( selected, ...rest ) => {
								if ( selected?.[ 0 ] ) {
									openDrawer( selected[ 0 ] );
								}
								return action.callback?.( selected, ...rest );
							},
						}
					: action
			),
		[ actions, openDrawer ]
	);

	const context = useMemo(
		() => ( {
			descriptors,
			commit,
			replace,
			notify: setFailure,
			openDrawer,
		} ),
		[ descriptors, commit, replace, openDrawer ]
	);

	const drawerItem =
		drawer === null
			? null
			: rows.find( ( item ) => item.id === drawer.id ) || drawer;

	const wrap = ( children ) => (
		<EditContext.Provider value={ context }>
			{ failure && (
				<div className="bulkcat-edit__notice">
					<ErrorNotice
						error={ failure.error }
						fallback={ failure.message }
						onRemove={ () => setFailure( null ) }
					/>
				</div>
			) }
			{ children }
			{ drawerItem && (
				<DetailDrawer
					item={ drawerItem }
					descriptors={ descriptors }
					commit={ commit }
					onClose={ closeDrawer }
					onExpandVariations={ onExpandVariations }
				/>
			) }
		</EditContext.Provider>
	);

	return { items: rows, actions: withDrawer, pending: allPending, wrap };
}
