/**
 * The export dialog's summary (SPEC §10; ticket 10, T103): `POST /export`
 * with `summary`, asked whenever what is exported changes (the row choice,
 * the selection's token or the identifier switch). Only the latest request
 * counts: a late answer to an earlier one is ignored (a generation counter).
 *
 * A lapsed selection (409 `bulkcat_selection_renew_required`) is renewed
 * once, through the session's renewAll() as the bulk panel's Restore does
 * (ruling R13), and the summary is asked again with the renewed token. The
 * dialog never renews ahead of time. Any other failure is kept for the
 * dialog to say, with a retry.
 */
import { useEffect, useRef, useState } from '@wordpress/element';

import { request } from '../../../api';

/** The refusal of a selection whose lease has lapsed. */
export const RENEW_REQUIRED = 'bulkcat_selection_renew_required';

/**
 * Ask for a summary.
 *
 * @param {Object} body rowsBody().
 * @return {Promise<Object>} `{ rows, left_out, guarded_identifiers, guarded_names, whole_catalogue, filename }`.
 */
export function fetchSummary( body ) {
	return request( 'export', {
		method: 'POST',
		data: { ...body, summary: true },
	} );
}

const IDLE = { status: 'idle', answer: null, error: null, key: '' };

/**
 * The summary for a body.
 *
 * @param {Object}   options         Options.
 * @param {Object}   options.body    rowsBody().
 * @param {boolean}  options.enabled Whether to ask at all (not while the selection is saving).
 * @param {Function} options.onRenew Renews the selection; returns a promise.
 * @param {Function} options.ask     fetchSummary() (tests).
 * @return {Object} `{ status, answer, error, ready, retry }`; `ready` is true
 *                  only when the answer is for this very body.
 */
export function useExportSummary( {
	body,
	enabled,
	onRenew,
	ask = fetchSummary,
} ) {
	const key = JSON.stringify( body );
	const [ state, setState ] = useState( IDLE );
	const [ attempt, setAttempt ] = useState( 0 );
	const generation = useRef( 0 );
	const renewed = useRef( false );
	const latest = useRef( {} );
	latest.current = { body, onRenew, ask };

	useEffect( () => {
		const mine = ++generation.current;
		if ( ! enabled ) {
			setState( IDLE );
			return;
		}
		const current = latest.current;
		const asked = current.body;
		setState( { status: 'loading', answer: null, error: null, key } );
		current.ask( asked ).then(
			( answer ) => {
				if ( mine === generation.current ) {
					setState( { status: 'ready', answer, error: null, key } );
				}
			},
			( error ) => {
				if ( mine !== generation.current ) {
					return;
				}
				const renew =
					error?.code === RENEW_REQUIRED &&
					asked.selection &&
					typeof current.onRenew === 'function' &&
					! renewed.current;
				if ( ! renew ) {
					setState( {
						status: 'error',
						answer: null,
						error: error || {},
						key,
					} );
					return;
				}
				renewed.current = true;
				setState( {
					status: 'renewing',
					answer: null,
					error: null,
					key,
				} );
				Promise.resolve()
					.then( () => current.onRenew() )
					.then(
						() => {
							// A renewed token changes the body, which asks
							// again by itself; the same token asks once more.
							if ( mine === generation.current ) {
								setAttempt( ( count ) => count + 1 );
							}
						},
						( failure ) => {
							if ( mine === generation.current ) {
								setState( {
									status: 'error',
									answer: null,
									error: failure || error,
									key,
								} );
							}
						}
					);
			}
		);
	}, [ key, enabled, attempt ] );

	useEffect(
		() => () => {
			generation.current += 1;
		},
		[]
	);

	return {
		...state,
		ready: state.status === 'ready' && state.key === key,
		retry: () => setAttempt( ( count ) => count + 1 ),
	};
}
