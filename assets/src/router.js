/**
 * Client-side routing between the screen's panels, kept in the page URL's
 * `panel` argument so a panel can be bookmarked, reloaded and reached with
 * the browser's back and forward buttons.
 */
import { useEffect, useState } from '@wordpress/element';

export const PANELS = [ 'catalogue', 'history', 'categories', 'settings' ];

export const DEFAULT_PANEL = 'catalogue';

/**
 * The panel a URL names, or the default for a missing or unknown one.
 *
 * @param {string} search A location's search string.
 * @return {string} Panel key.
 */
export function panelFrom( search ) {
	const panel = new URLSearchParams( search ).get( 'panel' );
	return PANELS.includes( panel ) ? panel : DEFAULT_PANEL;
}

/**
 * The same page's URL showing another panel.
 *
 * @param {string} panel Panel key.
 * @param {string} href  Current URL.
 * @return {string} URL.
 */
export function panelHref( panel, href ) {
	const url = new URL( href );
	// Arguments one panel reads once (History's product, batch and undo)
	// do not follow the owner to another panel (T75).
	[ 'product', 'batch', 'undo' ].forEach( ( key ) =>
		url.searchParams.delete( key )
	);
	url.hash = '';
	if ( panel === DEFAULT_PANEL ) {
		url.searchParams.delete( 'panel' );
	} else {
		url.searchParams.set( 'panel', panel );
	}
	return url.toString();
}

/**
 * The current panel and a function moving to another.
 *
 * @return {[string, Function]} Panel key and navigate( panel ).
 */
export function usePanel() {
	const [ panel, setPanel ] = useState( () =>
		panelFrom( window.location.search )
	);
	useEffect( () => {
		const onPop = () => setPanel( panelFrom( window.location.search ) );
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );
	// `params` are further URL arguments for the panel, such as History's
	// `batch` and `undo` (T75).
	const navigate = ( next, params = null ) => {
		if ( next === panel && ! params ) {
			return;
		}
		const url = new URL( panelHref( next, window.location.href ) );
		Object.entries( params || {} ).forEach( ( [ key, value ] ) =>
			url.searchParams.set( key, String( value ) )
		);
		window.history.pushState( { panel: next }, '', url.toString() );
		setPanel( next );
	};
	return [ panel, navigate ];
}
