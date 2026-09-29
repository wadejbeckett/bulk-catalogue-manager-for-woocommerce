/**
 * T92: the grid's mirror of the Status switch (SPEC §5.5) decides every row
 * of the shared fixture the server's StatusSwitchTest runs
 * (tests/fixtures/status-switch.json), so the two cannot drift; and the
 * Status badge reads the row's Off state.
 */
import { render, screen } from '@testing-library/react';

import { renderCell } from '../../assets/src/panels/catalogue/cells';
import { CONFIRMS, CODES } from '../../assets/src/panels/bulk/availability';
import {
	confirmLabel,
	offStateText,
	statusOutcome,
} from '../../assets/src/panels/catalogue/edit/status';

const FIXTURE = require( '../fixtures/status-switch.json' );
const { REGISTRY } = require( './catalogue-fixture' );

const STATUS = REGISTRY.fields.find( ( field ) => field.switchesOffOn );

/** A fixed clock: 2026-09-29 12:00:00 UTC, as StatusSwitchTest has it. */
const NOW = Date.UTC( 2026, 8, 29, 12, 0, 0 );

/**
 * A time as the Hydrator gives it: GMT, `Y-m-d H:i:s`.
 *
 * @param {number} ms Milliseconds.
 * @return {string} The time.
 */
const gmt = ( ms ) =>
	new Date( ms ).toISOString().slice( 0, 19 ).replace( 'T', ' ' );

/**
 * The grid row one fixture row describes: a record naming another product,
 * or one that cannot be read, is no Off state (Query\Hydrator).
 *
 * @param {Object} row Fixture row.
 * @return {Object} Grid row.
 */
function item( row ) {
	const record = row.record;
	let offState = null;
	if ( record && typeof record === 'object' ) {
		offState = {
			state: row.status === 'draft' ? 'off' : 'changed_outside',
			saved: record.status,
			saved_label: record.status,
			switched_off_gmt: '2026-09-01 08:00:00',
			batch_id: 7,
		};
		if ( record.status === 'future' ) {
			offState.scheduled_gmt = gmt(
				row.schedule === 'passed'
					? NOW - 3600 * 1000
					: NOW + 3 * 86400 * 1000
			);
		}
	}
	return {
		id: 501,
		parent_id: 0,
		[ STATUS.key ]: row.status,
		off_state: offState,
	};
}

describe( 'the Status switch mirror', () => {
	it( 'knows the same outcomes and confirms the same ones as the server', () => {
		expect( STATUS ).toBeTruthy();
		expect( CODES ).toEqual( FIXTURE.codes );
		expect( CONFIRMS ).toEqual( FIXTURE.confirms );
	} );

	it.each( FIXTURE.rows.map( ( row ) => [ row.case, row ] ) )(
		'%s',
		( _case, row ) => {
			const outcome = statusOutcome( item( row ), row.target, {
				descriptor: STATUS,
				name: 'Blue shirt',
				keep: row.keep,
				now: NOW,
			} );
			expect( outcome.code ).toBe( row.code );
			expect( outcome.state ).toBe( row.result.state );
			expect( outcome.old ).toBe( row.result.old );
			expect( outcome.new ).toBe( row.result.new );
			expect( outcome.refusal ).toBe( row.result.code || '' );
			expect( outcome.record ).toEqual( row.record_result );
			expect( outcome.confirm ).toBe(
				FIXTURE.confirms.includes( row.code )
			);
			// A confirm names the product; an outcome that does not confirm has no sentence.
			expect(
				outcome.confirm
					? outcome.sentence.includes( 'Blue shirt' )
					: outcome.sentence === ''
			).toBe( true );
			expect( confirmLabel( outcome.code ) ).not.toBe( '' );
		}
	);

	it( 'counts a schedule less than a minute ahead as passed, as WordPress does', () => {
		const off = ( seconds ) =>
			statusOutcome(
				{
					id: 501,
					parent_id: 0,
					[ STATUS.key ]: 'draft',
					off_state: {
						state: 'off',
						saved: 'future',
						scheduled_gmt: gmt( NOW + seconds * 1000 ),
					},
				},
				'publish',
				{ descriptor: STATUS, now: NOW }
			).code;
		expect( off( 59 ) ).toBe( 'on_schedule_passed' );
		expect( off( 60 ) ).toBe( 'on_scheduled' );
		expect( off( -1 ) ).toBe( 'on_schedule_passed' );
	} );

	it( 'leaves a variation to the field alone: no outcome, no confirm', () => {
		const outcome = statusOutcome(
			{
				id: 601,
				parent_id: 600,
				[ STATUS.key ]: 'publish',
				off_state: null,
			},
			'private',
			{ descriptor: STATUS }
		);
		expect( [ outcome.code, outcome.confirm, outcome.record ] ).toEqual( [
			'',
			false,
			'none',
		] );
	} );

	it( 'words the confirms plainly, naming the product and the statuses', () => {
		const on = statusOutcome(
			{
				id: 501,
				parent_id: 0,
				[ STATUS.key ]: 'draft',
				off_state: {
					state: 'off',
					saved: 'private',
					saved_label: 'Private',
				},
			},
			'publish',
			{ descriptor: STATUS, name: 'Blue shirt' }
		);
		expect( on.sentence ).toBe(
			'Blue shirt was Private before it was switched Off, so it comes back Private, not Published.'
		);
		expect( confirmLabel( on.code ) ).toBe( 'Switch On' );
		const off = statusOutcome(
			{
				id: 501,
				parent_id: 0,
				[ STATUS.key ]: 'publish',
				off_state: null,
			},
			'draft',
			{ descriptor: STATUS, name: 'Blue shirt' }
		);
		expect( off.sentence ).toBe(
			'Switch Blue shirt Off? It leaves the site for every buyer. Its status, Published, is saved, so choosing Published later brings it back.'
		);
		expect( confirmLabel( off.code ) ).toBe( 'Switch Off' );
		expect( confirmLabel( 'conflict' ) ).toBe( 'Keep current status' );
		expect( confirmLabel( 'record_dropped' ) ).toBe( 'Change status' );
	} );
} );

describe( 'the Status badge', () => {
	const cell = ( row ) =>
		render( renderCell( { item: row, descriptor: STATUS, role: '' } ) )
			.container;

	it( 'reads "Off (was Published)" for an Off product', () => {
		const container = cell( {
			id: 11,
			parent_id: 0,
			[ STATUS.key ]: 'draft',
			off_state: {
				state: 'off',
				saved: 'publish',
				saved_label: 'Published',
			},
		} );
		expect( screen.getByText( 'Off (was Published)' ) ).toBeTruthy();
		expect( container.querySelector( '.bulkcat-badge' ) ).toBeTruthy();
	} );

	it( 'reads "Changed outside this screen" for a product changed elsewhere while Off', () => {
		const container = cell( {
			id: 11,
			parent_id: 0,
			[ STATUS.key ]: 'private',
			off_state: {
				state: 'changed_outside',
				saved: 'publish',
				saved_label: 'Published',
			},
		} );
		expect(
			screen.getByText( 'Changed outside this screen' )
		).toBeTruthy();
		expect(
			container.querySelector( '.bulkcat-badge.is-warn' )
		).toBeTruthy();
	} );

	it( 'reads the ordinary status otherwise, a saved scheduled status by name', () => {
		cell( {
			id: 11,
			parent_id: 0,
			[ STATUS.key ]: 'draft',
			off_state: null,
		} );
		expect( screen.getByText( 'Draft' ) ).toBeTruthy();
		expect(
			offStateText( {
				off_state: {
					state: 'off',
					saved: 'future',
					saved_label: 'Scheduled',
				},
			} )
		).toBe( 'Off (was Scheduled)' );
		expect( offStateText( { off_state: null } ) ).toBe( '' );
	} );
} );
