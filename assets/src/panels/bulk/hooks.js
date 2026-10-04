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
	isUncertain,
	keptHolder,
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
	if ( selection?.targets ) {
		return { targets: selection.targets };
	}
	if ( selection?.typed_token ) {
		return { typed_token: selection.typed_token };
	}
	if ( selection?.token ) {
		return { token: String( selection.token ) };
	}
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
 * @param {Object} entry     Entry.
 * @return {string} Key.
 */
export function applyKey( selection, preview, entry = null ) {
	return JSON.stringify( {
		...( entry ? typedMetadata( preview, entry ) : {} ),
		operations: preview?.operations || null,
		scope: preview?.scope_hash || null,
		selection:
			selection?.kind === 'snapshot'
				? { snapshot: selection.key || selection.token }
				: selectionArgs( selection ),
	} );
}

/**
 * Typed metadata comes from scoped server discovery or the actual Preview response.
 * @param {Object} descriptor Descriptor.
 * @param {Object} entry      Entry.
 */
export function typedMetadata( descriptor, entry ) {
	return {
		serializer: 2,
		family: descriptor.family,
		external: descriptor.external,
		entry: entry.location,
	};
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
		async ( {
			operations,
			selection,
			sampleSize = 25,
			signature,
			entry = null,
		} ) => {
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
						...( entry ? typedMetadata( entry, entry ) : {} ),
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
const typedHolders = new Map();

/**
 * The screen's Apply holder, read from the tab's copy on first use.
 *
 * @param {Object} entry Entry.
 * @return {Object} `{ current, store }`.
 */
function applyGrant( entry = null ) {
	if ( entry ) {
		const key = JSON.stringify( {
			family: entry.family,
			external: entry.external,
			entry: entry.location,
			selectionKey: entry.selectionKey,
			targets: entry.selection?.targets || null,
		} );
		if ( ! typedHolders.has( key ) ) {
			typedHolders.set(
				key,
				keptHolder(
					tabStore(
						tabScope( readSettings(), 'provider-apply:' + key )
					)
				)
			);
		}
		return typedHolders.get( key );
	}
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
	typedHolders.clear();
}

/**
 * After a reload: whether the tab still held an Apply grant whose answer was
 * unknown, and if so what became of it (POST /requests/resolve, which never
 * creates work). When it had been accepted, the answer is its batch
 * (`{ batch_id, status, … }`) with the snapshot handle it was applied to
 * (`selectionKey`); otherwise null, and the grant is kept for the same change
 * or resolved again before a different one.
 *
 * @param {Object} entry Entry.
 * @return {Promise<Object|null>} The earlier change, or null.
 */
export async function resumeApply( entry = null ) {
	const holder = applyGrant( entry );
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
 * The durable apply (T84). One grant per intent: a retry of the same
 * previewed change — after a dropped connection, a server error, or a new
 * preview of the same exact scope — sends the same `uuid`, `request_proof`
 * and canonical intent again, and the server answers the batch it already
 * made (`replayed: true`) instead of making another. A different change
 * gets a new grant, only after an uncertain earlier one has been resolved.
 *
 * @return {Object} `{ status, batch, error, apply, reset }`; status is idle,
 *                  applying, applied, uncertain, changed, stale, renew,
 *                  legacy or error.
 */
export function useApply() {
	const mounted = useMounted();
	const [ state, setState ] = useState( { status: 'idle' } );

	const apply = useCallback(
		async ( { selection, preview, entry = null } ) => {
			const args = selectionArgs( selection );
			const selectionKey = entry
				? entry.selectionKey
				: ( selection?.kind === 'snapshot' && selection.key ) || '';
			setState( { status: 'applying' } );
			try {
				const batch = await sendWithGrant(
					applyGrant(
						entry
							? { ...entry, ...typedMetadata( preview, entry ) }
							: null
					),
					{
						key: applyKey( selection, preview, entry ),
						meta: {
							...( entry
								? {
										...typedMetadata( preview, entry ),
										selection: args,
										clearSubmitted: entry.clearSubmitted,
									}
								: {} ),
							selectionKey,
						},
						operation: 'batch.apply',
						intent: {
							...( entry ? typedMetadata( preview, entry ) : {} ),
							source: 'bulk',
							operations: preview?.operations,
							scope_hash: preview?.scope_hash,
							...( entry ? { selection: args } : args ),
						},
						send: ( grant ) =>
							request( 'batches', {
								method: 'POST',
								data: {
									...envelope( grant ),
									preview_hash: preview?.hash || '',
									...( args.token
										? { token: args.token }
										: {} ),
									...( entry && args.typed_token
										? { typed_token: args.typed_token }
										: {} ),
								},
							} ),
					}
				);
				if ( mounted.current ) {
					setState( { status: 'applied', batch } );
				}
				return batch;
			} catch ( error ) {
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
