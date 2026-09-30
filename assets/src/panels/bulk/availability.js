/** Native publication labels and retained legacy Status outcome descriptions. */
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatNumber } from './words';

/**
 * Every outcome code, in the order the server counts them
 * (Availability\Outcome::CODES).
 */
export const CODES = [
	'off',
	'off_recapture',
	'already_off',
	'already_draft',
	'on',
	'on_restores',
	'on_scheduled',
	'on_schedule_passed',
	'publishes_not_off',
	'conflict',
	'conflict_kept',
	'record_dropped',
];

/**
 * The historical outcomes that required confirmation (Availability\Outcome::CONFIRMS; Jest checks it against the
 * shared fixture, tests/fixtures/status-switch.json).
 */
export const CONFIRMS = [
	'off',
	'off_recapture',
	'on_restores',
	'on_scheduled',
	'on_schedule_passed',
	'publishes_not_off',
	'record_dropped',
	'conflict',
];

/**
 * The order the preview lists the counts in: conflicts first, then the
 * restores to another status, passed schedules and publications of products
 * never switched Off, then the rest.
 */
export const ORDER = [
	'conflict',
	'conflict_kept',
	'on_restores',
	'on_schedule_passed',
	'publishes_not_off',
	'off',
	'off_recapture',
	'on',
	'on_scheduled',
	'record_dropped',
	'already_off',
	'already_draft',
];

/**
 * The Status field, including descriptors accompanying historical responses.
 *
 * @param {Object} registry The `/fields` payload.
 * @return {Object|null} The Status field.
 */
export function statusField( registry ) {
	return (
		( registry?.fields || [] ).find(
			( field ) =>
				field.nativeStatus === true || field.switchesOffOn === true
		) || null
	);
}

/**
 * A status by its plain name, including Scheduled, which Status cannot be
 * set to but a product may be saved as.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {string} status   A post status.
 * @return {string} Its name.
 */
export function statusName( registry, status ) {
	if ( status === 'future' ) {
		return __( 'Scheduled', 'bulk-catalogue-manager-for-woocommerce' );
	}
	const choice = ( statusField( registry )?.choices || [] ).find(
		( each ) => each.value === status
	);
	return choice ? choice.label : String( status || '' );
}

/**
 * A scheduled time (GMT, `Y-m-d H:i:s`) in the reader's language.
 *
 * @param {string} gmt The time.
 * @return {string} The time, readable.
 */
export function scheduledTime( gmt ) {
	if ( ! gmt ) {
		return '';
	}
	const date = new Date( String( gmt ).replace( ' ', 'T' ) + 'Z' );
	if ( Number.isNaN( date.getTime() ) ) {
		return String( gmt );
	}
	const lang =
		typeof document !== 'undefined' && document.documentElement.lang;
	try {
		return new Intl.DateTimeFormat( lang || undefined, {
			dateStyle: 'medium',
			timeStyle: 'short',
		} ).format( date );
	} catch {
		return date.toISOString();
	}
}

/**
 * A native schedule consequence, solely from the server prediction.
 * @param {Object} registry Field registry.
 * @param {Object} context  Server prediction.
 * @return {string} Consequence or empty text.
 */
export function publicationSentence( registry, context = {} ) {
	if ( context.effective_status !== 'future' || ! context.scheduled_gmt ) {
		return '';
	}
	return sprintf(
		/* translators: 1: the requested status, such as Published; 2: the scheduled time. */
		__(
			'%1$s will be Scheduled for %2$s.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		statusName( registry, context.requested_status ),
		scheduledTime( context.scheduled_gmt )
	);
}

/**
 * One product's outcome in words, as a preview row or the in-place confirm
 * shows it; '' for a code it has no words for.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {string} code     The outcome code.
 * @param {Object} context  What it names: saved, restores, current, scheduled_gmt.
 * @return {string} The words.
 */
export function outcomeLabel( registry, code, context = {} ) {
	const name = ( status ) => statusName( registry, status );
	switch ( code ) {
		case 'off':
			return sprintf(
				/* translators: %s: the status saved, such as "Published". */
				__(
					'Switched Off (was %s)',
					'bulk-catalogue-manager-for-woocommerce'
				),
				name( context.saved )
			);
		case 'off_recapture':
			return sprintf(
				/* translators: %s: the status saved again, such as "Private". */
				__(
					'Changed by someone else while Off: switched Off again, saving %s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				name( context.saved )
			);
		case 'already_off':
			return __(
				'Already Off',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'already_draft':
			return __(
				'Already Draft: nothing to save',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'on':
			return __(
				'Switched back On',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'on_restores':
			return sprintf(
				/* translators: 1: the status it had before Off, and comes back with, such as "Private". */
				__(
					'Was %1$s before Off: comes back %1$s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				name( context.restores )
			);
		case 'on_scheduled':
			return sprintf(
				/* translators: %s: when it is scheduled, such as "4 Oct 2026, 09:30". */
				__(
					'Was scheduled before Off: comes back scheduled for %s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				scheduledTime( context.scheduled_gmt )
			);
		case 'on_schedule_passed':
			return __(
				'Its scheduled time has passed: this publishes it now',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'publishes_not_off':
			return context.current === 'future' && context.scheduled_gmt
				? sprintf(
						/* translators: %s: when it is scheduled, such as "4 Oct 2026, 09:30". */
						__(
							'Not switched Off: stays scheduled for %s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						scheduledTime( context.scheduled_gmt )
					)
				: __(
						'Not switched Off: this publishes it',
						'bulk-catalogue-manager-for-woocommerce'
					);
		case 'conflict':
			return __(
				'Changed by someone else while Off: left alone',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'conflict_kept':
			return sprintf(
				/* translators: %s: the status it keeps, such as "Published". */
				__(
					'Changed by someone else while Off: keeps %s, Off record cleared',
					'bulk-catalogue-manager-for-woocommerce'
				),
				name( context.current )
			);
		case 'record_dropped':
			return sprintf(
				/* translators: %s: the status saved before Off, such as "Published". */
				__(
					'Its saved status (%s) will not be restored',
					'bulk-catalogue-manager-for-woocommerce'
				),
				name( context.saved )
			);
		default:
			return '';
	}
}

/**
 * The sentence for one count of a retained legacy availability block.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {string} code     The outcome code.
 * @param {number} count    How many products.
 * @param {Object} restores `{ private, pending }` for on_restores.
 * @return {string} The sentence.
 */
export function countLine( registry, code, count, restores = {} ) {
	const n = formatNumber( count );
	switch ( code ) {
		case 'off':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product will be switched Off. Its status is saved, so Published brings it back.',
					"%s products will be switched Off. Each one's status is saved, so Published brings it back.",
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'off_recapture':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product was changed by someone else while Off and will be switched Off again.',
					'%s products were changed by someone else while Off and will be switched Off again.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'already_off':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product is already Off.',
					'%s products are already Off.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'already_draft':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product is already Draft. Nothing is saved for it.',
					'%s products are already Draft. Nothing is saved for them.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'on':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product will be switched back On.',
					'%s products will be switched back On.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'on_restores': {
			const parts = [ 'private', 'pending' ]
				.filter( ( status ) => Number( restores?.[ status ] ) > 0 )
				.map(
					( status ) =>
						formatNumber( restores[ status ] ) +
						' ' +
						statusName( registry, status )
				);
			return sprintf(
				/* translators: 1: a number of products, such as "3"; 2: the statuses they get back, such as "2 Private, 1 Pending review". */
				_n(
					'%1$s product comes back with the status it had before Off, not Published: %2$s.',
					'%1$s products come back with the status they had before Off, not Published: %2$s.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n,
				parts.join( ', ' )
			);
		}
		case 'on_scheduled':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product comes back scheduled.',
					'%s products come back scheduled.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'on_schedule_passed':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product was scheduled and its time has passed: this publishes it now.',
					'%s products were scheduled and their time has passed: this publishes them now.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'publishes_not_off':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product was not switched Off: this publishes it.',
					'%s products were not switched Off: this publishes them.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'conflict':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product was changed by someone else while Off and will be left alone.',
					'%s products were changed by someone else while Off and will be left alone.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'conflict_kept':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product was changed by someone else while Off and will keep its current status.',
					'%s products were changed by someone else while Off and will keep their current status.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		case 'record_dropped':
			return sprintf(
				/* translators: %s: a number of products, such as "3". */
				_n(
					'%s product is Off; its saved status will not be restored.',
					'%s products are Off; their saved status will not be restored.',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				n
			);
		default:
			return '';
	}
}

/**
 * The availability block's sentences, in ORDER, then the variations left
 * as they are.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {Object} block    The preview's `availability` block, or null.
 * @return {Array<{code: string, text: string}>} Lines.
 */
export function availabilityLines( registry, block ) {
	if ( ! block ) {
		return [];
	}
	const counts = block.counts || {};
	const lines = ORDER.filter( ( code ) => Number( counts[ code ] ) > 0 ).map(
		( code ) => ( {
			code,
			text: countLine(
				registry,
				code,
				Number( counts[ code ] ),
				block.restores
			),
		} )
	);
	const kept = Number( block.variations_kept ) || 0;
	if ( kept > 0 ) {
		lines.push( {
			code: 'variations_kept',
			text: sprintf(
				/* translators: %s: a number of variations, such as "6". */
				_n(
					'%s variation is left as it is: switching On restores only what switching Off saved.',
					'%s variations are left as they are: switching On restores only what switching Off saved.',
					kept,
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( kept )
			),
		} );
	}
	return lines;
}
