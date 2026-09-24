/**
 * The bulk action panel (T49, SPEC §4.3): the descriptor-driven chooser, the
 * argument controls, the operation list, every panel state, and the apply
 * with its 409 answers and request id.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';
import BulkChangePanel from '../../assets/src/panels/bulk';

const {
	CATEGORIES,
	HASH,
	HASH_2,
	IDS,
	REGISTRY,
	SETTINGS,
	callsTo,
	previewResponse,
	restError,
	routeApi,
} = require( './bulk-fixtures' );

jest.mock( '@wordpress/api-fetch', () => {
	const mock = jest.fn( () => new Promise( () => {} ) );
	mock.use = jest.fn();
	mock.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: mock };
} );

const UUID =
	/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

/**
 * Render the panel with the fixtures.
 *
 * @param {Object} props Overrides.
 * @return {Object} Render result plus the callbacks.
 */
function renderPanel( props = {} ) {
	const onClose = jest.fn();
	const onApplied = jest.fn();
	const all = {
		selection: { kind: 'ids', ids: IDS },
		registry: REGISTRY,
		settings: SETTINGS,
		categories: CATEGORIES,
		onClose,
		onApplied,
		pollInterval: 5,
		...props,
	};
	const result = render( <BulkChangePanel { ...all } /> );
	return {
		...result,
		onClose,
		onApplied,
		rerenderWith: ( more ) =>
			result.rerender( <BulkChangePanel { ...all } { ...more } /> ),
	};
}

const panel = () => screen.getByRole( 'dialog' );

/**
 * Choose an action under a group.
 *
 * @param {string} group  Group label.
 * @param {string} action Action label.
 */
function choose( group, action ) {
	const heading = screen.getByRole( 'heading', { name: group } );
	fireEvent.click(
		within( heading.parentElement ).getByRole( 'radio', {
			name: new RegExp( '^' + action ),
		} )
	);
}

const change = ( label, value ) =>
	fireEvent.change( screen.getByLabelText( label ), {
		target: { value },
	} );

/** Choose "Increase regular price by 10 per cent". */
function chooseIncrease() {
	choose( 'Pricing', 'Increase or decrease' );
	change( 'By', 'percent' );
	change( 'How much', '10' );
}

describe( 'bulk panel: chooser and controls', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockImplementation( () => new Promise( () => {} ) );
	} );

	it( 'is a slide-over dialog, not a modal, and starts idle', () => {
		renderPanel();
		expect( panel() ).toHaveAttribute( 'aria-modal', 'false' );
		expect( panel() ).toHaveAttribute( 'data-state', 'idle' );
		expect( screen.getByText( '24 selected products' ) ).toBeVisible();
		const check = screen.getByRole( 'button', {
			name: 'Check the change',
		} );
		expect( check ).toBeDisabled();
		expect( screen.getByText( 'Choose a change first.' ) ).toBeVisible();
	} );

	it( 'states a filter scope with its active filters', () => {
		renderPanel( {
			selection: {
				kind: 'filter',
				token: 'tok',
				count: 4312,
				filters: [ { label: 'Category', value: 'Shirts' } ],
			},
		} );
		expect(
			screen.getByText( 'All 4,312 products matching the current filter' )
		).toBeVisible();
		expect( screen.getByText( 'Category' ) ).toBeVisible();
		expect( screen.getByText( 'Shirts' ) ).toBeVisible();
	} );

	it( 'builds the chooser from the descriptors: the five groups and every action with its description', () => {
		renderPanel();
		const headings = within( panel() )
			.getAllByRole( 'heading', { level: 4 } )
			.map( ( heading ) => heading.textContent );
		expect( headings ).toEqual(
			REGISTRY.groups.map( ( group ) => group.label )
		);
		REGISTRY.actions.forEach( ( action ) => {
			action.groups.forEach( ( groupId ) => {
				const label = REGISTRY.groups.find(
					( group ) => group.id === groupId
				).label;
				const heading = screen.getByRole( 'heading', { name: label } );
				expect(
					within( heading.parentElement ).getByText( action.label )
				).toBeVisible();
			} );
			expect(
				screen.getAllByText( action.description ).length
			).toBeGreaterThan( 0 );
		} );
	} );

	it( 'hard-codes no action: a new descriptor appears with its own controls', () => {
		const registry = {
			...REGISTRY,
			actions: [
				{
					id: 'polish',
					label: 'Polish the name',
					description: 'Makes each name shine.',
					group: 'organisation',
					groups: [ 'organisation' ],
					editKinds: [ 'set' ],
					fields: [ 'name' ],
					args: {
						type: 'object',
						properties: {
							field: {
								title: 'Field',
								description: 'Choose the field.',
								type: 'string',
								enum: [ 'name' ],
								choices: [ { value: 'name', label: 'Name' } ],
								required: true,
							},
							shine: {
								title: 'How shiny',
								description: 'Enter a shine from 1 to 5.',
								type: 'integer',
								minimum: 1,
								maximum: 5,
								required: true,
							},
							skip_already: {
								title: 'Skip rows where the value is already correct',
								description: 'On or off.',
								type: 'boolean',
								default: true,
							},
						},
					},
				},
			],
		};
		renderPanel( { registry } );
		expect(
			within( panel() ).getAllByRole( 'heading', { level: 4 } )
		).toHaveLength( 1 );
		choose( 'Organisation', 'Polish the name' );
		expect( screen.getByLabelText( 'How shiny' ) ).toBeVisible();
		expect( panel() ).toHaveAttribute( 'data-state', 'blocked' );
		expect(
			screen.getAllByText( 'Enter a shine from 1 to 5.' ).length
		).toBeGreaterThan( 0 );
		change( 'How shiny', '9' );
		expect( panel() ).toHaveAttribute( 'data-state', 'blocked' );
		change( 'How shiny', '3' );
		expect( panel() ).toHaveAttribute( 'data-state', 'configuring' );
	} );

	it( 'generates the increase or decrease controls and blocks with the reason in place', () => {
		renderPanel();
		choose( 'Pricing', 'Increase or decrease' );
		const field = screen.getByLabelText( 'Field' );
		expect(
			within( field )
				.getAllByRole( 'option' )
				.map( ( option ) => option.value )
		).toEqual( [ 'regular_price', 'sale_price' ] );
		expect(
			within( screen.getByLabelText( 'Increase or decrease' ) )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'Increase', 'Decrease' ] );
		expect(
			within( screen.getByLabelText( 'By' ) )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'An amount', 'A percentage' ] );

		const amount = screen.getByLabelText( 'How much' );
		const reason =
			'Enter how much to change it by, as a number above 0 like 10 or 2.5.';
		expect( panel() ).toHaveAttribute( 'data-state', 'blocked' );
		expect( amount ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( screen.getByText( reason ) ).toBeVisible();
		expect( screen.getByText( 'Step 1: ' + reason ) ).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Check the change' } )
		).toBeDisabled();

		change( 'How much', '0' );
		expect( panel() ).toHaveAttribute( 'data-state', 'blocked' );
		change( 'How much', '10' );
		expect( panel() ).toHaveAttribute( 'data-state', 'configuring' );
		expect(
			screen.getByRole( 'button', { name: 'Check the change' } )
		).toBeEnabled();
	} );

	it( 'shows round’s decimals, step or ending only for the rounding chosen', () => {
		renderPanel();
		choose( 'Pricing', 'Round prices' );
		expect( screen.getByLabelText( 'Decimals' ) ).toBeVisible();
		expect( screen.queryByLabelText( 'Step' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Ending' ) ).toBeNull();
		change( 'Round to', 'ending' );
		expect( screen.queryByLabelText( 'Decimals' ) ).toBeNull();
		expect( screen.getByLabelText( 'Ending' ) ).toBeVisible();
		expect( screen.getByLabelText( 'Which way' ) ).toHaveValue( 'nearest' );
	} );

	it( 'generates find and replace, prefix or suffix and copy price controls', () => {
		renderPanel();
		choose( 'Organisation', 'Find and replace' );
		expect( screen.getByLabelText( 'Find' ) ).toHaveAttribute(
			'maxLength',
			'200'
		);
		expect( screen.getByLabelText( 'Replace with' ) ).toBeVisible();
		expect(
			screen.getByLabelText( 'Match whole words only' )
		).not.toBeChecked();
		expect(
			screen.getByLabelText( 'Match capitals exactly' )
		).not.toBeChecked();

		choose( 'Organisation', 'Add a prefix or suffix' );
		expect(
			within( screen.getByLabelText( 'Where' ) )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'At the start', 'At the end' ] );
		expect( screen.getByLabelText( 'Text to add' ) ).toBeVisible();

		choose( 'Pricing', 'Copy between regular and sale price' );
		expect( screen.getByLabelText( 'Discount (per cent)' ) ).toHaveValue(
			'0'
		);
		expect(
			screen.getByLabelText(
				'Clear the sale price where it is not below the regular price'
			)
		).not.toBeChecked();
	} );

	it( 'offers the terms picker from the categories prop, with paths', () => {
		renderPanel();
		choose( 'Organisation', 'Add categories or tags' );
		expect( screen.getByLabelText( 'Clothing › Shirts' ) ).toBeVisible();
		expect( panel() ).toHaveAttribute( 'data-state', 'blocked' );
		fireEvent.click( screen.getByLabelText( 'Clothing › Shirts' ) );
		expect( panel() ).toHaveAttribute( 'data-state', 'configuring' );
		fireEvent.change( screen.getByLabelText( 'Search the list' ), {
			target: { value: 'hat' },
		} );
		expect( screen.queryByLabelText( 'Sale' ) ).toBeNull();
		expect( screen.getByLabelText( 'Clothing › Hats' ) ).toBeVisible();
	} );

	it( 'types the set value control by the field', () => {
		renderPanel();
		choose( 'Publishing', 'Set a value' );
		change( 'Field', 'status' );
		expect(
			within( screen.getByLabelText( 'New value' ) )
				.getAllByRole( 'option' )
				.map( ( option ) => option.value )
		).toEqual( [ 'publish', 'draft', 'pending', 'private' ] );
		change( 'Field', 'featured' );
		expect(
			within( screen.getByLabelText( 'New value' ) )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [ 'On', 'Off' ] );

		choose( 'Pricing', 'Set a value' );
		expect( screen.getByLabelText( 'New value' ) ).toHaveAttribute(
			'inputmode',
			'decimal'
		);
		expect(
			screen.getByText( 'Leave it empty to clear this field.' )
		).toBeVisible();
		expect(
			screen.getByLabelText(
				'Clear the sale price where it is not below the regular price'
			)
		).toBeVisible();
	} );

	it( 'starts both switches per field: skip on, variations from the settings, absent on parent-only fields', () => {
		renderPanel();
		REGISTRY.actions.forEach( ( action ) => {
			choose(
				REGISTRY.groups.find(
					( group ) => group.id === action.groups[ 0 ]
				).label,
				action.label
			);
			expect(
				screen.getByRole( 'switch', {
					name: 'Skip rows where the value is already correct',
				} )
			).toBeChecked();
		} );

		choose( 'Publishing', 'Set a value' );
		change( 'Field', 'status' );
		expect(
			screen.getByLabelText( 'Also apply to variations' )
		).toBeChecked();
		change( 'Field', 'catalog_visibility' );
		expect(
			screen.queryByLabelText( 'Also apply to variations' )
		).toBeNull();
		expect(
			screen.getByText(
				'Catalogue visibility belongs to the product, not to each variation.'
			)
		).toBeVisible();

		choose( 'Pricing', 'Set a value' );
		expect(
			screen.getByLabelText( 'Also apply to variations' )
		).not.toBeChecked();
		choose( 'Stock', 'Set a value' );
		expect(
			screen.getByLabelText( 'Also apply to variations' )
		).not.toBeChecked();
		choose( 'Organisation', 'Add categories or tags' );
		expect(
			screen.queryByLabelText( 'Also apply to variations' )
		).toBeNull();
	} );

	it( 'reads the variations default from changed settings', () => {
		renderPanel( {
			settings: {
				settings: {
					...SETTINGS,
					variations_price: true,
					variations_status: false,
				},
			},
		} );
		choose( 'Pricing', 'Increase or decrease' );
		expect(
			screen.getByLabelText( 'Also apply to variations' )
		).toBeChecked();
		choose( 'Publishing', 'Set a value' );
		change( 'Field', 'status' );
		expect(
			screen.getByLabelText( 'Also apply to variations' )
		).not.toBeChecked();
	} );

	it( 'caps the ordered list at twenty steps, with add, remove and reorder', () => {
		renderPanel();
		chooseIncrease();
		const add = screen.getByRole( 'button', {
			name: 'Add another change',
		} );
		for ( let i = 1; i < 20; i++ ) {
			fireEvent.click( add );
		}
		expect(
			screen.getAllByRole( 'button', { name: /^Remove step/ } )
		).toHaveLength( 20 );
		expect( add ).toBeDisabled();
		expect(
			screen.getByText( 'One change can hold at most 20 steps.' )
		).toBeVisible();
		expect(
			screen.getByText(
				'Step 2 has no change chosen yet. Choose one or remove the step.'
			)
		).toBeVisible();

		for ( let i = 20; i > 2; i-- ) {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Remove step ' + i } )
			);
		}
		expect( add ).toBeEnabled();
		// Step 2 is being edited: give it a change, then move it up.
		choose( 'Organisation', 'Find and replace' );
		change( 'Find', 'Cotton' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Move step 2 up' } )
		);
		const titles = Array.from(
			panel().querySelectorAll( '.bulkcat-bulk__optitle' )
		).map( ( node ) => node.textContent );
		expect( titles ).toEqual( [
			'Find and replace — Name',
			'Increase or decrease — Regular price',
		] );
	} );
} );

describe( 'bulk panel: preview, apply and done', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'sends the variations switch as apply_to_variations (T69)', async () => {
		routeApi( apiFetch, {
			'POST preview': () => new Promise( () => {} ),
		} );
		renderPanel();
		chooseIncrease();
		fireEvent.click( screen.getByLabelText( 'Also apply to variations' ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		await waitFor( () =>
			expect( callsTo( apiFetch, 'POST preview' ) ).toHaveLength( 1 )
		);
		expect(
			callsTo( apiFetch, 'POST preview' )[ 0 ].data.operations[ 0 ].args
				.apply_to_variations
		).toBe( true );
	} );

	it( 'moves idle → configuring → previewing → ready, sending the operation list', async () => {
		let answer;
		routeApi( apiFetch, {
			'POST preview': () =>
				new Promise( ( resolve ) => {
					answer = resolve;
				} ),
		} );
		renderPanel();
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		expect( panel() ).toHaveAttribute( 'data-state', 'previewing' );
		expect(
			screen.getByText(
				'Working out what would change. Nothing is saved.'
			)
		).toBeVisible();
		expect( callsTo( apiFetch, 'POST preview' )[ 0 ].data ).toEqual( {
			operations: [
				{
					action: 'adjust',
					args: {
						field: 'regular_price',
						direction: 'increase',
						by: 'percent',
						amount: '10',
						skip_already: true,
						apply_to_variations: false,
					},
				},
			],
			ids: IDS,
			sample_size: 25,
		} );
		answer( previewResponse() );
		await waitFor( () =>
			expect( panel() ).toHaveAttribute( 'data-state', 'ready' )
		);
		expect(
			screen.getByRole( 'button', { name: 'Apply to 20 products' } )
		).toBeEnabled();
	} );

	it( 'disables Apply when the preview is empty', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse( {
				totals: {
					...previewResponse().totals,
					would_change: 0,
					already: 24,
				},
			} ),
		} );
		renderPanel();
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		await waitFor( () =>
			expect( panel() ).toHaveAttribute( 'data-state', 'empty' )
		);
		expect( screen.getByText( 'Nothing would change' ) ).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Apply to 0 products' } )
		).toBeDisabled();
		expect(
			screen.getByText(
				'Nothing would change, so there is nothing to apply.'
			)
		).toBeVisible();
	} );

	it( 'goes stale when an operation or the selection changes, and Apply becomes Preview again', async () => {
		routeApi( apiFetch, { 'POST preview': previewResponse() } );
		const { rerenderWith } = renderPanel();
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		await screen.findByRole( 'button', { name: 'Apply to 20 products' } );

		// Checking folds the steps to their summaries; Edit opens one again.
		expect( screen.queryByLabelText( 'How much' ) ).toBeNull();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit step 1' } )
		);
		change( 'How much', '12' );
		expect( panel() ).toHaveAttribute( 'data-state', 'stale' );
		expect(
			screen.queryByRole( 'button', { name: /^Apply to/ } )
		).toBeNull();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Preview again' } )
		);
		await waitFor( () =>
			expect( panel() ).toHaveAttribute( 'data-state', 'ready' )
		);
		expect( callsTo( apiFetch, 'POST preview' ) ).toHaveLength( 2 );

		rerenderWith( { selection: { kind: 'ids', ids: IDS.slice( 1 ) } } );
		expect( panel() ).toHaveAttribute( 'data-state', 'stale' );
		expect(
			screen.getByRole( 'button', { name: 'Preview again' } )
		).toBeVisible();
	} );

	it( 'applies with the uuid, operations, ids, source and preview hash, then shows progress and the result', async () => {
		let reads = 0;
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST batches': {
				batch_id: 7,
				status: 'queued',
				total_items: 24,
				replayed: false,
			},
			'GET batches/7': () => {
				reads++;
				return reads < 2
					? {
							batch_id: 7,
							status: 'running',
							total_items: 24,
							done_items: 6,
						}
					: {
							batch_id: 7,
							status: 'complete',
							total_items: 24,
							done_items: 24,
							summary: [],
						};
			},
		} );
		const onUndo = jest.fn();
		const onViewHistory = jest.fn();
		const { onApplied } = renderPanel( { onUndo, onViewHistory } );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);

		const [ sent ] = callsTo( apiFetch, 'POST batches' );
		expect( sent.data ).toEqual( {
			uuid: expect.stringMatching( UUID ),
			operations: callsTo( apiFetch, 'POST preview' )[ 0 ].data
				.operations,
			ids: IDS,
			source: 'bulk',
			preview_hash: HASH,
		} );
		await waitFor( () => expect( onApplied ).toHaveBeenCalledWith( 7 ) );
		expect( panel() ).toHaveAttribute( 'data-state', 'done' );
		expect(
			await screen.findByText(
				'You can leave this page. The update carries on in the background and appears in History when it finishes.'
			)
		).toBeVisible();
		expect(
			await screen.findByText( '24 products updated.' )
		).toBeVisible();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Undo this change' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'View in History' } )
		);
		expect( onUndo ).toHaveBeenCalledWith( 7 );
		expect( onViewHistory ).toHaveBeenCalledWith( 7 );
	} );

	it( 'is applying while the batch request is in flight', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST batches': () => new Promise( () => {} ),
		} );
		renderPanel();
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		expect( panel() ).toHaveAttribute( 'data-state', 'applying' );
		expect(
			screen.getByRole( 'button', { name: 'Apply to 20 products' } )
		).toBeDisabled();
	} );

	it( 'hands a stalled batch to renderStalled', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST batches': { batch_id: 8, status: 'queued', total_items: 24 },
			'GET batches/8': {
				batch_id: 8,
				status: 'queued',
				total_items: 24,
				done_items: 0,
				stalled: true,
				stalled_message: 'The background queue has not run.',
			},
		} );
		renderPanel( {
			renderStalled: ( report ) => (
				<p data-testid="stalled">{ report.stalled_message }</p>
			),
		} );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		expect( await screen.findByTestId( 'stalled' ) ).toHaveTextContent(
			'The background queue has not run.'
		);
	} );

	it( 'sends a filter token with the count the preview showed', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse( {
				selection: { kind: 'filter', count: 30, current_count: 24 },
			} ),
			'POST batches': { batch_id: 9, status: 'queued', total_items: 24 },
		} );
		renderPanel( {
			selection: { kind: 'filter', token: 'signed-token', count: 30 },
		} );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		expect( callsTo( apiFetch, 'POST preview' )[ 0 ].data.token ).toBe(
			'signed-token'
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		const [ sent ] = callsTo( apiFetch, 'POST batches' );
		expect( sent.data ).toMatchObject( {
			token: 'signed-token',
			preview_hash: HASH,
			preview_count: 24,
			source: 'bulk',
		} );
		expect( sent.data.ids ).toBeUndefined();
		await screen.findByText( 'Applying the change' );
	} );

	it( 'states both numbers on 409 bulkcat_selection_changed, then applies anyway with the same uuid', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST batches': ( options ) =>
				options.data.confirm_changed
					? { batch_id: 10, status: 'queued', total_items: 26 }
					: restError(
							'bulkcat_selection_changed',
							'The filter now matches 26 products.',
							{ status: 409, previewed: 24, current: 26 }
						),
		} );
		const { onApplied } = renderPanel( {
			selection: { kind: 'filter', token: 'tok', count: 24 },
		} );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		expect(
			await screen.findByText(
				'When you checked the change it matched 24 products. It matches 26 products now.'
			)
		).toBeVisible();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Apply anyway' } )
		);
		await waitFor( () => expect( onApplied ).toHaveBeenCalledWith( 10 ) );
		const [ first, second ] = callsTo( apiFetch, 'POST batches' );
		expect( second.data.confirm_changed ).toBe( true );
		expect( first.data.confirm_changed ).toBeUndefined();
		expect( second.data.uuid ).toBe( first.data.uuid );
	} );

	it( 'offers Preview again on 409 bulkcat_selection_changed', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST batches': restError(
				'bulkcat_selection_changed',
				'Changed.',
				{
					status: 409,
					previewed: 24,
					current: 30,
				}
			),
		} );
		renderPanel( {
			selection: { kind: 'filter', token: 'tok', count: 24 },
		} );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Preview again' } )
		);
		await waitFor( () =>
			expect( callsTo( apiFetch, 'POST preview' ) ).toHaveLength( 2 )
		);
		expect( screen.queryByText( /It matches 30 products now/ ) ).toBeNull();
	} );

	it( 're-runs the preview on 409 bulkcat_preview_stale, and a new preview gets a new uuid', async () => {
		let previews = 0;
		let batches = 0;
		routeApi( apiFetch, {
			'POST preview': () => {
				previews++;
				return previewResponse( {
					hash: previews === 1 ? HASH : HASH_2,
				} );
			},
			'POST batches': () => {
				batches++;
				return batches === 1
					? restError( 'bulkcat_preview_stale', 'Stale.', {
							status: 409,
						} )
					: { batch_id: 11, status: 'queued', total_items: 24 };
			},
		} );
		const { onApplied } = renderPanel();
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		await waitFor( () => expect( previews ).toBe( 2 ) );
		expect(
			await screen.findByText(
				'The products or the settings changed after you checked, so the preview has been worked out again. Check it, then apply.'
			)
		).toBeVisible();
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		await waitFor( () => expect( onApplied ).toHaveBeenCalledWith( 11 ) );
		const [ first, second ] = callsTo( apiFetch, 'POST batches' );
		expect( second.data.preview_hash ).toBe( HASH_2 );
		expect( second.data.uuid ).not.toBe( first.data.uuid );
	} );

	it( 'reuses the uuid when an apply is retried after a failure', async () => {
		let batches = 0;
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST batches': () => {
				batches++;
				return batches === 1
					? restError(
							'fetch_error',
							'You are probably offline.',
							{}
						)
					: { batch_id: 12, status: 'queued', total_items: 24 };
			},
		} );
		const { onApplied } = renderPanel();
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		expect(
			await screen.findByText( 'You are probably offline.' )
		).toBeVisible();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show details' } )
		);
		expect( screen.getByText( 'fetch_error' ) ).toBeVisible();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Apply to 20 products' } )
		);
		await waitFor( () => expect( onApplied ).toHaveBeenCalledWith( 12 ) );
		const [ first, second ] = callsTo( apiFetch, 'POST batches' );
		expect( second.data.uuid ).toBe( first.data.uuid );
	} );

	it( 'closes on Escape and returns focus to the control that opened it', () => {
		const opener = document.createElement( 'button' );
		document.body.appendChild( opener );
		opener.focus();
		const onClose = jest.fn();
		const { unmount } = render(
			<BulkChangePanel
				selection={ { kind: 'ids', ids: IDS } }
				registry={ REGISTRY }
				settings={ SETTINGS }
				onClose={ onClose }
			/>
		);
		expect( document.activeElement ).toBe(
			screen.getByRole( 'heading', { name: 'Bulk change' } )
		);
		fireEvent.keyDown( document.activeElement, { key: 'Escape' } );
		expect( onClose ).toHaveBeenCalled();
		unmount();
		expect( document.activeElement ).toBe( opener );
		opener.remove();
	} );
} );
