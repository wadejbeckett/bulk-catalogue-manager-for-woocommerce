/**
 * The category menu's own-category actions beyond rename and move (tickets
 * 23 and 24): Make default, Delete… and Merge into…. useCategoryActions()
 * gives the tree its extra menu items and the panel the dialogs they open,
 * so the panel's layout stays its own. Delete… and Merge into… act on every
 * chosen category when the one whose menu is open is among several chosen,
 * else on that one; the default category offers neither, as WooCommerce
 * never deletes it.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { announce } from './announce';
import DeleteDialog from './delete-dialog';
import { makeDefaultCategory } from './manage-requests';
import { valueIds } from './tree-model';
import './actions.scss';

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
		const chosen = valueIds( value ).filter(
			( each ) =>
				tree.byId.get( each ) && ! tree.byId.get( each ).node.default
		);
		return chosen.length > 1 && chosen.includes( id ) ? chosen : [ id ];
	};

	const removed = ( answer, into ) => {
		tree.reload();
		const count = answer?.deleted?.length || 0;
		announce(
			into
				? sprintf(
						/* translators: 1: how many categories, such as "2"; 2: a category path, such as "Kitchen › Pots". */
						_n(
							'%1$s category merged into %2$s.',
							'%1$s categories merged into %2$s.',
							count,
							'bulk-catalogue-manager-for-woocommerce'
						),
						count.toLocaleString(),
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
						count.toLocaleString()
					)
		);
	};

	const makeDefault = async ( id ) => {
		const name = tree.byId.get( id )?.node.name || '';
		setBusy( true );
		try {
			await makeDefaultCategory( id );
			tree.reload();
			announce(
				sprintf(
					/* translators: %s: a category name. */
					__(
						'%s is now the default category. Products left without a category are put in it.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					name
				)
			);
		} catch ( error ) {
			onFail?.(
				error,
				__(
					'The default category could not be changed. Try again in a moment.',
					'bulk-catalogue-manager-for-woocommerce'
				)
			);
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
				run: () => makeDefault( id ),
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
