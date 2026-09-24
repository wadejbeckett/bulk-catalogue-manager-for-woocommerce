/**
 * Progress announcements for a running change (SPEC §14, T75): politely at
 * a quarter, a half and three quarters rather than on every progress read,
 * so a screen reader is not talked over; the result is said once by the
 * caller, and errors assertively.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from '../categories/announce';
import { formatNumber } from './words';

/** The share of the work, in per cent, at which progress is announced. */
export const MILESTONES = [ 25, 50, 75 ];

/**
 * The highest milestone reached, or 0 before the first.
 *
 * @param {number} done  Rows done.
 * @param {number} total Rows in all.
 * @return {number} Per cent milestone.
 */
export function milestone( done, total ) {
	if ( ! total || done <= 0 ) {
		return 0;
	}
	const share = ( done / total ) * 100;
	return [ ...MILESTONES ].reverse().find( ( at ) => share >= at ) || 0;
}

/**
 * The polite sentence for a milestone.
 *
 * @param {number} done  Rows done.
 * @param {number} total Rows in all.
 * @return {string} Sentence.
 */
export function progressSentence( done, total ) {
	return sprintf(
		/* translators: 1: rows done, such as "312"; 2: rows in all, such as "1,284". */
		__(
			'Applying the change: %1$s of %2$s done.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( done ),
		formatNumber( total )
	);
}

/**
 * Announce each milestone once while a change runs.
 *
 * @param {number}  done     Rows done.
 * @param {number}  total    Rows in all.
 * @param {boolean} finished Whether the change has finished (nothing more is said).
 */
export function useProgressAnnouncer( done, total, finished ) {
	const said = useRef( 0 );
	useEffect( () => {
		if ( finished ) {
			return;
		}
		const reached = milestone( done, total );
		if ( reached > said.current ) {
			said.current = reached;
			announce( progressSentence( done, total ), 'polite' );
		}
	}, [ done, total, finished ] );
}
