/**
 * The right-hand pane of the Categories tab while several categories are
 * chosen (SPEC §4.7): how many, which (with their paths and counts, each
 * removable), and the actions that apply to all of them: Move to…, their
 * products in the product grid, and any registered action offered for many.
 */
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { GridLink } from './category-products';
import { joinPath, pathNames } from './tree-model';
import { categoryActions } from './workspace-actions';
import { catalogueParams } from './workspace-model';

/**
 * The pane.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.tree      useCategoryTree() result.
 * @param {number[]} props.ids       The chosen categories, in the order chosen.
 * @param {boolean}  props.canManage Whether the user may change categories.
 * @param {Function} props.navigate  The shell's navigate( panel, params ), or null.
 * @param {Function} props.onRemove  Called with an id to take it out of the choice.
 * @param {Function} props.onClear   Called to clear the choice.
 * @param {Function} props.onMove    Open Move to… for the chosen categories.
 * @param {Function} props.onAction  Open a registered action's dialog: ( action, ids ).
 * @return {Element} The pane.
 */
export default function SelectionPane( {
	tree,
	ids,
	canManage,
	navigate,
	onRemove,
	onClear,
	onMove,
	onAction,
} ) {
	const heading = sprintf(
		/* translators: %d: how many categories are chosen. */
		_n(
			'%d category selected',
			'%d categories selected',
			ids.length,
			'bulk-catalogue-manager-for-woocommerce'
		),
		ids.length
	);
	const extra = categoryActions( ids.length, { ids, tree } );
	return (
		<section
			className="bulkcat-cat-pane is-selection"
			aria-labelledby="bulkcat-cat-selection-title"
		>
			<div className="bulkcat-cat-pane__head">
				<h2
					id="bulkcat-cat-selection-title"
					className="bulkcat-cat-pane__title"
				>
					{ heading }
				</h2>
				<Button variant="tertiary" size="compact" onClick={ onClear }>
					{ __(
						'Clear selection',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
			</div>
			<div
				className="bulkcat-cat-pane__actions"
				role="group"
				aria-label={ __(
					'Actions for the selected categories',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				{ canManage && (
					<Button
						variant="secondary"
						size="compact"
						onClick={ onMove }
					>
						{ __(
							'Move to…',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				) }
				{ canManage &&
					extra.map( ( action ) => (
						<Button
							key={ action.key }
							variant="secondary"
							size="compact"
							isDestructive={ !! action.isDestructive }
							onClick={ () => onAction( action, ids ) }
						>
							{ action.label }
						</Button>
					) ) }
				<GridLink
					params={ catalogueParams( ids ) }
					navigate={ navigate }
				>
					{ __(
						'Show their products in the product grid',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</GridLink>
			</div>
			<p className="bulkcat-cat-pane__hint">
				{ __(
					'Click a category to choose only it. Ctrl or Cmd click, or its checkbox, adds or removes one; Shift click takes every category in between.',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</p>
			<ul className="bulkcat-cat-chosen">
				{ ids.map( ( id ) => {
					const entry = tree.byId.get( id );
					if ( ! entry ) {
						return null;
					}
					return (
						<li key={ id } className="bulkcat-cat-chosen__item">
							<span className="bulkcat-cat-chosen__path">
								{ joinPath( pathNames( tree.byId, id ) ) }
							</span>
							{ entry.node.default && (
								<span className="bulkcat-cat-default">
									{ __(
										'Default',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</span>
							) }
							<span className="bulkcat-cat-chosen__count">
								{ sprintf(
									/* translators: %s: how many products a category holds with its subcategories. */
									_n(
										'%s product',
										'%s products',
										entry.node.countWithDescendants || 0,
										'bulk-catalogue-manager-for-woocommerce'
									),
									(
										entry.node.countWithDescendants || 0
									).toLocaleString()
								) }
							</span>
							<Button
								variant="tertiary"
								size="small"
								onClick={ () => onRemove( id ) }
								label={ sprintf(
									/* translators: %s: a category name. */
									__(
										'Remove %s from the selection',
										'bulk-catalogue-manager-for-woocommerce'
									),
									entry.node.name
								) }
								showTooltip
							>
								<span aria-hidden="true">×</span>
							</Button>
						</li>
					);
				} ) }
			</ul>
		</section>
	);
}
