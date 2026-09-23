/**
 * The history's CSV downloads (SPEC §4.6, §10; T58): GET /export with
 * `source=changes|audit|batches`, the filters the list on screen carries and
 * `columns[]`, streamed by the server (src/Export/HistoryColumns.php names
 * each source's columns). The file is fetched the way the catalogue's
 * Export control fetches it (../catalogue/views/export.js): the URL, with
 * the current wp_rest nonce as `_wpnonce`, loads in a hidden frame, the
 * control is busy until the download starts, and a refusal comes back as
 * a §4.8 notice on the screen.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { currentNonce, download, exportUrl } from '../catalogue/views/export';

/** The columns of each history source, in the order they are written. */
export const EXPORT_COLUMNS = {
	changes: [
		'applied_gmt',
		'name',
		'object_id',
		'object_type',
		'field_label',
		'old_value',
		'new_value',
		'result',
		'message',
	],
	audit: [
		'applied_gmt',
		'batch_id',
		'user_name',
		'name',
		'object_id',
		'object_type',
		'field_label',
		'old_value',
		'new_value',
		'result',
		'message',
	],
	batches: [
		'batch_id',
		'created_gmt',
		'finished_gmt',
		'user_name',
		'actions',
		'status',
		'total_items',
		'changed_items',
		'failed_items',
		'variations_included',
		'reversibility',
		'undo',
		'undone_by',
	],
};

/** List arguments an export does not take: it pages itself. */
const NOT_EXPORTED = [ 'page', 'per_page' ];

/**
 * The GET /export arguments for a history source and the list's filters.
 *
 * @param {string} source  changes, audit or batches.
 * @param {Object} filters The list's query (paging is dropped).
 * @return {Object} Arguments.
 */
export function historyExportArgs( source, filters = {} ) {
	const args = { source, ...filters };
	NOT_EXPORTED.forEach( ( key ) => delete args[ key ] );
	args.columns = EXPORT_COLUMNS[ source ];
	return args;
}

/**
 * Start history downloads, one at a time, and report a refusal.
 *
 * @param {Function} onNotice         Show a screen notice (`{ status, error }`).
 * @param {Object}   options          Test seams.
 * @param {Function} options.getNonce The nonce to send.
 * @param {Function} options.start    download().
 * @return {{busy: boolean, run: Function}} Busy state and run( source, filters ).
 */
export function useHistoryExport(
	onNotice,
	{ getNonce = currentNonce, start = download } = {}
) {
	const [ busy, setBusy ] = useState( false );
	const cleanup = useRef( null );

	useEffect(
		() => () => {
			if ( cleanup.current ) {
				cleanup.current();
			}
		},
		[]
	);

	const run = useCallback(
		( source, filters ) => {
			if ( cleanup.current ) {
				cleanup.current();
			}
			setBusy( true );
			cleanup.current = start(
				exportUrl( historyExportArgs( source, filters ), getNonce() ),
				{
					onDone: () => setBusy( false ),
					onError: ( refusal ) => {
						setBusy( false );
						// Only the plugin's own refusals carry a sentence meant for the screen.
						const error = refusal?.code?.startsWith( 'bcm_' )
							? refusal
							: Object.assign( new Error( '' ), {
									code: refusal?.code,
								} );
						onNotice( { status: 'error', error } );
					},
				}
			);
		},
		[ onNotice, getNonce, start ]
	);

	return { busy, run };
}
