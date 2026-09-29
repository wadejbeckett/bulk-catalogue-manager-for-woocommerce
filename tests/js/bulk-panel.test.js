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
	OPERATIONS,
	REGISTRY,
	SCOPE,
	SCOPE_2,
	SETTINGS,
	callsTo,
	grantFor,
	previewResponse,
	restError,
	routeApi,
} = require( './bulk-fixtures' );

/** A snapshot selection as the catalogue hands it over (T84). */
const SNAPSHOT = {
	kind: 'snapshot',
	token: 'v2.snap.1.sig',
	key: 'h1',
	count: 1250,
	byType: { products: 1200, variations: 50 },
	waiting: '',
};

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

	it( 'states the whole exact selection by type, with its review, never as all matches of a filter', () => {
		const onReview = jest.fn();
		renderPanel( { selection: SNAPSHOT, onReview } );
		expect(
			screen.getByText( '1,200 products and 50 variations selected' )
		).toBeVisible();
		expect( panel().textContent ).not.toContain(
			'matching the current filter'
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Review selected products' } )
		);
		expect( onReview ).toHaveBeenCalled();
	} );

	it( 'waits, with the reason, while the selection is not settled', () => {
		renderPanel( {
			selection: {
				...SNAPSHOT,
				waiting:
					'Your selection is still being saved. Wait a moment, then check the change.',
			},
		} );
		chooseIncrease();
		expect(
			screen.getByRole( 'button', { name: 'Check the change' } )
		).toBeDisabled();
		expect(
			screen.getByText(
				'Your selection is still being saved. Wait a moment, then check the change.'
			)
		).toBeVisible();
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

	it( 'offers Keep current status only on a Published Status step (T91)', () => {
		renderPanel();
		choose( 'Publishing', 'Set a value' );
		change( 'Field', 'status' );
		const keep =
			'For products changed by someone else while Off: keep their current status and clear the Off record';
		expect( screen.getByLabelText( 'New value' ) ).toHaveValue( 'publish' );
		expect( screen.getByLabelText( keep ) ).not.toBeChecked();
		change( 'New value', 'draft' );
		expect( screen.queryByLabelText( keep ) ).toBeNull();
		change( 'New value', 'publish' );
		expect( screen.getByLabelText( keep ) ).toBeVisible();
		change( 'Field', 'featured' );
		expect( screen.queryByLabelText( keep ) ).toBeNull();
		choose( 'Pricing', 'Set a value' );
		expect( screen.queryByLabelText( keep ) ).toBeNull();
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

	it( 'says a server failure of the preview in plain words, never the server’s own page (T85)', async () => {
		routeApi( apiFetch, {
			'POST preview': restError(
				'internal_server_error',
				'<p>There has been a critical error on this website.</p><p><a href="https://wordpress.org/documentation/article/faq-troubleshooting/">Learn more about troubleshooting WordPress.</a></p>',
				{ status: 500 }
			),
		} );
		renderPanel();
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		const alert = await screen.findByRole( 'alert' );
		expect( alert.textContent ).toContain(
			'Something went wrong. Try again in a moment.'
		);
		expect( alert.textContent ).not.toContain( '<p>' );
		expect( alert.textContent ).not.toContain( 'critical error' );
		// The code stays behind Show details, and Check the change is offered again.
		fireEvent.click(
			within( alert ).getByRole( 'button', { name: 'Show details' } )
		);
		expect(
			within( alert ).getByText( 'internal_server_error' )
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Check the change' } )
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

	it( 'does not promise an undo the screen cannot yet make for a change this large (T85)', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST requests': grantFor,
			'POST batches': {
				batch_id: 8,
				status: 'queued',
				total_items: 24,
				replayed: false,
			},
			'GET batches/8': {
				batch_id: 8,
				status: 'complete',
				total_items: 24,
				done_items: 24,
				failed_items: 0,
				pending_items: 0,
				summary: [],
			},
		} );
		const onUndo = jest.fn();
		renderPanel( {
			settings: { ...SETTINGS, undoLimit: 10 },
			onUndo,
		} );
		const sentence =
			'Every change is saved with the old values, but undoing a change to more than 10 products and variations is not yet supported from this screen.';
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		const apply = await screen.findByRole( 'button', {
			name: 'Apply to 20 products',
		} );
		expect( panel().textContent ).toContain(
			'Step 2 of 3 · Too large to undo from this screen'
		);
		expect( panel().textContent ).not.toContain(
			'You can undo this afterwards'
		);
		expect( panel().textContent ).toContain( sentence );
		fireEvent.click( apply );
		await screen.findByText( '24 products updated.' );
		expect( panel().textContent ).toContain( sentence );
		expect(
			screen.queryByText(
				'The old values are saved, so you can put this back at any time.'
			)
		).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: 'Undo this change' } )
		).toBeNull();
	} );

	it( 'still promises undo within the size this host can undo (T85)', async () => {
		routeApi( apiFetch, { 'POST preview': previewResponse() } );
		renderPanel( { settings: { ...SETTINGS, undoLimit: 1000 } } );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		await screen.findByRole( 'button', { name: 'Apply to 20 products' } );
		expect( panel().textContent ).toContain(
			'You can undo this afterwards'
		);
		expect( panel().textContent ).toContain(
			'Every change is saved with the old values. One click in History puts them back.'
		);
	} );

	it( 'applies through a grant: POST /requests, then the durable body, then progress and the result', async () => {
		let finish = false;
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST requests': grantFor,
			'POST batches': {
				batch_id: 7,
				status: 'queued',
				total_items: 24,
				replayed: false,
			},
			'GET batches/7': () => {
				return ! finish
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
							failed_items: 0,
							pending_items: 0,
							summary: [],
						};
			},
		} );
		const onUndo = jest.fn();
		const onViewHistory = jest.fn();
		const onFinished = jest.fn();
		const { onApplied } = renderPanel( {
			onUndo,
			onViewHistory,
			onFinished,
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

		await waitFor( () =>
			expect( callsTo( apiFetch, 'POST batches' ) ).toHaveLength( 1 )
		);
		const [ issued ] = callsTo( apiFetch, 'POST requests' );
		expect( issued.data ).toEqual( {
			operation: 'batch.apply',
			intent: {
				source: 'bulk',
				operations: OPERATIONS,
				scope_hash: SCOPE,
				ids: IDS,
			},
		} );
		const [ sent ] = callsTo( apiFetch, 'POST batches' );
		expect( sent.data ).toEqual( {
			uuid: expect.stringMatching( UUID ),
			request_proof: expect.any( String ),
			intent: {
				version: 1,
				source: 'bulk',
				operations: OPERATIONS,
				scope_hash: SCOPE,
				ids: IDS,
			},
			preview_hash: HASH,
		} );
		// No 1.0 keys.
		[ 'operations', 'ids', 'source', 'token', 'preview_count' ].forEach(
			( key ) => expect( sent.data[ key ] ).toBeUndefined()
		);
		await waitFor( () =>
			expect( onApplied ).toHaveBeenCalledWith( 7, {
				selection: { kind: 'ids', ids: IDS },
				replayed: false,
			} )
		);
		expect( panel() ).toHaveAttribute( 'data-state', 'done' );
		expect(
			await screen.findByText(
				'You can leave this page. The update carries on in the background and appears in History when it finishes.'
			)
		).toBeVisible();
		expect( onFinished ).not.toHaveBeenCalled();
		finish = true;
		expect(
			await screen.findByText( '24 products updated.' )
		).toBeVisible();
		expect( onFinished ).toHaveBeenCalledTimes( 1 );
		expect( onFinished.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			batch_id: 7,
			status: 'complete',
		} );
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
			'POST requests': grantFor,
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
			'POST requests': grantFor,
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

	it( 'previews and applies a snapshot by its current token, with no count', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST requests': grantFor,
			'POST batches': { batch_id: 9, status: 'queued', total_items: 24 },
		} );
		const { onApplied, rerenderWith } = renderPanel( {
			selection: SNAPSHOT,
		} );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		expect( callsTo( apiFetch, 'POST preview' )[ 0 ].data ).toMatchObject( {
			token: SNAPSHOT.token,
		} );
		expect(
			callsTo( apiFetch, 'POST preview' )[ 0 ].data.ids
		).toBeUndefined();
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		await waitFor( () =>
			expect( callsTo( apiFetch, 'POST batches' ) ).toHaveLength( 1 )
		);
		expect( callsTo( apiFetch, 'POST requests' )[ 0 ].data.intent ).toEqual(
			{
				source: 'bulk',
				operations: OPERATIONS,
				scope_hash: SCOPE,
				token: SNAPSHOT.token,
			}
		);
		const [ sent ] = callsTo( apiFetch, 'POST batches' );
		expect( sent.data ).toMatchObject( {
			token: SNAPSHOT.token,
			preview_hash: HASH,
		} );
		expect( sent.data.preview_count ).toBeUndefined();
		expect( sent.data.confirm_changed ).toBeUndefined();
		await waitFor( () =>
			expect( onApplied ).toHaveBeenCalledWith( 9, {
				selection: SNAPSHOT,
				replayed: false,
			} )
		);
		await screen.findByText( 'Applying the change' );
		// Clearing the live selection afterwards leaves the scope applied.
		rerenderWith( {
			selection: {
				...SNAPSHOT,
				token: '',
				count: 0,
				byType: { products: 0, variations: 0 },
			},
		} );
		expect(
			screen.getByText( '1,200 products and 50 variations selected' )
		).toBeVisible();
	} );

	it( 'shows the server’s sentence on 409 bulkcat_selection_changed and offers only Preview again', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST requests': grantFor,
			'POST batches': restError(
				'bulkcat_selection_changed',
				'The selection changed after you checked it. Preview the change again.',
				{ status: 409 }
			),
		} );
		renderPanel( { selection: SNAPSHOT } );
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
				'The selection changed after you checked it. Preview the change again.'
			)
		).toBeVisible();
		expect(
			screen.queryByRole( 'button', { name: 'Apply anyway' } )
		).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'Apply to 20 products' } )
		).toBeDisabled();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Preview again' } )
		);
		await waitFor( () =>
			expect( callsTo( apiFetch, 'POST preview' ) ).toHaveLength( 2 )
		);
	} );

	it( 're-runs the preview on 409 bulkcat_preview_stale and applies with the same grant when the scope is the same', async () => {
		let previews = 0;
		let batches = 0;
		routeApi( apiFetch, {
			'POST preview': () => {
				previews++;
				return previewResponse( {
					hash: previews === 1 ? HASH : HASH_2,
				} );
			},
			'POST requests': grantFor,
			'POST batches': () => {
				batches++;
				return batches === 1
					? restError(
							'bulkcat_preview_stale',
							'This change no longer matches its preview. Preview the exact selected products again, then apply it.',
							{ status: 409 }
						)
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
		await waitFor( () => expect( onApplied ).toHaveBeenCalled() );
		const [ first, second ] = callsTo( apiFetch, 'POST batches' );
		expect( second.data.preview_hash ).toBe( HASH_2 );
		expect( second.data.uuid ).toBe( first.data.uuid );
		expect( callsTo( apiFetch, 'POST requests' ) ).toHaveLength( 1 );
	} );

	it( 'asks for a new grant when a new preview covers a different scope', async () => {
		let previews = 0;
		let batches = 0;
		routeApi( apiFetch, {
			'POST preview': () => {
				previews++;
				return previewResponse(
					previews === 1 ? {} : { hash: HASH_2, scope_hash: SCOPE_2 }
				);
			},
			'POST requests': grantFor,
			'POST batches': () => {
				batches++;
				return batches === 1
					? restError( 'bulkcat_preview_stale', 'Stale.', {
							status: 409,
						} )
					: { batch_id: 12, status: 'queued', total_items: 24 };
			},
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
		await waitFor( () => expect( previews ).toBe( 2 ) );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		await waitFor( () =>
			expect( callsTo( apiFetch, 'POST batches' ) ).toHaveLength( 2 )
		);
		const [ first, second ] = callsTo( apiFetch, 'POST batches' );
		expect( second.data.uuid ).not.toBe( first.data.uuid );
		expect( second.data.intent.scope_hash ).toBe( SCOPE_2 );
		// The first was refused outright, so nothing had to be resolved.
		expect( callsTo( apiFetch, 'POST requests/resolve' ) ).toHaveLength(
			0
		);
	} );

	it( 'resends the same grant after a lost answer, and shows the change the server already accepted', async () => {
		let batches = 0;
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST requests': grantFor,
			'POST batches': () => {
				batches++;
				return batches === 1
					? restError(
							'fetch_error',
							'You are probably offline.',
							{}
						)
					: {
							batch_id: 12,
							status: 'complete',
							total_items: 24,
							replayed: true,
						};
			},
			'GET batches/12': {
				batch_id: 12,
				status: 'complete',
				total_items: 24,
				done_items: 24,
				failed_items: 0,
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
			await screen.findByText(
				'We could not confirm whether the change was applied'
			)
		).toBeVisible();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show details' } )
		);
		expect( screen.getByText( 'fetch_error' ) ).toBeVisible();
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await waitFor( () =>
			expect( onApplied ).toHaveBeenCalledWith( 12, {
				selection: { kind: 'ids', ids: IDS },
				replayed: true,
			} )
		);
		const [ first, second ] = callsTo( apiFetch, 'POST batches' );
		expect( second.data.uuid ).toBe( first.data.uuid );
		expect( second.data.request_proof ).toBe( first.data.request_proof );
		expect( callsTo( apiFetch, 'POST requests' ) ).toHaveLength( 1 );
		// The receipt's outcome, once the batch has been read.
		await screen.findByText( '24 products updated.' );
		expect(
			screen.getByText(
				'This change had already been accepted, so it was not made again. This is its result.'
			)
		).toBeVisible();
	} );

	it( 'keeps an uncertain Apply’s grant when the panel is closed and opened again', async () => {
		let batches = 0;
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST requests': grantFor,
			'POST batches': () => {
				batches++;
				return batches === 1
					? restError( 'fetch_error', 'Offline.', {} )
					: { batch_id: 15, status: 'queued', total_items: 24 };
			},
		} );
		const first = renderPanel();
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		await screen.findByText(
			'We could not confirm whether the change was applied'
		);
		first.unmount();
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
		await waitFor( () => expect( onApplied ).toHaveBeenCalled() );
		const [ lost, again ] = callsTo( apiFetch, 'POST batches' );
		expect( again.data.uuid ).toBe( lost.data.uuid );
		expect( callsTo( apiFetch, 'POST requests' ) ).toHaveLength( 1 );
	} );

	it( 'keeps an uncertain Apply’s grant across a reload of the page (T85)', async () => {
		let batches = 0;
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST requests': grantFor,
			'POST batches': () => {
				batches++;
				return batches === 1
					? restError( 'fetch_error', 'Offline.', {} )
					: {
							batch_id: 16,
							status: 'queued',
							total_items: 24,
							replayed: true,
						};
			},
		} );
		const first = renderPanel( { selection: SNAPSHOT } );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		await screen.findByText(
			'We could not confirm whether the change was applied'
		);
		first.unmount();
		// A reload: the module's memory is gone, the tab's copy is not.
		require( '../../assets/src/panels/bulk/hooks' ).resetApplyGrant();
		const { onApplied } = renderPanel( { selection: SNAPSHOT } );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		await waitFor( () =>
			expect( onApplied ).toHaveBeenCalledWith( 16, {
				selection: SNAPSHOT,
				replayed: true,
			} )
		);
		const [ lost, again ] = callsTo( apiFetch, 'POST batches' );
		expect( again.data.uuid ).toBe( lost.data.uuid );
		expect( again.data.request_proof ).toBe( lost.data.request_proof );
		expect( callsTo( apiFetch, 'POST requests' ) ).toHaveLength( 1 );
	} );

	it( 'resolves an uncertain earlier Apply before a different change, and shows it when it had gone through', async () => {
		let batches = 0;
		routeApi( apiFetch, {
			'POST preview': ( options ) =>
				previewResponse( {
					operations: options.data.operations,
				} ),
			'POST requests': grantFor,
			'POST batches': () => {
				batches++;
				return restError( 'fetch_error', 'Offline.', {} );
			},
			'POST requests/resolve': {
				batch_id: 14,
				status: 'running',
				total_items: 24,
				replayed: true,
			},
			'GET batches/14': new Promise( () => {} ),
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
		await screen.findByText(
			'We could not confirm whether the change was applied'
		);
		// A different change: the earlier one is resolved first.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit step 1' } )
		);
		change( 'How much', '12' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Preview again' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		);
		// Applied elsewhere: not tied to this panel's selection.
		await waitFor( () =>
			expect( onApplied ).toHaveBeenCalledWith( 14, {
				selection: null,
				replayed: true,
			} )
		);
		expect( batches ).toBe( 1 );
		expect( callsTo( apiFetch, 'POST requests' ) ).toHaveLength( 1 );
		const [ resolved ] = callsTo( apiFetch, 'POST requests/resolve' );
		expect( resolved.data.operation ).toBe( 'batch.apply' );
		await waitFor( () =>
			expect( panel().textContent ).toContain(
				'An earlier attempt to apply a change had gone through, so nothing new was sent. This is that change.'
			)
		);
	} );

	it.each( [
		[
			'bulkcat_legacy_request',
			409,
			'This change was sent by an older version of this page. Check History for the change it may already have made, then reload the page before starting new work.',
			{ batch_ids: [ 41 ] },
		],
		[
			'bulkcat_request_gone',
			410,
			'The original change is no longer in History. This request cannot be applied again.',
			{},
		],
		[
			'bulkcat_request_expired',
			410,
			'This request is past its acceptance deadline. Resolve the original result before starting a new change.',
			{},
		],
	] )(
		'states %s in the server’s words',
		async ( code, status, sentence, data ) => {
			routeApi( apiFetch, {
				'POST preview': previewResponse(),
				'POST requests': grantFor,
				'POST batches': restError( code, sentence, {
					status,
					...data,
				} ),
			} );
			const onViewHistory = jest.fn();
			renderPanel( { onViewHistory } );
			chooseIncrease();
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Check the change' } )
			);
			fireEvent.click(
				await screen.findByRole( 'button', {
					name: 'Apply to 20 products',
				} )
			);
			expect( await screen.findByText( sentence ) ).toBeVisible();
		}
	);

	it( 'links an older page’s request to the change History names', async () => {
		routeApi( apiFetch, {
			'POST preview': previewResponse(),
			'POST requests': grantFor,
			'POST batches': restError(
				'bulkcat_legacy_request',
				'This change was sent by an older version of this page.',
				{ status: 409, batch_ids: [ 41 ] }
			),
		} );
		const onViewHistory = jest.fn();
		renderPanel( { onViewHistory } );
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
			await screen.findByRole( 'button', { name: 'View in History' } )
		);
		expect( onViewHistory ).toHaveBeenCalledWith( 41 );
	} );

	it( 'offers to restore a selection whose lease lapsed, from the preview or the Apply', async () => {
		routeApi( apiFetch, {
			'POST preview': restError(
				'bulkcat_selection_renew_required',
				'Your selected products are still saved. Restore your selection, then preview the change again.',
				{ status: 409 }
			),
		} );
		const onRenewSelection = jest.fn();
		renderPanel( { selection: SNAPSHOT, onRenewSelection } );
		chooseIncrease();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Restore the selection',
			} )
		);
		expect( onRenewSelection ).toHaveBeenCalled();
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
