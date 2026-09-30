/**
 * The Export control (SPEC §4.1, §10; T53, T103). **Export CSV** opens the
 * export dialog (./export-dialog.js), where the rows are chosen (the exact
 * selection, or everything matching the current filters) and the identifier
 * switch set; the columns are the view's: the product name, then each
 * visible column in the owner's order. The thumbnail is left out; it is a
 * picture, not a value a spreadsheet can use.
 *
 * How the file is fetched. The browser downloads the CSV itself, streamed
 * from the server, so a large export never sits in the page's memory. The
 * dialog's choice is posted (`POST /bulkcat/v1/export`, ruling R9) by a form
 * whose target is a hidden frame of this page, so the screen stays usable
 * and nothing is navigated away. A form cannot send the X-WP-Nonce header
 * apiFetch uses, so the current `wp_rest` nonce goes in the body as
 * `_wpnonce`: core's rest_cookie_check_errors() reads `$_REQUEST['_wpnonce']`
 * before the header (checked in WordPress 7.1, wp-includes/rest-api.php),
 * and without a valid nonce core treats the cookie request as signed out.
 * The selection's token goes in the body too, never in the form's address,
 * the page's history or a log (EXACT-SELECTION). The nonce is the one
 * apiFetch's nonce middleware holds, which Heartbeat keeps fresh
 * (../../../nonce.js); it falls back to the one the page was given.
 *
 * History's exports still load a `GET /export` address in a hidden frame
 * (download() and exportUrl(), used by ../../history/export.js).
 *
 * Busy and failure. The control is busy from Download until the frame
 * reports a page (a refusal, which the server sends as JSON before any CSV)
 * or, since browsers report no event when a download starts, until
 * SETTLE_MS has passed. A refusal is shown as a §4.8 notice with its plain
 * sentence and a Show details line, and Try again opens the dialog again;
 * a frame that cannot be read is treated as the download having started.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { buildQueryString } from '@wordpress/url';

import { routeUrl } from '../../../api';
import { plainMessage } from '../../../errors';
import { readSettings } from '../../../settings';
import { descriptorsOf, rolesOf } from '../fields';
import { ErrorNotice } from '../states';
import ExportDialog from './export-dialog';

/** How long the control stays busy when the browser reports nothing, in ms. */
export const SETTLE_MS = 4000;

/** GET /products arguments the export does not take: it pages itself and names its own columns. */
const NOT_EXPORTED = [ 'page', 'per_page', 'fields' ];

/** Lists the form sends as one comma-separated field (ruling R7). */
const ONE_FIELD = [ 'columns', 'orderby', 'order' ];

/** A number for each hidden frame's name. */
let frames = 0;

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
 * a refused export as JSON `{ code, message }`; a page with markup (a
 * server's HTML error page) is a failure whose text is never shown. A
 * download leaves the frame empty, and a file shown in it is plain text.
 *
 * @param {HTMLIFrameElement} frame Frame.
 * @return {Object|null} An Error with `code`, or null.
 */
export function refusalIn( frame ) {
	let text = '';
	let page = false;
	try {
		const body = frame.contentDocument?.body;
		text = body?.textContent || '';
		page =
			typeof body?.querySelector === 'function' &&
			null !== body.querySelector( ':scope > :not(pre)' );
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
	return page
		? Object.assign( new Error( '' ), { code: 'export_error_page' } )
		: null;
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
	frame.className = 'bulkcat-export__frame';
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
 * The download form's fields, in order: the nonce, then the body as PHP
 * reads a form. The lists the server takes as one field (`columns`,
 * `orderby`, `order`) are joined with commas, so PHP's `max_input_vars`
 * cannot cut them (ruling R7); switches are `1` or `0`; the filter's nested
 * conditions are written as the grid's GET wrote them
 * (`category_ids[value][0]`). A filter export says whether the whole
 * catalogue was confirmed; a selection export sends its token alone.
 *
 * @param {Object}  body               rowsBody(): the selection's token or the filter, the columns and the switch.
 * @param {Object}  options            Options.
 * @param {string}  options.nonce      wp_rest nonce.
 * @param {boolean} options.confirmAll Whether the whole catalogue was confirmed.
 * @return {Array<Array<string>>} `[ name, value ]` pairs.
 */
export function formFields( body, { nonce = '', confirmAll = false } = {} ) {
	const flat = {};
	Object.entries( body || {} ).forEach( ( [ key, value ] ) => {
		if ( ONE_FIELD.includes( key ) && Array.isArray( value ) ) {
			flat[ key ] = value.join( ',' );
		} else if ( typeof value === 'boolean' ) {
			flat[ key ] = value ? '1' : '0';
		} else {
			flat[ key ] = value;
		}
	} );
	if ( ! body?.selection ) {
		flat.confirm_all = confirmAll ? '1' : '0';
	}
	const fields = nonce ? [ [ '_wpnonce', nonce ] ] : [];
	new URLSearchParams( buildQueryString( flat ) ).forEach( ( value, name ) =>
		fields.push( [ name, value ] )
	);
	return fields;
}

/**
 * Post the download form into a hidden frame: the browser downloads the
 * attachment, and a refusal page is read back. The form's address is the
 * route alone; every value travels in the body. The frame is sandboxed
 * (`allow-same-origin allow-downloads`): no script in the answer runs, a
 * refusal can still be read, and the download still starts (checked in
 * Chromium by tests/e2e/csv-export.spec.js). The frame's blank page
 * (`about:blank`, reported by some browsers after the form is sent) is not
 * a report.
 *
 * @param {Array<Array<string>>} fields           formFields().
 * @param {Object}               handlers         Handlers.
 * @param {Function}             handlers.onDone  The download started (or nothing was reported).
 * @param {Function}             handlers.onError Called with the refusal.
 * @param {Document}             handlers.doc     Document to add the frame and form to.
 * @param {number}               handlers.settle  Busy time without a report, ms.
 * @param {string}               handlers.action  The route's address (tests).
 * @param {Function}             handlers.submit  Sends the form (tests).
 * @return {Function} Cleanup: stops waiting and removes the frame and form.
 */
export function postDownload(
	fields,
	{
		onDone,
		onError,
		doc = document,
		settle = SETTLE_MS,
		action = routeUrl( 'export' ),
		submit = ( form ) => form.submit(),
	}
) {
	frames += 1;
	const name = `bulkcat-export-${ frames }`;
	const frame = doc.createElement( 'iframe' );
	frame.hidden = true;
	frame.setAttribute( 'aria-hidden', 'true' );
	frame.tabIndex = -1;
	frame.className = 'bulkcat-export__frame';
	frame.name = name;
	// No script runs in whatever answers the post; the page can still read
	// a refusal (same origin) and the browser can still save the file.
	frame.setAttribute( 'sandbox', 'allow-same-origin allow-downloads' );
	const form = doc.createElement( 'form' );
	form.hidden = true;
	form.className = 'bulkcat-export__form';
	form.method = 'post';
	form.target = name;
	form.setAttribute( 'action', action );
	fields.forEach( ( [ field, value ] ) => {
		const input = doc.createElement( 'input' );
		input.type = 'hidden';
		input.name = field;
		input.value = value;
		form.appendChild( input );
	} );
	let sent = false;
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
	const blank = () => {
		try {
			return frame.contentWindow?.location?.href === 'about:blank';
		} catch {
			return false;
		}
	};
	frame.addEventListener( 'load', () => {
		if ( sent && ! blank() ) {
			finish( refusalIn( frame ) );
		}
	} );
	doc.body.appendChild( frame );
	doc.body.appendChild( form );
	timer = setTimeout( () => finish( null ), settle );
	submit( form );
	sent = true;
	return () => {
		finished = true;
		clearTimeout( timer );
		form.remove();
		frame.remove();
	};
}

/**
 * The toolbar's Export control and its dialog.
 *
 * @param {Object}       props                  Props.
 * @param {Object}       props.query            The grid's GET /products arguments.
 * @param {Object}       props.view             DataViews view.
 * @param {Object|Array} props.registry         GET /fields answer.
 * @param {Object}       props.selection        panelSelection(): `{ kind, token, key, count, byType, waiting }`.
 * @param {Function}     props.onRenewSelection Renews a lapsed selection (the session's renewAll()).
 * @param {Function}     props.getNonce         The nonce to send (tests).
 * @param {Function}     props.start            postDownload() (tests).
 * @param {Function}     props.fetchSummary     The dialog's summary request (tests).
 * @return {Element} Control.
 */
export default function ExportControl( {
	query,
	view,
	registry,
	selection,
	onRenewSelection,
	getNonce = currentNonce,
	start = postDownload,
	fetchSummary,
} ) {
	const [ open, setOpen ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const cleanup = useRef( null );
	const button = useRef( null );

	useEffect(
		() => () => {
			if ( cleanup.current ) {
				cleanup.current();
			}
		},
		[]
	);

	const choose = () => {
		setError( null );
		setOpen( true );
	};

	// Try again sits in the notice the dialog replaces: focus Export first,
	// so the dialog gives focus back to Export, not to a button that is gone.
	const retry = () => {
		button.current?.focus();
		choose();
	};

	const run = ( { body, confirmAll } ) => {
		setOpen( false );
		if ( cleanup.current ) {
			cleanup.current();
		}
		setError( null );
		setBusy( true );
		cleanup.current = start(
			formFields( body, { nonce: getNonce(), confirmAll } ),
			{
				onDone: () => setBusy( false ),
				onError: ( refusal ) => {
					setBusy( false );
					setError( refusal );
				},
			}
		);
	};

	const plain = plainMessage(
		error,
		__(
			'The CSV file could not be made. Reload the page and try again.',
			'bulk-catalogue-manager-for-woocommerce'
		)
	);

	return (
		<div className="bulkcat-export">
			<Button
				ref={ button }
				variant="secondary"
				className="bulkcat-export__button"
				onClick={ choose }
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
					onRetry={ retry }
				/>
			) }
			{ open && (
				<ExportDialog
					selection={ selection }
					filterArgs={ exportArgs( query, view, registry ) }
					registry={ registry }
					onRenewSelection={ onRenewSelection }
					onDownload={ run }
					onClose={ () => setOpen( false ) }
					fetchSummary={ fetchSummary }
				/>
			) }
		</div>
	);
}
