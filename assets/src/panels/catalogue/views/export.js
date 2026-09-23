/**
 * The Export control (SPEC §4.1, §10; T53): downloads the current view as
 * CSV through GET /bcm/v1/export with the active filters, search, sort and
 * mode (the same arguments the grid's GET /products carries, paging aside)
 * and `columns[]`, the visible columns in the owner's current order: the
 * product name, then each visible column. The thumbnail is left out; it is a
 * picture, not a value a spreadsheet can use.
 *
 * How the file is fetched. The browser downloads the CSV itself, streamed
 * from the server, so a large export never sits in the page's memory. It
 * does so by loading the export URL in a hidden frame of this page, so the
 * screen stays usable and nothing is navigated away. A frame cannot send
 * the X-WP-Nonce header apiFetch uses, so the current `wp_rest` nonce goes
 * in the `_wpnonce` query argument instead: core's rest_cookie_check_errors()
 * reads `$_REQUEST['_wpnonce']` before the header (checked in WordPress 7.1,
 * wp-includes/rest-api.php), and without a valid nonce core treats the
 * cookie request as signed out. The nonce is the one apiFetch's nonce
 * middleware holds, which Heartbeat keeps fresh (../../../nonce.js); it
 * falls back to the one the page was given.
 *
 * Busy and failure. The control is busy from the click until the frame
 * reports a page (a refusal, which the server sends as JSON before any CSV)
 * or, since browsers report no event when a download starts, until
 * SETTLE_MS has passed. A refusal is shown as a §4.8 notice with its plain
 * sentence and a Show details line; a frame that cannot be read is treated
 * as the download having started.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { routeUrl } from '../../../api';
import { readSettings } from '../../../settings';
import { descriptorsOf, rolesOf } from '../fields';
import { ErrorNotice } from '../states';

/** How long the control stays busy when the browser reports nothing, in ms. */
export const SETTLE_MS = 4000;

/** GET /products arguments the export does not take: it pages itself and names its own columns. */
const NOT_EXPORTED = [ 'page', 'per_page', 'fields' ];

/**
 * The columns to export, in order: the title field, then the visible
 * columns, each a registry field once; the image field is left out.
 *
 * @param {Object}        view        DataViews view.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Array<string>} Field keys.
 */
export function exportColumns( view, descriptors ) {
	const known = new Map(
		descriptors.map( ( field ) => [ field.key, field ] )
	);
	const title = view.titleField || rolesOf( descriptors ).title;
	const out = [];
	[ title, ...( view.fields || [] ) ].forEach( ( key ) => {
		const field = known.get( key );
		if ( field && field.type !== 'image' && ! out.includes( key ) ) {
			out.push( key );
		}
	} );
	return out;
}

/**
 * The GET /export arguments for the screen: the grid's GET /products
 * arguments without paging and fields, the view's mode when the query does
 * not already carry one, and `columns`.
 *
 * @param {Object}       query    The grid's GET /products arguments (useCatalogue().query).
 * @param {Object}       view     DataViews view.
 * @param {Object|Array} registry GET /fields answer, or its descriptors.
 * @return {Object} Arguments.
 */
export function exportArgs( query, view, registry ) {
	const args = { ...( query || {} ) };
	NOT_EXPORTED.forEach( ( key ) => delete args[ key ] );
	if ( view.mode && ! args.mode ) {
		args.mode = view.mode;
	}
	args.columns = exportColumns( view, descriptorsOf( registry ) );
	return args;
}

/**
 * The current wp_rest nonce: apiFetch's middleware's, else the page's.
 *
 * @return {string} Nonce.
 */
export function currentNonce() {
	return apiFetch.nonceMiddleware?.nonce || readSettings().nonce || '';
}

/**
 * The export URL, nonce included.
 *
 * @param {Object} args  exportArgs().
 * @param {string} nonce wp_rest nonce.
 * @return {string} URL.
 */
export function exportUrl( args, nonce ) {
	return routeUrl( 'export', nonce ? { ...args, _wpnonce: nonce } : args );
}

/**
 * The refusal a frame shows, when it shows one: core and the plugin answer
 * a refused export as JSON `{ code, message }`.
 *
 * @param {HTMLIFrameElement} frame Frame.
 * @return {Object|null} An Error with `code`, or null.
 */
export function refusalIn( frame ) {
	let text = '';
	try {
		text = frame.contentDocument?.body?.textContent || '';
	} catch {
		return null;
	}
	try {
		const body = JSON.parse( text );
		if ( body && typeof body.code === 'string' ) {
			return Object.assign( new Error( String( body.message || '' ) ), {
				code: body.code,
			} );
		}
	} catch {
		// Not JSON: a page that is not a refusal.
	}
	return null;
}

/**
 * Load a URL in a hidden frame: the browser downloads an attachment, and a
 * refusal page is read back.
 *
 * @param {string}   url              Export URL.
 * @param {Object}   handlers         Handlers.
 * @param {Function} handlers.onDone  The download started (or nothing was reported).
 * @param {Function} handlers.onError Called with the refusal.
 * @param {Document} handlers.doc     Document to add the frame to.
 * @param {number}   handlers.settle  Busy time without a report, ms.
 * @return {Function} Cleanup: stops waiting and removes the frame.
 */
export function download(
	url,
	{ onDone, onError, doc = document, settle = SETTLE_MS }
) {
	const frame = doc.createElement( 'iframe' );
	frame.hidden = true;
	frame.setAttribute( 'aria-hidden', 'true' );
	frame.tabIndex = -1;
	frame.className = 'bcm-export__frame';
	let finished = false;
	let timer = null;
	const finish = ( refusal ) => {
		if ( finished ) {
			return;
		}
		finished = true;
		clearTimeout( timer );
		if ( refusal ) {
			onError( refusal );
		} else {
			onDone();
		}
	};
	timer = setTimeout( () => finish( null ), settle );
	frame.addEventListener( 'load', () => finish( refusalIn( frame ) ) );
	frame.src = url;
	doc.body.appendChild( frame );
	return () => {
		finished = true;
		clearTimeout( timer );
		frame.remove();
	};
}

/**
 * The toolbar's Export control.
 *
 * @param {Object}       props          Props.
 * @param {Object}       props.query    The grid's GET /products arguments.
 * @param {Object}       props.view     DataViews view.
 * @param {Object|Array} props.registry GET /fields answer.
 * @param {Function}     props.getNonce The nonce to send (tests).
 * @param {Function}     props.start    download() (tests).
 * @return {Element} Control.
 */
export default function ExportControl( {
	query,
	view,
	registry,
	getNonce = currentNonce,
	start = download,
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const cleanup = useRef( null );

	useEffect(
		() => () => {
			if ( cleanup.current ) {
				cleanup.current();
			}
		},
		[]
	);

	const run = () => {
		if ( cleanup.current ) {
			cleanup.current();
		}
		setError( null );
		setBusy( true );
		cleanup.current = start(
			exportUrl( exportArgs( query, view, registry ), getNonce() ),
			{
				onDone: () => setBusy( false ),
				onError: ( refusal ) => {
					setBusy( false );
					setError( refusal );
				},
			}
		);
	};

	const plain =
		error?.code?.startsWith( 'bcm_' ) && error.message
			? error.message
			: __(
					'The CSV file could not be made. Reload the page and try again.',
					'bulk-catalogue-manager-for-woocommerce'
				);

	return (
		<div className="bcm-export">
			<Button
				variant="secondary"
				className="bcm-export__button"
				onClick={ run }
				isBusy={ busy }
				disabled={ busy }
				accessibleWhenDisabled
			>
				{ busy
					? __(
							'Preparing CSV…',
							'bulk-catalogue-manager-for-woocommerce'
						)
					: __(
							'Export CSV',
							'bulk-catalogue-manager-for-woocommerce'
						) }
			</Button>
			{ error && (
				<ErrorNotice
					message={ plain }
					error={ error }
					onRetry={ run }
				/>
			) }
		</div>
	);
}
