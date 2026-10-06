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
import { ROW } from '../defaults';
import { createInlineReceipts } from './receipts';
import { SaveNotice } from './reconciliation';
import ErrorNotice from '../../history/error-notice';
import DetailDrawer from './drawer';
import { readProduct } from './requests';

/** The row action that opens the detail panel (actions.js). */
const EDIT_DETAILS = 'edit-details';

export const EditContext = createContext( null );

const NONE = new Set();

/**
 * How long, after its save settles, an edited control keeps its claim on
 * keyboard focus while its row is drawn again (milliseconds).
 */
const HOLD_FOCUS = 5000;

/**
 * The control a focus home names: a cell's trigger (`key`), or the row's
 * action menu button (no `key`, the detail panel's return).
 *
 * @param {Element} root   The scope's wrapper.
 * @param {Object}  target { id, key }.
 * @return {Element|null} The control, when it is drawn.
 */
function findHome( root, target ) {
	const cells = [
		...( root?.querySelectorAll( '[data-bulkcat-row]' ) || [] ),
	];
	if ( target.key ) {
		const cell = cells.find(
			( element ) =>
				element.dataset.bulkcatRow === String( target.id ) &&
				element.dataset.bulkcatField === target.key
		);
		return cell?.querySelector( '.bulkcat-inline__trigger' ) || null;
	}
	const cell = cells.find(
		( element ) => element.dataset.bulkcatRow === String( target.id )
	);
	// The row's action menu button is its last menu button.
	const buttons = cell
		?.closest( 'tr' )
		?.querySelectorAll( 'button[aria-haspopup]' );
	return buttons?.[ buttons.length - 1 ] || null;
}

/**
 * Whether focus is still on the control a home names (its editor or its
 * trigger, before or after a redraw).
 *
 * @param {Element} root    The scope's wrapper.
 * @param {Element} element The focused element.
 * @param {Object}  target  { id, key }.
 * @return {boolean} Still there.
 */
function atHome( root, element, target ) {
	if ( ! target.key ) {
		return element === findHome( root, target );
	}
	const cell = element.closest?.( '[data-bulkcat-row]' );
	return (
		!! cell &&
		cell.dataset.bulkcatRow === String( target.id ) &&
		cell.dataset.bulkcatField === target.key
	);
}

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
 * After every draw of the scope's contents (a row drawn again included),
 * give focus left on nothing back to the control that holds it.
 *
 * @param {Object}   props        Props.
 * @param {Function} props.settle The scope's settleFocus().
 * @return {null} Nothing.
 */
function SettleFocus( { settle } ) {
	useEffect( () => {
		settle();
	} );
	return null;
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
 * @param {Object}        props.settings           Localised site/user storage scope.
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
	settings,
} ) {
	const root = settings?.root || '';
	const userId = Number( settings?.user?.id ) || 0;
	const receipts = useMemo(
		() => createInlineReceipts( { root, user: { id: userId } } ),
		[ root, userId ]
	);
	const [ written, setWritten ] = useState( () => new Map() );
	const [ busy, setBusy ] = useState( () => new Set( receipts.heldIds() ) );
	const [ failure, setFailure ] = useState( null );
	const [ drawer, setDrawer ] = useState( null );
	const [ outcomes, setOutcomes ] = useState( () => new Map() );
	const inflight = useRef( new Map() );
	const recovery = useRef( new Set() );
	const generations = useRef( new Map() );
	const committed = useRef( onCommitted );
	committed.current = onCommitted;
	const wrapper = useRef( null );
	const lostFocus = useRef( null );
	// The control that takes keyboard focus back when its row is drawn again
	// after a save (T75, 1.4.3): { id, key, parent, until }.
	const home = useRef( null );
	const homeTimer = useRef( null );
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

	// Adopt immutable evidence first; a current row is an independent strict read.
	const adopt = useCallback(
		async ( receipt, outcome ) => {
			const id = outcome.item.id;
			const currentGeneration = () =>
				generations.current.get( id ) === outcome.generation;
			if ( ! [ 'closed', 'refused' ].includes( receipt.receipt_state ) ) {
				const unconfirmed = {
					...outcome,
					state: 'uncertain',
					answer: receipt,
					readFailed: receipt.readFailed,
					reading: false,
					held: true,
				};
				record( id, unconfirmed );
				return unconfirmed;
			}
			let item = receipt.recovered ? undefined : receipt.item;
			let rowFailed = false;
			if ( receipt.recovered && receipt.receipt_state === 'closed' ) {
				try {
					item = await readProduct(
						id,
						rowFields(
							outcome.item,
							descriptors,
							Object.keys( outcome.values || {} )
						)
					);
				} catch {
					rowFailed = true;
				}
			}
			if ( currentGeneration() && item ) {
				replace( item );
			}
			const answer = {
				...receipt,
				status: receipt.write_status,
				...( item ? { item } : {} ),
			};
			if ( receipt.recovered ) {
				delete answer.item;
				delete answer.results;
				if ( item ) {
					answer.item = item;
				}
			}
			const readback =
				receipt.receipt_state === 'closed'
					? await refresh( answer )
					: { state: 'complete' };
			if ( rowFailed ) {
				readback.state = 'failed';
			}
			answer.refresh = readback;
			const state =
				receipt.write_status === 'complete'
					? 'saved'
					: receipt.write_status;
			const result = {
				...outcome,
				state: receipt.receipt_state === 'refused' ? 'refused' : state,
				answer,
				refresh: readback,
				reading: false,
				rowFailed,
				readFailed: readback.state === 'failed',
				held: receipt.held,
				clearFailed: receipt.clearFailed,
			};
			record( id, result );
			if ( currentGeneration() ) {
				mark( id, receipts.isHeld( id ) );
			}
			return result;
		},
		[ descriptors, mark, receipts, record, refresh, replace ]
	);

	// The constructor has already locked restored objects. Mount makes one
	// read-only pass; StrictMode effect replay shares that same pass.
	const latest = useRef();
	latest.current = { items, adopt };
	useEffect( () => {
		let live = true;
		const ids = receipts.heldIds();
		ids.forEach( ( id ) => {
			generations.current.set(
				id,
				( generations.current.get( id ) || 0 ) + 1
			);
			mark( id, true );
		} );
		const versions = new Map( generations.current );
		receipts
			.restore()
			.then( async ( answers ) => {
				for ( const answer of answers ) {
					if ( ! live ) {
						return;
					}
					const id = answer.object_id;
					await latest.current.adopt( answer, {
						item: latest.current.items?.find(
							( row ) => row.id === id
						) || { id },
						generation: versions.get( id ),
					} );
				}
			} )
			.catch( ( error ) => {
				if ( live ) {
					setFailure( { error, message: error.message } );
				}
			} );
		return () => {
			live = false;
		};
	}, [ receipts, mark ] );

	/**
	 * One Save: issue, strictly keep identity, PATCH once, adopt, reconcile.
	 * The public answer/error contracts remain usable by cells and drawers.
	 *
	 * @param {Object} item    Current row.
	 * @param {Object} values  Typed field values.
	 * @param {Array}  extra   Further read fields.
	 * @param {Object} options Legacy keep flag.
	 * @return {Promise<Object>} Answer with a separate refresh outcome.
	 */
	const commit = useCallback(
		( item, values, extra = [], options = {} ) => {
			const id = item.id;
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
			if ( receipts.isHeld( id ) ) {
				return Promise.reject( { code: 'invalid_json' } );
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
			const outcome = { generation, item, values: submitted };
			const promise = receipts
				.save(
					id,
					submitted,
					rowFields( item, descriptors, extra ),
					options
				)
				.then(
					async ( receipt ) => {
						const result = await adopt( receipt, outcome );
						if ( result.state === 'uncertain' ) {
							throw { code: 'invalid_json', data: { receipt } };
						}
						if ( result.state === 'refused' && receipt.refusal ) {
							throw receipt.refusal;
						}
						if ( result.state !== 'saved' ) {
							const refusal =
								receipt.refusal ||
								result.answer.results?.find( ( step ) =>
									[ 'skipped', 'failed' ].includes(
										step.state
									)
								);
							throw {
								code: refusal?.code || result.state,
								message:
									refusal?.message || refusal?.reason || '',
								data: {
									...refusal?.data,
									field:
										refusal?.field || refusal?.data?.field,
									answer: result.answer,
								},
							};
						}
						return result.answer;
					},
					( error ) => {
						if ( receipts.isHeld( id ) ) {
							record( id, {
								...outcome,
								state: 'uncertain',
								held: true,
								readFailed: true,
							} );
						}
						throw error;
					}
				)
				.finally( () => {
					inflight.current.delete( id );
					// The row is drawn again after this: the window starts now.
					if ( home.current?.id === id ) {
						home.current.until = Date.now() + HOLD_FOCUS;
					}
					mark( id, receipts.isHeld( id ) );
				} );
			inflight.current.set( id, { intent, promise } );
			return promise;
		},
		[ adopt, descriptors, mark, receipts, record ]
	);

	const recover = async ( outcome ) => {
		const id = outcome.item.id;
		if ( recovery.current.has( id ) ) {
			return;
		}
		recovery.current.add( id );
		record( id, { ...outcome, reading: true, readFailed: false } );
		try {
			if ( receipts.isHeld( id ) ) {
				await adopt( await receipts.resolve( id ), outcome );
			} else {
				// Retry a failed current read without reinterpreting the closure.
				await adopt(
					{
						...outcome.answer,
						recovered: true,
					},
					outcome
				);
			}
		} catch {
			record( id, { ...outcome, reading: false, readFailed: true } );
		} finally {
			recovery.current.delete( id );
		}
	};

	const latestRows = useRef( rows );
	latestRows.current = rows;
	const drawerOpen = useRef( false );
	drawerOpen.current = drawer !== null;
	const settleRef = useRef( null );

	/**
	 * Keep focus with a control while its row is drawn again: `target` is
	 * { id, key, parent } (a cell's trigger, or with no key the row's action
	 * menu button), or null to let go.
	 */
	const holdFocus = useCallback( ( target ) => {
		home.current = target
			? { ...target, until: Date.now() + HOLD_FOCUS }
			: null;
	}, [] );

	/**
	 * A removed row must not strand keyboard focus. Focus left on nothing goes
	 * back to the held control as soon as its row is drawn again; the results
	 * search takes it only when that row is truly gone: its save has settled
	 * and neither it nor its product is on the page, or the wait ran out.
	 * Never steal focus after the user moves elsewhere, or from a drawer that
	 * is still open.
	 */
	const settleFocus = useCallback( () => {
		const box = wrapper.current;
		const doc = box?.ownerDocument;
		// A closed detail panel returns focus to its opener first.
		if ( ! doc || returnFocus.current ) {
			return;
		}
		clearTimeout( homeTimer.current );
		const active = doc.activeElement;
		const lost = ! active || active === doc.body;
		const search = () =>
			box.querySelector( 'input[type="search"]' )?.focus();
		let target = home.current;
		const saving = !! target && inflight.current.has( target.id );
		if ( target && ! saving && Date.now() > target.until ) {
			// The wait ran out: the row was not drawn again.
			home.current = null;
			if ( lost && ! drawerOpen.current && ! findHome( box, target ) ) {
				lostFocus.current = null;
				search();
				return;
			}
			target = null;
		}
		if ( target ) {
			if ( ! lost ) {
				if ( ! atHome( box, active, target ) ) {
					home.current = null;
				}
				return;
			}
			if ( drawerOpen.current ) {
				return;
			}
			const element = findHome( box, target );
			if ( element ) {
				element.focus();
				lostFocus.current = null;
				return;
			}
			const listed = latestRows.current.some(
				( row ) =>
					row.id === target.id ||
					( !! target.parent && row.id === target.parent )
			);
			if ( saving || listed ) {
				if ( ! saving ) {
					homeTimer.current = setTimeout(
						() => settleRef.current(),
						target.until - Date.now() + 1
					);
				}
				return;
			}
			home.current = null;
			lostFocus.current = null;
			search();
			return;
		}
		const element = lostFocus.current;
		if ( element && ! element.isConnected ) {
			lostFocus.current = null;
			if ( lost && ! drawerOpen.current ) {
				search();
			}
		}
	}, [] );
	settleRef.current = settleFocus;
	useEffect( () => () => clearTimeout( homeTimer.current ), [] );

	// The user moving focus anywhere else lets the held control go at once,
	// before any redraw could take focus back to it.
	useEffect( () => {
		const doc = typeof document !== 'undefined' ? document : null;
		if ( ! doc ) {
			return;
		}
		const moved = ( event ) => {
			if (
				home.current &&
				! atHome( wrapper.current, event.target, home.current )
			) {
				home.current = null;
			}
		};
		doc.addEventListener( 'focusin', moved, true );
		return () => doc.removeEventListener( 'focusin', moved, true );
	}, [] );

	useEffect( () => {
		settleFocus();
	} );

	const openDrawer = useCallback( ( item ) => {
		const doc = typeof document !== 'undefined' ? document : null;
		opener.current = {
			element: doc?.activeElement || null,
			id: item.id,
			parent: Number( item[ ROW.parentId ] ) || 0,
		};
		setDrawer( item );
	}, [] );

	const closeDrawer = useCallback( () => {
		returnFocus.current = opener.current;
		setDrawer( null );
	}, [] );

	// Focus back to whatever opened the panel (§14): the control itself, or
	// the row's action menu button when the menu item has gone — once the row
	// is drawn again if a save is redrawing it, else the results search.
	useEffect( () => {
		if ( drawer !== null || ! returnFocus.current ) {
			return;
		}
		const { element, id, parent } = returnFocus.current;
		returnFocus.current = null;
		lostFocus.current = null;
		if ( element && element.isConnected && element.focus ) {
			element.focus();
			return;
		}
		const target = findHome( wrapper.current, { id } );
		if ( target ) {
			target.focus();
			return;
		}
		// The row is drawn with no menu to return to (a card): the search.
		const drawn = [
			...( wrapper.current?.querySelectorAll( '[data-bulkcat-row]' ) ||
				[] ),
		].some( ( cell ) => cell.dataset.bulkcatRow === String( id ) );
		if ( drawn ) {
			wrapper.current?.querySelector( 'input[type="search"]' )?.focus();
			return;
		}
		holdFocus( { id, key: null, parent } );
		settleFocus();
	}, [ drawer, holdFocus, settleFocus ] );

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
			holdFocus,
			settleFocus,
		} ),
		[ descriptors, commit, replace, openDrawer, holdFocus, settleFocus ]
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
				<SettleFocus settle={ settleFocus } />
				{ drawerItem && (
					<DetailDrawer
						item={ drawerItem }
						key={ drawerItem.id }
						blocked={ receipts.isHeld( drawerItem.id ) }
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
