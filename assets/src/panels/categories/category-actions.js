/**
 * The category menu's own-category actions beyond rename and move (tickets
 * 23 and 24): Make default, Delete… and Merge into…. useCategoryActions()
 * gives the narrow panel's tree its extra menu items and the dialogs they
 * open, so the panel's layout stays its own; the full-width workspace gets
 * the same three through its action slot (workspace-actions.js), registered
 * below. Delete… and Merge into… act on every chosen category when the one
 * whose menu is open is among several chosen, else on that one; the default
 * category offers neither, as WooCommerce never deletes it.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { announce } from './announce';
import DeleteDialog from './delete-dialog';
import { makeDefaultCategory } from './manage-requests';
import { valueIds } from './tree-model';
import { registerCategoryAction } from './workspace-actions';
import './actions/style.scss';
import { formatNumber } from '../bulk/words';

/**
 * Whether a category is the shop's default.
 *
 * @param {Object} tree useCategoryTree() result.
 * @param {number} id   Category id.
 * @return {boolean} True for the default category.
 */
const isDefault = ( tree, id ) => !! tree.byId.get( id )?.node.default;

/**
 * The categories that may be deleted or merged: those that exist and are not
 * the default.
 *
 * @param {Object}        tree useCategoryTree() result.
 * @param {Array<number>} ids  Category ids.
 * @return {Array<number>} The ids left.
 */
export function removable( tree, ids ) {
	return ids.filter(
		( id ) => tree.byId.get( id ) && ! isDefault( tree, id )
	);
}

/**
 * The sentence announced after a delete or merge.
 *
 * @param {Object}      answer The route's answer ({ deleted }).
 * @param {Object|null} into   The merge target ({ id, path }), or null.
 * @return {string} The sentence.
 */
export function removedSentence( answer, into ) {
	const count = answer?.deleted?.length || 0;
	return into
		? sprintf(
				/* translators: 1: how many categories, such as "2"; 2: a category path, such as "Kitchen › Pots". */
				_n(
					'%1$s category merged into %2$s.',
					'%1$s categories merged into %2$s.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( count ),
				into.path || ''
			)
		: sprintf(
				/* translators: %s: how many categories, such as "2". */
				_n(
					'%s category deleted.',
					'%s categories deleted.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( count )
			);
}

/**
 * Make a category the default and give the sentence to announce.
 *
 * @param {Object} tree useCategoryTree() result.
 * @param {number} id   Category id.
 * @return {Promise<string>} The sentence.
 */
export async function makeDefault( tree, id ) {
	const name = tree.byId.get( id )?.node.name || '';
	await makeDefaultCategory( id );
	return sprintf(
		/* translators: %s: a category name. */
		__(
			'%s is now the default category. Products left without a category are put in it.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		name
	);
}

/**
 * The fallback sentence when Make default fails.
 *
 * @return {string} The sentence.
 */
const defaultFailure = () =>
	__(
		'The default category could not be changed. Try again in a moment.',
		'bulk-catalogue-manager-for-woocommerce'
	);

/**
 * Delete… or Merge into… as the workspace's dialog: the default category is
 * left out (and the dialog says so), the result is announced, and after a
 * merge the target stays chosen.
 *
 * @param {Object}        props         Props.
 * @param {Array<number>} props.ids     The chosen categories.
 * @param {Object}        props.tree    useCategoryTree() result.
 * @param {boolean}       props.merge   Merge into… rather than Delete….
 * @param {Function}      props.onClose Close it.
 * @param {Function}      props.onDone  Called with the ids to keep chosen.
 * @return {Element} The dialog.
 */
function WorkspaceRemoveDialog( { ids, tree, merge, onClose, onDone } ) {
	// Taken once, so a tree read while it is open does not check again.
	const [ chosen ] = useState( () => removable( tree, ids ) );
	const [ left ] = useState( () =>
		ids.find( ( id ) => isDefault( tree, id ) )
	);
	return (
		<DeleteDialog
			tree={ tree }
			ids={ chosen }
			merge={ merge }
			notice={
				left
					? sprintf(
							/* translators: %s: the default category's name, such as "Uncategorized". */
							__(
								'The default category, %s, is left out: WooCommerce never deletes it.',
								'bulk-catalogue-manager-for-woocommerce'
							),
							tree.byId.get( left ).node.name
						)
					: null
			}
			onDone={ ( answer, into ) => {
				announce( removedSentence( answer, into ) );
				onDone( into?.id ? [ into.id ] : [] );
			} }
			onClose={ onClose }
		/>
	);
}

/**
 * Delete… in the workspace.
 *
 * @param {Object} props The slot's dialog props.
 * @return {Element} The dialog.
 */
function DeleteAction( props ) {
	return <WorkspaceRemoveDialog { ...props } merge={ false } />;
}

/**
 * Merge into… in the workspace.
 *
 * @param {Object} props The slot's dialog props.
 * @return {Element} The dialog.
 */
function MergeAction( props ) {
	return <WorkspaceRemoveDialog { ...props } merge />;
}

registerCategoryAction( {
	key: 'default',
	label: __( 'Make default', 'bulk-catalogue-manager-for-woocommerce' ),
	available: ( ids, tree ) =>
		ids.length === 1 &&
		!! tree.byId.get( ids[ 0 ] ) &&
		! isDefault( tree, ids[ 0 ] ),
	run: ( { ids, tree } ) => makeDefault( tree, ids[ 0 ] ),
	failure: defaultFailure(),
} );

registerCategoryAction( {
	key: 'delete',
	label: __( 'Delete…', 'bulk-catalogue-manager-for-woocommerce' ),
	isDestructive: true,
	many: true,
	available: ( ids, tree ) => removable( tree, ids ).length > 0,
	Dialog: DeleteAction,
} );

registerCategoryAction( {
	key: 'merge',
	label: __( 'Merge into…', 'bulk-catalogue-manager-for-woocommerce' ),
	many: true,
	available: ( ids, tree ) => removable( tree, ids ).length > 0,
	Dialog: MergeAction,
} );

/**
 * Extra menu items and their dialogs for the category tree.
 *
 * @param {Object}   props        Props.
 * @param {Object}   props.tree   useCategoryTree() result.
 * @param {Object}   props.value  The panel's chosen categories ({ id, ids }) or null.
 * @param {Function} props.onFail Called with ( error, fallback sentence ) when a request fails.
 * @return {{ menuItems: Function, dialogs: Element|null, busy: boolean }} Items for a category id, and the dialogs to render.
 */
export default function useCategoryActions( { tree, value, onFail } ) {
	const [ busy, setBusy ] = useState( false );
	const [ removing, setRemoving ] = useState( null );

	const chosenWith = ( id ) => {
		const chosen = removable( tree, valueIds( value ) );
		return chosen.length > 1 && chosen.includes( id ) ? chosen : [ id ];
	};

	const removed = ( answer, into ) => {
		tree.reload();
		announce( removedSentence( answer, into ) );
	};

	const setDefault = async ( id ) => {
		setBusy( true );
		try {
			const sentence = await makeDefault( tree, id );
			tree.reload();
			announce( sentence );
		} catch ( error ) {
			onFail?.( error, defaultFailure() );
		} finally {
			setBusy( false );
		}
	};

	const menuItems = ( id ) => {
		const node = tree.byId.get( id )?.node;
		if ( ! node ) {
			return [];
		}
		const items = [];
		if ( ! node.default ) {
			items.push( {
				key: 'default',
				label: __(
					'Make default',
					'bulk-catalogue-manager-for-woocommerce'
				),
				run: () => setDefault( id ),
			} );
			items.push( {
				key: 'delete',
				label: __(
					'Delete…',
					'bulk-catalogue-manager-for-woocommerce'
				),
				run: () =>
					setRemoving( { ids: chosenWith( id ), merge: false } ),
			} );
			items.push( {
				key: 'merge',
				label: __(
					'Merge into…',
					'bulk-catalogue-manager-for-woocommerce'
				),
				run: () =>
					setRemoving( { ids: chosenWith( id ), merge: true } ),
			} );
		}
		return items;
	};

	const dialogs = removing ? (
		<DeleteDialog
			tree={ tree }
			ids={ removing.ids }
			merge={ removing.merge }
			onDone={ removed }
			onClose={ () => setRemoving( null ) }
		/>
	) : null;

	return { menuItems, dialogs, busy };
}
