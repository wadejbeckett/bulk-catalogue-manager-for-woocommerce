/**
 * An add-on's actions on categories in the Categories workspace (contract
 * version 2, category target): each action `/fields` advertises with
 * `target: product_cat` is registered in the workspace's action slot
 * (workspace-actions.js), for one category and for several, so the details
 * pane, the selection pane and the tree row's menu offer it alike, inside
 * one menu per add-on group (1.4.1). With no such add-on nothing is
 * registered and the workspace is as before.
 */
import { createElement } from '@wordpress/element';

import { menuOf } from '../../../components/provider-menu';
import {
	registerCategoryAction,
	unregisterCategoryActions,
} from '../workspace-actions';
import ProviderActionDialog from './dialog';
import { categoryActionsOf } from './model';
import './style.scss';

/** The slot keys of add-on actions start with this. */
export const KEY_PREFIX = 'provider:';

/**
 * Register the registry's category actions in the workspace slot, replacing
 * whatever an earlier registry registered.
 *
 * @param {Object|null} registry The `/fields` payload.
 * @param {Object}      settings The screen's localised settings.
 * @return {number} How many actions are offered.
 */
export function syncProviderActions( registry, settings ) {
	unregisterCategoryActions( KEY_PREFIX );
	const actions = categoryActionsOf( registry );
	actions.forEach( ( action ) => {
		registerCategoryAction( {
			key: KEY_PREFIX + action.id,
			label: action.label,
			// 1.4.1: offered in its group's menu, never as a button of its own.
			menu: menuOf( registry, action ),
			many: true,
			Dialog: ( props ) =>
				createElement( ProviderActionDialog, {
					...props,
					action,
					registry,
					settings,
				} ),
		} );
	} );
	return actions.length;
}
