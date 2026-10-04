import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ProviderActionPanel from '../../assets/src/provider-actions/panel';
import { rowEntry } from '../../assets/src/provider-actions/entry';
import { resetApplyGrant } from '../../assets/src/panels/bulk/hooks';
import ChoiceControl from '../../assets/src/provider-actions/choice-control';
import { changeArguments } from '../../assets/src/provider-actions/arguments';
jest.mock( '@wordpress/api-fetch', () => {
	const mock = jest.fn();
	mock.use = jest.fn();
	mock.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: mock };
} );
const selection = { targets: [ { kind: 'product', taxonomy: null, id: 41 } ] };
const schema = {
	type: 'lookup',
	label: 'Values',
	multiple: true,
	min_items: 1,
	max_items: 100,
	depends_on: [ 'part', 'operation' ],
	active_when: { argument: 'operation', op: 'in', values: [ 'add' ] },
};
function Harness() {
	const [ args, setArgs ] = useState( {
		part: 'a',
		operation: 'add',
		values: [ 'v1' ],
	} );
	const [ preview, setPreview ] = useState( true );
	return (
		<>
			<button
				onClick={ () => {
					setArgs(
						changeArguments( { values: schema }, args, 'part', 'b' )
					);
					setPreview( false );
				} }
			>
				Part B
			</button>
			<span>{ preview ? 'Preview current' : 'Preview invalidated' }</span>
			<ChoiceControl
				schema={ schema }
				value={ args.values }
				onChange={ ( values ) => {
					setArgs( { ...args, values } );
					setPreview( false );
				} }
				provider="sample"
				action="x_sample_assign"
				argument="values"
				selection={ selection }
				dependencies={ { part: args.part, operation: args.operation } }
			/>
		</>
	);
}
beforeEach( () => apiFetch.mockReset() );
test( 'late page and resolution from an old dependency cannot restore chips or mutate targets', async () => {
	const held = [];
	apiFetch.mockImplementation( ( { data } ) => {
		if ( data.dependencies.part === 'a' ) {
			return new Promise( ( resolve ) => held.push( resolve ) );
		}
		return Promise.resolve( {
			items: [ { value: 'v2', label: 'B choice' } ],
			next_cursor: null,
		} );
	} );
	render( <Harness /> );
	expect( screen.getByRole( 'status' ).textContent ).toMatch( /Loading/ );
	fireEvent.click( screen.getByText( 'Part B' ) );
	await screen.findByText( 'B choice' );
	await act( async () => {
		held.forEach( ( resolve ) =>
			resolve( {
				items: [ { value: 'v1', label: 'A choice', available: true } ],
				next_cursor: 'old',
			} )
		);
	} );
	expect( screen.queryByText( 'A choice' ) ).toBeNull();
	expect( screen.getByText( 'Preview invalidated' ) ).toBeTruthy();
	expect( selection ).toEqual( {
		targets: [ { kind: 'product', taxonomy: null, id: 41 } ],
	} );
	expect(
		apiFetch.mock.calls.every(
			( [ request ] ) =>
				JSON.stringify( request.data.selection ) ===
				JSON.stringify( selection )
		)
	).toBe( true );
} );
test( 'loading, no matches, safe error, retry, relabeling and selected values across pages', async () => {
	let fail = true;
	apiFetch.mockImplementation( ( { url, data } ) => {
		if ( url.endsWith( '/resolve' ) ) {
			return Promise.resolve( {
				items: [
					{
						value: 'v1',
						label: 'Renamed selection',
						available: true,
						reason: '',
					},
				],
			} );
		}
		if ( fail ) {
			return Promise.reject( {
				message: '/private/error.php',
				code: 'server_failure',
			} );
		}
		if ( data.query === 'absent' ) {
			return Promise.resolve( { items: [], next_cursor: null } );
		}
		return Promise.resolve( {
			items: [
				{
					value: data.cursor ? 'v3' : 'v2',
					label: data.cursor ? 'Third' : 'Second',
				},
			],
			next_cursor: data.cursor ? null : 'next',
		} );
	} );
	render(
		<ChoiceControl
			schema={ schema }
			value={ [ 'v1' ] }
			onChange={ jest.fn() }
			provider="sample"
			action="x_sample_assign"
			argument="values"
			selection={ selection }
			dependencies={ { part: 'a', operation: 'add' } }
		/>
	);
	await screen.findByRole( 'alert' );
	expect( screen.queryByText( '/private/error.php' ) ).toBeNull();
	fail = false;
	fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
	await screen.findByText( 'Second' );
	await screen.findByText( 'Renamed selection' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Load more' } ) );
	await screen.findByText( 'Third' );
	expect( screen.getByText( 'Renamed selection' ) ).toBeTruthy();
	fireEvent.change( screen.getByRole( 'searchbox' ), {
		target: { value: 'absent' },
	} );
	await waitFor( () =>
		expect( screen.getByRole( 'status' ).textContent ).toMatch(
			/No matches/
		)
	);
	expect( screen.getByText( 'Renamed selection' ) ).toBeTruthy();
} );

test( 'the provider shell uses the shared wizard, invalidates real Preview, and confirms once', async () => {
	const registry = require( './provider-actions.fixture.json' );
	const entry = rowEntry( selection.targets[ 0 ], 'x_sample_assign' );
	const requests = [];
	apiFetch.mockImplementation( ( { url, data } ) => {
		requests.push( { url, data } );
		if ( url.endsWith( 'provider-actions' ) ) {
			return Promise.resolve( {
				...registry,
				actions: registry.actions.map( ( action ) => ( {
					...action,
					alwaysConfirm: true,
					confirmReason: 'Review this assignment.',
				} ) ),
			} );
		}
		if ( url.endsWith( '/search' ) ) {
			return Promise.resolve( {
				items: [
					{
						value: 'v1',
						label:
							data.dependencies.part === 'a'
								? 'A option'
								: 'B option',
					},
				],
				next_cursor: null,
			} );
		}
		if ( url.endsWith( '/resolve' ) ) {
			return Promise.resolve( {
				items: data.values.map( ( value ) => ( {
					value,
					label: 'Chosen option',
					available: true,
					reason: '',
				} ) ),
			} );
		}
		if ( url.endsWith( 'preview' ) ) {
			return Promise.resolve( {
				serializer: 2,
				family: 'product',
				external: {
					contract: 2,
					provider: 'sample',
					identity: 'org.example.sample',
				},
				entry: 'product.row',
				operations: data.operations,
				selection: data.targets,
				hash: 'hash',
				scope_hash: 'scope',
				totals: { would_change: 1, already: 0, would_fail: {} },
				sample: [
					{
						target: selection.targets[ 0 ],
						field: 'x_sample_composite',
						state: 'changed',
						reason: '',
					},
				],
			} );
		}
		return new Promise( () => {} );
	} );
	const close = jest.fn();
	render(
		<ProviderActionPanel
			entry={ entry }
			onClose={ close }
			onCompleted={ jest.fn() }
		/>
	);
	fireEvent.click( await screen.findByLabelText( 'A option' ) );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Check the change' } )
	);
	await screen.findByText( '1 selected item would change.' );
	const sent = requests.find( ( { url } ) => url.endsWith( 'preview' ) ).data;
	expect( sent ).toMatchObject( {
		serializer: 2,
		family: 'product',
		entry: 'product.row',
		targets: selection.targets,
		external: { identity: 'org.example.sample' },
	} );
	expect( sent.selection ).toBeUndefined();
	expect( sent.operations[ 0 ].args.apply_to_variations ).not.toBe( true );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Apply to 1 item' } )
	);
	expect( screen.getAllByText( 'Review this assignment.' ) ).toHaveLength(
		1
	);
	expect( requests.some( ( { url } ) => url.endsWith( 'batches' ) ) ).toBe(
		false
	);
	fireEvent.keyDown(
		screen.getByRole( 'button', { name: 'Yes, change 1 item' } ),
		{ key: 'Escape' }
	);
	expect( document.activeElement ).toBe(
		screen.getByRole( 'button', { name: 'Apply to 1 item' } )
	);
	fireEvent.click( screen.getByRole( 'button', { name: 'Edit step 1' } ) );
	fireEvent.change( screen.getByLabelText( 'Part' ), {
		target: { value: 'b' },
	} );
	await screen.findByLabelText( 'B option' );
	expect( screen.queryByLabelText( 'A option' ) ).toBeNull();
	expect(
		screen.queryByRole( 'button', { name: 'Apply to 1 item' } )
	).toBeNull();
	expect( entry.selection ).toEqual( selection );
	resetApplyGrant();
} );
