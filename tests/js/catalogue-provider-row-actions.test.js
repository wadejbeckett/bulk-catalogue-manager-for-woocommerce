/**
 * Add-on actions on one row (ticket 25; docs/EXTENSIONS-v2.md §6): an
 * action `/fields` marks `row` is offered in a product row's menu, disabled
 * with its reason when it cannot be used; choosing it opens the change panel
 * for that one product only, at that action, with the row's object for its
 * lookups, never the retained selection.
 */
import { fireEvent, render, screen, within } from '@testing-library/react';

import {
	providerRowActions,
	providerRowChange,
	rowActions,
} from '../../assets/src/panels/catalogue/actions';
import { ROW } from '../../assets/src/panels/catalogue/defaults';

const PICKS = require( '../Unit/Extension/fields-picks.snapshot.json' );
const { SETTINGS } = require( './bulk-fixtures' );

const product = { id: 42 };
const variation = { id: 43, [ ROW.parentId ]: 42 };

/**
 * The registry with only one of the add-on's row entries.
 *
 * @return {Object} `/fields` payload.
 */
const ONE_ROW_ENTRY = () => ( {
	...PICKS,
	actions: PICKS.actions.map( ( action ) =>
		action.id === 'x_picks_remove' ? { ...action, row: undefined } : action
	),
} );

test( 'the row menu offers a lone add-on row entry directly, after the native ones', () => {
	const chosen = jest.fn();
	const actions = rowActions( {
		providerActions: providerRowActions( ONE_ROW_ENTRY(), chosen ),
	} );
	const ids = actions.map( ( action ) => action.id );
	expect( ids ).toContain( 'provider-x_picks_add' );
	expect( ids ).not.toContain( 'provider-x_picks_remove' );
	expect( ids ).not.toContain( 'provider-x_picks_only' );
	expect( ids.indexOf( 'provider-x_picks_add' ) ).toBeGreaterThan(
		ids.indexOf( 'edit-details' )
	);
	const add = actions.find(
		( action ) => action.id === 'provider-x_picks_add'
	);
	expect( add.label ).toBe( 'Add recipients…' );
	expect( add.supportsBulk ).toBe( false );
	expect( add.isEligible( product ) ).toBe( true );
	expect( add.isEligible( variation ) ).toBe( false );
	add.callback( [ product ] );
	expect( chosen ).toHaveBeenCalledWith(
		PICKS.actions.find( ( action ) => action.id === 'x_picks_add' ),
		product
	);
	add.callback( [ product, { id: 44 } ] );
	expect( chosen ).toHaveBeenCalledTimes( 1 );
} );

test( 'an action that cannot be used is listed disabled with its reason', () => {
	const registry = {
		...ONE_ROW_ENTRY(),
		actions: ONE_ROW_ENTRY().actions.map( ( action ) =>
			action.id === 'x_picks_add'
				? {
						...action,
						available: false,
						unavailableReason:
							'You do not have permission to use this action.',
					}
				: action
		),
	};
	const add = providerRowActions( registry, jest.fn() ).find(
		( action ) => action.id === 'provider-x_picks_add'
	);
	expect( add.disabled ).toBe( true );
	expect( add.label ).toBe(
		'Add recipients (You do not have permission to use this action.)'
	);
	add.callback( [ product ] );
} );

test( 'a version 1 registry offers no row entries', () => {
	expect(
		providerRowActions(
			require( '../Unit/Extension/fields-external.snapshot.json' ),
			jest.fn()
		)
	).toEqual( [] );
} );

test( 'choosing it opens a change of that one row at that action', () => {
	const add = PICKS.actions.find( ( action ) => action.id === 'x_picks_add' );
	const change = providerRowChange( PICKS, SETTINGS, add, product );
	expect( change.selection ).toEqual( { kind: 'ids', ids: [ 42 ] } );
	expect( change.object ).toBe( 'product:42' );
	expect( change.live ).toBeFalsy();
	expect( change.initialOperations ).toHaveLength( 1 );
	expect( change.initialOperations[ 0 ] ).toMatchObject( {
		action: 'x_picks_add',
		group: 'x_picks_items',
		args: { field: 'x_picks_items' },
	} );
	expect( providerRowChange( PICKS, SETTINGS, add, variation ).object ).toBe(
		'variation:43'
	);
} );

describe( 'several row entries (1.4.1)', () => {
	it( 'groups them into one entry per group, after the native ones', () => {
		const chosen = jest.fn();
		const actions = rowActions( {
			providerActions: providerRowActions( PICKS, chosen ),
		} );
		const ids = actions.map( ( action ) => action.id );
		expect( ids ).not.toContain( 'provider-x_picks_add' );
		expect( ids ).not.toContain( 'provider-x_picks_remove' );
		const group = actions.find(
			( action ) => action.id === 'provider-group-x_picks_items'
		);
		expect( group.label ).toBe( 'Sample picks…' );
		expect( group.supportsBulk ).toBe( false );
		expect( ids.indexOf( group.id ) ).toBeGreaterThan(
			ids.indexOf( 'edit-details' )
		);
		expect( group.isEligible( product ) ).toBe( true );
		expect( group.isEligible( variation ) ).toBe( false );
		expect( typeof group.RenderModal ).toBe( 'function' );
	} );

	it( 'opens a menu of the group’s actions; choosing one opens its change', () => {
		const chosen = jest.fn();
		const closeModal = jest.fn();
		const group = providerRowActions( PICKS, chosen ).find(
			( action ) => action.id === 'provider-group-x_picks_items'
		);
		const { RenderModal } = group;
		render(
			<RenderModal items={ [ product ] } closeModal={ closeModal } />
		);
		const menu = screen.getByRole( 'menu', { name: 'Sample picks' } );
		expect(
			within( menu )
				.getAllByRole( 'menuitem' )
				.map( ( each ) => each.textContent )
		).toEqual( [
			expect.stringContaining( 'Add recipients' ),
			expect.stringContaining( 'Remove recipients' ),
		] );
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', {
				name: /^Remove recipients/,
			} )
		);
		expect( closeModal ).toHaveBeenCalled();
		expect( chosen ).toHaveBeenCalledWith(
			PICKS.actions.find( ( action ) => action.id === 'x_picks_remove' ),
			product
		);
	} );

	it( 'lists an action that cannot be used disabled, with its reason', () => {
		const registry = {
			...PICKS,
			actions: PICKS.actions.map( ( action ) =>
				action.id === 'x_picks_add'
					? {
							...action,
							available: false,
							unavailableReason:
								'You do not have permission to use this action.',
						}
					: action
			),
		};
		const chosen = jest.fn();
		const { RenderModal } = providerRowActions( registry, chosen ).find(
			( action ) => action.id === 'provider-group-x_picks_items'
		);
		render(
			<RenderModal items={ [ product ] } closeModal={ jest.fn() } />
		);
		const add = screen.getByRole( 'menuitem', { name: /^Add recipients/ } );
		expect( add.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( add.textContent ).toContain(
			'You do not have permission to use this action.'
		);
		fireEvent.click( add );
		expect( chosen ).not.toHaveBeenCalled();
	} );
} );
