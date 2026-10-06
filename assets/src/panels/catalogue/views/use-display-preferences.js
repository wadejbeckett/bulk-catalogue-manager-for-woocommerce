/**
 * Loading and remembering the signed-in user's display choices through
 * /bulkcat/v1/display-preferences (ticket 19). Nothing is written on load;
 * a changed display is saved once changes settle, one request at a time,
 * always with the latest choices. A failed save is reported, never shown
 * as saved.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { request } from '../../../api';
import { displayOf, mergeAvailableFields, sameDisplay } from './display-state';

/** Wait after the last change before saving, in milliseconds. */
export const SETTLE_MS = 800;

const ROUTE = 'display-preferences';

/**
 * The stored display, read once.
 *
 * @return {Object} { display: Object|null, hasLoaded: boolean }.
 */
export function useDisplayPreferences() {
	const [ state, setState ] = useState( {
		display: null,
		hasLoaded: false,
	} );
	useEffect( () => {
		let live = true;
		Promise.resolve()
			.then( () => request( ROUTE ) )
			.then(
				( answer ) =>
					answer?.display && typeof answer.display === 'object'
						? answer.display
						: null,
				() => null
			)
			.then( ( display ) => {
				if ( live ) {
					setState( { display, hasLoaded: true } );
				}
			} );
		return () => {
			live = false;
		};
	}, [] );
	return state;
}

/**
 * Save the view's display after the user changes it. Only changes the user
 * makes (touch() marks them) are saved; the screen's own changes — its
 * defaults, the stored choices arriving, a default view opening — become the
 * new starting point without a write. Nothing is saved before the stored
 * choices have been read. Stored columns this screen does not offer just now
 * are kept in place.
 *
 * @param {Object}             view   DataViews view.
 * @param {boolean}            ready  Whether the stored choices have been read.
 * @param {Object|null}        stored The stored choices, already on the server.
 * @param {Array<string>|null} known  Column keys this screen offers; null keeps no dormant columns.
 * @return {Object} { error, retry, touch }.
 */
export function useRememberDisplay(
	view,
	ready = true,
	stored = null,
	known = null
) {
	const display = displayOf( view );
	if ( known && Array.isArray( stored?.fields ) ) {
		display.fields = mergeAvailableFields(
			stored.fields,
			display.fields,
			known
		);
	}
	const key = JSON.stringify( display );
	const saved = useRef( null );
	const wanted = useRef( display );
	const running = useRef( false );
	const touched = useRef( false );
	const [ error, setError ] = useState( null );

	const flush = useCallback( () => {
		if (
			running.current ||
			null === saved.current ||
			sameDisplay( wanted.current, saved.current )
		) {
			return Promise.resolve();
		}
		running.current = true;
		const sending = wanted.current;
		return request( ROUTE, { method: 'PUT', data: { display: sending } } )
			.then(
				() => {
					saved.current = sending;
					setError( null );
				},
				( failure ) => setError( failure || new Error() )
			)
			.finally( () => {
				running.current = false;
			} )
			.then( () => {
				// A change made while this one was saving (a failure waits for Try again).
				if (
					! sameDisplay( wanted.current, sending ) &&
					! sameDisplay( wanted.current, saved.current )
				) {
					return flush();
				}
			} );
	}, [] );

	useEffect( () => {
		wanted.current = JSON.parse( key );
		if ( ! ready ) {
			return undefined;
		}
		if ( null === saved.current || ! touched.current ) {
			// The screen's own change: the new starting point, not a write.
			saved.current = wanted.current;
			return undefined;
		}
		if ( sameDisplay( wanted.current, saved.current ) ) {
			return undefined;
		}
		const timer = setTimeout( flush, SETTLE_MS );
		return () => clearTimeout( timer );
	}, [ key, flush, ready ] );

	const touch = useCallback( () => {
		touched.current = true;
	}, [] );

	return { error, retry: flush, touch };
}
