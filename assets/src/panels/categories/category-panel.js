/**
 * <CategoryPanel>: the category panel left of the catalogue table (SPEC
 * §4.1, §4.7, §7; T61–T66). It collapses, resizes by dragging or with the
 * arrow keys on its edge, and remembers both per user; below 960 pixels it
 * is a button opening the same tree as a sheet. Selecting a category emits a
 * filter value and its chip sentence; the toggle chooses this category only
 * or with its sub-categories. With manage_product_terms it also creates,
 * renames, edits and moves categories and accepts a dropped product
 * selection.
 */
import {
	Button,
	Modal,
	SearchControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from './announce';
import CategoryManager from './category-manager';
import CategoryTree, { ALL } from './category-tree';
import CreationNotice, { IncompleteCreationNotice } from './creation-notice';
import ErrorNotice, { errorMessage } from './error-notice';
import MoveDialog from './move-dialog';
import { moveCategory, updateCategory } from './requests';
import {
	clampWidth,
	MAX_WIDTH,
	MIN_WIDTH,
	readPanelState,
	writePanelState,
} from './storage';
import { categorySentence, joinPath, nextValue, pathNames } from './tree-model';
import useCategoryTree from './use-category-tree';
import './style.scss';

export const NARROW_WIDTH = 960;

const RESIZE_STEP = 16;

/**
 * Whether the current user may change categories, from the localised
 * settings: `user.capabilities.manage_product_terms` or `user.canManageTerms`.
 * Anything else is read-only; the server checks again on every write.
 *
 * @param {Object} settings Localised settings (window.bulkcatSettings).
 * @return {boolean} True when the edit controls should show.
 */
export function canManageTerms( settings ) {
	const user = settings?.user || {};
	return (
		user.capabilities?.manage_product_terms === true ||
		user.canManageTerms === true
	);
}

/**
 * Whether the window is narrower than the panel's breakpoint.
 *
 * @param {number} width Breakpoint in pixels.
 * @return {boolean} True below it.
 */
function useNarrow( width ) {
	const query = `(max-width: ${ width - 1 }px)`;
	const read = () =>
		typeof window !== 'undefined' && typeof window.matchMedia === 'function'
			? window.matchMedia( query ).matches
			: false;
	const [ narrow, setNarrow ] = useState( read );
	useEffect( () => {
		if ( typeof window.matchMedia !== 'function' ) {
			return undefined;
		}
		const list = window.matchMedia( query );
		const update = () => setNarrow( list.matches );
		list.addEventListener?.( 'change', update );
		return () => list.removeEventListener?.( 'change', update );
	}, [ query ] );
	return narrow;
}

/**
 * The panel.
 *
 * @param {Object}   props                 Props.
 * @param {Object}   props.value           { id, ids?, includeChildren } or null for all products.
 * @param {Function} props.onChange        Called with ( value|null, sentence ).
 * @param {Object}   props.query           The grid's search, mode and filter arguments, so counts follow them.
 * @param {Object}   props.tree            A shared useCategoryTree() result; the panel loads its own without one.
 * @param {Object}   props.settings        Localised settings: user id, the category panel default and capabilities.
 * @param {boolean}  props.canManage       Overrides canManageTerms( settings ).
 * @param {number}   props.total           Optional count for the All products row.
 * @param {Function} props.onDropSelection Called with a category id when the product selection is dropped on it.
 * @param {number}   props.narrowWidth     Breakpoint for the sheet; 960 by default.
 * @param {boolean}  props.compact         Use the sheet while the bulk panel needs the space.
 * @return {Element} The panel.
 */
export default function CategoryPanel( {
	value = null,
	onChange = () => {},
	query = {},
	tree: sharedTree,
	settings = {},
	canManage: canManageProp,
	total,
	onDropSelection,
	narrowWidth = NARROW_WIDTH,
	compact = false,
} ) {
	const ownTree = useCategoryTree( query, { enabled: ! sharedTree } );
	const tree = sharedTree || ownTree;
	const canManage =
		typeof canManageProp === 'boolean'
			? canManageProp
			: canManageTerms( settings );
	const userId = settings?.user?.id || 0;
	const openDefault = settings?.settings?.category_panel !== false;

	const bodyElement = useRef( null );
	const emptyOpener = useRef( null );
	const emptyOpenerRef = useCallback( ( button ) => {
		const previous = emptyOpener.current;
		// Readback can remove the first-category opener after Modal restored
		// focus to it. Rescue only that focused removal, never a background
		// read while the user works elsewhere. Keep this ref callback stable.
		if ( ! button && previous === previous?.ownerDocument.activeElement ) {
			bodyElement.current?.querySelector( '[role="status"]' )?.focus();
		}
		emptyOpener.current = button;
	}, [] );

	const [ layout, setLayout ] = useState( () =>
		readPanelState( userId, openDefault )
	);
	const [ search, setSearch ] = useState( '' );
	const [ includeChildren, setIncludeChildren ] = useState(
		value?.includeChildren ?? true
	);
	const [ editing, setEditing ] = useState( null );
	const [ incompleteCreation, setIncompleteCreation ] = useState( false );
	const [ moving, setMoving ] = useState( null );
	const [ failure, setFailure ] = useState( null );
	// T75: when Move to… closes, focus goes back into the tree — the moved
	// category, or its new parent while that is collapsed — rather than to the
	// menu item that opened the dialog, which is gone.
	const [ refocus, setRefocus ] = useState( null );
	useEffect( () => {
		// After a move, wait for the tree to be read again, so the node is
		// focused where it now is.
		if ( ! refocus || moving || refocus.before === tree.nodes ) {
			return;
		}
		const frame = window.requestAnimationFrame( () => {
			const doc = window.document;
			const target =
				doc.getElementById( `bulkcat-cat-${ refocus.id }` ) ||
				( refocus.parent
					? doc.getElementById( `bulkcat-cat-${ refocus.parent }` )
					: null ) ||
				doc.querySelector( '[role="treeitem"][tabindex="0"]' );
			if ( target ) {
				target.focus();
				setRefocus( null );
			}
		} );
		return () => window.cancelAnimationFrame( frame );
	}, [ refocus, moving, tree.nodes ] );
	const [ sheet, setSheet ] = useState( false );
	const narrow = useNarrow( narrowWidth );
	const resizing = useRef( null );

	useEffect( () => {
		writePanelState( userId, layout );
	}, [ userId, layout ] );

	useEffect( () => {
		if ( typeof value?.includeChildren === 'boolean' ) {
			setIncludeChildren( value.includeChildren );
		}
	}, [ value?.includeChildren ] );

	const emit = useCallback(
		( next ) => {
			const sentence = categorySentence( next, tree.byId );
			onChange( next, sentence );
			announce(
				sentence ||
					__(
						'Showing all products.',
						'bulk-catalogue-manager-for-woocommerce'
					)
			);
		},
		[ onChange, tree.byId ]
	);

	const select = ( id, multiple ) => {
		if ( id === ALL ) {
			emit( null );
			return;
		}
		emit( nextValue( value, id, multiple, includeChildren ) );
	};

	const toggleChildren = ( checked ) => {
		setIncludeChildren( checked );
		if ( value ) {
			emit( { ...value, includeChildren: checked } );
		}
	};

	const fail = ( error, fallback ) => {
		setFailure( { error, fallback } );
		announce( errorMessage( error, fallback ), 'assertive' );
	};

	const rename = async ( id, name ) => {
		setFailure( null );
		try {
			const answer = await updateCategory( id, { name } );
			tree.remember?.( answer?.item );
			tree.reload();
			announce(
				sprintf(
					/* translators: %s: the category's new name. */
					__(
						'Category renamed to %s.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					answer?.item?.name || name
				)
			);
		} catch ( error ) {
			fail(
				error,
				__(
					'The category could not be renamed. Try again in a moment.',
					'bulk-catalogue-manager-for-woocommerce'
				)
			);
			throw error;
		}
	};

	const move = async ( id, parent, position ) => {
		setFailure( null );
		setRefocus( { id, parent, before: tree.nodes } );
		const name = tree.byId.get( id )?.node.name || '';
		try {
			await moveCategory( id, parent, position );
			tree.reload();
			announce(
				sprintf(
					/* translators: 1: a category name, 2: where it now sits, such as "Clothing › T-Shirts" or "Top level". */
					__(
						'%1$s moved to %2$s.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					name,
					parent
						? joinPath( pathNames( tree.byId, parent ) )
						: __(
								'the top level',
								'bulk-catalogue-manager-for-woocommerce'
							)
				)
			);
		} catch ( error ) {
			fail(
				error,
				__(
					'The category could not be moved. Try again in a moment.',
					'bulk-catalogue-manager-for-woocommerce'
				)
			);
			throw error;
		}
	};

	// Resizing by the edge: pointer drag, or arrow keys on the separator.
	const setWidth = ( width ) =>
		setLayout( ( current ) => ( {
			...current,
			width: clampWidth( width ),
		} ) );

	const onResizeStart = ( event ) => {
		event.preventDefault();
		resizing.current = { x: event.clientX, width: layout.width };
		const onMoveEvent = ( moveEvent ) => {
			if ( resizing.current ) {
				setWidth(
					resizing.current.width +
						( moveEvent.clientX - resizing.current.x )
				);
			}
		};
		const onUp = () => {
			resizing.current = null;
			window.removeEventListener( 'pointermove', onMoveEvent );
			window.removeEventListener( 'pointerup', onUp );
		};
		window.addEventListener( 'pointermove', onMoveEvent );
		window.addEventListener( 'pointerup', onUp );
	};

	const onResizeKey = ( event ) => {
		const steps = {
			ArrowLeft: layout.width - RESIZE_STEP,
			ArrowRight: layout.width + RESIZE_STEP,
			Home: MIN_WIDTH,
			End: MAX_WIDTH,
		};
		if ( event.key in steps ) {
			event.preventDefault();
			setWidth( steps[ event.key ] );
		}
	};

	const setCollapsed = ( collapsed ) =>
		setLayout( ( current ) => ( { ...current, collapsed } ) );

	const addCategory = ( event ) => {
		// Some pointer activations do not focus buttons. Give the native Modal
		// a reliable focus-return target before it captures the active element.
		event.currentTarget.focus();
		setEditing( { id: null } );
	};

	const saved = ( item ) => {
		if ( ! editing.id ) {
			const complete = Number.isInteger( item?.id ) && item.id > 0;
			setIncompleteCreation( ! complete );
			if ( ! complete ) {
				// A prior receipt cannot confirm this new, incomplete response.
				tree.dismissCreation();
			}
		}
		setEditing( null );
	};

	const sentence = categorySentence( value, tree.byId );

	const body = ( toggle = null ) => (
		<div ref={ bodyElement } className="bulkcat-cat-panel__body">
			<div className="bulkcat-cat-panel__head">
				<div className="bulkcat-cat-panel__row">
					<h2 className="bulkcat-cat-panel__title">
						{ __(
							'Categories',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</h2>
					<Button
						variant="link"
						className="bulkcat-cat-panel__all"
						onClick={ () => {
							setSearch( '' );
							emit( null );
						} }
						disabled={ ! value && ! search }
						accessibleWhenDisabled
					>
						{ __(
							'Show all',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
					{ toggle }
				</div>
				<SearchControl
					__nextHasNoMarginBottom
					label={ __(
						'Search categories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					placeholder={ __(
						'Search categories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					value={ search }
					onChange={ setSearch }
					size="compact"
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Include sub-categories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					checked={ includeChildren }
					onChange={ toggleChildren }
				/>
			</div>
			{ incompleteCreation ? (
				<IncompleteCreationNotice
					onRetry={ tree.reload }
					loading={ tree.loading }
				/>
			) : (
				<CreationNotice
					creation={ tree.creation }
					onRetry={ tree.reload }
					onDismiss={ tree.dismissCreation }
				/>
			) }
			{ failure && (
				<ErrorNotice
					error={ failure.error }
					fallback={ failure.fallback }
					onDismiss={ () => setFailure( null ) }
				/>
			) }
			<div className="bulkcat-cat-panel__scroll">
				{ tree.error && (
					<div className="bulkcat-cat-panel__state">
						<ErrorNotice
							error={ tree.error }
							fallback={ __(
								'The categories could not be loaded.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						/>
						{ ! tree.creation && ! incompleteCreation && (
							<Button variant="secondary" onClick={ tree.reload }>
								{ __(
									'Try again',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						) }
					</div>
				) }
				{ ! tree.loaded && ! tree.error && (
					<p className="bulkcat-cat-panel__state">
						{ __(
							'Loading categories…',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				) }
				{ tree.loaded && tree.nodes.length === 0 && (
					<div className="bulkcat-cat-panel__state">
						<p>
							{ __(
								'You have no product categories yet.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</p>
						{ canManage && (
							<Button
								ref={ emptyOpenerRef }
								variant="primary"
								onClick={ addCategory }
							>
								{ __(
									'Create your first category',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						) }
					</div>
				) }
				{ tree.loaded && tree.nodes.length > 0 && (
					<CategoryTree
						tree={ tree }
						value={ value }
						includeChildren={ includeChildren }
						search={ search }
						canManage={ canManage }
						total={ total }
						onSelect={ select }
						onRename={ rename }
						onMove={ move }
						onEdit={ ( id ) => setEditing( { id } ) }
						onMoveDialog={ setMoving }
						onDropSelection={ onDropSelection }
					/>
				) }
			</div>
			<div className="bulkcat-cat-panel__foot">
				{ canManage ? (
					tree.loaded &&
					tree.nodes.length > 0 && (
						<Button
							variant="secondary"
							size="compact"
							onClick={ addCategory }
						>
							{ __(
								'+ Add category',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					)
				) : (
					<p className="bulkcat-cat-readonly">
						{ __(
							'You can browse and filter by categories, but changing them needs permission to manage product categories.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				) }
			</div>
		</div>
	);

	const dialogs = (
		<>
			{ editing && (
				<Modal
					title={
						editing.id
							? __(
									'Category details',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: __(
									'Add a category',
									'bulk-catalogue-manager-for-woocommerce'
								)
					}
					onRequestClose={ () => setEditing( null ) }
					className="bulkcat-cat-modal"
				>
					<CategoryManager
						tree={ tree }
						categoryId={ editing.id }
						canManage={ canManage }
						onSaved={ saved }
						onCancel={ () => setEditing( null ) }
					/>
				</Modal>
			) }
			{ moving && (
				<MoveDialog
					tree={ tree }
					id={ moving }
					onMove={ move }
					onClose={ () => {
						setRefocus( ( was ) => was || { id: moving } );
						setMoving( null );
					} }
				/>
			) }
		</>
	);

	if ( narrow || compact ) {
		return (
			<div className="bulkcat-cat-panel is-narrow">
				<Button
					variant="secondary"
					aria-haspopup="dialog"
					onClick={ () => setSheet( true ) }
				>
					{ sentence ||
						__(
							'Categories',
							'bulk-catalogue-manager-for-woocommerce'
						) }
				</Button>
				{ sheet && (
					<Modal
						title={ __(
							'Categories',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						onRequestClose={ () => setSheet( false ) }
						className="bulkcat-cat-sheet"
					>
						{ body() }
					</Modal>
				) }
				{ dialogs }
			</div>
		);
	}

	if ( layout.collapsed ) {
		return (
			<div className="bulkcat-cat-panel is-collapsed">
				<Button
					className="bulkcat-cat-panel__toggle"
					variant="tertiary"
					aria-expanded={ false }
					onClick={ () => setCollapsed( false ) }
					label={ __(
						'Show categories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					showTooltip
				>
					<span aria-hidden="true">›</span>
				</Button>
				{ dialogs }
			</div>
		);
	}

	return (
		<aside
			className="bulkcat-cat-panel"
			style={ { width: layout.width } }
			aria-label={ __(
				'Product categories',
				'bulk-catalogue-manager-for-woocommerce'
			) }
		>
			{ body(
				<Button
					className="bulkcat-cat-panel__toggle"
					variant="tertiary"
					size="small"
					aria-expanded
					onClick={ () => setCollapsed( true ) }
					label={ __(
						'Hide categories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					showTooltip
				>
					<span aria-hidden="true">‹</span>
				</Button>
			) }
			{ /* The separator is a focusable resize control (WAI-ARIA window splitter). */ }
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="bulkcat-cat-panel__edge"
				role="separator"
				aria-orientation="vertical"
				aria-label={ __(
					'Resize the category panel',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				aria-valuemin={ MIN_WIDTH }
				aria-valuemax={ MAX_WIDTH }
				aria-valuenow={ layout.width }
				tabIndex={ 0 }
				onPointerDown={ onResizeStart }
				onKeyDown={ onResizeKey }
			/>
			{ dialogs }
		</aside>
	);
}
