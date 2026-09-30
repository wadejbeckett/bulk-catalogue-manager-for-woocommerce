/** Native Status presentation. Requested targets never restore an old record. */
import { outcomeLabel, statusName } from '../../bulk/availability';
import { allowedChoices } from './rules';

/**
 * A stored status by its registry label.
 * @param {Object} descriptor Status descriptor.
 * @param {string} status     Stored status.
 * @return {string} Label.
 */
export function statusLabel( descriptor, status ) {
	return statusName( { fields: [ descriptor ] }, status );
}

/**
 * Writable choices plus a disabled label for a currently stored schedule.
 * @param {Object} descriptor Field descriptor.
 * @param {Object} item       Saved row.
 * @return {Array<Object>} Options. Scheduled is never an ordinary target.
 */
export function editChoices( descriptor, item ) {
	const choices = allowedChoices( descriptor, item );
	return descriptor.nativeStatus && item[ descriptor.key ] === 'future'
		? [
				{
					value: 'future',
					label: statusLabel( descriptor, 'future' ),
					disabled: true,
				},
				...choices,
			]
		: choices;
}

/**
 * Announce the server's stored result, never an optimistic requested target.
 * @param {Object} descriptor Field descriptor.
 * @param {Object} answer     PATCH result.
 * @return {string} Saved status or retained legacy outcome.
 */
export function savedOutcome( descriptor, answer ) {
	const step = ( answer?.results || [] ).find(
		( result ) => result.field === descriptor?.key
	);
	if ( descriptor?.nativeStatus ) {
		const stored = answer?.item?.[ descriptor.key ] ?? step?.new;
		return stored ? statusLabel( descriptor, stored ) : '';
	}
	return step?.note
		? outcomeLabel(
				{ fields: [ descriptor ] },
				step.note,
				step.context || {}
			)
		: '';
}
