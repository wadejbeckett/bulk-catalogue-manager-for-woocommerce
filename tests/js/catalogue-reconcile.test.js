/** Real catalogue/editing hooks over mutable HTTP rows; delayed answers remain snapshots. */
import {
	act,
	configure,
	fireEvent,
	render,
	renderHook,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import Catalogue from '../../assets/src/panels/catalogue';
import { useCatalogue } from '../../assets/src/panels/catalogue/use-catalogue';
import { useContext } from '@wordpress/element';
import {
	EditContext,
	useEditScope,
} from '../../assets/src/panels/catalogue/edit/scope';
import {
	descriptorsOf,
	fieldsFromRegistry,
	defaultView,
} from '../../assets/src/panels/catalogue/fields';
import { getSelectionSession } from '../../assets/src/panels/catalogue/selection';

jest.mock( '@wordpress/api-fetch', () => {
	// DataViews measures card layout; jsdom does not provide this browser API.
	global.ResizeObserver =
		global.ResizeObserver ||
		class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );
const apiFetch = require( '@wordpress/api-fetch' ).default;
const {
	REGISTRY,
	ROWS,
	CATEGORIES,
	page,
	route,
} = require( './catalogue-fixture' );
const { createSelectionServer } = require( './selection-server' );
const fields = fieldsFromRegistry( REGISTRY );
const descriptors = descriptorsOf( REGISTRY );
const base = {
	...defaultView( REGISTRY, {} ),
	fields: [ 'sku', 'regular_price', 'visible_on_site' ],
	filters: [
		{ field: 'catalog_visibility', operator: 'isAny', value: [ 'hidden' ] },
	],
};
let saved, read, patch, server, receiptRead, rowRead;
function deferred() {
	let resolve, reject;
	const promise = new Promise( ( yes, no ) => {
		resolve = yes;
		reject = no;
	} );
	return { promise, resolve, reject };
}
function answer( id, values ) {
	saved = saved.map( ( row ) =>
		row.id === id
			? {
					...row,
					...values,
					visible_on_site:
						( values.catalog_visibility ||
							row.catalog_visibility ) === 'hidden'
							? 'off'
							: 'on',
				}
			: row
	);
	return {
		item: saved.find( ( row ) => row.id === id ),
		status: 'complete',
		batch_id: id,
		results: [],
	};
}
function listed( query ) {
	const filtered = saved.filter(
		( row ) =>
			( ! query.search || row.name.includes( query.search ) ) &&
			( ! query.catalog_visibility ||
				query.catalog_visibility.value.includes(
					row.catalog_visibility
				) )
	);
	const size = Number( query.per_page || 25 ),
		start = ( Number( query.page || 1 ) - 1 ) * size;
	return page( filtered.slice( start, start + size ), filtered.length, size );
}
const patches = () =>
	apiFetch.mock.calls.filter( ( [ call ] ) => call.method === 'PATCH' );
const lists = () =>
	apiFetch.mock.calls.filter( ( [ call ] ) =>
		/^products\?/.test( call.url )
	);
const toggle = ( id = 11 ) =>
	screen.getByRole( 'switch', {
		name: `Shown in shop and search, ${ saved.find( ( row ) => row.id === id ).name }`,
	} );
async function loaded( initialView = base ) {
	render(
		<Catalogue settings={ { settings: {} } } initialView={ initialView } />
	);
	await screen.findByRole( 'switch', { name: /Classic Cotton/ } );
}
beforeEach( () => {
	saved = ROWS.map( ( row ) => ( {
		...row,
		type: 'simple',
		catalog_visibility: 'hidden',
		visible_on_site: 'off',
	} ) );
	receiptRead = jest.fn( () => ( {
		receipt_state: 'unclosed',
		batch_id: 11,
		replayed: true,
	} ) );
	rowRead = jest.fn( ( id ) => ( {
		item: saved.find( ( row ) => row.id === id ),
	} ) );
	server = createSelectionServer();
	server.setMatches( () => saved.map( ( row ) => row.id ) );
	read = jest.fn( ( query ) => listed( query ) );
	patch = jest.fn( ( id, values ) => answer( id, values ) );
	apiFetch.mockReset();
	route( apiFetch, {
		fields: () => REGISTRY,
		categories: () => CATEGORIES,
		products: ( query ) => read( query ),
		'products/11': ( query, { method, data } ) =>
			method === 'PATCH' ? patch( 11, data ) : rowRead( 11 ),
		'products/12': ( query, { method, data } ) =>
			method === 'PATCH' ? patch( 12, data ) : rowRead( 12 ),
		'products/11/variations': () => page( [], 0 ),
		audit: () => ( { items: [] } ),
		...server.routes(),
	} );
	const transport = apiFetch.getMockImplementation();
	let sequence = 0;
	apiFetch.mockImplementation( async ( options ) => {
		if (
			options.url === 'requests' &&
			options.data.operation === 'product.inline'
		) {
			sequence++;
			return {
				uuid: `00000000-0000-4000-8000-${ String( sequence ).padStart( 12, '0' ) }`,
				request_proof: 'opaque-proof',
				accept_until: 1800000000,
				operation: 'product.inline',
				intent: {
					version: 1,
					payload_version: 1,
					registry_revision: 'a'.repeat( 64 ),
					commitment: 'b'.repeat( 64 ),
					object_id: options.data.intent.object_id,
					source: 'inline',
				},
			};
		}
		if (
			options.url === 'requests/resolve' &&
			options.data.operation === 'product.inline'
		) {
			return receiptRead( options.data );
		}
		const result = await transport( options );
		return options.method === 'PATCH' && result?.status
			? {
					...result,
					receipt_state: 'closed',
					write_status: result.status,
					steps: [],
					replayed: false,
				}
			: result;
	} );
} );
jest.setTimeout( 20000 );
configure( { asyncUtilTimeout: 2000 } );

it( 'reconciles the active filter after one save, retaining unrelated exact selection and counts', async () => {
	await loaded();
	fireEvent.click(
		screen.getByRole( 'checkbox', {
			name: 'Ladies Golf Shirt',
			exact: true,
		} )
	);
	await waitFor( () =>
		expect(
			screen.getByRole( 'region', { name: 'Selection' } ).textContent
		).toMatch( /1 product selected/ )
	);
	const session = getSelectionSession();
	const before = session.getState();
	fireEvent.click( toggle() );
	await waitFor( () =>
		expect(
			screen.queryByRole( 'switch', { name: /Classic Cotton/ } )
		).toBeNull()
	);
	expect( patches() ).toHaveLength( 1 );
	expect( lists().length ).toBeGreaterThan( 1 );
	expect( screen.getByText( '1 product' ) ).toBeTruthy();
	expect( session.getState().current ).toEqual( before.current );
	expect( server.members( session.getState().current.token ) ).toEqual( [
		12,
	] );
	expect(
		screen.getByRole( 'checkbox', {
			name: 'Ladies Golf Shirt',
			exact: true,
		} ).checked
	).toBe( true );
} );

it( 'a failed reload cannot turn a confirmed save into a failed save; retry only reads', async () => {
	await loaded();
	read.mockRejectedValueOnce( new Error( 'offline' ) );
	fireEvent.click( toggle() );
	await screen.findAllByText( /saved.*could not.*refresh/i );
	expect( screen.queryByText( /could not save/i ) ).toBeNull();
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Refresh results' } )
	);
	await waitFor( () =>
		expect(
			screen.queryByRole( 'switch', { name: /Classic Cotton/ } )
		).toBeNull()
	);
	expect( patches() ).toHaveLength( 1 );
	expect(
		screen.getByRole( 'link', { name: 'View change in History' } ).href
	).toContain( 'batch=11' );
} );

it( 'late reads cannot restore rows after reconciliation or a changed query', async () => {
	const old = deferred();
	read.mockReturnValueOnce( old.promise );
	const { result, rerender } = renderHook(
		( { view } ) => useCatalogue( view, descriptors ),
		{ initialProps: { view: base } }
	);
	await waitFor( () => expect( read ).toHaveBeenCalledTimes( 1 ) );
	answer( 11, { catalog_visibility: 'visible' } );
	await act( async () => {
		await result.current.reconcile();
	} );
	expect( result.current.items.map( ( row ) => row.id ) ).toEqual( [ 12 ] );
	await act( async () => old.resolve( page( ROWS, 60 ) ) );
	expect( result.current.pagination.totalItems ).toBe( 1 );
	const stale = deferred();
	read.mockReturnValueOnce( stale.promise );
	let pending;
	act( () => {
		pending = result.current.reconcile();
	} );
	rerender( { view: { ...base, search: 'no matches' } } );
	await waitFor( () => expect( result.current.items ).toEqual( [] ) );
	await act( async () => stale.resolve( page( ROWS, 60 ) ) );
	expect( await pending ).toEqual( { status: 'superseded' } );
	expect( result.current.pagination.totalItems ).toBe( 0 );
} );

it( 'a save finishing after a query change reconciles the current query', async () => {
	const held = deferred();
	patch.mockImplementationOnce( async ( id, values ) => {
		await held.promise;
		return answer( id, values );
	} );
	await loaded();
	fireEvent.click( toggle() );
	fireEvent.change(
		screen.getByRole( 'searchbox', { name: 'Search by name or SKU' } ),
		{ target: { value: 'Ladies' } }
	);
	await waitFor( () =>
		expect( read.mock.calls.at( -1 )[ 0 ].search ).toBe( 'Ladies' )
	);
	await act( async () => held.resolve() );
	await waitFor( () =>
		expect(
			read.mock.calls.filter( ( [ q ] ) => q.search === 'Ladies' ).length
		).toBeGreaterThan( 1 )
	);
	expect(
		screen.queryByRole( 'switch', { name: /Classic Cotton/ } )
	).toBeNull();
} );

it( 'reverse-order saves of different objects both reconcile without resurrecting either row', async () => {
	const one = deferred(),
		two = deferred();
	patch.mockImplementation( async ( id, values ) => {
		await ( id === 11 ? one : two ).promise;
		return answer( id, values );
	} );
	await loaded();
	fireEvent.click( toggle() );
	fireEvent.click( toggle( 12 ) );
	await act( async () => two.resolve() );
	await waitFor( () =>
		expect( screen.queryByRole( 'switch', { name: /Ladies/ } ) ).toBeNull()
	);
	await act( async () => one.resolve() );
	await waitFor( () =>
		expect( screen.queryAllByRole( 'switch' ) ).toHaveLength( 0 )
	);
	expect( patches() ).toHaveLength( 2 );
} );

it( 'same-turn duplicate intent shares one write; a conflicting in-flight edit is refused', async () => {
	const held = deferred();
	patch.mockReturnValue( held.promise );
	let scope;
	function Inside() {
		scope = useContext( EditContext );
		return null;
	}
	function Harness() {
		return useEditScope( { items: saved, fields, actions: [] } ).wrap(
			<Inside />
		);
	}
	render( <Harness /> );
	let one, two, conflict;
	act( () => {
		one = scope.commit( saved[ 0 ], { sku: 'new' } );
		two = scope.commit( saved[ 0 ], { sku: 'new' } );
		conflict = scope
			.commit( saved[ 0 ], { sku: 'different' } )
			.catch( ( e ) => e );
	} );
	await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
	expect( two ).toBe( one );
	expect( ( await conflict ).message ).toMatch( /already.*saving/i );
	await act( async () => held.resolve( answer( 11, { sku: 'new' } ) ) );
} );

it( 'partial PATCH reconciles actual state, retains the refusal and exposes its batch', async () => {
	patch.mockImplementationOnce( ( id, values ) => ( {
		...answer( id, values ),
		status: 'partial',
		results: [
			{
				state: 'failed',
				field: 'sku',
				code: 'bulkcat_invalid',
				reason: 'SKU refused.',
			},
		],
	} ) );
	await loaded();
	fireEvent.click( toggle() );
	await screen.findAllByText( /only part.*saved/i );
	await waitFor( () =>
		expect(
			screen.queryByRole( 'switch', { name: /Classic Cotton/ } )
		).toBeNull()
	);
	expect(
		screen.getByRole( 'link', { name: 'View change in History' } ).href
	).toContain( 'batch=11' );
	expect( patches() ).toHaveLength( 1 );
} );

it( 'transport uncertainty blocks blind resend and readback does not claim the lost write succeeded', async () => {
	patch.mockImplementationOnce( ( id, values ) => {
		answer( id, values );
		throw new TypeError( 'Failed to fetch' );
	} );
	await loaded( { ...base, filters: [] } );
	fireEvent.click( toggle() );
	await screen.findAllByText( /outcome.*unconfirmed/i );
	fireEvent.click( toggle() );
	expect( patches() ).toHaveLength( 1 );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Check save outcome' } )
	);
	await waitFor( () =>
		expect( receiptRead.mock.calls.length ).toBeGreaterThan( 1 )
	);
	expect( screen.queryByText( /could not change whether/i ) ).toBeNull();
	expect( screen.queryByText( /^Saved/ ) ).toBeNull();
	fireEvent.click( toggle() );
	expect( toggle().getAttribute( 'aria-disabled' ) ).toBe( 'true' );
	expect( patches() ).toHaveLength( 1 );
} );

it( 'confirmed validation refusal leaves a corrected ordinary retry available', async () => {
	patch.mockRejectedValueOnce( {
		code: 'bulkcat_forbidden',
		message: 'Permission refused.',
		data: { status: 403, receipt_state: 'refused', replayed: false },
	} );
	await loaded();
	fireEvent.click( toggle() );
	await screen.findAllByText( /was not saved/i );
	fireEvent.click( toggle() );
	await waitFor( () =>
		expect(
			screen.queryByRole( 'switch', { name: /Classic Cotton/ } )
		).toBeNull()
	);
	expect( patches() ).toHaveLength( 2 );
} );

it( 'reconciliation clamps an emptied last page once and reads the valid page', async () => {
	saved = Array.from( { length: 26 }, ( _, i ) => ( {
		...saved[ 0 ],
		id: 100 + i,
		name: `Product ${ i }`,
	} ) );
	const { result } = renderHook( () => {
		const React = require( '@wordpress/element' );
		const [ view, setView ] = React.useState( { ...base, page: 2 } );
		return useCatalogue( view, descriptors, {}, setView );
	} );
	await waitFor( () => expect( result.current.items ).toHaveLength( 1 ) );
	saved = saved.slice( 0, 25 );
	await act( async () => {
		await result.current.reconcile();
	} );
	await waitFor( () => expect( result.current.items ).toHaveLength( 25 ) );
	expect( result.current.query.page ).toBe( 1 );
	expect( result.current.pagination.totalItems ).toBe( 25 );
	expect( read.mock.calls.map( ( [ q ] ) => Number( q.page ) ) ).toEqual( [
		2, 2, 1,
	] );
} );

it( 'a vanished row returns keyboard focus to the results search', async () => {
	await loaded();
	toggle().focus();
	fireEvent.click( toggle() );
	await waitFor( () =>
		expect(
			screen.queryByRole( 'switch', { name: /Classic Cotton/ } )
		).toBeNull()
	);
	expect( document.activeElement ).toBe(
		screen.getByRole( 'searchbox', { name: 'Search by name or SKU' } )
	);
} );

it( 'drawer edits typed while Save is pending survive the authoritative saved answer', async () => {
	const held = deferred();
	patch.mockImplementationOnce( async ( id, values ) => {
		await held.promise;
		return answer( id, values );
	} );
	await loaded( { ...base, filters: [] } );
	const row = screen
		.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
		.closest( 'tr' );
	fireEvent.click( within( row ).getByRole( 'button', { name: 'Actions' } ) );
	fireEvent.click(
		await screen.findByRole( 'menuitem', { name: 'Edit details' } )
	);
	const dialog = await screen.findByRole( 'dialog', { name: 'Details' } );
	const sku = await within( dialog ).findByRole( 'textbox', { name: 'SKU' } );
	fireEvent.change( sku, { target: { value: 'submitted' } } );
	fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Save' } ) );
	fireEvent.change( sku, { target: { value: 'newer draft' } } );
	await act( async () => held.resolve() );
	await waitFor( () =>
		expect(
			within( dialog )
				.getByRole( 'button', { name: 'Save' } )
				.getAttribute( 'aria-disabled' )
		).toBe( 'false' )
	);
	expect( sku.value ).toBe( 'newer draft' );
	expect( patches() ).toHaveLength( 1 );
} );

it( 'a malformed success reply remains unconfirmed and cannot be resent', async () => {
	patch.mockResolvedValueOnce( {} );
	await loaded();
	fireEvent.click( toggle() );
	await screen.findAllByText( /outcome.*unconfirmed/i );
	fireEvent.click( toggle() );
	expect( patches() ).toHaveLength( 1 );
} );

it( 'layout changes retain unconfirmed intent and keep the object read-only', async () => {
	patch.mockRejectedValueOnce( new TypeError( 'Lost response' ) );
	await loaded();
	fireEvent.click( toggle() );
	await screen.findAllByText( /outcome.*unconfirmed/i );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Grid', exact: true } )
	);
	await screen.findByRole( 'switch', { name: /Classic Cotton/ } );
	fireEvent.click( toggle() );
	expect( patches() ).toHaveLength( 1 );
	expect( toggle().getAttribute( 'aria-disabled' ) ).toBe( 'true' );
	expect(
		(
			await screen.findByRole( 'link', {
				name: 'View change in History',
			} )
		).href
	).toContain( 'batch=11' );
} );

it( 'an older refresh recovery cannot replace the notice for a newer completed save', async () => {
	let batch = 40;
	patch.mockImplementation( ( id, values ) => ( {
		...answer( id, values ),
		batch_id: ++batch,
	} ) );
	await loaded( { ...base, filters: [] } );
	read.mockRejectedValueOnce( new Error( 'offline' ) );
	fireEvent.click( toggle() );
	await screen.findAllByText( /saved.*could not.*refresh/i );
	const old = deferred();
	read.mockReturnValueOnce( old.promise );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Refresh results' } )
	);
	fireEvent.click( toggle() );
	await waitFor( () =>
		expect(
			screen.getByRole( 'link', { name: 'View change in History' } ).href
		).toContain( 'batch=42' )
	);
	await act( async () => old.reject( new Error( 'late failure' ) ) );
	expect(
		screen.getByRole( 'link', { name: 'View change in History' } ).href
	).toContain( 'batch=42' );
	expect( patches() ).toHaveLength( 2 );
} );

it( 'drawer double-submit in one turn sends once and keeps a newer edit to an unrelated field', async () => {
	const held = deferred();
	patch.mockImplementationOnce( async ( id, values ) => {
		await held.promise;
		return answer( id, values );
	} );
	await loaded( { ...base, filters: [] } );
	const row = screen
		.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
		.closest( 'tr' );
	fireEvent.click( within( row ).getByRole( 'button', { name: 'Actions' } ) );
	fireEvent.click(
		await screen.findByRole( 'menuitem', { name: 'Edit details' } )
	);
	const dialog = await screen.findByRole( 'dialog', { name: 'Details' } );
	fireEvent.change(
		await within( dialog ).findByRole( 'textbox', { name: 'SKU' } ),
		{ target: { value: 'new sku' } }
	);
	const form = dialog.querySelector( 'form' );
	act( () => {
		fireEvent.submit( form );
		fireEvent.submit( form );
	} );
	await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
	const price = within( dialog ).getByRole( 'spinbutton', {
		name: 'Regular price',
	} );
	fireEvent.change( price, { target: { value: '28.75' } } );
	await act( async () => held.resolve() );
	expect( price.value ).toBe( '28.75' );
	expect(
		within( dialog ).getByRole( 'textbox', { name: 'SKU' } ).value
	).toBe( 'new sku' );
} );

it( 'an old expanded-variation read cannot overwrite children after their parent page is reconciled', async () => {
	const {
		useVariationRows,
	} = require( '../../assets/src/panels/catalogue/variations/use-variation-rows' );
	const parent = { ...saved[ 0 ], type: 'variable', variation_count: 1 };
	const child = {
		...saved[ 0 ],
		id: 31,
		type: 'variation',
		parent_id: 11,
		regular_price: '28.75',
	};
	const old = deferred();
	const readChildren = jest
		.fn()
		.mockReturnValueOnce( old.promise )
		.mockResolvedValue( page( [ child ], 1 ) );
	route( apiFetch, { 'products/11/variations': () => readChildren() } );
	const { result, rerender } = renderHook(
		( { items } ) =>
			useVariationRows( {
				items,
				fields,
				view: base,
				onChangeView: () => {},
				actions: [],
				pagination: { totalItems: 1, totalPages: 1 },
			} ),
		{ initialProps: { items: [ parent ] } }
	);
	act( () => result.current.expand( parent ) );
	await waitFor( () => expect( readChildren ).toHaveBeenCalledTimes( 1 ) );
	rerender( { items: [ { ...parent, sku: 'saved' } ] } );
	await waitFor( () =>
		expect(
			result.current.props.data.find( ( row ) => row.id === 31 )
				?.regular_price
		).toBe( '28.75' )
	);
	await act( async () =>
		old.resolve( page( [ { ...child, regular_price: '12.50' } ], 1 ) )
	);
	expect(
		result.current.props.data.find( ( row ) => row.id === 31 ).regular_price
	).toBe( '28.75' );
} );

it( 'the first catalogue read still settles under React StrictMode', async () => {
	const { StrictMode } = require( '@wordpress/element' );
	const { result } = renderHook( () => useCatalogue( base, descriptors ), {
		wrapper: StrictMode,
	} );
	await waitFor( () => expect( result.current.hasLoaded ).toBe( true ) );
	expect( result.current.items.map( ( row ) => row.id ) ).toEqual( [
		11, 12,
	] );
} );

it( 'an expanded variation saves once through the shared callback and reads the parent page again', async () => {
	saved[ 0 ] = { ...saved[ 0 ], type: 'variable', variation_count: 1 };
	let child = {
		...saved[ 1 ],
		id: 31,
		parent_id: 11,
		type: 'variation',
		name: 'Small option',
		attribute_summary: 'Small',
		regular_price: '12.50',
	};
	const send = apiFetch.getMockImplementation();
	apiFetch.mockImplementation( ( options ) => {
		const path = options.url.split( '?' )[ 0 ];
		if ( path === 'products/11/variations' ) {
			return Promise.resolve( page( [ child ], 1 ) );
		}
		if ( path === 'products/31' && options.method === 'PATCH' ) {
			child = { ...child, ...options.data };
			return Promise.resolve( {
				item: child,
				status: 'complete',
				receipt_state: 'closed',
				write_status: 'complete',
				steps: [],
				replayed: false,
				batch_id: 31,
				results: [],
			} );
		}
		return send( options );
	} );
	await loaded( { ...base, filters: [] } );
	fireEvent.click(
		screen.getByRole( 'button', {
			name: 'Show the variations of Classic Cotton T-Shirt',
		} )
	);
	fireEvent.click(
		await screen.findByRole( 'button', {
			name: 'Edit Regular price, Small option',
		} )
	);
	const control = screen.getByRole( 'textbox', {
		name: 'Regular price, Small option',
	} );
	fireEvent.change( control, { target: { value: '28.75' } } );
	fireEvent.keyDown( control, { key: 'Enter' } );
	await waitFor( () =>
		expect(
			screen.getByRole( 'button', {
				name: 'Edit Regular price, Small option',
			} ).textContent
		).toContain( '28.75' )
	);
	expect( patches() ).toHaveLength( 1 );
	expect( patches()[ 0 ][ 0 ].url ).toMatch( /^products\/31\?/ );
	expect( lists().length ).toBeGreaterThan( 1 );
} );

it( 'changed variation columns replace an outstanding child read even if the parent read fails', async () => {
	const {
		useVariationRows,
	} = require( '../../assets/src/panels/catalogue/variations/use-variation-rows' );
	const parent = { ...saved[ 0 ], type: 'variable', variation_count: 1 };
	const child = {
		...saved[ 0 ],
		id: 31,
		type: 'variation',
		parent_id: 11,
		weight: '2.50',
	};
	const old = deferred();
	const readChildren = jest
		.fn()
		.mockReturnValueOnce( old.promise )
		.mockResolvedValue( page( [ child ], 1 ) );
	route( apiFetch, { 'products/11/variations': () => readChildren() } );
	const items = [ parent ];
	const { result, rerender } = renderHook(
		( { view } ) =>
			useVariationRows( {
				items,
				fields,
				view,
				onChangeView: () => {},
				actions: [],
				pagination: { totalItems: 1, totalPages: 1 },
			} ),
		{ initialProps: { view: base } }
	);
	act( () => result.current.expand( parent ) );
	await waitFor( () => expect( readChildren ).toHaveBeenCalledTimes( 1 ) );
	// The parent page remains its last good value while its changed-column read fails.
	rerender( { view: { ...base, fields: [ ...base.fields, 'weight' ] } } );
	await waitFor( () =>
		expect(
			result.current.props.data.find( ( row ) => row.id === 31 )?.weight
		).toBe( '2.50' )
	);
	await act( async () =>
		old.resolve( page( [ { ...child, weight: '' } ], 1 ) )
	);
	expect(
		result.current.props.data.find( ( row ) => row.id === 31 ).weight
	).toBe( '2.50' );
} );

it( 'a failed clamped-page read remains a failed reconciliation and can recover read-only', async () => {
	saved = Array.from( { length: 26 }, ( _, i ) => ( {
		...saved[ 0 ],
		id: 100 + i,
		name: `Product ${ i }`,
	} ) );
	const { result } = renderHook( () => {
		const React = require( '@wordpress/element' );
		const [ view, setView ] = React.useState( { ...base, page: 2 } );
		return useCatalogue( view, descriptors, {}, setView );
	} );
	await waitFor( () => expect( result.current.items ).toHaveLength( 1 ) );
	saved = saved.slice( 0, 25 );
	const failure = { message: 'read unavailable', data: { status: 503 } };
	read.mockImplementationOnce( () => Promise.resolve( page( [], 25 ) ) );
	read.mockRejectedValueOnce( failure );
	let error;
	await act( async () => {
		try {
			await result.current.reconcile();
		} catch ( refused ) {
			error = refused;
		}
	} );
	expect( error ).toBe( failure );
	expect( result.current.error ).toBe( failure );
	expect( result.current.query.page ).toBe( 1 );
	await act( async () => {
		await result.current.reconcile();
	} );
	expect( result.current.items ).toHaveLength( 25 );
	expect( result.current.error ).toBeNull();
	expect( patches() ).toHaveLength( 0 );
} );

it( 'lost completion automatically adopts its receipt and preserves exact selection', async () => {
	patch.mockImplementationOnce( ( id, values ) => {
		answer( id, values );
		throw new TypeError( 'lost' );
	} );
	receiptRead.mockReturnValue( {
		receipt_state: 'closed',
		write_status: 'complete',
		batch_id: 11,
		steps: [],
		replayed: true,
	} );
	await loaded();
	fireEvent.click(
		screen.getByRole( 'checkbox', {
			name: 'Ladies Golf Shirt',
			exact: true,
		} )
	);
	await waitFor( () =>
		expect( getSelectionSession().getState().current ).toBeTruthy()
	);
	const selected = getSelectionSession().getState().current;
	fireEvent.click( toggle() );
	await screen.findAllByText( /Saved changes to/ );
	await waitFor( () =>
		expect(
			screen.queryByRole( 'switch', { name: /Classic Cotton/ } )
		).toBeNull()
	);
	expect( getSelectionSession().getState().current ).toEqual( selected );
	expect( patches() ).toHaveLength( 1 );
} );

it( 'recovered success survives a failed strict row read and later Undo is shown only as current state', async () => {
	patch.mockImplementationOnce( ( id, values ) => {
		answer( id, values );
		throw new TypeError( 'lost' );
	} );
	receiptRead.mockReturnValue( {
		receipt_state: 'closed',
		write_status: 'complete',
		batch_id: 11,
		steps: [],
		replayed: true,
	} );
	rowRead.mockRejectedValueOnce( new TypeError( 'row unavailable' ) );
	await loaded( { ...base, filters: [] } );
	fireEvent.click( toggle() );
	await screen.findAllByText( /saved.*could not.*refresh/i );
	expect( screen.queryByText( /could not change whether/i ) ).toBeNull();
	// A later Undo changes the current row, never the recorded original write outcome.
	answer( 11, { catalog_visibility: 'hidden' } );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Refresh results' } )
	);
	await waitFor( () =>
		expect( toggle().getAttribute( 'aria-checked' ) ).toBe( 'false' )
	);
	expect( screen.getAllByText( /Saved changes to/ ).length ).toBeGreaterThan(
		0
	);
	expect( patches() ).toHaveLength( 1 );
} );

it( 'recovery adopts a closed receipt and a failed strict row read leaves newer drawer text intact', async () => {
	const held = deferred();
	patch.mockImplementationOnce( async ( id, values ) => {
		await held.promise;
		answer( id, values );
		throw new TypeError( 'lost' );
	} );
	receiptRead.mockReturnValue( {
		receipt_state: 'closed',
		write_status: 'complete',
		batch_id: 11,
		steps: [],
		replayed: true,
	} );
	await loaded( { ...base, filters: [] } );
	const row = screen
		.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
		.closest( 'tr' );
	fireEvent.click( within( row ).getByRole( 'button', { name: 'Actions' } ) );
	fireEvent.click(
		await screen.findByRole( 'menuitem', { name: 'Edit details' } )
	);
	const dialog = await screen.findByRole( 'dialog', { name: 'Details' } );
	const sku = await within( dialog ).findByRole( 'textbox', { name: 'SKU' } );
	fireEvent.change( sku, { target: { value: 'submitted' } } );
	fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Save' } ) );
	await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
	fireEvent.change( sku, { target: { value: 'newer draft' } } );
	rowRead.mockRejectedValueOnce( new Error( 'strict current read failed' ) );
	await act( async () => held.resolve() );
	await within( dialog ).findByText( /saved.*could not.*refresh/i );
	expect( sku.value ).toBe( 'newer draft' );
	expect( patches() ).toHaveLength( 1 );
} );

it.each( [ 'table', 'cards', 'catalogue', 'catalogue recovered' ] )(
	'the %s caller restores the localized identity before permitting an edit',
	async ( layout ) => {
		const { tabScope } = require( '../../assets/src/tab-store' );
		const settings = { root: '', user: { id: 79 } };
		const key = tabScope( settings, 'inline-writes' );
		sessionStorage.setItem(
			key,
			JSON.stringify( [
				{
					object_id: 11,
					grant: {
						uuid: '00000000-0000-4000-8000-000000000079',
						request_proof: 'opaque-proof',
						accept_until: 1800000000,
						operation: 'product.inline',
						intent: {
							version: 1,
							payload_version: 1,
							registry_revision: 'a'.repeat( 64 ),
							commitment: 'b'.repeat( 64 ),
							object_id: 11,
							source: 'inline',
						},
					},
				},
			] )
		);
		const Standalone =
			layout === 'table'
				? require( '../../assets/src/panels/catalogue/table' ).default
				: require( '../../assets/src/panels/catalogue/views/cards' )
						.CatalogueCards;
		const recovered = layout === 'catalogue recovered';
		if ( recovered ) {
			receiptRead.mockReturnValue( {
				receipt_state: 'closed',
				write_status: 'complete',
				batch_id: 11,
				steps: [],
				replayed: true,
			} );
		}
		render(
			layout.startsWith( 'catalogue' ) ? (
				<Catalogue settings={ settings } initialView={ base } />
			) : (
				<Standalone
					settings={ settings }
					fields={ fields }
					items={ saved }
					actions={ [] }
					view={ {
						...base,
						type: layout === 'cards' ? 'grid' : 'table',
					} }
					onChangeView={ () => {} }
					pagination={ { totalItems: 2, totalPages: 1 } }
					hasLoaded
				/>
			)
		);
		await screen.findByRole( 'link', { name: 'View change in History' } );
		await screen.findAllByText(
			recovered ? /Saved changes to/ : /outcome.*unconfirmed/i
		);
		expect( receiptRead ).toHaveBeenCalledTimes( 1 );
		expect( patches() ).toHaveLength( 0 );
		expect( sessionStorage.getItem( key ) === null ).toBe( recovered );
		expect(
			apiFetch.mock.calls.filter(
				( [ c ] ) =>
					c.url === 'requests' &&
					c.data.operation === 'product.inline'
			)
		).toHaveLength( 0 );
	}
);

it( 'does not describe a later current Status as the historical recovered save result', () => {
	const {
		savedOutcome,
	} = require( '../../assets/src/panels/catalogue/edit/status' );
	const status = descriptors.find( ( d ) => d.nativeStatus );
	expect(
		savedOutcome( status, {
			recovered: true,
			receipt_state: 'closed',
			write_status: 'complete',
			item: { [ status.key ]: 'private' },
		} )
	).toBe( '' );
} );

it( 'a failed later recovery read never reinstates an older current row after Undo', async () => {
	let editContext, drawn;
	function Inside() {
		editContext = useContext( EditContext );
		return null;
	}
	const onCommitted = jest
		.fn()
		.mockRejectedValue( new Error( 'list unavailable' ) );
	function Harness( { items } ) {
		const edit = useEditScope( {
			items,
			fields,
			actions: [],
			onCommitted,
		} );
		drawn = edit.items;
		return edit.wrap( <Inside /> );
	}
	patch.mockImplementationOnce( ( id, values ) => {
		answer( id, values );
		throw new TypeError( 'lost' );
	} );
	receiptRead.mockReturnValue( {
		receipt_state: 'closed',
		write_status: 'complete',
		batch_id: 11,
		steps: [],
		replayed: true,
	} );
	const { rerender } = render( <Harness items={ saved } /> );
	await act( async () => {
		await editContext.commit( saved[ 0 ], { sku: 'saved once' } );
	} );
	expect( drawn[ 0 ].sku ).toBe( 'saved once' );
	answer( 11, { sku: 'after Undo' } );
	rerender( <Harness items={ saved } /> );
	expect( drawn[ 0 ].sku ).toBe( 'after Undo' );
	rowRead.mockRejectedValueOnce( new Error( 'row unavailable' ) );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Refresh results' } )
	);
	await waitFor( () => expect( onCommitted ).toHaveBeenCalledTimes( 2 ) );
	expect( drawn[ 0 ].sku ).toBe( 'after Undo' );
	expect( patches() ).toHaveLength( 1 );
	expect( screen.getAllByText( /Saved changes to/ ).length ).toBeGreaterThan(
		0
	);
} );

it( 'a recovered partial outcome preserves unrelated exact selection and leaves corrected editing available', async () => {
	patch.mockImplementationOnce( ( id, values ) => {
		answer( id, values );
		throw new TypeError( 'lost' );
	} );
	receiptRead.mockReturnValue( {
		receipt_state: 'closed',
		write_status: 'partial',
		batch_id: 11,
		steps: [ { field: 'sku', state: 'failed', code: '' } ],
		replayed: true,
	} );
	await loaded( { ...base, filters: [] } );
	fireEvent.click(
		screen.getByRole( 'checkbox', {
			name: 'Ladies Golf Shirt',
			exact: true,
		} )
	);
	await waitFor( () =>
		expect( getSelectionSession().getState().current ).toBeTruthy()
	);
	const selected = getSelectionSession().getState().current;
	fireEvent.click( toggle() );
	await screen.findAllByText( /only part.*saved/i );
	await waitFor( () =>
		expect( toggle().getAttribute( 'aria-disabled' ) ).not.toBe( 'true' )
	);
	expect( getSelectionSession().getState().current ).toEqual( selected );
	expect( patches() ).toHaveLength( 1 );
	fireEvent.click( toggle() );
	await waitFor( () => expect( patches() ).toHaveLength( 2 ) );
} );
