/**
 * An add-on's actions grouped into menus (1.4.1), so a screen offering many
 * of them shows one menu per group instead of one button per action: the
 * action's own chooser group (docs/EXTENSIONS.md §5: a group id the add-on
 * declares starts with `x_<provider id>_`), or, for an action placed in a
 * native group, its add-on. Shared by the Categories workspace (details
 * pane, selection pane, tree row menu) and the product row menu.
 */
import {
	DropdownMenu,
	MenuGroup,
	MenuItem,
	NavigableMenu,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import './style.scss';

/**
 * The menu an add-on action belongs to.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {Object} action   The `/fields` action.
 * @return {{key: string, label: string}} The menu's key and its label.
 */
export function menuOf( registry, action ) {
	const provider = String( action?.provider || '' );
	const group = String( action?.group || '' );
	const own =
		provider && group.startsWith( `x_${ provider }_` )
			? ( registry?.groups || [] ).find( ( each ) => each.id === group )
			: null;
	if ( own?.label ) {
		return { key: `group-${ own.id }`, label: own.label };
	}
	const row = ( registry?.providers || [] ).find(
		( each ) => each.id === provider
	);
	return { key: `addon-${ provider }`, label: row?.label || provider };
}

/**
 * Add-on actions grouped into menus, each in the place of its first action.
 *
 * @param {Object}        registry The `/fields` payload.
 * @param {Array<Object>} actions  `/fields` actions, in the order to offer them.
 * @return {Array<Object>} `{ key, label, actions }`.
 */
export function providerMenus( registry, actions ) {
	const menus = [];
	( actions || [] ).forEach( ( action ) => {
		const { key, label } = menuOf( registry, action );
		let menu = menus.find( ( each ) => each.key === key );
		if ( ! menu ) {
			menu = { key, label, actions: [] };
			menus.push( menu );
		}
		menu.actions.push( action );
	} );
	return menus;
}

/**
 * A group menu's button text: "Customer access…".
 *
 * @param {string} label The group's label.
 * @return {string} Text.
 */
export function menuLabel( label ) {
	return sprintf(
		/* translators: %s: a group of an add-on's actions, such as "Sample picks"; the menu lists them. */
		__( '%s…', 'bulk-catalogue-manager-for-woocommerce' ),
		label
	);
}

/**
 * A group's button, opening a menu of its actions (a keyboard menu:
 * Arrow keys move, Escape closes and returns focus to the button).
 *
 * @param {Object}        props       Props.
 * @param {string}        props.label The group's label.
 * @param {Array<Object>} props.items `{ key, label, onClick }`.
 * @return {Element} The button.
 */
export function ProviderMenuButton( { label, items } ) {
	const text = menuLabel( label );
	return (
		<DropdownMenu
			className="bulkcat-provider-menu"
			icon={ null }
			text={ text }
			label={ text }
			toggleProps={ {
				variant: 'secondary',
				size: 'compact',
				showTooltip: false,
			} }
			popoverProps={ { placement: 'bottom-start' } }
		>
			{ ( { onClose } ) => (
				<MenuGroup>
					{ items.map( ( item ) => (
						<MenuItem
							key={ item.key }
							onClick={ () => {
								onClose();
								item.onClick();
							} }
						>
							{ item.label }
						</MenuItem>
					) ) }
				</MenuGroup>
			) }
		</DropdownMenu>
	);
}

/**
 * A group's actions as a menu inside a dialog (the product row menu, which
 * cannot hold a menu of its own): one item each, an action that cannot be
 * used disabled with its reason.
 *
 * @param {Object}        props          Props.
 * @param {string}        props.label    The group's label.
 * @param {Array<Object>} props.actions  `/fields` actions.
 * @param {Function}      props.onChoose Receives the chosen action.
 * @return {Element} The menu.
 */
export function ProviderMenuList( { label, actions, onChoose } ) {
	return (
		<NavigableMenu
			role="menu"
			aria-label={ label }
			className="bulkcat-provider-menu__list"
		>
			<MenuGroup>
				{ actions.map( ( action ) => {
					const usable = action.available !== false;
					return (
						<MenuItem
							key={ action.id }
							disabled={ ! usable }
							accessibleWhenDisabled
							info={
								usable
									? action.description || undefined
									: action.unavailableReason || undefined
							}
							onClick={ () => {
								if ( usable ) {
									onChoose( action );
								}
							} }
						>
							{ action.label }
						</MenuItem>
					);
				} ) }
			</MenuGroup>
		</NavigableMenu>
	);
}
