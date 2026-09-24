/**
 * The panel's requests: the preview (POST /preview, SPEC §9.1), the apply
 * (POST /batches, T31, T38) and the batch's progress (GET /batches/{id}).
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { request } from '../../api';

/** The 409 codes the apply answers (Engine\BatchFactory). */
export const SELECTION_CHANGED = 'bulkcat_selection_changed';
export const PREVIEW_STALE = 'bulkcat_preview_stale';

/** Batch statuses after which nothing more happens (Data\Schema). */
export const FINISHED = [
	'complete',
	'partial',
	'failed',
	'cancelled',
	'undoing',
	'undone',
];

/**
 * The selection as the preview and batch routes take it: a token when the
 * selection has one (a filter, or ids exchanged for a token), else the ids.
 *
 * @param {Object} selection Panel selection.
 * @return {Object} `{ token }` or `{ ids }`.
 */
export function selectionArgs( selection ) {
	if ( selection?.token ) {
		return { token: selection.token };
	}
	return { ids: ( selection?.ids || [] ).map( Number ) };
}

/**
 * Whether a selection is all rows matching a filter.
 *
 * @param {Object} selection Panel selection.
 * @return {boolean} True for a filter.
 */
export function isFilterSelection( selection ) {
	return selection?.kind === 'filter';
}

/**
 * A request id for POST /batches: crypto.randomUUID() where there is one,
 * else a version 4 UUID from crypto.getRandomValues().
 *
 * @return {string} UUID.
 */
export function newUuid() {
	const cryptoApi = globalThis.crypto;
	if ( cryptoApi?.randomUUID ) {
		return cryptoApi.randomUUID();
	}
	const bytes = new Uint8Array( 16 );
	if ( cryptoApi?.getRandomValues ) {
		cryptoApi.getRandomValues( bytes );
	} else {
		for ( let i = 0; i < 16; i++ ) {
			bytes[ i ] = Math.floor( Math.random() * 256 );
		}
	}
	const digits = Array.from( bytes, ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
	// Version 4 in the 13th digit, the variant (8, 9, a or b) in the 17th.
	const hex =
		digits.slice( 0, 12 ) +
		'4' +
		digits.slice( 13, 16 ) +
		( ( bytes[ 8 ] % 4 ) + 8 ).toString( 16 ) +
		digits.slice( 17 );
	return [
		hex.slice( 0, 8 ),
		hex.slice( 8, 12 ),
		hex.slice( 12, 16 ),
		hex.slice( 16, 20 ),
		hex.slice( 20 ),
	].join( '-' );
}

/**
 * Whether the component is still mounted, for answers arriving late.
 *
 * @return {Object} Ref whose `current` is true while mounted.
 */
function useMounted() {
	const mounted = useRef( true );
	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
		};
	}, [] );
	return mounted;
}

/**
 * The preview: `run()` posts the change and keeps the latest answer with the
 * signature of what was previewed, so the panel can tell when it is stale.
 *
 * @return {Object} `{ status, response, error, signature, sampleSize, run, reset }`;
 *                  status is idle, computing, ready or error.
 */
export function usePreview() {
	const mounted = useMounted();
	const sequence = useRef( 0 );
	const [ state, setState ] = useState( {
		status: 'idle',
		response: null,
		error: null,
		signature: null,
		sampleSize: 25,
	} );

	const run = useCallback(
		async ( { operations, selection, sampleSize = 25, signature } ) => {
			const id = ++sequence.current;
			setState( ( previous ) => ( {
				...previous,
				status: 'computing',
				error: null,
				sampleSize,
			} ) );
			try {
				const response = await request( 'preview', {
					method: 'POST',
					data: {
						operations,
						...selectionArgs( selection ),
						sample_size: sampleSize,
					},
				} );
				if ( mounted.current && id === sequence.current ) {
					setState( {
						status: 'ready',
						response,
						error: null,
						signature,
						sampleSize,
					} );
				}
			} catch ( error ) {
				if ( mounted.current && id === sequence.current ) {
					setState( ( previous ) => ( {
						...previous,
						status: 'error',
						error,
					} ) );
				}
			}
		},
		[ mounted ]
	);

	const reset = useCallback( () => {
		sequence.current++;
		setState( {
			status: 'idle',
			response: null,
			error: null,
			signature: null,
			sampleSize: 25,
		} );
	}, [] );

	return { ...state, run, reset };
}

/**
 * The apply. One request id per previewed change: a retry of the same
 * preview (after a failed request, or "Apply anyway") sends the same uuid,
 * so the server never queues the change twice; a new preview gets a new one.
 *
 * @return {Object} `{ status, batch, error, previewed, current, apply, reset }`;
 *                  status is idle, applying, applied, changed, stale or error.
 */
export function useApply() {
	const mounted = useMounted();
	const requestId = useRef( { uuid: '', hash: '' } );
	const [ state, setState ] = useState( { status: 'idle' } );

	const apply = useCallback(
		async ( {
			operations,
			selection,
			preview,
			confirmChanged = false,
		} ) => {
			const hash = preview?.hash || '';
			if ( ! requestId.current.uuid || requestId.current.hash !== hash ) {
				requestId.current = { uuid: newUuid(), hash };
			}
			const data = {
				uuid: requestId.current.uuid,
				operations,
				...selectionArgs( selection ),
				source: 'bulk',
				preview_hash: hash,
			};
			if ( isFilterSelection( selection ) ) {
				data.preview_count =
					preview?.selection?.current_count ??
					preview?.totals?.matched ??
					selection.count;
			}
			if ( confirmChanged ) {
				data.confirm_changed = true;
			}
			setState( { status: 'applying' } );
			try {
				const batch = await request( 'batches', {
					method: 'POST',
					data,
				} );
				// Done: a later change starts with a fresh request id.
				requestId.current = { uuid: '', hash: '' };
				if ( mounted.current ) {
					setState( { status: 'applied', batch } );
				}
				return batch;
			} catch ( error ) {
				if ( ! mounted.current ) {
					return null;
				}
				if ( error?.code === SELECTION_CHANGED ) {
					setState( {
						status: 'changed',
						previewed: error.data?.previewed,
						current: error.data?.current,
						error,
					} );
				} else if ( error?.code === PREVIEW_STALE ) {
					setState( { status: 'stale', error } );
				} else {
					setState( { status: 'error', error } );
				}
				return null;
			}
		},
		[ mounted ]
	);

	const reset = useCallback( () => setState( { status: 'idle' } ), [] );

	return { ...state, apply, reset };
}

/**
 * A batch's progress report, read every `interval` milliseconds until it
 * has finished.
 *
 * @param {number} batchId  Batch id, or 0 for none.
 * @param {number} interval Milliseconds between reads.
 * @return {{report: Object|null, error: Object|null}} The latest report.
 */
export function useBatchProgress( batchId, interval = 2000 ) {
	const [ state, setState ] = useState( { report: null, error: null } );

	useEffect( () => {
		if ( ! batchId ) {
			return undefined;
		}
		let stopped = false;
		let timer = null;
		const read = async () => {
			try {
				const report = await request( 'batches/' + batchId );
				if ( stopped ) {
					return;
				}
				setState( { report, error: null } );
				if ( ! FINISHED.includes( report?.status ) ) {
					timer = setTimeout( read, interval );
				}
			} catch ( error ) {
				if ( stopped ) {
					return;
				}
				setState( ( previous ) => ( { ...previous, error } ) );
				timer = setTimeout( read, interval * 2 );
			}
		};
		read();
		return () => {
			stopped = true;
			clearTimeout( timer );
		};
	}, [ batchId, interval ] );

	return state;
}
