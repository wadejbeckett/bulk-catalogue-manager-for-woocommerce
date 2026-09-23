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
	const navigate = ( next ) => {
		if ( next === panel ) {
			return;
		}
		window.history.pushState(
			{ panel: next },
			'',
			panelHref( next, window.location.href )
		);
		setPanel( next );
	};
	return [ panel, navigate ];
}
