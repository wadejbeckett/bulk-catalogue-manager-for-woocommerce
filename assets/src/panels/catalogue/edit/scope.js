/**
 * The editing scope around the table (T45–T47): the rows as last written
 * (a PATCH answer replaces its row until the page is read again), the rows
 * with a write in flight, the notice for a write that failed outside a cell,
 * and the detail panel with the control that opened it.
 *
 * The catalogue layout owns this scope across table/cards changes; standalone
 * tables/cards own one themselves. Cells reach it through EditContext.
 */
import {
	createContext,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';

import { __ } from '@wordpress/i18n';
import { request } from '../../../api';
import { isUncertain } from '../../../requests';
import { SaveNotice } from './reconciliation';
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
 * @param {Function}      props.onCommitted        Reconcile the current query after a write.
 * @return {Object} { items, actions, pending, wrap }.
 */
export function useEditScope( {
	items,
	fields,
	actions,
	pending,
	onExpandVariations,
	onCommitted,
} ) {
	const [ written, setWritten ] = useState( () => new Map() );
	const [ busy, setBusy ] = useState( () => new Set() );
	const [ failure, setFailure ] = useState( null );
	const [ drawer, setDrawer ] = useState( null );
	const [ outcomes, setOutcomes ] = useState( () => new Map() );
	const inflight = useRef( new Map() );
	const uncertain = useRef( new Map() );
	const recovery = useRef( new Set() );
	const generations = useRef( new Map() );
	const committed = useRef( onCommitted );
	committed.current = onCommitted;
	const wrapper = useRef( null );
	const lostFocus = useRef( null );
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

	// A read recovery for an older save cannot replace a newer outcome.
	const record = useCallback( ( id, outcome ) => {
		setOutcomes( ( current ) => {
			if (
				outcome &&
				outcome.generation !== generations.current.get( id )
			) {
				return current;
			}
			const next = new Map( current );
			if ( outcome ) {
				next.set( id, outcome );
			} else {
				next.delete( id );
			}
			return next;
		} );
	}, [] );

	const refresh = useCallback( async ( answer ) => {
		try {
			await committed.current?.( answer );
			return { state: 'complete' };
		} catch {
			return {
				state: 'failed',
				message: __(
					'The results could not be refreshed.',
					'bulk-catalogue-manager-for-woocommerce'
				),
			};
		}
	}, [] );

	/**
	 * Write once, then reconcile separately. Partial answers retain their
	 * refusal; an unconfirmed transport answer keeps the object locked.
	 *
	 * @param {Object}        item         Row.
	 * @param {Object}        values       Field key => value.
	 * @param {Array<string>} extra        Further fields the answer should carry.
	 * @param {Object}        options      Options.
	 * @param {boolean}       options.keep Legacy intent only; current controls never send it.
	 * @return {Promise<Object>} Mutation answer with a separate refresh outcome.
	 */
	const commit = useCallback(
		( item, values, extra = [], options = {} ) => {
			const id = item.id;
			const held = uncertain.current.get( id );
			if ( held ) {
				return Promise.reject( held.error );
			}
			const intent = JSON.stringify( [
				Object.keys( values )
					.sort()
					.map( ( key ) => [ key, values[ key ] ] ),
				!! options.keep,
			] );
			const existing = inflight.current.get( id );
			if ( existing ) {
				return existing.intent === intent
					? existing.promise
					: Promise.reject( {
							code: 'edit_in_flight',
							message: __(
								'This product is already saving. Wait for its outcome before saving another edit.',
								'bulk-catalogue-manager-for-woocommerce'
							),
							data: { status: 409 },
						} );
			}
			const generation = ( generations.current.get( id ) || 0 ) + 1;
			generations.current.set( id, generation );
			setFailure( null );
			const submitted = JSON.parse( JSON.stringify( values ) );
			const doc = wrapper.current?.ownerDocument;
			const focused = doc?.activeElement;
			if ( focused && wrapper.current?.contains( focused ) ) {
				lostFocus.current = focused;
			}
			mark( id, true );
			record( id, null );
			// Store the promise synchronously before another event can dispatch.
			const promise = patchProduct(
				id,
				submitted,
				rowFields( item, descriptors, extra ),
				options
			)
				.then(
					async ( answer ) => {
						replace( answer?.item );
						const readback = await refresh( answer );
						const result = { ...answer, refresh: readback };
						record( id, {
							state: 'saved',
							generation,
							item,
							values: submitted,
							answer: result,
							refresh: readback,
						} );
						return result;
					},
					async ( error ) => {
						if ( error?.data?.answer ) {
							const answer = error.data.answer;
							replace( answer.item );
							answer.refresh = await refresh( answer );
							record( id, {
								state: answer.status,
								generation,
								item,
								values: submitted,
								answer,
								refresh: answer.refresh,
							} );
						} else if ( isUncertain( error ) ) {
							const outcome = {
								state: 'uncertain',
								generation,
								item,
								values: submitted,
								error,
							};
							uncertain.current.set( id, outcome );
							record( id, outcome );
						}
						throw error;
					}
				)
				.finally( () => {
					inflight.current.delete( id );
					if ( ! uncertain.current.has( id ) ) {
						mark( id, false );
					}
				} );
			inflight.current.set( id, { intent, promise } );
			return promise;
		},
		[ descriptors, mark, replace, record, refresh ]
	);

	const recover = async ( outcome ) => {
		const id = outcome.item.id;
		if ( recovery.current.has( id ) ) {
			return;
		}
		recovery.current.add( id );
		record( id, { ...outcome, reading: true, readFailed: false } );
		let current;
		try {
			if ( outcome.state === 'uncertain' ) {
				const answer = await request( `products/${ Number( id ) }`, {
					query: {
						fields: rowFields(
							outcome.item,
							descriptors,
							Object.keys( outcome.values )
						),
					},
				} );
				if ( Number( answer?.item?.id ) !== Number( id ) ) {
					throw new Error();
				}
				current = answer.item;
				replace( current );
			}
			const readback = await refresh( outcome.answer );
			record( id, {
				...outcome,
				current,
				refresh: readback,
				readFailed: readback.state === 'failed',
			} );
		} catch {
			record( id, { ...outcome, readFailed: true } );
		} finally {
			recovery.current.delete( id );
		}
	};

	// A removed row must not strand keyboard focus. Never steal focus after
	// the user moves elsewhere, or from a drawer that is still open.
	useEffect( () => {
		const element = lostFocus.current;
		const doc = wrapper.current?.ownerDocument;
		if ( element && ! element.isConnected ) {
			lostFocus.current = null;
			if ( doc?.activeElement === doc.body && ! drawer ) {
				wrapper.current
					?.querySelector( 'input[type="search"]' )
					?.focus();
			}
		}
	} );

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
		const target =
			buttons?.[ buttons.length - 1 ] ||
			wrapper.current?.querySelector( 'input[type="search"]' );
		target?.focus();
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

	const notices = ( id ) =>
		[ ...outcomes.values() ]
			.filter( ( outcome ) =>
				id === undefined
					? outcome.item.id !== drawer?.id
					: outcome.item.id === id
			)
			.map( ( outcome ) => (
				<SaveNotice
					key={ outcome.item.id }
					outcome={ outcome }
					descriptors={ descriptors }
					onRefresh={ () => recover( outcome ) }
					onRemove={ () => record( outcome.item.id, null ) }
				/>
			) );
	const wrap = ( children ) => (
		<EditContext.Provider value={ context }>
			<div ref={ wrapper }>
				{ notices() }
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
						key={ drawerItem.id }
						blocked={ uncertain.current.has( drawerItem.id ) }
						notice={ notices( drawerItem.id ) }
						descriptors={ descriptors }
						commit={ commit }
						onClose={ closeDrawer }
						onExpandVariations={ onExpandVariations }
					/>
				) }
			</div>
		</EditContext.Provider>
	);

	return { items: rows, actions: withDrawer, pending: allPending, wrap };
}
