/** Historical outcome codes stay readable; current badges show stored native values. */
import { render, screen } from '@testing-library/react';
import { renderCell } from '../../assets/src/panels/catalogue/cells';
import {
	CODES,
	CONFIRMS,
	outcomeLabel,
	availabilityLines,
} from '../../assets/src/panels/bulk/availability';
const FIXTURE = require( '../fixtures/status-switch.json' );
const { REGISTRY } = require( './catalogue-fixture' );
const STATUS = REGISTRY.fields.find( ( field ) => field.nativeStatus );

it( 'retains every legacy outcome code and historical confirmation classification', () => {
	expect( CODES ).toEqual( FIXTURE.codes );
	expect( CONFIRMS ).toEqual( FIXTURE.confirms );
} );
it.each( FIXTURE.codes )(
	'still describes historical %s outcomes',
	( code ) => {
		expect(
			outcomeLabel( REGISTRY, code, {
				saved: 'private',
				restores: 'private',
				current: 'publish',
				scheduled_gmt: '2026-10-04 09:30:00',
			} )
		).not.toBe( '' );
	}
);
it( 'retains the meaning of a remembered Private restoration in historical outcomes', () => {
	expect(
		outcomeLabel( REGISTRY, 'on_restores', { restores: 'private' } )
	).toBe( 'Was Private before Off: comes back Private' );
	expect(
		availabilityLines( REGISTRY, {
			counts: { on_restores: 2 },
			restores: { private: 2 },
		} )[ 0 ].text
	).toContain( '2 Private' );
} );
it.each( [
	[ 'draft', 'Draft' ],
	[ 'pending', 'Pending Review' ],
	[ 'private', 'Private' ],
	[ 'publish', 'Published' ],
	[ 'future', 'Scheduled' ],
] )(
	'renders stored %s independently of a legacy record',
	( status, label ) => {
		render(
			renderCell( {
				item: {
					status,
					off_state: {
						state: 'off',
						saved: 'private',
						saved_label: 'Private',
					},
				},
				descriptor: STATUS,
			} )
		);
		expect( screen.getByText( label ) ).toBeTruthy();
		expect( document.body.textContent ).not.toMatch(
			/Off|Changed outside/
		);
	}
);
