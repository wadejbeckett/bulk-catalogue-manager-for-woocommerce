/**
 * The lookup picker (ticket 25; docs/EXTENSIONS-v2.md §4): an add-on's
 * choices searched and paged through GET /lookup, never all at once; the
 * loading, no-matches and failure states said in words; keyboard choice;
 * single and several items; the most items respected; a row's object passed.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { useState } from '@wordpress/element';

import apiFetch from '@wordpress/api-fetch';
import { LookupPicker } from '../../assets/src/components/lookup-picker';

jest.mock( '@wordpress/api-fetch', () => {
	const mock = jest.fn();
	mock.use = jest.fn();
	mock.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: mock };
} );

const ALL = Array.from( { length: 5000 }, ( _, index ) => {
	const n = String( index + 1 ).padStart( 4, '0' );
	return { id: `r${ n }`, label: `Recipient ${ n }`, detail: 'Team A' };
} );

/**
 * The query arguments of an apiFetch call.
 *
 * @param {Object} options apiFetch options.
 * @return {URLSearchParams} Arguments.
 */
function argsOf( options ) {
	return new URL( options.url, 'https://example.test' ).searchParams;
}

/**
 * Answer lookups like the server: 20 per page over a search.
 *
 * @param {Object} options apiFetch options.
 * @return {Promise<Object>} The page.
 */
function serve( options ) {
	const args = argsOf( options );
	const query = ( args.get( 'query' ) || '' ).toLowerCase();
	const page = Number( args.get( 'page' ) || 1 );
	const matches = ALL.filter( ( item ) =>
		item.label.toLowerCase().includes( query )
	);
	return Promise.resolve( {
		items: matches.slice( ( page - 1 ) * 20, page * 20 ),
		page,
		hasMore: matches.length > page * 20,
	} );
}

/**
 * The picker with its value held in state, as a panel holds it.
 *
 * @param {Object} props Picker props.
 * @return {Element} Element.
 */
function Held( props ) {
	const [ value, setValue ] = useState( props.initial || [] );
	return (
		<LookupPicker
			action="x_picks_add"
			argument="recipients"
			label="Recipients"
			help="Choose at least one recipient from the list."
			multiple
			maxItems={ 20 }
			{ ...props }
			value={ value }
			onChange={ ( items ) => {
				setValue( items );
				props.onSeen?.( items );
			} }
		/>
	);
}

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( serve );
} );

test( 'it searches and pages the choices without loading them all', async () => {
	render( <Held /> );
	const box = screen.getByRole( 'combobox', { name: 'Recipients' } );
	fireEvent.focus( box );
	expect( await screen.findByText( 'Recipient 0001' ) ).toBeTruthy();
	expect( screen.getAllByRole( 'option' ) ).toHaveLength( 20 );
	expect( argsOf( apiFetch.mock.calls[ 0 ][ 0 ] ).get( 'action' ) ).toBe(
		'x_picks_add'
	);
	expect( argsOf( apiFetch.mock.calls[ 0 ][ 0 ] ).get( 'argument' ) ).toBe(
		'recipients'
	);
	fireEvent.click( screen.getByRole( 'button', { name: 'Show more' } ) );
	expect( await screen.findByText( 'Recipient 0040' ) ).toBeTruthy();
	expect( screen.getAllByRole( 'option' ) ).toHaveLength( 40 );
	expect( argsOf( apiFetch.mock.calls[ 1 ][ 0 ] ).get( 'page' ) ).toBe( '2' );
	fireEvent.change( box, { target: { value: '4999' } } );
	await waitFor( () =>
		expect( screen.getAllByRole( 'option' ) ).toHaveLength( 1 )
	);
	expect( screen.getByRole( 'option' ).textContent ).toContain(
		'Recipient 4999'
	);
	expect( screen.queryByRole( 'button', { name: 'Show more' } ) ).toBeNull();
	expect( apiFetch.mock.calls.length ).toBeLessThan( 6 );
} );

test( 'loading, no matches and failure are said in words, and it can try again', async () => {
	let release;
	apiFetch.mockImplementationOnce(
		() =>
			new Promise( ( resolve ) => {
				release = resolve;
			} )
	);
	render( <Held /> );
	const box = screen.getByRole( 'combobox', { name: 'Recipients' } );
	fireEvent.focus( box );
	expect( await screen.findByText( 'Loading choices…' ) ).toBeTruthy();
	await act( async () => release( { items: [], page: 1, hasMore: false } ) );
	expect( await screen.findByText( 'No matches.' ) ).toBeTruthy();
	apiFetch.mockImplementationOnce( () =>
		Promise.reject( {
			code: 'bulkcat_lookup_failed',
			message: 'The recipient directory cannot be searched right now.',
		} )
	);
	fireEvent.change( box, { target: { value: 'any' } } );
	expect( ( await screen.findByRole( 'alert' ) ).textContent ).toContain(
		'The recipient directory cannot be searched right now.'
	);
	fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
	await waitFor( () => expect( screen.queryByRole( 'alert' ) ).toBeNull() );
} );

test( 'the keyboard chooses, and a chosen item can be removed', async () => {
	const seen = jest.fn();
	render( <Held onSeen={ seen } /> );
	const box = screen.getByRole( 'combobox', { name: 'Recipients' } );
	fireEvent.focus( box );
	await screen.findByText( 'Recipient 0001' );
	fireEvent.keyDown( box, { key: 'ArrowDown' } );
	fireEvent.keyDown( box, { key: 'ArrowDown' } );
	expect( box.getAttribute( 'aria-activedescendant' ) ).toBe(
		screen.getAllByRole( 'option' )[ 1 ].id
	);
	fireEvent.keyDown( box, { key: 'Enter' } );
	expect( seen ).toHaveBeenLastCalledWith( [
		{ id: 'r0002', label: 'Recipient 0002', detail: 'Team A' },
	] );
	expect(
		screen.getAllByRole( 'option' )[ 1 ].getAttribute( 'aria-selected' )
	).toBe( 'true' );
	fireEvent.keyDown( box, { key: 'Escape' } );
	expect( box.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Remove Recipient 0002' } )
	);
	expect( seen ).toHaveBeenLastCalledWith( [] );
} );

test( 'a single choice replaces the one before', async () => {
	const seen = jest.fn();
	render(
		<Held
			argument="recipient"
			label="Recipient"
			multiple={ false }
			onSeen={ seen }
		/>
	);
	const box = screen.getByRole( 'combobox', { name: 'Recipient' } );
	fireEvent.focus( box );
	fireEvent.click( await screen.findByText( 'Recipient 0003' ) );
	fireEvent.focus( box );
	fireEvent.click( await screen.findByText( 'Recipient 0005' ) );
	expect( seen ).toHaveBeenLastCalledWith( [
		{ id: 'r0005', label: 'Recipient 0005', detail: 'Team A' },
	] );
} );

test( 'it stops at the most items allowed', async () => {
	const seen = jest.fn();
	render(
		<Held
			maxItems={ 2 }
			initial={ [
				{ id: 'r0001', label: 'Recipient 0001' },
				{ id: 'r0002', label: 'Recipient 0002' },
			] }
			onSeen={ seen }
		/>
	);
	fireEvent.focus( screen.getByRole( 'combobox', { name: 'Recipients' } ) );
	const third = await screen.findByText( 'Recipient 0003' );
	expect(
		third.closest( '[role="option"]' ).getAttribute( 'aria-disabled' )
	).toBe( 'true' );
	fireEvent.click( third );
	expect( seen ).not.toHaveBeenCalled();
	expect(
		screen.getByText( 'You have chosen the most allowed (2).' )
	).toBeTruthy();
} );

test( 'a row passes its object to the server', async () => {
	render( <Held object="product:42" /> );
	fireEvent.focus( screen.getByRole( 'combobox', { name: 'Recipients' } ) );
	await screen.findByText( 'Recipient 0001' );
	expect( argsOf( apiFetch.mock.calls[ 0 ][ 0 ] ).get( 'object' ) ).toBe(
		'product:42'
	);
} );

test( 'Escape closes the open list without closing what surrounds it', async () => {
	const outer = jest.fn();
	render(
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- A stand-in for the panel's own key handler.
		<div onKeyDown={ ( event ) => outer( event.key ) }>
			<Held />
		</div>
	);
	const box = screen.getByRole( 'combobox', { name: 'Recipients' } );
	fireEvent.focus( box );
	await screen.findByText( 'Recipient 0001' );
	fireEvent.keyDown( box, { key: 'Escape' } );
	expect( box.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	expect( outer ).not.toHaveBeenCalled();
	fireEvent.keyDown( box, { key: 'Escape' } );
	expect( outer ).toHaveBeenCalledWith( 'Escape' );
} );
