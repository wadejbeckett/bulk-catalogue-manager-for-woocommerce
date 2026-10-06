import { withInlineReceipts } from './inline-transport';
/**
 * 1.4.3: keyboard focus after a save that redraws the edited row (SPEC §14,
 * T75). A variation's options are read again after its save; its row stays
 * drawn meanwhile, so focus stays on the edited cell. Where a row is drawn
 * again all the same (the scope-and-cell harness below, the order 1.4.2 took
 * in the browser), focus waits for the new cell and returns to its trigger,
 * never to the results search; the search takes focus only when the row is
 * truly gone.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';
import { CellContext } from '../../assets/src/panels/catalogue/cells';
import { fieldsFromRegistry } from '../../assets/src/panels/catalogue/fields';
import { useContext } from '@wordpress/element';
import {
	EditContext,
	InlineCell,
	useEditScope,
} from '../../assets/src/panels/catalogue/edit';

jest.mock( '@wordpress/api-fetch', () => {
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
const { getQueryArgs } = require( '@wordpress/url' );
const { REGISTRY, ROWS, CATEGORIES, page } = require( './catalogue-fixture' );

jest.setTimeout( 20000 );

const SETTINGS = { settings: { rows_per_page: 25 } };
const SIMPLE = { ...ROWS[ 0 ], type: 'simple' };
const POLO = {
	...ROWS[ 0 ],
	id: 22,
	name: 'Polo',
	sku: 'POLO',
	type: 'variable',
	variation_count: 2,
	regular_price: '',
	price_html: '<span>R20.00</span>',
};
const variation = ( id, size ) => ( {
	id,
	parent_id: 22,
	parent_name: 'Polo',
	attribute_summary: size,
	name: `Polo - ${ size }`,
	thumbnail: 0,
	thumbnail_url: '',
	sku: `POLO-${ size }`,
	status: 'publish',
	regular_price: '20.00',
	sale_price: '',
	stock_status: 'instock',
	stock_quantity: 5,
	manage_stock: true,
	category_ids: [],
} );

let products, variations, held, heldPage, patch;

function deferred() {
	let resolve;
	const promise = new Promise( ( yes ) => {
		resolve = yes;
	} );
	return { promise, resolve };
}

beforeEach( () => {
	window.wp = { a11y: { speak: jest.fn() } };
	products = [ SIMPLE, POLO ];
	variations = [ variation( 2201, 'Small' ), variation( 2202, 'Large' ) ];
	held = null;
	heldPage = null;
	patch = jest.fn( async ( path, data ) => {
		const id = Number( path.split( '/' )[ 1 ] );
		const changes = { ...data };
		delete changes.uuid;
		delete changes.intent;
		delete changes.request_proof;
		let item;
		const apply = ( row ) => {
			if ( row.id !== id ) {
				return row;
			}
			item = { ...row, ...changes };
			return item;
		};
		products = products.map( apply );
		variations = variations.map( apply );
		return { item, batch_id: id, status: 'complete', results: [] };
	} );
	apiFetch.mockReset();
	apiFetch.mockImplementation( ( { url, method, data } ) => {
		const path = url.split( '?' )[ 0 ];
		if ( method === 'PATCH' ) {
			return patch( path, data, getQueryArgs( url ) );
		}
		switch ( path ) {
			case 'fields':
				return Promise.resolve( REGISTRY );
			case 'categories':
				return Promise.resolve( CATEGORIES );
			case 'products': {
				const answer = () => page( products, products.length );
				return heldPage
					? heldPage.promise.then( answer )
					: Promise.resolve( answer() );
			}
			case 'products/22/variations': {
				// A held read answers with the rows as they are when released.
				const answer = () => page( variations, variations.length );
				return held
					? held.promise.then( answer )
					: Promise.resolve( answer() );
			}
			case 'products/2201':
				return Promise.resolve( { item: variations[ 0 ] } );
			case 'audit':
				return Promise.resolve( {
					items: [],
					pagination: { total: 0 },
				} );
			default:
				return new Promise( () => {} );
		}
	} );
	withInlineReceipts( apiFetch );
} );

afterEach( () => {
	delete window.wp;
} );

const variationReads = () =>
	apiFetch.mock.calls.filter(
		( [ { url } ] ) => url.split( '?' )[ 0 ] === 'products/22/variations'
	).length;
const search = () =>
	screen.getByRole( 'searchbox', { name: 'Search by name or SKU' } );
const priceOf = ( name ) =>
	screen.queryByRole( 'button', { name: `Edit Regular price, ${ name }` } );

async function openPolo() {
	render( <Catalogue settings={ SETTINGS } /> );
	await screen.findByText( 'Polo' );
	fireEvent.click(
		screen.getByRole( 'button', { name: 'Show the variations of Polo' } )
	);
	await waitFor( () => expect( priceOf( 'Polo - Small' ) ).toBeTruthy() );
}

/**
 * Enter on a cell, type, Enter: the keyboard journey of variations.spec.js.
 *
 * @param {string} name  Product name in the cell's label.
 * @param {string} value Typed value.
 */
function saveByKeyboard( name, value ) {
	const cell = priceOf( name );
	cell.focus();
	fireEvent.keyDown( cell, { key: 'Enter' } );
	const input = screen.getByRole( 'textbox', {
		name: `Regular price, ${ name }`,
	} );
	expect( document.activeElement ).toBe( input );
	fireEvent.change( input, { target: { value } } );
	fireEvent.keyDown( input, { key: 'Enter' } );
}

describe( 'focus after a save that redraws the row', () => {
	it( 'keeps a variation’s row, and focus on its cell, while its options are read again', async () => {
		await openPolo();
		held = deferred();
		const reads = variationReads();
		saveByKeyboard( 'Polo - Small', '33.50' );
		await waitFor( () => expect( patch ).toHaveBeenCalledTimes( 1 ) );
		await waitFor( () =>
			expect( variationReads() ).toBeGreaterThan( reads )
		);
		// The re-read is out; the row is still drawn and the save settles on it.
		await waitFor( () =>
			expect( document.activeElement ).toBe( priceOf( 'Polo - Small' ) )
		);
		await act( async () => {
			await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
		} );
		expect( document.activeElement ).toBe( priceOf( 'Polo - Small' ) );
		const trigger = priceOf( 'Polo - Small' );
		await act( async () => {
			held.resolve();
			await held.promise;
		} );
		await waitFor( () =>
			expect( priceOf( 'Polo - Small' )?.textContent ).toContain(
				'33.50'
			)
		);
		// The same cell, never drawn again: focus never left it.
		expect( priceOf( 'Polo - Small' ) ).toBe( trigger );
		expect( document.activeElement ).toBe( trigger );
	} );

	it( 'returns to the redrawn cell when the options come back at once', async () => {
		await openPolo();
		saveByKeyboard( 'Polo - Small', '33.50' );
		await waitFor( () => expect( variationReads() ).toBe( 2 ) );
		await waitFor( () =>
			expect( priceOf( 'Polo - Small' )?.textContent ).toContain(
				'33.50'
			)
		);
		await waitFor( () =>
			expect( document.activeElement ).toBe( priceOf( 'Polo - Small' ) )
		);
	} );

	it( 'keeps focus on a simple product’s cell after its save', async () => {
		await openPolo();
		saveByKeyboard( SIMPLE.name, '14.00' );
		await waitFor( () =>
			expect( priceOf( SIMPLE.name )?.textContent ).toContain( '14.00' )
		);
		await waitFor( () =>
			expect( document.activeElement ).toBe( priceOf( SIMPLE.name ) )
		);
	} );

	it( 'returns to the trigger on Escape and leaves it there', async () => {
		await openPolo();
		const cell = priceOf( 'Polo - Large' );
		cell.focus();
		fireEvent.keyDown( cell, { key: 'Enter' } );
		const input = screen.getByRole( 'textbox', {
			name: 'Regular price, Polo - Large',
		} );
		fireEvent.change( input, { target: { value: '99' } } );
		fireEvent.keyDown( input, { key: 'Escape' } );
		expect( document.activeElement ).toBe( priceOf( 'Polo - Large' ) );
		expect( patch ).not.toHaveBeenCalled();
		// The user moves on: nothing pulls focus back to the cell.
		search().focus();
		await act( async () => {
			await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
		} );
		expect( document.activeElement ).toBe( search() );
	} );

	it( 'falls back to the search only when the edited row is truly gone', async () => {
		await openPolo();
		saveByKeyboard( 'Polo - Small', '33.50' );
		// The product left the results with this save.
		products = [ SIMPLE ];
		await waitFor( () => expect( patch ).toHaveBeenCalledTimes( 1 ) );
		await waitFor( () =>
			expect( screen.queryByText( 'Polo' ) ).toBeNull()
		);
		await waitFor( () =>
			expect( document.activeElement ).toBe( search() )
		);
	} );

	it( 'returns from the details panel to the variation row’s menu while its options are read again', async () => {
		await openPolo();
		const row = priceOf( 'Polo - Small' ).closest( 'tr' );
		const menu = within( row ).getByRole( 'button', { name: 'Actions' } );
		menu.focus();
		fireEvent.click( menu );
		fireEvent.click(
			await screen.findByRole( 'menuitem', { name: 'Edit details' } )
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Details',
		} );
		held = deferred();
		fireEvent.change(
			await within( dialog ).findByRole( 'spinbutton', {
				name: 'Regular price',
			} ),
			{ target: { value: '31' } }
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save' } )
		);
		await within( dialog ).findByText( /^Saved: Regular price/ );
		expect( priceOf( 'Polo - Small' ) ).toBeTruthy();
		fireEvent.keyDown( dialog, { key: 'Escape' } );
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Details' } )
			).toBeNull()
		);
		expect( document.activeElement ).not.toBe( search() );
		await act( async () => {
			held.resolve();
			await held.promise;
		} );
		await waitFor( () => expect( priceOf( 'Polo - Small' ) ).toBeTruthy() );
		await waitFor( () =>
			expect( document.activeElement ).toBe(
				within( priceOf( 'Polo - Small' ).closest( 'tr' ) ).getByRole(
					'button',
					{ name: 'Actions' }
				)
			)
		);
	} );
} );

/**
 * The order the browser takes (1.4.2, variations.spec.js): the PATCH answer
 * replaces the row, so the product's options are read again and the edited
 * row leaves the grid while the page's own read after the save is still out;
 * the grid redraws (the drawn rows changed) and the scope renders again; the
 * options come back and a new cell mounts while the save is still pending;
 * only then does the page read answer and the save settle.
 */
describe( 'a cell redrawn while its save is still pending', () => {
	const FIELDS = fieldsFromRegistry( REGISTRY );
	const PRICE = FIELDS.find(
		( field ) => field.descriptor?.key === 'regular_price'
	).descriptor;
	const SMALL = variation( 2201, 'Small' );
	// The page: the variation's product is on it, unless it left.
	const PAGE = [ SIMPLE, POLO ];
	const LEFT = [ SIMPLE ];

	function Harness( { shown, page: pageRead, tick, items = PAGE } ) {
		const edit = useEditScope( {
			items,
			fields: FIELDS,
			actions: [],
			onCommitted: () => pageRead.promise,
			settings: {},
		} );
		const row = variations.find( ( item ) => item.id === SMALL.id );
		return edit.wrap(
			<CellContext.Provider
				value={ { termNames: {}, pending: edit.pending } }
			>
				<input
					type="search"
					aria-label="Search by name or SKU"
					data-tick={ tick }
				/>
				<table>
					<tbody>
						{ shown && (
							<tr>
								<td>
									<InlineCell
										item={ row }
										descriptor={ PRICE }
										display={ row.regular_price }
									/>
								</td>
								<td>
									<button type="button">
										Next in the row
									</button>
								</td>
								<td>
									<Menu item={ row } />
								</td>
							</tr>
						) }
					</tbody>
				</table>
			</CellContext.Provider>
		);
	}

	// The row's action menu: here it opens the detail panel at once.
	function Menu( { item } ) {
		const scope = useContext( EditContext );
		return (
			<button
				type="button"
				aria-haspopup="menu"
				onClick={ () => scope.openDrawer( item ) }
			>
				Actions
			</button>
		);
	}

	it( 'returns focus to the new cell’s trigger, not to the search', async () => {
		const pageRead = deferred();
		const view = render( <Harness shown page={ pageRead } tick={ 0 } /> );
		saveByKeyboard( 'Polo - Small', '33.50' );
		await waitFor( () => expect( patch ).toHaveBeenCalledTimes( 1 ) );
		// The options are read again: the row (and its editor) leave.
		view.rerender(
			<Harness shown={ false } page={ pageRead } tick={ 1 } />
		);
		// The grid's drawn rows changed, so the scope renders again.
		view.rerender(
			<Harness shown={ false } page={ pageRead } tick={ 2 } />
		);
		expect( document.activeElement ).not.toBe( search() );
		// The options are back; the save is still pending.
		view.rerender( <Harness shown page={ pageRead } tick={ 3 } /> );
		expect(
			priceOf( 'Polo - Small' ).getAttribute( 'aria-disabled' )
		).toBe( 'true' );
		expect( document.activeElement ).toBe( priceOf( 'Polo - Small' ) );
		// The page read answers and the save settles.
		await act( async () => {
			pageRead.resolve();
			await pageRead.promise;
		} );
		await waitFor( () =>
			expect(
				priceOf( 'Polo - Small' ).getAttribute( 'aria-disabled' )
			).toBeNull()
		);
		expect( document.activeElement ).toBe( priceOf( 'Polo - Small' ) );
	} );

	it( 'never takes focus back after the user moves on', async () => {
		const pageRead = deferred();
		const view = render( <Harness shown page={ pageRead } tick={ 0 } /> );
		saveByKeyboard( 'Polo - Small', '33.50' );
		await act( async () => {
			pageRead.resolve();
			await pageRead.promise;
		} );
		await waitFor( () =>
			expect( document.activeElement ).toBe( priceOf( 'Polo - Small' ) )
		);
		// Tab on along the row, then the row is drawn again under it.
		screen.getByRole( 'button', { name: 'Next in the row' } ).focus();
		view.rerender(
			<Harness shown={ false } page={ pageRead } tick={ 1 } />
		);
		view.rerender( <Harness shown page={ pageRead } tick={ 2 } /> );
		expect( document.activeElement ).not.toBe( priceOf( 'Polo - Small' ) );
	} );

	it( 'returns from the details panel to the row’s menu once the row is drawn again', async () => {
		const pageRead = deferred();
		const view = render( <Harness shown page={ pageRead } tick={ 0 } /> );
		const menu = screen.getByRole( 'button', { name: 'Actions' } );
		menu.focus();
		fireEvent.click( menu );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Details',
		} );
		// The row is drawn again while the panel is open.
		view.rerender(
			<Harness shown={ false } page={ pageRead } tick={ 1 } />
		);
		fireEvent.keyDown( dialog, { key: 'Escape' } );
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Details' } )
			).toBeNull()
		);
		expect( document.activeElement ).not.toBe( search() );
		view.rerender( <Harness shown page={ pageRead } tick={ 2 } /> );
		expect( document.activeElement ).toBe(
			screen.getByRole( 'button', { name: 'Actions' } )
		);
	} );

	it( 'falls back to the search once the saved row is truly gone', async () => {
		const pageRead = deferred();
		const view = render( <Harness shown page={ pageRead } tick={ 0 } /> );
		saveByKeyboard( 'Polo - Small', '33.50' );
		await waitFor( () => expect( patch ).toHaveBeenCalledTimes( 1 ) );
		view.rerender(
			<Harness shown={ false } page={ pageRead } tick={ 1 } />
		);
		await act( async () => {
			pageRead.resolve();
			await pageRead.promise;
		} );
		// Its product is not on the page: the row will not come back.
		view.rerender(
			<Harness
				shown={ false }
				page={ pageRead }
				tick={ 2 }
				items={ LEFT }
			/>
		);
		await waitFor( () =>
			expect( document.activeElement ).toBe( search() )
		);
	} );
} );
