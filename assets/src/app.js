/**
 * The screen: a tab bar in the admin's own style and the current panel.
 */
import { __ } from '@wordpress/i18n';

import { panelHref, usePanel } from './router';
import Catalogue from './panels/catalogue';
import History from './panels/history';
import Categories from './panels/categories';
import Settings from './panels/settings';

/**
 * The panels in tab order, each with its label and component.
 *
 * @return {Array<Object>} Panels.
 */
function panels() {
	return [
		{
			key: 'catalogue',
			label: __( 'Catalogue', 'bulk-catalogue-manager-for-woocommerce' ),
			Component: Catalogue,
		},
		{
			key: 'history',
			label: __( 'History', 'bulk-catalogue-manager-for-woocommerce' ),
			Component: History,
		},
		{
			key: 'categories',
			label: __( 'Categories', 'bulk-catalogue-manager-for-woocommerce' ),
			Component: Categories,
		},
		{
			key: 'settings',
			label: __( 'Settings', 'bulk-catalogue-manager-for-woocommerce' ),
			Component: Settings,
		},
	];
}

export default function App( { settings } ) {
	const [ current, navigate ] = usePanel();
	const all = panels();
	const active = all.find( ( panel ) => panel.key === current ) || all[ 0 ];
	const { Component } = active;

	return (
		<div className="bcm-app">
			<nav
				className="nav-tab-wrapper bcm-app__tabs"
				aria-label={ __(
					'Bulk Catalogue Manager sections',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				{ all.map( ( panel ) => (
					<a
						key={ panel.key }
						href={ panelHref( panel.key, window.location.href ) }
						className={
							'nav-tab' +
							( panel.key === active.key
								? ' nav-tab-active'
								: '' )
						}
						aria-current={
							panel.key === active.key ? 'page' : undefined
						}
						onClick={ ( event ) => {
							if (
								event.metaKey ||
								event.ctrlKey ||
								event.shiftKey ||
								event.button !== 0
							) {
								return;
							}
							event.preventDefault();
							navigate( panel.key );
						} }
					>
						{ panel.label }
					</a>
				) ) }
			</nav>
			<div className="bcm-app__panel">
				<Component settings={ settings } />
			</div>
		</div>
	);
}
