/**
 * Further actions on chosen categories (SPEC §4.7), shown after the
 * workspace's own (Rename, Move to…, Add subcategory, Edit details) in the
 * details pane for one category and in the selection pane for several. This
 * is the slot for actions that live in their own modules, such as deleting a
 * category or merging it into another: each module registers its action
 * once, when it is imported, and the workspace draws a button for it and
 * opens its dialog.
 *
 * An action is
 * `{ key, label, isDestructive?, many?, available?, Dialog | run, failure? }`:
 * - `label`: the button's text, already translated.
 * - `menu`: `{ key, label }`, offer it inside that group's menu ("Sample
 *   picks…") with the other actions of the same menu, not as a button of its
 *   own (an add-on's actions, 1.4.1).
 * - `isDestructive`: draw it as a destructive action.
 * - `many`: also offer it for several chosen categories (default one only).
 * - `available( ids, tree )`: whether it applies to these categories (for
 *   example, not to the shop's default category); offered when absent.
 * - `Dialog`: a component given `{ ids, tree, onClose, onDone }`; `ids` are
 *   the chosen category ids, `tree` the shared useCategoryTree() result,
 *   `onClose()` closes it and `onDone( keptIds )` closes it after a change,
 *   reloads the tree and keeps only `keptIds` chosen.
 * - `run( { ids, tree } )`: instead of a Dialog, an action that happens at
 *   once; it resolves with the sentence to announce, after which the tree is
 *   read again, or rejects with the request's error, shown with `failure`
 *   (the fallback sentence) when the error has no message of its own.
 *
 * The tree row's menu offers the same actions as the panes: those available
 * for the row's category, acting on every chosen category when the row is
 * one of several chosen and the action is offered for many.
 */

const registered = [];

/**
 * Add an action to the workspace. A second registration under the same key
 * replaces the first.
 *
 * @param {Object} action The action.
 */
export function registerCategoryAction( action ) {
	if (
		! action?.key ||
		( typeof action.Dialog !== 'function' &&
			typeof action.run !== 'function' )
	) {
		return;
	}
	const at = registered.findIndex( ( each ) => each.key === action.key );
	if ( at === -1 ) {
		registered.push( action );
	} else {
		registered[ at ] = action;
	}
}

/**
 * The registered actions that apply to this many chosen categories and, when
 * given, to these categories.
 *
 * @param {number}        count       How many categories are chosen.
 * @param {Object}        chosen      Optional.
 * @param {Array<number>} chosen.ids  The chosen category ids.
 * @param {Object}        chosen.tree useCategoryTree() result.
 * @return {Array<Object>} Actions, in the order registered.
 */
export function categoryActions( count, { ids, tree } = {} ) {
	return registered.filter(
		( action ) =>
			( count === 1 || action.many ) &&
			( ! ids ||
				! tree ||
				typeof action.available !== 'function' ||
				action.available( ids, tree ) )
	);
}

/**
 * Remove every registered action whose key starts with a prefix (an
 * add-on's actions, registered again when the registry changes).
 *
 * @param {string} prefix Key prefix.
 */
export function unregisterCategoryActions( prefix ) {
	for ( let at = registered.length - 1; at >= 0; at-- ) {
		if ( String( registered[ at ].key ).startsWith( prefix ) ) {
			registered.splice( at, 1 );
		}
	}
}

/**
 * Actions as the workspace offers them: each without a `menu` on its own,
 * and those sharing a menu together, in the place of the first of them.
 *
 * @param {Array<Object>} actions Registered actions, in order.
 * @return {Array<Object>} `{ action }` or `{ menu: { key, label }, actions }`.
 */
export function offeredCategoryActions( actions ) {
	const offered = [];
	actions.forEach( ( action ) => {
		if ( ! action.menu?.key ) {
			offered.push( { action } );
			return;
		}
		const menu = offered.find(
			( each ) => each.menu?.key === action.menu.key
		);
		if ( menu ) {
			menu.actions.push( action );
		} else {
			offered.push( { menu: action.menu, actions: [ action ] } );
		}
	} );
	return offered;
}
