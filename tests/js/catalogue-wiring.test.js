/**
 * The catalogue's wire-ins: the category panel filters the rows (T61, T62),
 * "Make a change to these" opens the bulk change panel on the selection
 * (T48–T50), the settings come through useSettings() (T73), and an applied
 * change shows its progress once the panel is closed (T44).
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import Catalogue, { AppliedProgress } from '../../assets/src/panels/catalogue';
import { setSettings } from '../../assets/src/panels/settings/store';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const {
	REGISTRY,
	CATEGORIES,
	page,
	route,
	calls,
} = require( './catalogue-fixture' );

const SETTINGS = {
	settings: { rows_per_page: 25, category_panel: true },
	user: { id: 3, name: 'Mia', capabilities: {} },
};

beforeEach( () => {
	apiFetch.mockReset();
	window.localStorage.clear();
	route( apiFetch, {
		fields: () => REGISTRY,
		products: () => page(),
		categories: () => CATEGORIES,
	} );
} );

async function renderLoaded() {
	const result = render( <Catalogue settings={ SETTINGS } /> );
	await screen.findByText( 'Classic Cotton T-Shirt' );
	return result;
}

describe( 'catalogue wiring', () => {
	it( 'filters the rows by the category chosen in the panel left of the table', async () => {
		await renderLoaded();
		const tree = await screen.findByRole( 'tree' );
		const table = screen.getByRole( 'table' );
		expect(
			// eslint-disable-next-line no-bitwise
			tree.compareDocumentPosition( table ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		const clothing = await within( tree ).findByRole( 'treeitem', {
			name: /^Clothing/,
		} );
		fireEvent.click( clothing.querySelector( '.bcm-cat-row' ) );
		await waitFor( () =>
			expect( calls( apiFetch, 'products' ).length ).toBeGreaterThan( 1 )
		);
		const last = calls( apiFetch, 'products' ).at( -1 );
		expect( last.category_ids ).toEqual( {
			operator: 'isAny',
			value: [ '2' ],
		} );
		expect( last.page ).toBe( '1' );
		expect(
			document.querySelector( '.bcm-catalogue__chip' ).textContent
		).toContain( 'Category is Clothing or its sub-categories' );
	} );

	it( 'opens the bulk change panel on the selected rows from "Make a change to these"', async () => {
		await renderLoaded();
		const row = screen
			.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
			.closest( 'tr' );
		fireEvent.click( within( row ).getByRole( 'checkbox' ) );
		const bar = await screen.findByRole( 'region', { name: 'Selection' } );
		fireEvent.click(
			within( bar ).getByRole( 'button', {
				name: 'Make a change to these',
			} )
		);
		const dialog = await screen.findByRole( 'dialog' );
		expect( dialog.textContent ).toContain( '1 selected product' );
	} );

	it( 'reads the settings through useSettings, so a save reaches the grid', async () => {
		act( () => setSettings( { rows_per_page: 100 } ) );
		await renderLoaded();
		expect( calls( apiFetch, 'products' )[ 0 ].per_page ).toBe( '100' );
		act( () => setSettings( { rows_per_page: 25 } ) );
	} );

	it( 'shows an applied change’s progress, then reads the rows again', async () => {
		let report = { status: 'running', done_items: 3, total_items: 10 };
		route( apiFetch, { 'batches/9': () => report } );
		const onFinished = jest.fn();
		render( <AppliedProgress batchId={ 9 } onFinished={ onFinished } /> );
		expect( await screen.findByText( '3 of 10 done' ) ).toBeTruthy();
		expect( onFinished ).not.toHaveBeenCalled();

		report = { status: 'complete', done_items: 10, total_items: 10 };
		await waitFor( () => expect( onFinished ).toHaveBeenCalledTimes( 1 ), {
			timeout: 4000,
		} );
		expect( screen.queryByText( /done$/ ) ).toBeNull();
	} );
} );
