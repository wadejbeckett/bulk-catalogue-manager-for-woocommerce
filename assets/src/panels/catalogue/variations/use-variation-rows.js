/**
 * The row tree's hook: turns the page's items into the rows DataViews draws
 * (variable rows with their open variations and footer rows), fetches a
 * product's variations in one request when it is opened, and supplies the
 * mode switch, Open all with variations and the count line around the
 * table. CatalogueTable spreads `props` onto DataViews and renders `before`
 * and `after` around it.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useReducer,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { request } from '../../../api';
import { announce } from '../../categories/announce';
import { toQuery } from '../query';
import { TreeCell } from './cells';
import {
	INITIAL,
	KINDS,
	isFooter,
	metaOf,
	buildRows,
	reducer,
	shownOf,
	sourceOf,
	toFetch,
} from './model';
import { CAP, MODES, countOf, variationRoles } from './registry';
import { CountLine, FocusNotice, ModeBar } from './toolbar';

/**
 * The view's grid mode.
 *
 * @param {Object} view DataViews view.
 * @return {string} MODES value.
 */
export function modeOf( view ) {
	return Object.values( MODES ).includes( view?.mode )
		? view.mode
		: MODES.products;
}

/**
 * The selection split into products and variations: the addition to the
 * selection contract (see README.md).
 *
 * @param {Array<string>} ids        Selected row ids.
 * @param {Set<string>}   variations Ids known to be variations.
 * @return {{selectedProductIds: Array<number>, selectedVariationIds: Array<number>}} Split.
 */
export function splitSelection( ids, variations ) {
	const selectedProductIds = [];
	const selectedVariationIds = [];
	ids.forEach( ( id ) =>
		( variations.has( String( id ) )
			? selectedVariationIds
			: selectedProductIds
		).push( Number( id ) )
	);
	return { selectedProductIds, selectedVariationIds };
}

const nameOf = ( row, key ) => String( row?.[ key ] ?? '' );

/**
 * The hook.
 *
 * @param {Object}        props                   CatalogueTable's props.
 * @param {Array<Object>} props.items             Page rows.
 * @param {Array<Object>} props.fields            DataViews fields (each with its `descriptor`).
 * @param {Object}        props.view              View.
 * @param {Function}      props.onChangeView      View change.
 * @param {Array<Object>} props.actions           Row actions.
 * @param {Object}        props.pagination        { totalItems, totalPages }.
 * @param {Array<string>} props.selection         Selected ids.
 * @param {Function}      props.onChangeSelection Selection change.
 * @return {{props: Object, before: Element, after: Element}} What the table draws.
 */
export function useVariationRows( {
	items,
	fields,
	view,
	onChangeView,
	actions,
	pagination,
	selection,
	onChangeSelection,
} ) {
	const descriptors = useMemo(
		() => fields.map( ( field ) => field.descriptor ).filter( Boolean ),
		[ fields ]
	);
	const roles = useMemo(
		() => variationRoles( descriptors ),
		[ descriptors ]
	);
	const mode = modeOf( view );
	const titleKey = view.titleField;
	const [ state, dispatch ] = useReducer( reducer, INITIAL );
	// Show all: the view's `parent` narrows the Variations list to one
	// product's options (GET /products?mode=variations&parent=ID, through
	// withVariations()), so the grid's own read is the only one. `named`
	// keeps the product's name and count for the notice.
	const [ named, setNamed ] = useState( null );
	const parentId = mode === MODES.variations ? Number( view.parent ) || 0 : 0;
	const activeFocus = parentId
		? {
				id: parentId,
				name: named?.id === parentId ? named.name : '',
				total: named?.id === parentId ? named.total : 0,
			}
		: null;
	const fieldsKey = JSON.stringify(
		toQuery( view, descriptors ).fields || []
	);
	const live = useRef( true );
	const inflight = useRef( new Set() );
	const generation = useRef( 0 );
	const source = useRef( { items, fieldsKey, mode } );
	// A reconciled parent page invalidates every child read synchronously.
	if (
		source.current.items !== items ||
		source.current.fieldsKey !== fieldsKey ||
		source.current.mode !== mode
	) {
		generation.current++;
		inflight.current.clear();
		source.current = { items, fieldsKey, mode };
	}
	const speakFor = useRef( new Set() );
	const known = useRef( new Set() );

	useEffect( () => {
		live.current = true;
		return () => {
			live.current = false;
		};
	}, [] );

	// The page or requested fields changed: fetched variations may be stale,
	// and a product no longer on the page is no longer open.
	useEffect( () => {
		dispatch( {
			type: 'refresh',
			ids: items.map( ( row ) => Number( row.id ) ),
		} );
	}, [ items, fieldsKey ] );

	// A mode change closes everything (expansion is never carried over).
	const firstMode = useRef( mode );
	useEffect( () => {
		if ( firstMode.current !== mode ) {
			firstMode.current = mode;
			dispatch( { type: 'reset' } );
		}
	}, [ mode ] );

	// One request per opened product, never repeated while it is known.
	useEffect( () => {
		toFetch( state ).forEach( ( id ) => {
			if ( inflight.current.has( id ) ) {
				return;
			}
			inflight.current.add( id );
			dispatch( { type: 'request', id } );
			const ticket = generation.current;
			request( `products/${ id }/variations`, {
				query: {
					page: 1,
					per_page: CAP,
					fields: JSON.parse( fieldsKey ),
				},
			} )
				.then( ( answer ) => {
					if ( ! live.current || ticket !== generation.current ) {
						return;
					}
					inflight.current.delete( id );
					const rows = Array.isArray( answer?.items )
						? answer.items
						: [];
					const total =
						Number( answer?.pagination?.total ) || rows.length;
					dispatch( { type: 'receive', id, items: rows, total } );
					if ( speakFor.current.delete( id ) ) {
						announce(
							total
								? sprintf(
										/* translators: 1: how many options are shown, 2: how many there are. */
										_n(
											'%1$d of %2$d option shown.',
											'%1$d of %2$d options shown.',
											total,
											'bulk-catalogue-manager-for-woocommerce'
										),
										Math.min( total, CAP ),
										total
									)
								: __(
										'This product has no variations yet.',
										'bulk-catalogue-manager-for-woocommerce'
									)
						);
					}
				} )
				.catch( ( error ) => {
					if ( ticket !== generation.current ) {
						return;
					}
					inflight.current.delete( id );
					if ( live.current ) {
						dispatch( { type: 'fail', id, error } );
						announce(
							__(
								'The options could not be loaded.',
								'bulk-catalogue-manager-for-woocommerce'
							),
							'assertive'
						);
					}
				} );
		} );
	}, [ state, fieldsKey ] );

	const base = items;
	const rows = useMemo(
		() => buildRows( base, state, roles, mode ),
		[ base, state, roles, mode ]
	);
	rows.forEach( ( row ) => {
		if ( metaOf( row ).kind === KINDS.variation ) {
			known.current.add( String( row.id ) );
		}
	} );

	const change = useCallback(
		( ids ) => {
			if ( ! onChangeSelection ) {
				return;
			}
			const clean = ids.filter(
				( id ) =>
					! rows.some( ( row ) => isFooter( row ) && row.id === id )
			);
			onChangeSelection( clean, splitSelection( clean, known.current ) );
		},
		[ onChangeSelection, rows ]
	);

	const setMode = useCallback(
		( next ) => {
			if ( next !== mode ) {
				const { parent, ...rest } = view;
				onChangeView( { ...rest, mode: next, page: 1 } );
			}
		},
		[ mode, onChangeView, view ]
	);
	const clearFocus = () => {
		const { parent, ...rest } = view;
		onChangeView( { ...rest, page: 1 } );
	};

	const parents = rows.filter(
		( row ) => metaOf( row ).kind === KINDS.parent
	);
	const anyClosed = parents.some( ( row ) => ! metaOf( row ).open );

	const ctx = {
		roles,
		titleKey,
		descriptors,
		firstDerived: descriptors.find(
			( field ) => field.variableParentNote && field.type === 'money'
		)?.key,
		toggle: ( row ) => {
			const id = Number( row.id );
			if ( state.open.includes( id ) ) {
				dispatch( { type: 'close', id } );
				announce(
					sprintf(
						/* translators: %s: product name. */
						__(
							'Options of %s hidden.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						nameOf( row, titleKey )
					)
				);
				return;
			}
			if ( state.entries[ id ]?.status === 'loaded' ) {
				const total = state.entries[ id ].total;
				announce(
					sprintf(
						/* translators: 1: how many options are shown, 2: how many there are. */
						_n(
							'%1$d of %2$d option shown.',
							'%1$d of %2$d options shown.',
							total,
							'bulk-catalogue-manager-for-woocommerce'
						),
						Math.min( total, CAP ),
						total
					)
				);
			} else {
				speakFor.current.add( id );
			}
			dispatch( { type: 'open', id } );
		},
		close: ( row ) => dispatch( { type: 'close', id: Number( row.id ) } ),
		retry: ( row ) => dispatch( { type: 'retry', id: Number( row.id ) } ),
		selectAll: ( row ) => {
			const ids = shownOf( state.entries[ Number( row.id ) ] ).map(
				( child ) => String( child.id )
			);
			ids.forEach( ( id ) => known.current.add( id ) );
			change( [ ...new Set( [ ...( selection || [] ), ...ids ] ) ] );
		},
		showAll: ( row, total ) => {
			setNamed( {
				id: Number( row.id ),
				name: nameOf( row, titleKey ),
				total,
			} );
			onChangeView( {
				...view,
				mode: MODES.variations,
				parent: Number( row.id ),
				page: 1,
			} );
		},
	};
	const ctxRef = useRef( ctx );
	ctxRef.current = ctx;

	const wrapped = useMemo(
		() =>
			fields.map( ( field ) =>
				field.descriptor
					? {
							...field,
							render: ( { item } ) => (
								<TreeCell
									item={ item }
									descriptor={ field.descriptor }
									inner={ ( props ) =>
										field.render( { ...props, field } )
									}
									ctx={ ctxRef.current }
								/>
							),
						}
					: field
			),
		[ fields ]
	);

	const treeActions = useMemo(
		() =>
			( actions || [] ).map( ( action ) => ( {
				...action,
				...( action.callback
					? {
							callback: ( chosen, ...rest ) =>
								action.callback(
									chosen.map( sourceOf ),
									...rest
								),
						}
					: {} ),
				isEligible: ( item ) =>
					! isFooter( item ) &&
					( ! action.isEligible || action.isEligible( item ) ),
			} ) ),
		[ actions ]
	);

	const openAll = () => {
		if ( anyClosed ) {
			const ids = parents.map( ( row ) => Number( row.id ) );
			dispatch( { type: 'open_all', ids } );
			announce(
				sprintf(
					/* translators: %d: how many products were opened. */
					_n(
						'Opened %d product with options.',
						'Opened %d products with options.',
						ids.length,
						'bulk-catalogue-manager-for-woocommerce'
					),
					ids.length
				)
			);
		} else {
			dispatch( { type: 'close_all' } );
		}
	};

	/**
	 * Show a product's options in the grid (the detail panel's link, T47):
	 * open it in place when it is a row on this page in a product mode,
	 * otherwise list its options in Variations mode as Show all does.
	 *
	 * @param {Object} product Product row.
	 */
	const expand = ( product ) => {
		const id = Number( product?.id );
		if ( ! id ) {
			return;
		}
		const onPage = parents.some( ( row ) => Number( row.id ) === id );
		if ( mode !== MODES.variations && onPage ) {
			if ( ! state.open.includes( id ) ) {
				ctx.toggle( product );
			}
			return;
		}
		ctx.showAll( product, countOf( product, roles ) || 0 );
	};

	const paginationInfo = pagination;
	return {
		expand,
		props: {
			data: rows,
			fields: wrapped,
			actions: treeActions,
			paginationInfo,
			...( onChangeSelection
				? { selection, onChangeSelection: change }
				: {} ),
		},
		before: (
			<>
				<ModeBar
					mode={ mode }
					onChangeMode={ setMode }
					canOpenAll={
						mode !== MODES.variations && parents.length > 0
					}
					anyClosed={ anyClosed }
					onOpenAll={ openAll }
				/>
				{ activeFocus && (
					<FocusNotice
						focus={ activeFocus }
						failed={ false }
						onClear={ clearFocus }
					/>
				) }
			</>
		),
		after: (
			<CountLine
				mode={ mode }
				total={ paginationInfo?.totalItems || 0 }
			/>
		),
	};
}
