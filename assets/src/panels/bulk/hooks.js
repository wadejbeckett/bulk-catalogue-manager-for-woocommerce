/**
 * The panel's requests: the preview (POST /preview, SPEC §9.1) and the
 * durable apply (T84: a `batch.apply` grant from POST /requests, then POST
 * /batches with that grant, the preview's hash and the snapshot's current
 * token), and the batch's progress (GET /batches/{id}).
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { request } from '../../api';
import {
	envelope,
	isDead,
	isUncertain,
	keepHeld,
	keptHolder,
	resolveGrant,
	resolveHeld,
	sendWithGrant,
} from '../../requests';
import { readSettings } from '../../settings';
import { tabScope, tabStore } from '../../tab-store';

/** The 409 codes the apply answers (Engine\BatchFactory, Requests\*). */
export const SELECTION_CHANGED = 'bulkcat_selection_changed';
export const PREVIEW_STALE = 'bulkcat_preview_stale';
export const LEGACY_REQUEST = 'bulkcat_legacy_request';
export const REQUEST_GONE = 'bulkcat_request_gone';
export const RENEW_REQUIRED = 'bulkcat_selection_renew_required';

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
 * The selection as the preview and batch routes take it: the snapshot's
 * current token, or explicit ids, sorted (T83: every preview is exact).
 *
 * @param {Object} selection Panel selection.
 * @return {Object} `{ token }` or `{ ids }`.
 */
export function selectionArgs( selection ) {
	if ( selection?.kind === 'snapshot' ) {
		return { token: String( selection.token || '' ) };
	}
	return {
		ids: [ ...new Set( ( selection?.ids || [] ).map( Number ) ) ].sort(
			( a, b ) => a - b
		),
	};
}

/**
 * The identity of one Apply's intent on this screen: the change as the
 * preview normalised it, its exact scope and the selection (the snapshot's
 * handle, which a renewed token keeps, or the ids). The same identity always
 * sends the grant it already holds.
 *
 * @param {Object} selection Panel selection.
 * @param {Object} preview   The preview answer.
 * @return {string} Key.
 */
export function applyKey( selection, preview ) {
	return JSON.stringify( {
		operations: preview?.operations || null,
		scope: preview?.scope_hash || null,
		selection:
			selection?.kind === 'snapshot'
				? { snapshot: selection.key || selection.token }
				: selectionArgs( selection ),
	} );
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
 * The Apply grant held between attempts. It belongs to the screen, not to
 * one opening of the panel: closing the panel after an uncertain Apply and
 * opening it again still resends, or first resolves, that grant. Since T85
 * it is also kept in the tab's copy (tab-store.js, beside the selection's
 * handles), so a reload while the answer is unknown still resends or
 * resolves the same grant instead of asking for a new one: a relative change
 * such as "increase by 10 per cent" can then never be made twice.
 */
let applyHolder = null;

/**
 * The screen's Apply holder, read from the tab's copy on first use.
 *
 * @return {Object} `{ current, store }`.
 */
function applyGrant() {
	if ( ! applyHolder ) {
		applyHolder = keptHolder(
			tabStore( tabScope( readSettings(), 'apply' ) )
		);
	}
	return applyHolder;
}

/**
 * Forget the held Apply grant (tests). The tab's copy is left alone.
 */
export function resetApplyGrant() {
	applyHolder = null;
}

/**
 * After a reload: whether the tab still held an Apply grant whose answer was
 * unknown, and if so what became of it (POST /requests/resolve, which never
 * creates work). When it had been accepted, the answer is its batch
 * (`{ batch_id, status, … }`) with the snapshot handle it was applied to
 * (`selectionKey`); otherwise null, and the grant is kept for the same change
 * or resolved again before a different one.
 *
 * @return {Promise<Object|null>} The earlier change, or null.
 */
export async function resumeApply() {
	const holder = applyGrant();
	const held = holder.current;
	if ( ! held?.restored ) {
		return null;
	}
	const outcome = await resolveHeld( holder );
	if ( ! outcome?.batch_id ) {
		return null;
	}
	return { ...outcome, selectionKey: held.meta?.selectionKey || '' };
}

/**
 * FRONTEND-12: what became of an Apply whose answer never came, asked of the
 * server with the same grant (POST /requests/resolve, which never makes the
 * change). `{ batch_id, …, selectionKey }` when it had been accepted (the
 * grant is then let go); `{ status: 'not_made' }` when the server has no
 * record of it, or the grant can no longer be accepted; `{ status: 'none' }`
 * when no unanswered Apply is held. A failed check rejects, and the grant
 * stays held.
 *
 * @param {Function} resolve Grant resolution (tests).
 * @return {Promise<Object>} The outcome.
 */
export async function checkApply( resolve = resolveGrant ) {
	const holder = applyGrant();
	const held = holder.current;
	if ( ! held?.uncertain ) {
		return { status: 'none' };
	}
	let outcome;
	try {
		outcome = await resolve( held.grant );
	} catch ( error ) {
		if ( holder.current === held && isDead( error ) ) {
			holder.current = null;
			keepHeld( holder );
			return { status: 'not_made' };
		}
		throw error;
	}
	if ( holder.current !== held ) {
		return { status: 'none' };
	}
	if ( outcome?.batch_id ) {
		holder.current = null;
		keepHeld( holder );
		return { ...outcome, selectionKey: held.meta?.selectionKey || '' };
	}
	return { status: 'not_made' };
}

/**
 * The durable apply (T84). One grant per intent: a retry of the same
 * previewed change — after a dropped connection, a server error, or a new
 * preview of the same exact scope — sends the same `uuid`, `request_proof`
 * and canonical intent again, and the server answers the batch it already
 * made (`replayed: true`) instead of making another. A different change
 * gets a new grant, only after an uncertain earlier one has been resolved.
 *
 * An answer that never came is also told to `onUncertain`, even after the
 * panel has closed, so the screen can keep it in view (FRONTEND-12).
 *
 * @param {Function} onUncertain Called when an Apply ends without a definite answer.
 * @return {Object} `{ status, batch, error, apply, reset }`; status is idle,
 *                  applying, applied, uncertain, changed, stale, renew,
 *                  legacy or error.
 */
export function useApply( onUncertain = null ) {
	const mounted = useMounted();
	const [ state, setState ] = useState( { status: 'idle' } );
	const tellUncertain = useRef( onUncertain );
	tellUncertain.current = onUncertain;

	const apply = useCallback(
		async ( { selection, preview } ) => {
			const args = selectionArgs( selection );
			setState( { status: 'applying' } );
			try {
				const batch = await sendWithGrant( applyGrant(), {
					key: applyKey( selection, preview ),
					meta: {
						selectionKey:
							selection?.kind === 'snapshot'
								? selection.key || ''
								: '',
					},
					operation: 'batch.apply',
					intent: {
						source: 'bulk',
						operations: preview?.operations,
						scope_hash: preview?.scope_hash,
						...args,
					},
					send: ( grant ) =>
						request( 'batches', {
							method: 'POST',
							data: {
								...envelope( grant ),
								preview_hash: preview?.hash || '',
								...( args.token ? { token: args.token } : {} ),
							},
						} ),
				} );
				if ( mounted.current ) {
					setState( { status: 'applied', batch } );
				}
				return batch;
			} catch ( error ) {
				if ( isUncertain( error ) ) {
					tellUncertain.current?.();
				}
				if ( ! mounted.current ) {
					return null;
				}
				let status = 'error';
				if ( isUncertain( error ) ) {
					status = 'uncertain';
				} else if ( error?.code === SELECTION_CHANGED ) {
					status = 'changed';
				} else if ( error?.code === PREVIEW_STALE ) {
					status = 'stale';
				} else if ( error?.code === RENEW_REQUIRED ) {
					status = 'renew';
				} else if ( error?.code === LEGACY_REQUEST ) {
					status = 'legacy';
				}
				setState( { status, error } );
				return null;
			}
		},
		[ mounted ]
	);

	const reset = useCallback( () => setState( { status: 'idle' } ), [] );

	return { ...state, apply, reset };
}

/**
 * Whether a failed read will fail the same way however often it is asked
 * again (FRONTEND-8): signed out, not allowed, or the batch is gone.
 *
 * @param {Object} error The rejection.
 * @return {boolean} Whether to stop asking.
 */
export function permanentReadError( error ) {
	return (
		[ 401, 403, 404 ].includes( Number( error?.data?.status ) ) ||
		[ 'rest_not_logged_in', 'rest_cookie_invalid_nonce' ].includes(
			error?.code
		)
	);
}

/**
 * A batch's progress report, read every `interval` milliseconds until it
 * has finished. A passing failure is read again after twice the interval;
 * a permanent one (permanentReadError()) stops the reads, `stopped` says
 * so, and `checkAgain()` starts them again (FRONTEND-8).
 *
 * @param {number} batchId  Batch id, or 0 for none.
 * @param {number} interval Milliseconds between reads.
 * @return {{report: Object|null, error: Object|null, stopped: boolean, checkAgain: Function}} The latest report.
 */
export function useBatchProgress( batchId, interval = 2000 ) {
	const [ state, setState ] = useState( {
		report: null,
		error: null,
		stopped: false,
	} );
	const [ attempt, setAttempt ] = useState( 0 );
	const checkAgain = useCallback( () => setAttempt( ( n ) => n + 1 ), [] );

	useEffect( () => {
		if ( ! batchId ) {
			return undefined;
		}
		setState( ( previous ) =>
			previous.stopped ? { ...previous, stopped: false } : previous
		);
		let stopped = false;
		let timer = null;
		const read = async () => {
			try {
				const report = await request( 'batches/' + batchId );
				if ( stopped ) {
					return;
				}
				setState( { report, error: null, stopped: false } );
				if ( ! FINISHED.includes( report?.status ) ) {
					timer = setTimeout( read, interval );
				}
			} catch ( error ) {
				if ( stopped ) {
					return;
				}
				const permanent = permanentReadError( error );
				setState( ( previous ) => ( {
					...previous,
					error,
					stopped: permanent,
				} ) );
				if ( ! permanent ) {
					timer = setTimeout( read, interval * 2 );
				}
			}
		};
		read();
		return () => {
			stopped = true;
			clearTimeout( timer );
		};
	}, [ batchId, interval, attempt ] );

	return { ...state, checkAgain };
}
