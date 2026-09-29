/**
 * Status as the global Off/On switch in the grid and the detail panel (T92,
 * SPEC §5.5, §4.5): what a Status change does to one product, and the
 * in-place confirm the grid's cell and the drawer show before writing it.
 *
 * statusOutcome() mirrors the server's Availability\StatusSwitch for one
 * product row, from the row's status and its `off_state` (Query\Hydrator).
 * Jest runs it over the same fixture as PHPUnit
 * (tests/fixtures/status-switch.json), so the two cannot drift; the server
 * stays the judge, and its answer replaces the row. The outcomes in
 * CONFIRMS — the same ones the bulk panel confirms — ask first; the others
 * write as any other inline change does.
 */
import { Button } from '@wordpress/components';
import { useEffect, useId, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from '../../categories/announce';
import { CONFIRMS, outcomeLabel, scheduledTime } from '../../bulk/availability';
import { isVariation } from './rules';

/** The code of a Published refused on a product changed elsewhere while Off (Availability\Outcome::CONFLICT_CODE). */
export const CONFLICT_CODE = 'bulkcat_availability_conflict';

/** WordPress schedules a publish only this far ahead or more, in seconds (StatusSwitch::SCHEDULE_LEAD). */
const SCHEDULE_LEAD = 60;

/** The statuses the switch reads; anything else keeps the field's ordinary rules. */
const STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];

/** The row property carrying the product's Off state (Query\Hydrator::OFF_STATE). */
export const OFF_STATE = 'off_state';

/**
 * Whether a field's change on a row goes through the switch: the field the
 * registry flags as the Off/On switch, on a product (never a variation).
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {Object} item       Row.
 * @return {boolean} It does.
 */
export function switchesOffOn( descriptor, item ) {
	return descriptor?.switchesOffOn === true && ! isVariation( item );
}

/**
 * A status by its plain name, "Scheduled" included.
 *
 * @param {Object} descriptor The Status descriptor.
 * @param {string} status     A post status.
 * @return {string} Its name.
 */
export function statusLabel( descriptor, status ) {
	if ( status === 'future' ) {
		return __( 'Scheduled', 'bulk-catalogue-manager-for-woocommerce' );
	}
	const found = ( descriptor?.choices || [] ).find(
		( choice ) => choice.value === status
	);
	return found ? found.label : String( status ?? '' );
}

/**
 * What the Status cell reads for a row with an Off state; '' when the row
 * has none (its ordinary status label applies).
 *
 * @param {Object} item Row.
 * @return {string} "Off (was Published)", "Changed outside this screen", or ''.
 */
export function offStateText( item ) {
	const state = item?.[ OFF_STATE ];
	if ( state?.state === 'off' ) {
		return sprintf(
			/* translators: %s: the status saved when the product was switched Off, such as "Published". */
			__( 'Off (was %s)', 'bulk-catalogue-manager-for-woocommerce' ),
			state.saved_label || state.saved || ''
		);
	}
	if ( state?.state === 'changed_outside' ) {
		return __(
			'Changed outside this screen',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	return '';
}

/**
 * Whether a saved scheduled time is still at least a minute ahead.
 *
 * @param {string} gmt `Y-m-d H:i:s`, GMT.
 * @param {number} now Milliseconds.
 * @return {boolean} Ahead.
 */
function ahead( gmt, now ) {
	const time = gmt
		? Date.parse( String( gmt ).replace( ' ', 'T' ) + 'Z' )
		: NaN;
	return ! Number.isNaN( time ) && ( time - now ) / 1000 >= SCHEDULE_LEAD;
}

/**
 * The sentence the confirm shows for an outcome on one product.
 *
 * @param {Object} descriptor The Status descriptor.
 * @param {Object} outcome    statusOutcome()'s answer.
 * @param {string} name       The product's name.
 * @return {string} The sentence; '' for an outcome that does not confirm.
 */
export function outcomeSentence( descriptor, outcome, name ) {
	const product =
		name || __( 'This product', 'bulk-catalogue-manager-for-woocommerce' );
	const label = ( status ) => statusLabel( descriptor, status );
	const context = outcome?.context || {};
	switch ( outcome?.code ) {
		case 'off':
			return sprintf(
				/* translators: 1: a product name, 2: its status now, such as "Published". */
				__(
					'Switch %1$s Off? It leaves the site for every buyer. Its status, %2$s, is saved, so choosing Published later brings it back.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				product,
				label( context.saved )
			);
		case 'off_recapture':
			return sprintf(
				/* translators: 1: a product name, 2: its status now, such as "Private". */
				__(
					'%1$s was changed by someone else while Off. Switch it Off again? Its status now, %2$s, is saved in place of the one saved before.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				product,
				label( context.saved )
			);
		case 'on_restores':
			return sprintf(
				/* translators: 1: a product name, 2: the status it had before Off, such as "Private". */
				__(
					'%1$s was %2$s before it was switched Off, so it comes back %2$s, not Published.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				product,
				label( context.restores )
			);
		case 'on_scheduled':
			return sprintf(
				/* translators: 1: a product name, 2: when it is scheduled, such as "4 Oct 2026, 09:30". */
				__(
					'%1$s was scheduled before it was switched Off, so it comes back scheduled for %2$s.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				product,
				scheduledTime( context.scheduled_gmt )
			);
		case 'on_schedule_passed':
			return sprintf(
				/* translators: %s: a product name. */
				__(
					'%s was scheduled before it was switched Off and its time has passed, so this publishes it now.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				product
			);
		case 'publishes_not_off':
			return context.current === 'future'
				? sprintf(
						/* translators: %s: a product name. */
						__(
							'%s is scheduled and was not switched Off. Published keeps it scheduled until its time, or publishes it now if that has passed.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						product
					)
				: sprintf(
						/* translators: 1: a product name, 2: its status now, such as "Draft". */
						__(
							'%1$s is %2$s and was not switched Off, so this publishes it: buyers will see it.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						product,
						label( context.current )
					);
		case 'record_dropped':
			return sprintf(
				/* translators: 1: a product name, 2: the status saved before Off, such as "Published", 3: the status chosen, such as "Private". */
				__(
					'%1$s has a status saved from before it was switched Off (%2$s). Changing Status to %3$s clears it, so switching On will not bring it back.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				product,
				label( context.saved ),
				label( outcome.new )
			);
		case 'conflict':
			return sprintf(
				/* translators: 1: a product name, 2: its status now, such as "Private". */
				__(
					'%1$s was changed by someone else while Off, so it was left alone. Choose Keep current status to keep it %2$s and clear its Off record.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				product,
				label( context.current )
			);
		default:
			return '';
	}
}

/**
 * What choosing a status does to one product: the mirror of the server's
 * StatusSwitch::decide() for a row (SPEC §5.5).
 *
 * @param {Object}  item               Row: its status and `off_state`.
 * @param {string}  target             The status chosen.
 * @param {Object}  options            Options.
 * @param {Object}  options.descriptor The Status descriptor (the registry's Off/On field: its key and choice names).
 * @param {string}  options.name       The product's name, for the sentence.
 * @param {boolean} options.keep       Keep current status.
 * @param {number}  options.now        The time, in milliseconds (Date.now() by default).
 * @return {Object} { code, context, state, old, new, refusal, record, confirm, sentence }: record is 'none', 'clear' or { save }.
 */
export function statusOutcome( item, target, options = {} ) {
	const { descriptor = null, name = '', keep = false } = options;
	const now = options.now ?? Date.now();
	// The row's status, read by the registry's key for the switch.
	const current = descriptor?.key
		? String( item?.[ descriptor.key ] ?? '' )
		: '';
	const result = ( code, changes = {} ) => {
		const outcome = {
			code,
			context: {},
			state: current === target ? 'already' : 'changed',
			old: current,
			new: target,
			refusal: '',
			record: 'none',
			...changes,
		};
		outcome.confirm = CONFIRMS.includes( code );
		outcome.sentence = outcomeSentence( descriptor, outcome, name );
		return outcome;
	};
	if ( isVariation( item ) || ! STATUSES.includes( current ) ) {
		return result( '' );
	}
	const off = item?.[ OFF_STATE ] || null;
	const saved = off?.saved;

	if ( target === 'draft' ) {
		if ( ! off ) {
			return current === 'draft'
				? result( 'already_draft' )
				: result( 'off', {
						context: { saved: current },
						record: { save: current },
					} );
		}
		if ( current === 'draft' ) {
			return result( 'already_off', { context: { saved } } );
		}
		return result( 'off_recapture', {
			context: { saved: current, current },
			record: { save: current },
		} );
	}

	if ( target === 'publish' ) {
		if ( ! off ) {
			return current === 'publish'
				? result( '' )
				: result( 'publishes_not_off', { context: { current } } );
		}
		if ( current !== 'draft' ) {
			const context = { saved, current };
			return keep
				? result( 'conflict_kept', {
						context,
						state: 'already',
						new: current,
						record: 'clear',
					} )
				: result( 'conflict', {
						context,
						state: 'skipped',
						new: current,
						refusal: CONFLICT_CODE,
					} );
		}
		const context = { saved, restores: saved };
		if ( saved === 'publish' ) {
			return result( 'on', { context, record: 'clear' } );
		}
		if ( saved === 'private' || saved === 'pending' ) {
			return result( 'on_restores', {
				context,
				new: saved,
				record: 'clear',
			} );
		}
		return result(
			ahead( off.scheduled_gmt, now )
				? 'on_scheduled'
				: 'on_schedule_passed',
			{
				context: { ...context, scheduled_gmt: off.scheduled_gmt || '' },
				record: 'clear',
			}
		);
	}

	// Pending review or Private: ordinary statuses; with a record they clear it.
	if ( ! off ) {
		return result( '' );
	}
	return result( 'record_dropped', { context: { saved }, record: 'clear' } );
}

/**
 * The confirm button's words for an outcome.
 *
 * @param {string} code The outcome code.
 * @return {string} The words.
 */
export function confirmLabel( code ) {
	switch ( code ) {
		case 'off':
		case 'off_recapture':
			return __( 'Switch Off', 'bulk-catalogue-manager-for-woocommerce' );
		case 'on_restores':
		case 'on_scheduled':
		case 'on_schedule_passed':
			return __( 'Switch On', 'bulk-catalogue-manager-for-woocommerce' );
		case 'conflict':
			return __(
				'Keep current status',
				'bulk-catalogue-manager-for-woocommerce'
			);
		default:
			return __(
				'Change status',
				'bulk-catalogue-manager-for-woocommerce'
			);
	}
}

/**
 * What a saved Status change did, in words, from the server's answer: its
 * Status result's outcome code and context. '' when it names none.
 *
 * @param {Object} descriptor The Status descriptor.
 * @param {Object} answer     The PATCH answer.
 * @return {string} The words, such as "Switched Off (was Published)".
 */
export function savedOutcome( descriptor, answer ) {
	const step = ( answer?.results || [] ).find(
		( result ) => result.field === descriptor?.key && result.note
	);
	return step
		? outcomeLabel(
				{ fields: [ descriptor ] },
				step.note,
				step.context || {}
			)
		: '';
}

/**
 * The in-place confirm (SPEC §4.5): the outcome's sentence with its confirm
 * button and Cancel. It takes focus and is announced; Enter confirms and
 * Escape cancels.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.outcome   statusOutcome()'s answer (code and sentence).
 * @param {Function} props.onConfirm Confirm.
 * @param {Function} props.onCancel  Cancel: back to the control.
 * @param {boolean}  props.busy      A write is in flight.
 * @param {string}   props.error     A refusal to show, if any.
 * @return {Element} The confirm.
 */
export function StatusConfirm( { outcome, onConfirm, onCancel, busy, error } ) {
	const id = useId();
	const confirm = useRef( null );
	const sentence = outcome?.sentence || '';

	useEffect( () => {
		confirm.current?.focus();
		announce(
			sentence,
			outcome?.code === 'conflict' ? 'assertive' : 'polite'
		);
		// Once per outcome shown.
	}, [ outcome?.code, sentence ] );

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			event.stopPropagation();
			if ( ! busy ) {
				onCancel();
			}
		} else if (
			event.key === 'Enter' &&
			event.target?.dataset?.bulkcatCancel === undefined
		) {
			event.preventDefault();
			event.stopPropagation();
			if ( ! busy ) {
				onConfirm();
			}
		}
	};

	return (
		// The confirm's own keys: Enter confirms, Escape goes back (§14).
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
		<div
			className={
				'bulkcat-status-confirm' +
				( outcome?.code === 'conflict' ? ' is-conflict' : '' )
			}
			role="group"
			aria-labelledby={ id }
			data-bulkcat-outcome={ outcome?.code || '' }
			onKeyDown={ onKeyDown }
		>
			<p id={ id } className="bulkcat-status-confirm__text">
				{ sentence }
			</p>
			{ error && (
				<p className="bulkcat-status-confirm__error" role="alert">
					{ error }
				</p>
			) }
			<span className="bulkcat-status-confirm__actions">
				<Button
					ref={ confirm }
					variant="primary"
					size="small"
					isBusy={ busy }
					aria-disabled={ busy || undefined }
					onClick={ () => ! busy && onConfirm() }
				>
					{ confirmLabel( outcome?.code ) }
				</Button>
				<Button
					variant="tertiary"
					size="small"
					data-bulkcat-cancel=""
					aria-disabled={ busy || undefined }
					onClick={ () => ! busy && onCancel() }
				>
					{ __( 'Cancel', 'bulk-catalogue-manager-for-woocommerce' ) }
				</Button>
			</span>
		</div>
	);
}
