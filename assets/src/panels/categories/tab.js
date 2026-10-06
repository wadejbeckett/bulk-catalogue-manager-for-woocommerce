/**
 * The Categories tab: a full-width workspace for looking after categories on
 * their own (SPEC §4.7, §7). The tree runs down the left of the screen with
 * its search, Expand all and Collapse all, names that wrap rather than being
 * cut off, product counts and a checkbox per row; the right shows the chosen
 * category's details, actions and products, or, while several are chosen,
 * how many and what applies to all of them. Choosing categories here never
 * filters the product grid; the panes link to it.
 */
import {
	Button,
	Modal,
	SearchControl,
	ToggleControl,
} from '@wordpress/components';
import {
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { ShellContext } from '../../shell';
import { useRegistry } from '../catalogue/use-catalogue';
import { announce } from './announce';
// Registers Make default, Delete… and Merge into… in the action slot.
import './category-actions';
import CategoryDetails from './category-details';
import CategoryManager from './category-manager';
import { canManageTerms } from './category-panel';
import CategoryTree from './category-tree';
import CreationNotice from './creation-notice';
import ErrorNotice, { errorMessage } from './error-notice';
import MoveDialog from './move-dialog';
import MoveManyDialog from './move-many-dialog';
import { moveCategory, updateCategory } from './requests';
import SelectionPane from './selection-pane';
import {
	joinPath,
	moveSentence,
	pathNames,
	searchMatches,
	visibleRows,
} from './tree-model';
import useCategoryTree from './use-category-tree';
import { menuLabel } from '../../components/provider-menu';
import { categoryActions, offeredCategoryActions } from './workspace-actions';
import { syncProviderActions } from './provider-actions';
import {
	ancestorsOf,
	branchIds,
	matchCount,
	nextSelection,
} from './workspace-model';
import './style.scss';
import './workspace/style.scss';

/** No grid filters here: the counts are the whole catalogue's. */
const QUERY = {};

/**
 * @param {Object} props
 * @param {Object} props.settings The screen's localised settings.
 * @return {Element} The tab.
 */
export default function CategoriesTab( { settings } ) {
	const tree = useCategoryTree( QUERY );
	const { registry } = useRegistry();
	// An add-on's actions on categories, in the action slot (contract version 2).
	useMemo(
		() => syncProviderActions( registry, settings ),
		[ registry, settings ]
	);
	const shell = useContext( ShellContext );
	const navigate = shell?.navigate || null;
	const canManage = canManageTerms( settings );

	const [ search, setSearch ] = useState( '' );
	const [ expanded, setExpanded ] = useState( () => new Set() );
	const [ chosen, setChosen ] = useState( [] );
	const anchor = useRef( null );
	const [ includeChildren, setIncludeChildren ] = useState( true );
	const [ mode, setMode ] = useState( 'view' );
	const [ adding, setAdding ] = useState( null );
	const [ incompleteCreation, setIncompleteCreation ] = useState( false );
	const [ moving, setMoving ] = useState( null );
	const [ action, setAction ] = useState( null );
	const [ failure, setFailure ] = useState( null );
	const [ fresh, setFresh ] = useState( null );
	// When Move to… closes, focus goes back to the moved category in the tree
	// once it has been read again, or to its new parent while that is shut,
	// not to the menu item that opened the dialog, which is gone (SPEC §14).
	const [ refocus, setRefocus ] = useState( null );

	const { byId, nodes } = tree;
	// Categories that are gone after a read are no longer chosen.
	const selected = useMemo(
		() => chosen.filter( ( id ) => byId.has( id ) ),
		[ chosen, byId ]
	);
	const kept = useMemo(
		() => searchMatches( byId, search ),
		[ byId, search ]
	);
	const rows = useMemo(
		() => visibleRows( nodes, expanded, kept ),
		[ nodes, expanded, kept ]
	);

	useEffect( () => {
		if ( ! refocus || moving || refocus.before === nodes ) {
			return undefined;
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
	}, [ refocus, moving, nodes ] );

	const reveal = useCallback(
		( ids ) =>
			setExpanded(
				( open ) =>
					new Set( [ ...open, ...ancestorsOf( tree.byId, ids ) ] )
			),
		[ tree.byId ]
	);

	// A category just created is revealed, highlighted and chosen once the
	// tree read returns it.
	const shown = useRef( null );
	const { creation } = tree;
	useEffect( () => {
		if (
			creation?.phase !== 'visible' ||
			shown.current === creation.revision
		) {
			return;
		}
		shown.current = creation.revision;
		if ( kept && ! kept.has( creation.id ) ) {
			setSearch( '' );
		}
		reveal( [ creation.id ] );
		setFresh( creation.id );
		setChosen( [ creation.id ] );
		anchor.current = creation.id;
		setMode( 'view' );
	}, [ creation, kept, reveal ] );

	const choose = ( id, multiple, how = {} ) => {
		const next = nextSelection( selected, id, {
			multiple,
			range: !! how.range,
			anchor: anchor.current,
			rows,
		} );
		if ( ! how.range ) {
			anchor.current = id;
		}
		setChosen( next );
		setMode( 'view' );
		if ( next.length === 1 ) {
			announce(
				sprintf(
					/* translators: %s: a category's path, such as "Kitchen › Pots". */
					__(
						'%s chosen.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					joinPath( pathNames( byId, next[ 0 ] ) )
				)
			);
		} else {
			announce(
				sprintf(
					/* translators: %d: how many categories are chosen. */
					_n(
						'%d category selected.',
						'%d categories selected.',
						next.length,
						'bulk-catalogue-manager-for-woocommerce'
					),
					next.length
				)
			);
		}
	};

	const chooseOnly = ( id ) => {
		anchor.current = id;
		reveal( [ id ] );
		setChosen( [ id ] );
		setMode( 'view' );
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
		// Said from the tree as it was before the move.
		const said = moveSentence( nodes, byId, id, parent, position );
		setRefocus( ( was ) =>
			was && was.before === nodes ? was : { id, parent, before: nodes }
		);
		try {
			await moveCategory( id, parent, position );
			reveal( parent ? [ parent, id ] : [ id ] );
			if ( parent ) {
				setExpanded( ( open ) => new Set( [ ...open, parent ] ) );
			}
			tree.reload();
			announce( said );
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

	// A registered action (workspace-actions.js): its dialog, or at once.
	const runAction = async ( chosenAction, ids ) => {
		if ( chosenAction.Dialog ) {
			setAction( { action: chosenAction, ids } );
			return;
		}
		setFailure( null );
		try {
			const sentence = await chosenAction.run( { ids, tree } );
			tree.reload();
			if ( sentence ) {
				announce( sentence );
			}
		} catch ( error ) {
			fail( error, chosenAction.failure );
		}
	};

	// The row menu offers what the panes offer: the actions available for
	// the row's category, on every chosen category when the row is one of
	// several chosen and the action is offered for many.
	const moreActions = ( id ) => {
		const group =
			selected.length > 1 && selected.includes( id ) ? selected : [ id ];
		const item = ( registered ) => ( {
			key: registered.key,
			label: registered.label,
			run: () =>
				runAction( registered, registered.many ? group : [ id ] ),
		} );
		// 1.4.1: an add-on's actions sit in one item per group, opening a list.
		return offeredCategoryActions(
			categoryActions( 1, { ids: [ id ], tree } )
		).map( ( offered ) =>
			offered.menu
				? {
						key: offered.menu.key,
						label: menuLabel( offered.menu.label ),
						name: offered.menu.label,
						items: offered.actions.map( item ),
					}
				: item( offered.action )
		);
	};

	const added = ( item ) => {
		const complete = Number.isInteger( item?.id ) && item.id > 0;
		setIncompleteCreation( ! complete );
		if ( ! complete ) {
			tree.dismissCreation();
		}
		setAdding( null );
	};

	const matches = search.trim() ? matchCount( byId, search ) : null;
	const value = selected.length
		? { id: selected[ 0 ], ids: selected, includeChildren }
		: null;

	let pane;
	if ( selected.length > 1 ) {
		pane = (
			<SelectionPane
				tree={ tree }
				ids={ selected }
				canManage={ canManage }
				navigate={ navigate }
				onRemove={ ( id ) =>
					setChosen( ( was ) =>
						was.filter( ( each ) => each !== id )
					)
				}
				onClear={ () => setChosen( [] ) }
				onMove={ () => setMoving( selected ) }
				onAction={ runAction }
			/>
		);
	} else if ( selected.length === 1 ) {
		pane = (
			<CategoryDetails
				tree={ tree }
				id={ selected[ 0 ] }
				canManage={ canManage }
				registry={ registry }
				navigate={ navigate }
				mode={ mode }
				onMode={ setMode }
				onSelect={ chooseOnly }
				onMove={ ( id ) => setMoving( [ id ] ) }
				onAdd={ ( id ) => setAdding( { parentId: id } ) }
				onAction={ runAction }
				edit={ settings?.categoryEdit || null }
			/>
		);
	} else {
		pane = (
			<div className="bulkcat-cat-pane is-empty">
				<p>
					{ __(
						'Choose a category on the left to see its details and products.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
				<p className="bulkcat-cat-pane__hint">
					{ __(
						'Ctrl or Cmd click, or tick the checkboxes, to choose several and act on them together.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			</div>
		);
	}

	const ActionDialog = action?.action.Dialog;

	return (
		<div className="bulkcat-cat-workspace">
			<div className="bulkcat-cat-workspace__bar">
				<SearchControl
					__nextHasNoMarginBottom
					className="bulkcat-cat-workspace__search"
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
				/>
				<Button
					variant="secondary"
					onClick={ () => setExpanded( branchIds( byId ) ) }
					disabled={ !! kept }
					accessibleWhenDisabled
					__next40pxDefaultSize
				>
					{ __(
						'Expand all',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => setExpanded( new Set() ) }
					disabled={ !! kept }
					accessibleWhenDisabled
					__next40pxDefaultSize
				>
					{ __(
						'Collapse all',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Counts include subcategories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					checked={ includeChildren }
					onChange={ setIncludeChildren }
				/>
				<span className="bulkcat-cat-workspace__total">
					{ matches !== null
						? sprintf(
								/* translators: %d: how many categories' names match the search. */
								_n(
									'%d category matches',
									'%d categories match',
									matches,
									'bulk-catalogue-manager-for-woocommerce'
								),
								matches
							)
						: sprintf(
								/* translators: %d: how many product categories the shop has. */
								_n(
									'%d category',
									'%d categories',
									byId.size,
									'bulk-catalogue-manager-for-woocommerce'
								),
								byId.size
							) }
				</span>
				{ canManage && tree.loaded && nodes.length > 0 && (
					<Button
						variant="primary"
						className="bulkcat-cat-workspace__add"
						onClick={ ( event ) => {
							event.currentTarget.focus();
							setAdding( { parentId: 0 } );
						} }
						__next40pxDefaultSize
					>
						{ __(
							'Add category',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				) }
			</div>
			<CreationNotice
				creation={ tree.creation }
				incomplete={ incompleteCreation }
				loading={ tree.loading }
				onRetry={ tree.reload }
				onDismiss={ tree.dismissCreation }
			/>
			{ failure && (
				<ErrorNotice
					error={ failure.error }
					fallback={ failure.fallback }
					onDismiss={ () => setFailure( null ) }
				/>
			) }
			<div className="bulkcat-cat-workspace__body">
				<section
					className="bulkcat-cat-workspace__tree"
					aria-label={ __(
						'Product categories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					<div
						className="bulkcat-cat-workspace__columns"
						aria-hidden="true"
					>
						<span>
							{ __(
								'Category',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</span>
						<span>
							{ __(
								'Products',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</span>
					</div>
					{ tree.error && (
						<div className="bulkcat-cat-panel__state">
							<ErrorNotice
								error={ tree.error }
								fallback={ __(
									'The categories could not be loaded.',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							/>
							<Button variant="secondary" onClick={ tree.reload }>
								{ __(
									'Try again',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
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
					{ tree.loaded && nodes.length === 0 && (
						<div className="bulkcat-cat-panel__state">
							<p>
								{ __(
									'You have no product categories yet.',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</p>
							{ canManage && (
								<Button
									variant="primary"
									onClick={ () =>
										setAdding( { parentId: 0 } )
									}
								>
									{ __(
										'Create your first category',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</Button>
							) }
						</div>
					) }
					{ tree.loaded && nodes.length > 0 && (
						<CategoryTree
							tree={ tree }
							value={ value }
							includeChildren={ includeChildren }
							search={ search }
							canManage={ canManage }
							showAll={ false }
							expanded={ expanded }
							onExpandedChange={ setExpanded }
							wrap
							checkboxes
							markMatches
							highlight={ fresh }
							onSelect={ choose }
							onRename={ rename }
							onMove={ move }
							onEdit={ ( id ) => {
								chooseOnly( id );
								setMode( 'edit' );
							} }
							onMoveDialog={ ( id ) => setMoving( [ id ] ) }
							moreActions={ canManage ? moreActions : undefined }
						/>
					) }
				</section>
				<div className="bulkcat-cat-workspace__pane">{ pane }</div>
			</div>
			{ adding && (
				<Modal
					title={ __(
						'Add a category',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					onRequestClose={ () => setAdding( null ) }
					className="bulkcat-cat-modal"
				>
					<CategoryManager
						tree={ tree }
						categoryId={ null }
						parentId={ adding.parentId }
						canManage={ canManage }
						onSaved={ added }
						onCancel={ () => setAdding( null ) }
					/>
				</Modal>
			) }
			{ moving?.length === 1 && (
				<MoveDialog
					tree={ tree }
					id={ moving[ 0 ] }
					onMove={ move }
					onClose={ () => {
						setRefocus( ( was ) => was || { id: moving[ 0 ] } );
						setMoving( null );
					} }
				/>
			) }
			{ moving?.length > 1 && (
				<MoveManyDialog
					tree={ tree }
					ids={ moving }
					onMove={ ( id, parent ) => move( id, parent ) }
					onClose={ () => {
						setRefocus( ( was ) => was || { id: moving[ 0 ] } );
						setMoving( null );
					} }
				/>
			) }
			{ ActionDialog && (
				<ActionDialog
					ids={ action.ids }
					tree={ tree }
					onClose={ () => setAction( null ) }
					onDone={ ( keep = [] ) => {
						setAction( null );
						reveal( keep );
						anchor.current = keep[ 0 ] ?? null;
						setChosen( keep );
						setMode( 'view' );
						tree.reload();
					} }
				/>
			) }
		</div>
	);
}
