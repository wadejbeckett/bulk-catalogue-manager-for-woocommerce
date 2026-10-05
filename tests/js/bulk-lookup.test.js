/**
 * A lookup argument in the bulk panel (ticket 25; docs/EXTENSIONS-v2.md §4):
 * the chooser lists the version 2 test provider's actions from `/fields`
 * (tests/Unit/Extension/fields-picks.snapshot.json); the step shows the
 * searchable picker, blocks until a choice is made, and the preview request
 * carries only the chosen ids.
 */
import { fireEvent, render, screen, within } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';
import BulkChangePanel from '../../assets/src/panels/bulk';
import {
	operationProblem,
	propertyKind,
	toRequestOperations,
} from '../../assets/src/panels/bulk/descriptors';

const { IDS, SETTINGS } = require( './bulk-fixtures' );
const PICKS = require( '../Unit/Extension/fields-picks.snapshot.json' );

jest.mock( '@wordpress/api-fetch', () => {
	const mock = jest.fn( () => new Promise( () => {} ) );
	mock.use = jest.fn();
	mock.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: mock };
} );

const action = ( id ) => PICKS.actions.find( ( each ) => each.id === id );

/**
 * Answer lookups with three recipients; leave everything else pending.
 *
 * @param {Object} options apiFetch options.
 * @return {Promise<Object>} Answer.
 */
function serve( options ) {
	if ( String( options.url ).startsWith( 'lookup' ) ) {
		return Promise.resolve( {
			items: [
				{ id: 'r0001', label: 'Recipient 0001', detail: 'Team B' },
				{ id: 'r0002', label: 'Recipient 0002', detail: 'Team C' },
				{ id: 'r0003', label: 'Recipient 0003', detail: 'Team D' },
			],
			page: 1,
			hasMore: false,
		} );
	}
	return new Promise( () => {} );
}

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( serve );
} );

test( 'a lookup argument is its own kind, sent as ids and checked for count', () => {
	const add = action( 'x_picks_add' );
	const only = action( 'x_picks_only' );
	expect( propertyKind( 'recipients', add.args.properties.recipients ) ).toBe(
		'lookup'
	);
	expect( propertyKind( 'recipient', only.args.properties.recipient ) ).toBe(
		'lookup'
	);
	const items = [
		{ id: 'r0002', label: 'Recipient 0002' },
		{ id: 'r0001', label: 'Recipient 0001' },
	];
	expect(
		toRequestOperations( PICKS, [
			{
				action: 'x_picks_add',
				args: { field: 'x_picks_items', recipients: items },
			},
			{
				action: 'x_picks_only',
				args: { field: 'x_picks_items', recipient: [ items[ 0 ] ] },
			},
		] )
	).toEqual( [
		{
			action: 'x_picks_add',
			args: { field: 'x_picks_items', recipients: [ 'r0002', 'r0001' ] },
		},
		{
			action: 'x_picks_only',
			args: { field: 'x_picks_items', recipient: 'r0002' },
		},
	] );
	expect(
		operationProblem( PICKS, {
			action: 'x_picks_add',
			args: { field: 'x_picks_items', recipients: [] },
		} )?.arg
	).toBe( 'recipients' );
	expect(
		operationProblem( PICKS, {
			action: 'x_picks_add',
			args: {
				field: 'x_picks_items',
				recipients: Array.from( { length: 21 }, ( _, n ) => ( {
					id: `r${ n }`,
					label: `R${ n }`,
				} ) ),
			},
		} )?.arg
	).toBe( 'recipients' );
	expect(
		operationProblem( PICKS, {
			action: 'x_picks_add',
			args: { field: 'x_picks_items', recipients: items },
		} )
	).toBeNull();
} );

test( 'the panel shows the picker and previews only the chosen ids', async () => {
	render(
		<BulkChangePanel
			selection={ { kind: 'ids', ids: IDS } }
			registry={ PICKS }
			settings={ SETTINGS }
			onClose={ jest.fn() }
			onApplied={ jest.fn() }
			pollInterval={ 5 }
		/>
	);
	const group = screen.getByRole( 'heading', {
		name: 'Sample picks',
	} ).parentElement;
	fireEvent.click(
		within( group ).getByRole( 'radio', { name: /^Add recipients/ } )
	);
	const dialog = screen.getByRole( 'dialog' );
	expect( dialog.getAttribute( 'data-state' ) ).toBe( 'blocked' );
	const box = screen.getByRole( 'combobox', { name: 'Recipients' } );
	fireEvent.focus( box );
	fireEvent.click( await screen.findByText( 'Recipient 0002' ) );
	expect(
		screen.getByRole( 'button', { name: 'Remove Recipient 0002' } )
	).toBeTruthy();
	expect( dialog.getAttribute( 'data-state' ) ).toBe( 'configuring' );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Check the change' } )
	);
	const previews = apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter( ( options ) => String( options.url ).startsWith( 'preview' ) );
	expect( previews ).toHaveLength( 1 );
	expect( previews[ 0 ].data.operations ).toEqual( [
		{
			action: 'x_picks_add',
			args: {
				field: 'x_picks_items',
				recipients: [ 'r0002' ],
				skip_already: true,
			},
		},
	] );
} );
