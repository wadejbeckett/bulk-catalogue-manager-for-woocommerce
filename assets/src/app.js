/**
 * The screen: the page header (the lede, the section switch, Export CSV and
 * Settings, as the prototype's header has them) and the current panel.
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { panelHref, usePanel } from './router';
import { ShellContext } from './shell';
import { watch } from './a11y-patches';
import Catalogue from './panels/catalogue';
import History from './panels/history';
import Categories from './panels/categories';
import Settings from './panels/settings';

const COMPONENTS = {
	catalogue: Catalogue,
	history: History,
	categories: Categories,
	settings: Settings,
};

/**
 * A plain click, which the screen handles itself; a modified click (a new tab
 * or window) is left to the browser.
 *
 * @param {Event} event Click.
 * @return {boolean} Whether to route it here.
 */
function plainClick( event ) {
	return ! (
		event.metaKey ||
		event.ctrlKey ||
		event.shiftKey ||
		event.altKey ||
		event.button !== 0
	);
}

/**
 * A link to another panel of this screen.
 *
 * @param {Object}   props           Props.
 * @param {string}   props.panel     Panel key.
 * @param {boolean}  props.current   Whether it is the panel on screen.
 * @param {Function} props.navigate  Move to a panel.
 * @param {string}   props.className Class.
 * @param {Element}  props.children  Label.
 * @return {Element} Link.
 */
function PanelLink( { panel, current, navigate, className, children } ) {
	return (
		<a
			href={ panelHref( panel, window.location.href ) }
			className={ className + ( current ? ' is-current' : '' ) }
			aria-current={ current ? 'page' : undefined }
			onClick={ ( event ) => {
				if ( plainClick( event ) ) {
					event.preventDefault();
					navigate( panel );
				}
			} }
		>
			{ children }
		</a>
	);
}

/**
 * The page header below the admin's own heading.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.current  Panel on screen.
 * @param {Function} props.navigate Move to a panel.
 * @param {Function} props.onBulk   Open the bulk change panel.
 * @param {Function} props.onSlot   Receives the Export CSV slot element.
 * @return {Element} Header.
 */
function Header( { current, navigate, onBulk, onSlot } ) {
	return (
		<div className="bcm-head">
			<p className="bcm-head__lede">
				{ __(
					'Change prices, stock, categories and what shows in your shop — for one product or a thousand.',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</p>
			<div className="bcm-head__acts">
				<nav
					className="bcm-head__nav"
					aria-label={ __(
						'Bulk Catalogue Manager sections',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					<PanelLink
						panel="catalogue"
						current={ current === 'catalogue' }
						navigate={ navigate }
						className="bcm-head__seg"
					>
						{ __(
							'Products',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</PanelLink>
					<button
						type="button"
						className="bcm-head__seg"
						onClick={ onBulk }
					>
						{ __(
							'Bulk change',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</button>
					<PanelLink
						panel="history"
						current={ current === 'history' }
						navigate={ navigate }
						className="bcm-head__seg"
					>
						{ __(
							'History and undo',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</PanelLink>
				</nav>
				<span className="bcm-head__slot" ref={ onSlot } />
				<PanelLink
					panel="categories"
					current={ current === 'categories' }
					navigate={ navigate }
					className="bcm-head__link"
				>
					{ __(
						'Categories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</PanelLink>
				<PanelLink
					panel="settings"
					current={ current === 'settings' }
					navigate={ navigate }
					className="bcm-head__link"
				>
					{ __(
						'Settings',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</PanelLink>
			</div>
		</div>
	);
}

export default function App( { settings } ) {
	const [ current, navigate ] = usePanel();
	const [ exportSlot, setExportSlot ] = useState( null );
	const [ bulkRequested, setBulkRequested ] = useState( false );
	const Component = COMPONENTS[ current ] || Catalogue;
	const onBulk = useCallback( () => {
		setBulkRequested( true );
		navigate( 'catalogue' );
	}, [ navigate ] );
	const consumeBulk = useCallback( () => setBulkRequested( false ), [] );
	const go = useRef( navigate );
	go.current = navigate;
	const navigateTo = useCallback(
		( panel, params ) => go.current( panel, params ),
		[]
	);
	const shell = useMemo(
		() => ( {
			exportSlot,
			bulkRequested,
			consumeBulk,
			navigate: navigateTo,
		} ),
		[ exportSlot, bulkRequested, consumeBulk, navigateTo ]
	);
	// T75: name the components' unnamed controls while the screen is open.
	useEffect( () => watch( document ), [] );
	// Moving between panels puts focus on the new panel, so a keyboard or
	// screen reader user lands where the content changed (SPEC §14).
	const panelRef = useRef( null );
	const first = useRef( true );
	useEffect( () => {
		if ( first.current ) {
			first.current = false;
			return;
		}
		panelRef.current?.focus( { preventScroll: true } );
	}, [ current ] );

	return (
		<ShellContext.Provider value={ shell }>
			<div className="bcm-app">
				<Header
					current={ current }
					navigate={ navigate }
					onBulk={ onBulk }
					onSlot={ setExportSlot }
				/>
				<div
					className="bcm-app__panel"
					ref={ panelRef }
					tabIndex={ -1 }
					aria-label={ __(
						'Bulk Catalogue Manager',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					role="region"
				>
					<Component settings={ settings } />
				</div>
			</div>
		</ShellContext.Provider>
	);
}
