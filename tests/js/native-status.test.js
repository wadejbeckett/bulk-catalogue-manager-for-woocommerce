/** Native Status presentation consumes the registry and server results. */
import { render, screen } from '@testing-library/react';
import { savedOutcome } from '../../assets/src/panels/catalogue/edit/status';
import { PreviewResult } from '../../assets/src/panels/bulk/preview';
import { alwaysConfirmReasons } from '../../assets/src/panels/bulk/confirm';
import {
	filterChips,
	viewToQuery,
} from '../../assets/src/panels/catalogue/toolbar';
import { fieldsFromRegistry } from '../../assets/src/panels/catalogue/fields';
import {
	fromSaved,
	toSaved,
} from '../../assets/src/panels/catalogue/views/serialize';
const { REGISTRY, previewResponse } = require( './bulk-fixtures' );
const status = REGISTRY.fields.find( ( field ) => field.key === 'status' );

it( 'discovers native Status independently of generic Set value and legacy filter choices', () => {
	expect( status.nativeStatus ).toBe( true );
	expect( status.switchesOffOn ).toBeUndefined();
	expect(
		fieldsFromRegistry( REGISTRY )
			.find( ( field ) => field.id === 'status' )
			.elements.map( ( option ) => option.value )
	).toEqual( [ 'publish', 'future', 'draft', 'pending', 'private' ] );
	const action = REGISTRY.actions.find(
		( each ) => each.id === 'set_status'
	);
	expect( action.fields ).toEqual( [ 'status' ] );
} );

it.each( [ 'off', 'changed_outside' ] )(
	'retains the exact legacy %s predicate and identifies its chip',
	( value ) => {
		const view = {
			filters: [
				{
					field: 'status',
					operator: 'isNone',
					value: [ value, 'draft' ],
				},
			],
		};
		expect( fromSaved( toSaved( view ) ).view.filters ).toEqual(
			view.filters
		);
		expect( viewToQuery( view, REGISTRY.fields ).status ).toEqual( {
			operator: 'isNone',
			value: [ value, 'draft' ],
		} );
		expect( filterChips( view, REGISTRY.fields )[ 0 ].sentence ).toContain(
			'legacy status filter'
		);
	}
);

it( 'uses the saved row over the requested target or a prediction in a save announcement', () => {
	expect(
		savedOutcome( status, {
			item: { status: 'future' },
			results: [
				{
					field: 'status',
					new: 'future',
					context: {
						requested_status: 'publish',
						effective_status: 'publish',
					},
				},
			],
		} )
	).toBe( 'Scheduled' );
} );

it.each( [ 'publish', 'private' ] )(
	'adds no extra native Status confirmation for %s',
	( value ) => {
		expect(
			alwaysConfirmReasons(
				REGISTRY,
				[ { action: 'set_status', args: { field: 'status', value } } ],
				1,
				{ counts: { off: 1, on_restores: 1 } }
			)
		).toEqual( [] );
	}
);

// FRONTEND-4: the Settings help says a change that drafts always asks.
it.each( [
	[ 'draft', 'Draft' ],
	[ 'pending', 'Pending Review' ],
] )( 'always asks before setting Status to %s', ( value, label ) => {
	expect(
		alwaysConfirmReasons(
			REGISTRY,
			[ { action: 'set_status', args: { field: 'status', value } } ],
			1
		)
	).toEqual( [
		`This sets Status to ${ label } on 1 product, which takes them off the site.`,
	] );
} );

it( 'renders server schedule and redacted password consequences without inventing effects for other Private rows', () => {
	const response = previewResponse();
	response.sample = [
		{
			object_id: 1,
			name: 'Scheduled subject',
			field: 'status',
			current: 'draft',
			new: 'future',
			state: 'changed',
			context: {
				requested_status: 'publish',
				effective_status: 'future',
				scheduled_gmt: '2026-10-04 09:30:00',
			},
		},
		{
			object_id: 2,
			name: 'Protected subject',
			field: 'status',
			current: 'publish',
			new: 'private',
			state: 'changed',
			password_cleared: true,
		},
		{
			object_id: 3,
			name: 'Unprotected subject',
			field: 'status',
			current: 'publish',
			new: 'private',
			state: 'changed',
		},
	];
	response.warnings = { publication_password_cleared: 7 };
	render(
		<PreviewResult
			response={ response }
			registry={ REGISTRY }
			operations={ [] }
			terms={ {} }
			sampleSize={ 25 }
			onSampleSize={ () => {} }
			onChangedOnly={ () => {} }
		/>
	);
	expect(
		screen.getByText( /Published will be Scheduled for/ )
	).toBeTruthy();
	expect(
		screen.getAllByText( 'The publication password will be cleared.' )
	).toHaveLength( 1 );
	expect(
		screen.getByText(
			'Publication passwords will be cleared on 7 products.'
		)
	).toBeTruthy();
} );
