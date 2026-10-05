/**
 * Loading and remembering the signed-in user's display choices through
 * /bulkcat/v1/display-preferences (ticket 19). Nothing is written on load;
 * a changed display is saved once changes settle, one request at a time,
 * always with the latest choices. A failed save is reported, never shown
 * as saved.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { request } from '../../../api';
import { displayOf, sameDisplay } from './display-state';

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
 * Whether a display holds every stored choice.
 *
 * @param {Object|null} stored  Stored choices.
 * @param {Object}      display Display.
 * @return {boolean} Holds them.
 */
function holds( stored, display ) {
	return (
		!! stored &&
		Object.keys( stored ).every(
			( key ) =>
				JSON.stringify( stored[ key ] ) ===
				JSON.stringify( display[ key ] )
		)
	);
}

/**
 * Save the view's display whenever the user changes it. Nothing is saved
 * before the stored choices have been read (so they are never overwritten
 * by the screen's defaults); the display at that moment is the starting point.
 *
 * @param {Object}      view   DataViews view.
 * @param {boolean}     ready  Whether the stored choices have been read.
 * @param {Object|null} stored The stored choices, already on the server.
 * @return {Object} { error, retry }.
 */
export function useRememberDisplay( view, ready = true, stored = null ) {
	const display = displayOf( view );
	const key = JSON.stringify( display );
	const saved = useRef( null );
	const wanted = useRef( display );
	const running = useRef( false );
	const [ error, setError ] = useState( null );
	if ( ready && null === saved.current ) {
		saved.current = display;
	}

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
				// A change made while this one was saving.
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
		if (
			null === saved.current ||
			sameDisplay( wanted.current, saved.current )
		) {
			return undefined;
		}
		if ( holds( stored, wanted.current ) ) {
			// The stored choices being shown: the server has them already.
			saved.current = wanted.current;
			return undefined;
		}
		const timer = setTimeout( flush, SETTLE_MS );
		return () => clearTimeout( timer );
	}, [ key, flush, stored ] );

	return { error, retry: flush };
}
