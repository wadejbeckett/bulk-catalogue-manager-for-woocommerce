/**
 * The details pane of the Categories tab (SPEC §4.7): the chosen category's
 * path, its native fields (name, slug, description, parent, display type and
 * image), its product and subcategory counts, its actions and the paged list
 * of its products. Edit details opens the same editor as the category menu,
 * in the pane; Rename edits the name in place; All fields shows WordPress's
 * own form for the category, with the fields other plugins add to it.
 */
import {
	Button,
	ExternalLink,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import AllFields, { termEditUrl } from './all-fields';
import { announce } from './announce';
import CategoryManager from './category-manager';
import CategoryProducts from './category-products';
import ErrorNotice, { errorMessage } from './error-notice';
import { fetchCategory, updateCategory } from './requests';
import { joinPath, lineage, pathNames } from './tree-model';
import ActionButtons from './action-buttons';
import { categoryActions } from './workspace-actions';

/**
 * WooCommerce's display types by value; '' is Default.
 *
 * @return {Object} value => label.
 */
function displayLabels() {
	return {
		'': __( 'Default', 'bulk-catalogue-manager-for-woocommerce' ),
		products: __( 'Products', 'bulk-catalogue-manager-for-woocommerce' ),
		subcategories: __(
			'Subcategories',
			'bulk-catalogue-manager-for-woocommerce'
		),
		both: __( 'Both', 'bulk-catalogue-manager-for-woocommerce' ),
	};
}

/**
 * The display type and image, read through GET /categories/{id} for a user
 * who may manage categories (the route's own permission).
 *
 * @param {number}  id        Category id.
 * @param {boolean} canManage Whether the user may manage categories.
 * @param {*}       revision  Read again when this changes.
 * @return {Object} { state: loading|ready|failed|unavailable, display_type, thumbnail_url, thumbnail_id }.
 */
function useNativeDetails( id, canManage, revision ) {
	const [ details, setDetails ] = useState( { state: 'loading' } );
	useEffect( () => {
		if ( ! canManage ) {
			setDetails( { state: 'unavailable' } );
			return undefined;
		}
		let live = true;
		setDetails( ( was ) =>
			was.id === id ? was : { state: 'loading', id }
		);
		Promise.resolve()
			.then( () => fetchCategory( id ) )
			.then(
				( answer ) => {
					const item = answer?.item;
					if ( ! live ) {
						return;
					}
					setDetails(
						typeof item?.display_type === 'string'
							? {
									state: 'ready',
									id,
									display_type: item.display_type,
									thumbnail_id:
										Number( item.thumbnail_id ) || 0,
									thumbnail_url: item.thumbnail_url || null,
								}
							: { state: 'failed', id }
					);
				},
				() => live && setDetails( { state: 'failed', id } )
			);
		return () => {
			live = false;
		};
	}, [ id, canManage, revision ] );
	return details;
}

/**
 * The name, edited in place.
 *
 * @param {Object}   props        Props.
 * @param {Object}   props.tree   useCategoryTree() result.
 * @param {number}   props.id     Category id.
 * @param {string}   props.name   Current name.
 * @param {Function} props.onDone Called when the edit closes.
 * @return {Element} The form.
 */
function RenameForm( { tree, id, name, onDone } ) {
	const [ draft, setDraft ] = useState( name );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const submit = async ( event ) => {
		event.preventDefault();
		const next = draft.trim();
		if ( ! next || next === name ) {
			onDone();
			return;
		}
		setBusy( true );
		try {
			const answer = await updateCategory( id, { name: next } );
			tree.remember?.( answer?.item );
			tree.reload();
			announce(
				sprintf(
					/* translators: %s: the category's new name. */
					__(
						'Category renamed to %s.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					answer?.item?.name || next
				)
			);
			onDone();
		} catch ( failure ) {
			setBusy( false );
			setError( failure || {} );
			if ( failure?.data?.applied ) {
				// Made but not recorded (SILENT-FAILURES-9): show the tree as it now is.
				tree.reload();
			}
			announce(
				errorMessage(
					failure,
					__(
						'The category could not be renamed. Try again in a moment.',
						'bulk-catalogue-manager-for-woocommerce'
					)
				),
				'assertive'
			);
		}
	};
	return (
		<form className="bulkcat-cat-pane__rename" onSubmit={ submit }>
			{ error && (
				<ErrorNotice
					error={ error }
					fallback={ __(
						'The category could not be renamed. Try again in a moment.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				/>
			) }
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ sprintf(
					/* translators: %s: a category name. */
					__(
						'New name for %s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					name
				) }
				hideLabelFromVision
				value={ draft }
				onChange={ setDraft }
				// eslint-disable-next-line jsx-a11y/no-autofocus
				autoFocus
				onKeyDown={ ( event ) => {
					if ( event.key === 'Escape' ) {
						event.preventDefault();
						onDone();
					}
				} }
			/>
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy }
				accessibleWhenDisabled
				__next40pxDefaultSize
			>
				{ __( 'Save name', 'bulk-catalogue-manager-for-woocommerce' ) }
			</Button>
			<Button variant="tertiary" onClick={ onDone } __next40pxDefaultSize>
				{ __( 'Cancel', 'bulk-catalogue-manager-for-woocommerce' ) }
			</Button>
		</form>
	);
}

/**
 * The pane.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.tree      useCategoryTree() result.
 * @param {number}   props.id        The chosen category.
 * @param {boolean}  props.canManage Whether the user may change categories.
 * @param {Object}   props.registry  GET /fields answer for the product list, or null.
 * @param {Function} props.navigate  The shell's navigate( panel, params ), or null.
 * @param {string}   props.mode      'view', 'edit' or 'fields'.
 * @param {Function} props.onMode    Called with the next mode.
 * @param {Function} props.onSelect  Choose another category (the path's links).
 * @param {Function} props.onMove    Open Move to… for this category.
 * @param {Function} props.onAdd     Open Add subcategory under this category.
 * @param {Function} props.onAction  Open a registered action's dialog: ( action, ids ).
 * @param {Object}   props.edit      The screen's `categoryEdit` ({ url, flag }), or null.
 * @return {Element} The pane.
 */
export default function CategoryDetails( {
	tree,
	id,
	canManage,
	registry,
	navigate,
	mode = 'view',
	onMode,
	onSelect,
	onMove,
	onAdd,
	onAction,
	edit = null,
} ) {
	const entry = tree.byId.get( id );
	const [ renaming, setRenaming ] = useState( false );
	const details = useNativeDetails( id, canManage, tree.nodes );
	// 1.3.4: the name of the category whose details were just saved, said on screen as well as aloud.
	const [ saved, setSaved ] = useState( '' );
	useEffect( () => {
		setRenaming( false );
		setSaved( '' );
	}, [ id ] );

	if ( ! entry ) {
		return null;
	}
	const { node } = entry;
	const ancestors = lineage( tree.byId, id ).slice( 0, -1 );
	const headingId = `bulkcat-cat-pane-${ id }`;
	const labels = displayLabels();
	const description = tree.descriptions?.get( id ) ?? node.description ?? '';
	const extra = categoryActions( 1, { ids: [ id ], tree } );

	const facts = [
		{
			key: 'slug',
			term: __( 'Slug', 'bulk-catalogue-manager-for-woocommerce' ),
			value: <code>{ node.slug }</code>,
		},
		{
			key: 'parent',
			term: __(
				'Parent category',
				'bulk-catalogue-manager-for-woocommerce'
			),
			value: entry.parent
				? joinPath( pathNames( tree.byId, entry.parent ) )
				: __(
						'None (top level)',
						'bulk-catalogue-manager-for-woocommerce'
					),
		},
		{
			key: 'description',
			term: __( 'Description', 'bulk-catalogue-manager-for-woocommerce' ),
			value: description ? (
				<span className="bulkcat-cat-pane__description">
					{ description }
				</span>
			) : (
				<span className="bulkcat-cat-pane__muted">
					{ __(
						'No description',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</span>
			),
		},
		{
			key: 'display',
			term: __(
				'Display type',
				'bulk-catalogue-manager-for-woocommerce'
			),
			value:
				details.state === 'ready' ? (
					( labels[ details.display_type ] ?? details.display_type )
				) : (
					<span className="bulkcat-cat-pane__muted">
						{ details.state === 'loading'
							? __(
									'Loading…',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: __(
									'Not available',
									'bulk-catalogue-manager-for-woocommerce'
								) }
					</span>
				),
		},
		{
			key: 'image',
			term: __( 'Image', 'bulk-catalogue-manager-for-woocommerce' ),
			value:
				details.state === 'ready' && details.thumbnail_url ? (
					<img
						className="bulkcat-cat-pane__image"
						src={ details.thumbnail_url }
						alt={ __(
							'Category image',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						width="64"
						height="64"
					/>
				) : (
					<span className="bulkcat-cat-pane__muted">
						{ details.state === 'ready'
							? __(
									'No image',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: __(
									'Not available',
									'bulk-catalogue-manager-for-woocommerce'
								) }
					</span>
				),
		},
		{
			key: 'products',
			term: __( 'Products', 'bulk-catalogue-manager-for-woocommerce' ),
			value: sprintf(
				/* translators: 1: products in the category itself, 2: products in it and its subcategories. */
				__(
					'%1$s in this category, %2$s with its subcategories',
					'bulk-catalogue-manager-for-woocommerce'
				),
				( node.count || 0 ).toLocaleString(),
				( node.countWithDescendants || 0 ).toLocaleString()
			),
		},
		{
			key: 'children',
			term: __(
				'Subcategories',
				'bulk-catalogue-manager-for-woocommerce'
			),
			value: entry.children.length
				? sprintf(
						/* translators: %d: how many subcategories a category has directly. */
						_n(
							'%d subcategory',
							'%d subcategories',
							entry.children.length,
							'bulk-catalogue-manager-for-woocommerce'
						),
						entry.children.length
					)
				: __( 'None', 'bulk-catalogue-manager-for-woocommerce' ),
		},
	];

	return (
		<section className="bulkcat-cat-pane" aria-labelledby={ headingId }>
			<nav
				className="bulkcat-cat-pane__path"
				aria-label={ __(
					'Category path',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				{ ancestors.map( ( each ) => (
					<span key={ each } className="bulkcat-cat-pane__crumb">
						<Button
							variant="link"
							onClick={ () => onSelect( each ) }
						>
							{ tree.byId.get( each ).node.name }
						</Button>
						<span
							className="bulkcat-cat-pane__sep"
							aria-hidden="true"
						>
							›
						</span>
					</span>
				) ) }
				<span aria-current="page">{ node.name }</span>
			</nav>
			<div className="bulkcat-cat-pane__head">
				{ details.state === 'ready' && details.thumbnail_url && (
					<img
						className="bulkcat-cat-pane__thumb"
						src={ details.thumbnail_url }
						alt=""
						width="40"
						height="40"
					/>
				) }
				{ renaming ? (
					<>
						<h2 id={ headingId } className="bulkcat-sr-only">
							{ node.name }
						</h2>
						<RenameForm
							tree={ tree }
							id={ id }
							name={ node.name }
							onDone={ () => setRenaming( false ) }
						/>
					</>
				) : (
					<h2
						id={ headingId }
						className={
							node.default
								? 'bulkcat-cat-pane__title has-badge'
								: 'bulkcat-cat-pane__title'
						}
					>
						{ node.name }
					</h2>
				) }
				{ node.default && (
					<span className="bulkcat-cat-default">
						{ __(
							'Default',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</span>
				) }
			</div>
			{ canManage ? (
				<div
					className="bulkcat-cat-pane__actions"
					role="group"
					aria-label={ __(
						'Category actions',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					<Button
						variant="secondary"
						size="compact"
						onClick={ () => setRenaming( true ) }
						disabled={ renaming }
						accessibleWhenDisabled
					>
						{ __(
							'Rename',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
					<Button
						variant="secondary"
						size="compact"
						onClick={ () => onMove( id ) }
					>
						{ __(
							'Move to…',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
					<Button
						variant="secondary"
						size="compact"
						onClick={ () => onAdd( id ) }
					>
						{ __(
							'Add subcategory',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
					<Button
						variant={ mode === 'edit' ? 'primary' : 'secondary' }
						size="compact"
						aria-pressed={ mode === 'edit' }
						onClick={ () =>
							onMode( mode === 'edit' ? 'view' : 'edit' )
						}
					>
						{ __(
							'Edit details',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
					{ edit?.url && (
						<Button
							variant={
								mode === 'fields' ? 'primary' : 'secondary'
							}
							size="compact"
							aria-pressed={ mode === 'fields' }
							onClick={ () =>
								onMode( mode === 'fields' ? 'view' : 'fields' )
							}
						>
							{ __(
								'All fields',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					) }
					{ /* Actions from their own modules (Delete, Merge into…, an add-on's group menus): workspace-actions.js. */ }
					<ActionButtons
						actions={ extra }
						ids={ [ id ] }
						onAction={ onAction }
					/>
				</div>
			) : (
				<p className="bulkcat-cat-readonly">
					{ __(
						'You can browse categories and their products, but changing them needs permission to manage product categories.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ node.default && (
				<p className="bulkcat-cat-pane__hint">
					{ __(
						'This is the shop’s default category: products left without a category are put in it. WooCommerce never deletes it, so it cannot be deleted or merged; make another category the default first.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ canManage && edit?.url && (
				<p className="bulkcat-cat-pane__native">
					<ExternalLink href={ termEditUrl( edit, id ) }>
						{ __(
							'Open in WooCommerce',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</ExternalLink>
				</p>
			) }
			{ mode === 'fields' && canManage && edit?.url && (
				<AllFields
					key={ id }
					edit={ edit }
					id={ id }
					name={ node.name }
					onSaved={ () => tree.reload() }
					onClose={ () => onMode( 'view' ) }
				/>
			) }
			{ saved && mode !== 'edit' && (
				<Notice
					status="success"
					// Said aloud once already, by the editor that saved it.
					spokenMessage=""
					onRemove={ () => setSaved( '' ) }
					className="bulkcat-cat-pane__saved"
				>
					{ sprintf(
						/* translators: %s: a category name. */
						__(
							'Category "%s" saved.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						saved
					) }
				</Notice>
			) }
			{ mode === 'edit' && canManage ? (
				<div className="bulkcat-cat-pane__card">
					<CategoryManager
						key={ id }
						tree={ tree }
						categoryId={ id }
						canManage={ canManage }
						onSaved={ ( item ) => {
							setSaved( item?.name || node.name );
							onMode( 'view' );
						} }
						onCancel={ () => onMode( 'view' ) }
					/>
				</div>
			) : (
				<dl className="bulkcat-cat-pane__facts">
					{ facts.map( ( fact ) => (
						<div
							key={ fact.key }
							className="bulkcat-cat-pane__fact"
						>
							<dt>{ fact.term }</dt>
							<dd>{ fact.value }</dd>
						</div>
					) ) }
				</dl>
			) }
			<CategoryProducts
				id={ id }
				name={ node.name }
				registry={ registry }
				navigate={ navigate }
				revision={ tree.nodes }
			/>
		</section>
	);
}
