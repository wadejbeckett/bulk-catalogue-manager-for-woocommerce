/**
 * External actions in the bulk panel (T105, docs/EXTENSIONS.md §5.3; E3,
 * E13): the chooser lists a provider's actions from the descriptors alone,
 * after the native groups, with the multichoice control; an unavailable
 * action is disabled with its reason; a change mixing kinds says why in
 * place and cannot be checked or applied; provider text is rendered as text;
 * and the catalogue's grid ignores external fields. The fixture is the
 * extended `/fields` payload the PHP suite pins
 * (tests/Unit/Extension/fields-external.snapshot.json).
 */
import { fireEvent, render, screen, within } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';
import BulkChangePanel from '../../assets/src/panels/bulk';
import {
	mixesKinds,
	operationProblem,
	propertyKind,
	toRequestOperations,
	unavailableReason,
} from '../../assets/src/panels/bulk/descriptors';
import { alwaysConfirmReasons } from '../../assets/src/panels/bulk/confirm';
import {
	descriptorsOf,
	fieldsFromRegistry,
} from '../../assets/src/panels/catalogue/fields';

const { IDS, SETTINGS } = require( './bulk-fixtures' );
const EXTERNAL = require( '../Unit/Extension/fields-external.snapshot.json' );

jest.mock( '@wordpress/api-fetch', () => {
	const mock = jest.fn( () => new Promise( () => {} ) );
	mock.use = jest.fn();
	mock.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: mock };
} );

const MIXED =
	"This action can't be combined with other kinds of change. Make it a separate change.";

/**
 * The fixture with some actions replaced.
 *
 * @param {Function} change Maps an external action to its replacement.
 * @return {Object} Registry.
 */
function withActions( change ) {
	return {
		...EXTERNAL,
		actions: EXTERNAL.actions.map( ( action ) =>
			action.provider ? change( action ) : action
		),
	};
}

/**
 * An action as `/fields` sends it when this user cannot use it now: its
 * label and the reason only (no description, arguments or confirmation).
 *
 * @param {Object} action The usable entry.
 * @param {string} reason Why it is disabled.
 * @return {Object} The disabled entry.
 */
function disabled( action, reason ) {
	return {
		...action,
		description: '',
		args: { type: 'object', properties: {}, additionalProperties: false },
		confirmReason: '',
		available: false,
		unavailableReason: reason,
	};
}

/**
 * Render the panel over a registry.
 *
 * @param {Object} registry The `/fields` payload.
 * @return {Object} Render result.
 */
function renderPanel( registry = EXTERNAL ) {
	return render(
		<BulkChangePanel
			selection={ { kind: 'ids', ids: IDS } }
			registry={ registry }
			settings={ SETTINGS }
			onClose={ jest.fn() }
			onApplied={ jest.fn() }
			pollInterval={ 5 }
		/>
	);
}

const panel = () => screen.getByRole( 'dialog' );

/**
 * The radio of an action under a group.
 *
 * @param {string} group  Group label.
 * @param {string} action Action label.
 * @return {Element} Radio.
 */
function radio( group, action ) {
	const heading = screen.getByRole( 'heading', { name: group } );
	return within( heading.parentElement ).getByRole( 'radio', {
		name: new RegExp( '^' + action ),
	} );
}

describe( 'external actions in the bulk panel', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockImplementation( () => new Promise( () => {} ) );
	} );

	it( 'lists the provider group after the native groups, its actions in the provider’s order', () => {
		renderPanel();
		const headings = within( panel() )
			.getAllByRole( 'heading', { level: 4 } )
			.map( ( heading ) => heading.textContent );
		expect( headings ).toEqual( [
			'Pricing',
			'Stock',
			'Organisation',
			'Shipping and tax',
			'Publishing',
			'Sample labels',
		] );
		const group = screen.getByRole( 'heading', {
			name: 'Sample labels',
		} ).parentElement;
		expect(
			within( group )
				.getAllByRole( 'radio' )
				.map( ( each ) => each.value )
		).toEqual( [
			'x_sample_labels:x_sample_add',
			'x_sample_labels:x_sample_remove',
			'x_sample_labels:x_sample_replace',
			'x_sample_labels:x_sample_clear',
		] );
		expect( radio( 'Organisation', 'Set sample note' ) ).toBeVisible();
	} );

	it( 'generates the multichoice control and blocks until a label is chosen', () => {
		renderPanel();
		fireEvent.click( radio( 'Sample labels', 'Add labels' ) );
		const labels = screen.getByRole( 'group', { name: 'Labels' } );
		expect(
			within( labels )
				.getAllByRole( 'checkbox' )
				.map( ( box ) => box.parentElement.textContent )
		).toEqual( [ 'Blue', 'Green', 'Red', 'Sale' ] );
		expect( panel() ).toHaveAttribute( 'data-state', 'blocked' );
		expect(
			screen.getAllByText( /Choose at least one label from the list\./ )
				.length
		).toBeGreaterThan( 0 );
		fireEvent.click( within( labels ).getByLabelText( 'Red' ) );
		expect( panel() ).toHaveAttribute( 'data-state', 'configuring' );
	} );

	it( 'sends the chosen labels as a list', () => {
		const action = EXTERNAL.actions.find(
			( each ) => each.id === 'x_sample_add'
		);
		expect( propertyKind( 'labels', action.args.properties.labels ) ).toBe(
			'multichoice'
		);
		expect(
			toRequestOperations( EXTERNAL, [
				{
					action: 'x_sample_add',
					group: 'x_sample_labels',
					args: {
						field: 'x_sample_labels',
						labels: [ 'red', 'blue' ],
						skip_already: true,
					},
				},
			] )
		).toEqual( [
			{
				action: 'x_sample_add',
				args: {
					field: 'x_sample_labels',
					labels: [ 'red', 'blue' ],
					skip_already: true,
				},
			},
		] );
		const tooMany = {
			action: 'x_sample_add',
			args: {
				field: 'x_sample_labels',
				labels: Array.from( { length: 21 }, () => 'red' ),
			},
		};
		expect( operationProblem( EXTERNAL, tooMany )?.arg ).toBe( 'labels' );
		const unknown = {
			action: 'x_sample_add',
			args: { field: 'x_sample_labels', labels: [ 'purple' ] },
		};
		expect( operationProblem( EXTERNAL, unknown )?.arg ).toBe( 'labels' );
	} );

	it( 'disables an unavailable action with its reason', () => {
		const reason =
			'Sample labels needs its settings saved before it can be used.';
		const registry = withActions( ( action ) =>
			disabled( action, reason )
		);
		renderPanel( registry );
		const add = radio( 'Sample labels', 'Add labels' );
		expect( add ).toBeDisabled();
		// The server sends nothing of the provider's but the label and reason.
		expect( screen.queryByText( 'Adds the chosen labels.' ) ).toBeNull();
		expect(
			document.getElementById( add.getAttribute( 'aria-describedby' ) )
		).toHaveTextContent( reason );
		expect( unavailableReason( registry.actions.at( -1 ) ) ).toBe( reason );
		expect( unavailableReason( registry.actions[ 0 ] ) ).toBe( '' );
	} );

	it( 'disables an action the user may not use, saying so', () => {
		renderPanel(
			withActions( ( action ) =>
				disabled(
					action,
					'You do not have permission to use this action.'
				)
			)
		);
		expect( radio( 'Organisation', 'Set sample note' ) ).toBeDisabled();
		expect(
			screen.getAllByText(
				'You do not have permission to use this action.'
			).length
		).toBeGreaterThan( 0 );
	} );

	it( 'says in place that a change cannot mix kinds, and blocks it', () => {
		renderPanel();
		fireEvent.click( radio( 'Stock', 'Set a value' ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Add another change' } )
		);
		fireEvent.click( radio( 'Sample labels', 'Clear labels' ) );
		expect( screen.getAllByText( MIXED ).length ).toBeGreaterThan( 0 );
		expect( panel() ).toHaveAttribute( 'data-state', 'blocked' );
		expect(
			mixesKinds( EXTERNAL, [
				{ action: 'set_value' },
				{ action: 'x_sample_clear' },
			] )
		).toBe( true );
		expect(
			mixesKinds( EXTERNAL, [
				{ action: 'x_sample_add' },
				{ action: 'x_sample_clear' },
			] )
		).toBe( false );
		const other = {
			...EXTERNAL,
			actions: [
				...EXTERNAL.actions,
				{
					...EXTERNAL.actions.at( -1 ),
					id: 'x_other_set',
					provider: 'other',
				},
			],
		};
		expect(
			mixesKinds( other, [
				{ action: 'x_sample_add' },
				{ action: 'x_other_set' },
			] )
		).toBe( true );
	} );

	it( 'always confirms with the provider’s own reason', () => {
		const reasons = alwaysConfirmReasons(
			EXTERNAL,
			[
				{
					action: 'x_sample_replace',
					args: { field: 'x_sample_labels', labels: [ 'red' ] },
				},
			],
			24
		);
		expect( reasons ).toEqual( [
			'This replaces every label on the chosen products.',
		] );
		expect(
			alwaysConfirmReasons(
				EXTERNAL,
				[
					{
						action: 'x_sample_add',
						args: { field: 'x_sample_labels', labels: [ 'red' ] },
					},
				],
				24
			)
		).toEqual( [] );
	} );

	it( 'renders provider strings as text, never as markup (E13)', () => {
		const registry = withActions( ( action ) =>
			action.id === 'x_sample_add'
				? {
						...action,
						label: '<img src=x onerror="alert(1)">Add labels',
						description: '<script>alert(2)</script>Adds.',
					}
				: action
		);
		renderPanel( registry );
		expect( document.querySelector( '.bulkcat-bulk img' ) ).toBeNull();
		expect( document.querySelector( '.bulkcat-bulk script' ) ).toBeNull();
		expect(
			screen.getByText( '<script>alert(2)</script>Adds.' )
		).toBeVisible();
	} );
} );

describe( 'the catalogue grid and external fields', () => {
	it( 'ignores fields whose source is external', () => {
		const keys = descriptorsOf( EXTERNAL ).map( ( field ) => field.key );
		expect( keys.some( ( key ) => key.startsWith( 'x_' ) ) ).toBe( false );
		expect( keys ).toEqual(
			descriptorsOf(
				require( '../Unit/Fields/fields.snapshot.json' )
			).map( ( field ) => field.key )
		);
		expect(
			fieldsFromRegistry( EXTERNAL ).some( ( field ) =>
				field.id.startsWith( 'x_' )
			)
		).toBe( false );
		const withViews = {
			...EXTERNAL,
			fields: EXTERNAL.fields.map( ( field ) =>
				field.source === 'external'
					? { ...field, dataviews: { type: 'text' } }
					: field
			),
		};
		expect(
			descriptorsOf( withViews ).some(
				( field ) => field.source === 'external'
			)
		).toBe( false );
	} );
} );

describe( 'the preview of an external change', () => {
	const { routeApi } = require( './bulk-fixtures' );
	const SALE =
		'Adding the Sale label asks for a second click before it is applied.';

	/**
	 * A /preview answer for Add labels, as the server sends it (T106).
	 *
	 * @return {Object} Answer.
	 */
	function externalPreview() {
		return {
			totals: {
				matched: 3,
				computed: 3,
				estimated: false,
				would_change: 2,
				already: 0,
				would_fail: {
					'“Sample labels” is kept on the product, not on each variation.': 1,
				},
				rows: { changed: 2, already: 0, skipped: 1, failed: 0 },
				by_type: {
					product: { matched: 2, would_change: 2 },
					variation: { matched: 1, would_change: 0 },
				},
			},
			summary: 'Sample labels will change on 2 products.',
			sample: [
				{
					object_id: 101,
					object_type: 'product',
					name: 'Blue Shirt',
					field: 'x_sample_labels',
					fieldLabel: 'Sample labels',
					current: 'Blue',
					new: 'Blue, Sale',
					change: 'Adds Sale',
					notes: [ 'Keeps 1 existing label.' ],
					state: 'changed',
					reason: '',
					code: '',
					replaces: false,
					note: '',
					context: [],
					external: true,
				},
				{
					object_id: 102,
					object_type: 'product',
					name: 'Formal Shirt',
					field: 'x_sample_labels',
					fieldLabel: 'Sample labels',
					current: 'No labels',
					new: 'Sale',
					change: 'Adds Sale',
					notes: [],
					state: 'changed',
					reason: '',
					code: '',
					replaces: false,
					note: '',
					context: [],
					external: true,
				},
				{
					object_id: 201,
					object_type: 'variation',
					name: 'Rain Jacket - Blue, Large',
					field: 'x_sample_labels',
					fieldLabel: 'Sample labels',
					current: '',
					new: '',
					change: '',
					notes: [],
					state: 'skipped',
					reason: '“Sample labels” is kept on the product, not on each variation.',
					code: 'bulkcat_not_available',
					replaces: false,
					note: '',
					context: [],
					external: true,
				},
			],
			warnings: {
				sale_above_regular: 0,
				price_not_positive: 0,
				stock_negative: 0,
				sku_duplicate: 0,
			},
			unreadable: [],
			selection: { kind: 'ids', count: 3 },
			hash: 'a'.repeat( 64 ),
			unavailable_count: 0,
			scope_hash: 'c'.repeat( 64 ),
			operations: [
				{
					action: 'x_sample_add',
					args: {
						field: 'x_sample_labels',
						labels: [ 'sale' ],
						skip_already: true,
					},
				},
			],
			availability: null,
			external: {
				provider: 'sample',
				label: 'Sample labels',
				version: '1.0.0',
				warnings: [
					{
						code: 'x_sample_sale',
						sentence: SALE,
						count: 2,
						confirm: true,
					},
				],
				confirm: true,
				reasons: [ SALE ],
			},
		};
	}

	beforeEach( () => {
		apiFetch.mockReset();
		routeApi( apiFetch, { 'POST preview': externalPreview() } );
	} );

	it( 'shows the provider’s text, what changes, notes and warnings, and always confirms', async () => {
		renderPanel();
		fireEvent.click( radio( 'Sample labels', 'Add labels' ) );
		fireEvent.click(
			within(
				screen.getByRole( 'group', { name: 'Labels' } )
			).getByLabelText( 'Sale' )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		await screen.findByText( 'Sample labels will change on 2 products.' );
		const row = screen.getByRole( 'rowheader', {
			name: 'Blue Shirt',
		} ).parentElement;
		expect( within( row ).getByText( 'Sample labels' ) ).toBeVisible();
		expect( within( row ).getByText( 'Blue' ).tagName ).toBe( 'DEL' );
		expect( within( row ).getByText( 'Blue, Sale' ).tagName ).toBe(
			'STRONG'
		);
		expect( within( row ).getByText( 'Adds Sale' ) ).toBeVisible();
		expect(
			within( row ).getByText( 'Keeps 1 existing label.' )
		).toBeVisible();
		const skipped = screen.getByRole( 'rowheader', {
			name: 'Rain Jacket - Blue, Large',
		} ).parentElement;
		expect( skipped ).toHaveTextContent(
			'Will be skipped: “Sample labels” is kept on the product, not on each variation.'
		);
		const block = document.querySelector( '[data-block="warnings"]' );
		expect( block ).toHaveTextContent( SALE + ' — 2 products' );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Apply to 2 products' } )
		);
		expect(
			screen.getByRole( 'button', { name: 'Yes, change 2 products' } )
		).toBeVisible();
		expect( screen.getByText( SALE ) ).toBeVisible(); // Restated by the confirmation; the warning reads "… — 2 products".
	} );
} );
