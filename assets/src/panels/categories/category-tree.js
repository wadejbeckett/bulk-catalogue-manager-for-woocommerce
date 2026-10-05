/**
 * The ARIA tree of product categories (SPEC §4.7, §7, §14; T61, T62, T64,
 * T65, T66): one flat list of treeitems carrying their level, set size and
 * position, a roving tab index, arrow keys, Home, End and type-ahead, a
 * per-node menu (Rename, Edit details…, Move to…), inline rename, and native
 * HTML5 drag and drop to re-parent or reorder a category or to drop the
 * current product selection onto one. Without manage_product_terms the menu,
 * the rename and the drag are absent.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { announce } from './announce';
import {
	dropZone,
	joinPath,
	lineage,
	movePayload,
	nodeCount,
	previewPath,
	searchMatches,
	valueIds,
	visibleRows,
} from './tree-model';

/** The drag data type a dragged category carries. */
export const CATEGORY_DRAG_TYPE = 'application/x-bulkcat-category';

/**
 * The drag data type the catalogue sets when the current product selection
 * is dragged; its value is free for the catalogue (the panel never reads it,
 * it calls onDropSelection( categoryId ) and the catalogue uses its own
 * selection and scope).
 */
export const SELECTION_DRAG_TYPE = 'application/x-bulkcat-selection';

/** The "All products" row's id. */
export const ALL = 0;

const TYPE_AHEAD_MS = 500;

const INDENT = 17;

/**
 * A row's indent and its guides: one 1px line per level, where the
 * prototype's nested lists draw their left border.
 *
 * @param {number} depth Depth from 0.
 * @return {Object} Inline style.
 */
function indent( depth ) {
	if ( ! depth ) {
		return undefined;
	}
	const levels = Array.from( { length: depth }, ( _, level ) => level );
	return {
		paddingInlineStart: depth * INDENT,
		backgroundImage: levels
			.map( () => 'linear-gradient(#dcdcde, #dcdcde)' )
			.join( ', ' ),
		backgroundSize: levels.map( () => '1px 100%' ).join( ', ' ),
		backgroundPosition: levels
			.map( ( level ) => `${ 13 + level * INDENT }px 0` )
			.join( ', ' ),
	};
}

/**
 * The drag types an event carries, as an array.
 *
 * @param {Event} event Drag event.
 * @return {string[]} Types.
 */
function dragTypes( event ) {
	return Array.from( event.dataTransfer?.types || [] );
}

/**
 * The tree.
 *
 * @param {Object}   props                 Props.
 * @param {Object}   props.tree            useCategoryTree() result.
 * @param {Object}   props.value           The filter value { id, ids, includeChildren } or null.
 * @param {boolean}  props.includeChildren Whether counts include sub-categories.
 * @param {string}   props.search          Search text.
 * @param {boolean}  props.canManage       Show the menu, rename and drag.
 * @param {number}   props.total           Optional count for the All products row.
 * @param {Function} props.onSelect        Called with ( id, multiple ); 0 is All products.
 * @param {Function} props.onRename        Called with ( id, name ); returns a promise.
 * @param {Function} props.onMove          Called with ( id, parent, position|undefined ); returns a promise.
 * @param {Function} props.onEdit          Called with ( id ) to open the details pane.
 * @param {Function} props.onMoveDialog    Called with ( id ) to open Move to….
 * @param {Function} props.onDropSelection Called with ( id ) when the product selection is dropped on a category.
 * @param {Function} props.moreActions     Called with ( id ); returns more menu items `{ key, label, run }` (Make default, Delete…).
 * @return {Element} The tree.
 */
export default function CategoryTree( {
	tree,
	value,
	includeChildren,
	search = '',
	canManage = false,
	total,
	onSelect,
	onRename,
	onMove,
	onEdit,
	onMoveDialog,
	onDropSelection,
	moreActions,
} ) {
	const { nodes, byId } = tree;
	const selected = valueIds( value );
	const [ expanded, setExpanded ] = useState( () => {
		const open = new Set();
		selected.forEach( ( id ) =>
			lineage( byId, id )
				.slice( 0, -1 )
				.forEach( ( each ) => open.add( each ) )
		);
		return open;
	} );
	const [ focused, setFocused ] = useState( selected[ 0 ] || ALL );
	const [ menu, setMenu ] = useState( null );
	const [ renaming, setRenaming ] = useState( null );
	const [ draft, setDraft ] = useState( '' );
	const [ drag, setDrag ] = useState( null );
	const dragged = useRef( null );
	const items = useRef( new Map() );
	const focusNext = useRef( false );
	const typed = useRef( { text: '', at: 0 } );

	const kept = useMemo(
		() => searchMatches( byId, search ),
		[ byId, search ]
	);
	const rows = useMemo( () => {
		const all = {
			id: ALL,
			node: {
				id: ALL,
				name: __(
					'All products',
					'bulk-catalogue-manager-for-woocommerce'
				),
			},
			depth: 0,
			parent: null,
			hasChildren: false,
			open: false,
			setSize: 1,
			posInSet: 1,
		};
		const list = visibleRows( nodes, expanded, kept );
		return kept ? list : [ all, ...list ];
	}, [ nodes, expanded, kept ] );

	const tabStop = rows.some( ( row ) => row.id === focused )
		? focused
		: rows[ 0 ]?.id;

	useEffect( () => {
		if ( focusNext.current ) {
			focusNext.current = false;
			items.current.get( tabStop )?.focus();
		}
	} );

	const moveFocus = ( id ) => {
		focusNext.current = true;
		setFocused( id );
	};

	const toggle = ( row, open ) => {
		if ( ! row.hasChildren || !! kept ) {
			return;
		}
		setExpanded( ( current ) => {
			const next = new Set( current );
			if ( open ) {
				next.add( row.id );
			} else {
				next.delete( row.id );
			}
			return next;
		} );
		announce(
			open
				? sprintf(
						/* translators: %s: a category name. */
						__(
							'%s expanded',
							'bulk-catalogue-manager-for-woocommerce'
						),
						row.node.name
					)
				: sprintf(
						/* translators: %s: a category name. */
						__(
							'%s collapsed',
							'bulk-catalogue-manager-for-woocommerce'
						),
						row.node.name
					)
		);
	};

	const startRename = ( id ) => {
		setMenu( null );
		setRenaming( id );
		setDraft( byId.get( id )?.node.name || '' );
	};

	const finishRename = async ( commit ) => {
		const id = renaming;
		const name = draft.trim();
		setRenaming( null );
		moveFocus( id );
		if ( ! commit || ! name || name === byId.get( id )?.node.name ) {
			return;
		}
		try {
			await onRename?.( id, name );
		} catch {
			// The panel shows the refusal.
		}
	};

	const typeAhead = ( key, index ) => {
		const now = Date.now();
		const state = typed.current;
		state.text = now - state.at > TYPE_AHEAD_MS ? key : state.text + key;
		state.at = now;
		const text = state.text.toLocaleLowerCase();
		const repeated = [ ...text ].every( ( ch ) => ch === text[ 0 ] );
		const order = [
			...rows.slice( index + 1 ),
			...rows.slice( 0, index + 1 ),
		];
		const match =
			order.find( ( row ) =>
				row.node.name.toLocaleLowerCase().startsWith( text )
			) ||
			( repeated &&
				order.find( ( row ) =>
					row.node.name.toLocaleLowerCase().startsWith( text[ 0 ] )
				) );
		if ( match ) {
			moveFocus( match.id );
		}
	};

	const onKeyDown = ( event ) => {
		if ( event.target.getAttribute( 'role' ) !== 'treeitem' ) {
			return;
		}
		const index = rows.findIndex( ( row ) => row.id === tabStop );
		const row = rows[ index ];
		if ( ! row ) {
			return;
		}
		const handled = () => {
			event.preventDefault();
			event.stopPropagation();
		};
		switch ( event.key ) {
			case 'ArrowDown':
				handled();
				if ( index < rows.length - 1 ) {
					moveFocus( rows[ index + 1 ].id );
				}
				return;
			case 'ArrowUp':
				handled();
				if ( index > 0 ) {
					moveFocus( rows[ index - 1 ].id );
				}
				return;
			case 'Home':
				handled();
				moveFocus( rows[ 0 ].id );
				return;
			case 'End':
				handled();
				moveFocus( rows[ rows.length - 1 ].id );
				return;
			case 'ArrowRight':
				handled();
				if ( row.hasChildren && ! row.open ) {
					toggle( row, true );
				} else if ( row.hasChildren ) {
					moveFocus( rows[ index + 1 ].id );
				}
				return;
			case 'ArrowLeft':
				handled();
				if ( row.hasChildren && row.open && ! kept ) {
					toggle( row, false );
				} else if ( row.parent ) {
					moveFocus( row.parent );
				}
				return;
			case 'Enter':
			case ' ':
				handled();
				onSelect?.( row.id, event.ctrlKey || event.metaKey );
				return;
			case 'F2':
				if ( canManage && row.id !== ALL ) {
					handled();
					startRename( row.id );
				}
				return;
			case 'ContextMenu':
				if ( canManage && row.id !== ALL ) {
					handled();
					setMenu( row.id );
				}
				return;
			case 'F10':
				if ( event.shiftKey && canManage && row.id !== ALL ) {
					handled();
					setMenu( row.id );
				}
				return;
			default:
				if (
					event.key.length === 1 &&
					event.key !== ' ' &&
					! event.ctrlKey &&
					! event.metaKey &&
					! event.altKey
				) {
					handled();
					typeAhead( event.key, index );
				}
		}
	};

	// Drag and drop.
	const clearDrag = () => {
		dragged.current = null;
		setDrag( null );
	};

	const onDragStart = ( event, row ) => {
		dragged.current = row.id;
		try {
			event.dataTransfer.setData( CATEGORY_DRAG_TYPE, String( row.id ) );
			event.dataTransfer.setData( 'text/plain', row.node.name );
			event.dataTransfer.effectAllowed = 'move';
		} catch {
			// Some browsers refuse custom types; the ref still carries the id.
		}
		setMenu( null );
		setDrag( { id: row.id } );
	};

	const dropState = ( event, row ) => {
		if ( dragged.current ) {
			const box = event.currentTarget.getBoundingClientRect();
			const zone =
				row.id === ALL
					? 'inside'
					: dropZone( ( event.clientY || 0 ) - box.top, box.height );
			const payload = movePayload(
				nodes,
				byId,
				dragged.current,
				row.id,
				zone
			);
			return { id: dragged.current, target: row.id, zone, payload };
		}
		if (
			onDropSelection &&
			row.id !== ALL &&
			dragTypes( event ).includes( SELECTION_DRAG_TYPE )
		) {
			return { selection: true, target: row.id, zone: 'inside' };
		}
		return null;
	};

	const onDragOver = ( event, row ) => {
		const state = dropState( event, row );
		if ( ! state ) {
			return;
		}
		const allowed =
			state.selection || ( state.payload && ! state.payload.blocked );
		if ( allowed ) {
			event.preventDefault();
			if ( event.dataTransfer ) {
				event.dataTransfer.dropEffect = state.selection
					? 'copy'
					: 'move';
			}
		}
		setDrag( ( current ) =>
			current?.target === state.target &&
			current?.zone === state.zone &&
			current?.selection === state.selection
				? current
				: state
		);
	};

	const onDrop = async ( event, row ) => {
		const state = dropState( event, row );
		clearDrag();
		if ( ! state ) {
			return;
		}
		event.preventDefault();
		if ( state.selection ) {
			onDropSelection( row.id );
			return;
		}
		if ( ! state.payload ) {
			return;
		}
		if ( state.payload.blocked ) {
			announce(
				__(
					'A category cannot be moved inside itself.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				'assertive'
			);
			return;
		}
		try {
			await onMove?.(
				state.id,
				state.payload.parent,
				state.payload.position
			);
		} catch {
			// The panel shows the refusal.
		}
	};

	let dragLine = '';
	if ( drag?.selection ) {
		dragLine = sprintf(
			/* translators: %s: a category name. */
			__(
				'Drop to add the selected products to “%s”.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			byId.get( drag.target )?.node.name || ''
		);
	} else if ( drag?.payload?.blocked ) {
		dragLine = __(
			'A category cannot be moved inside itself.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	} else if ( drag?.payload ) {
		dragLine = sprintf(
			/* translators: %s: the category's new path, such as "Clothing › T-Shirts". */
			__( 'New place: %s', 'bulk-catalogue-manager-for-woocommerce' ),
			joinPath( previewPath( byId, drag.id, drag.payload.parent ) )
		);
	}

	const menuItems = ( id ) => [
		{
			key: 'rename',
			label: __( 'Rename', 'bulk-catalogue-manager-for-woocommerce' ),
			run: () => startRename( id ),
		},
		{
			key: 'edit',
			label: __(
				'Edit details…',
				'bulk-catalogue-manager-for-woocommerce'
			),
			run: () => onEdit?.( id ),
		},
		{
			key: 'move',
			label: __( 'Move to…', 'bulk-catalogue-manager-for-woocommerce' ),
			run: () => onMoveDialog?.( id ),
		},
		...( moreActions?.( id ) || [] ),
	];

	const closeMenu = ( id ) => {
		setMenu( null );
		moveFocus( id );
	};

	const onMenuKeyDown = ( event, id ) => {
		const buttons = Array.from(
			event.currentTarget.querySelectorAll( '[role="menuitem"]' )
		);
		const at = buttons.indexOf( event.target );
		if ( event.key === 'Escape' || event.key === 'Tab' ) {
			event.preventDefault();
			event.stopPropagation();
			closeMenu( id );
		} else if ( event.key === 'ArrowDown' ) {
			event.preventDefault();
			buttons[ ( at + 1 ) % buttons.length ]?.focus();
		} else if ( event.key === 'ArrowUp' ) {
			event.preventDefault();
			buttons[ ( at - 1 + buttons.length ) % buttons.length ]?.focus();
		} else if ( event.key === 'Home' ) {
			event.preventDefault();
			buttons[ 0 ]?.focus();
		} else if ( event.key === 'End' ) {
			event.preventDefault();
			buttons[ buttons.length - 1 ]?.focus();
		}
		event.stopPropagation();
	};

	const countLabel = ( row ) => {
		if ( row.id === ALL ) {
			return Number.isFinite( total ) ? total : null;
		}
		return nodeCount( row.node, includeChildren );
	};

	return (
		<>
			<ul
				className="bulkcat-cat-tree"
				role="tree"
				aria-label={ __(
					'Product categories',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				aria-multiselectable="true"
				aria-describedby={
					canManage ? 'bulkcat-cat-tree-help' : undefined
				}
				onKeyDown={ onKeyDown }
			>
				{ rows.map( ( row ) => {
					const count = countLabel( row );
					const isSelected =
						row.id === ALL
							? selected.length === 0
							: selected.includes( row.id );
					const zone = drag?.target === row.id ? drag.zone : null;
					const blocked =
						drag?.target === row.id && drag.payload?.blocked;
					const name = row.node.name;
					const label =
						count === null
							? name
							: sprintf(
									/* translators: 1: a category name, 2: how many products it holds. */
									_n(
										'%1$s, %2$s product',
										'%1$s, %2$s products',
										count,
										'bulk-catalogue-manager-for-woocommerce'
									),
									name,
									count.toLocaleString()
								);
					const manageable = canManage && row.id !== ALL;
					const className = [
						'bulkcat-cat-row',
						isSelected && 'is-selected',
						drag?.id === row.id && 'is-dragging',
						zone && ! blocked && `is-drop-${ zone }`,
						blocked && 'is-drop-blocked',
						menu === row.id && 'is-menu-open',
					]
						.filter( Boolean )
						.join( ' ' );
					return (
						<li
							key={ row.id }
							ref={ ( element ) => {
								if ( element ) {
									items.current.set( row.id, element );
								} else {
									items.current.delete( row.id );
								}
							} }
							role="treeitem"
							id={ `bulkcat-cat-${ row.id }` }
							aria-label={ label }
							aria-level={ row.depth + 1 }
							aria-setsize={ row.setSize }
							aria-posinset={ row.posInSet }
							aria-expanded={
								row.hasChildren ? row.open : undefined
							}
							aria-selected={ isSelected }
							tabIndex={ row.id === tabStop ? 0 : -1 }
							className="bulkcat-cat-item"
							onFocus={ ( event ) => {
								if ( event.target === event.currentTarget ) {
									setFocused( row.id );
								}
							} }
							onDragOver={ ( event ) => onDragOver( event, row ) }
							onDrop={ ( event ) => onDrop( event, row ) }
						>
							{ /* The row is the drag source and a pointer target; the treeitem takes the keys, and keyboard and touch use Move to…. */ }
							{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
							<div
								className={ className }
								style={ indent( row.depth ) }
								draggable={ manageable && renaming !== row.id }
								onDragStart={
									manageable
										? ( event ) => onDragStart( event, row )
										: undefined
								}
								onDragEnd={ clearDrag }
								onClick={ ( event ) => {
									if (
										event.target.closest( 'button, input' )
									) {
										return;
									}
									setFocused( row.id );
									onSelect?.(
										row.id,
										event.ctrlKey || event.metaKey
									);
								} }
							>
								{ manageable ? (
									<span
										className="bulkcat-cat-grip"
										aria-hidden="true"
									/>
								) : (
									<span
										className="bulkcat-cat-grip is-empty"
										aria-hidden="true"
									/>
								) }
								{ row.hasChildren ? (
									<span
										className={
											'bulkcat-cat-twist' +
											( row.open ? ' is-open' : '' )
										}
										aria-hidden="true"
										onClick={ ( event ) => {
											event.stopPropagation();
											toggle( row, ! row.open );
										} }
									/>
								) : (
									<span
										className="bulkcat-cat-twist is-leaf"
										aria-hidden="true"
									/>
								) }
								{ renaming === row.id ? (
									<input
										className="bulkcat-cat-rename"
										aria-label={ sprintf(
											/* translators: %s: a category name. */
											__(
												'New name for %s',
												'bulk-catalogue-manager-for-woocommerce'
											),
											name
										) }
										value={ draft }
										// eslint-disable-next-line jsx-a11y/no-autofocus
										autoFocus
										onChange={ ( event ) =>
											setDraft( event.target.value )
										}
										onKeyDown={ ( event ) => {
											event.stopPropagation();
											if ( event.key === 'Enter' ) {
												event.preventDefault();
												finishRename( true );
											} else if (
												event.key === 'Escape'
											) {
												event.preventDefault();
												finishRename( false );
											}
										} }
										onBlur={ () =>
											renaming === row.id &&
											finishRename( true )
										}
									/>
								) : (
									<span
										className="bulkcat-cat-name"
										title={ name }
									>
										{ name }
									</span>
								) }
								{ row.node.default && (
									<span className="bulkcat-cat-default">
										{ __(
											'Default',
											'bulk-catalogue-manager-for-woocommerce'
										) }
									</span>
								) }
								{ count !== null && (
									<span
										className="bulkcat-cat-count"
										aria-hidden="true"
									>
										{ count.toLocaleString() }
									</span>
								) }
								{ manageable && (
									<button
										type="button"
										className="bulkcat-cat-kebab"
										tabIndex={ -1 }
										aria-haspopup="menu"
										aria-expanded={ menu === row.id }
										aria-label={ sprintf(
											/* translators: %s: a category name. */
											__(
												'More actions for %s',
												'bulk-catalogue-manager-for-woocommerce'
											),
											name
										) }
										onClick={ () =>
											setMenu(
												menu === row.id ? null : row.id
											)
										}
									>
										<span aria-hidden="true">⋮</span>
									</button>
								) }
							</div>
							{ menu === row.id && (
								<MenuList
									items={ menuItems( row.id ) }
									label={ sprintf(
										/* translators: %s: a category name. */
										__(
											'Actions for %s',
											'bulk-catalogue-manager-for-woocommerce'
										),
										name
									) }
									onKeyDown={ ( event ) =>
										onMenuKeyDown( event, row.id )
									}
									onChoose={ ( item ) => {
										setMenu( null );
										item.run();
									} }
									onLeave={ () => setMenu( null ) }
								/>
							) }
						</li>
					);
				} ) }
			</ul>
			{ canManage && (
				<p id="bulkcat-cat-tree-help" className="bulkcat-sr-only">
					{ __(
						'Press Shift+F10 for a category’s actions, including Move to…, or F2 to rename it.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ kept && kept.size === 0 && (
				<p className="bulkcat-cat-none">
					{ __(
						'No categories match this search.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			<p
				className={
					'bulkcat-cat-drag-line' +
					( drag?.payload?.blocked ? ' is-blocked' : '' )
				}
				hidden={ ! dragLine }
			>
				{ dragLine }
			</p>
		</>
	);
}

/**
 * A node's menu: a role="menu" list whose first item takes focus.
 *
 * @param {Object}   props           Props.
 * @param {Array}    props.items     { key, label, run }.
 * @param {string}   props.label     Accessible name.
 * @param {Function} props.onKeyDown Key handler.
 * @param {Function} props.onChoose  Called with the chosen item.
 * @param {Function} props.onLeave   Called when focus leaves the menu.
 * @return {Element} Menu.
 */
function MenuList( { items, label, onKeyDown, onChoose, onLeave } ) {
	const ref = useRef();
	useEffect( () => {
		ref.current?.querySelector( '[role="menuitem"]' )?.focus();
	}, [] );
	return (
		<div
			ref={ ref }
			className="bulkcat-cat-menu"
			role="menu"
			aria-label={ label }
			tabIndex={ -1 }
			onKeyDown={ onKeyDown }
			onBlur={ ( event ) => {
				const to = event.relatedTarget;
				if (
					! event.currentTarget.contains( to ) &&
					! to?.classList?.contains( 'bulkcat-cat-kebab' )
				) {
					onLeave();
				}
			} }
		>
			{ items.map( ( item ) => (
				<button
					key={ item.key }
					type="button"
					role="menuitem"
					tabIndex={ -1 }
					onClick={ () => onChoose( item ) }
				>
					{ item.label }
				</button>
			) ) }
		</div>
	);
}
