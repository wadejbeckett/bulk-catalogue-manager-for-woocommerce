/**
 * The category menu's own-category actions beyond rename and move (tickets
 * 23 and 24): Make default. useCategoryActions() gives the tree its extra
 * menu items and the panel the dialogs they open, so the panel's layout
 * stays its own.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from './announce';
import { makeDefaultCategory } from './manage-requests';
import './actions.scss';

/**
 * Extra menu items and their dialogs for the category tree.
 *
 * @param {Object}   props        Props.
 * @param {Object}   props.tree   useCategoryTree() result.
 * @param {Function} props.onFail Called with ( error, fallback sentence ) when a request fails.
 * @return {{ menuItems: Function, dialogs: Element|null, busy: boolean }} Items for a category id, and the dialogs to render.
 */
export default function useCategoryActions( { tree, onFail } ) {
	const [ busy, setBusy ] = useState( false );

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
		}
		return items;
	};

	return { menuItems, dialogs: null, busy };
}
