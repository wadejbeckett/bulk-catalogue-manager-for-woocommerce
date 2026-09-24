/**
 * Entry: mounts the screen into its one mount element (src/Admin/Screen.php)
 * and does nothing at all on a page without it.
 */
import { createRoot } from '@wordpress/element';
import '@wordpress/components/build-style/style.css';
import '@wordpress/dataviews/build-style/style.css';

import App from './app';
import { configureApi } from './api';
import { readSettings } from './settings';
import './style.scss';

export const MOUNT_ID = 'bulkcat-app';

/**
 * Render the screen into #bulkcat-app.
 *
 * @param {Document} doc Document to mount in.
 * @return {Object|null} The React root, or null when there is no mount element.
 */
export function mount( doc = document ) {
	const element = doc.getElementById( MOUNT_ID );
	if ( ! element ) {
		return null;
	}
	const settings = readSettings( doc.defaultView || window );
	configureApi( settings );
	const root = createRoot( element );
	root.render( <App settings={ settings } /> );
	return root;
}

mount();
