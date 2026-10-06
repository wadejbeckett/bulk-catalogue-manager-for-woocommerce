/**
 * T75: the accessibility helpers. The progress announcer speaks at
 * milestones only and politely; the grid's arrow keys move between cell
 * controls and leave text fields alone; the table gains its caption, row
 * headers and aria-sort; the component repairs name the unnamed controls;
 * History reads the batch the bulk panel links to; and the export dialog
 * (T103) is a named dialog whose every control has a visible label.
 */
import { fireEvent, render, screen, within } from '@testing-library/react';

import {
	MILESTONES,
	milestone,
	progressSentence,
	useProgressAnnouncer,
} from '../../assets/src/panels/bulk/announce';
import {
	arrowTarget,
	onGridKeyDown,
} from '../../assets/src/panels/catalogue/grid-keys';
import { describeTable } from '../../assets/src/panels/catalogue/table';
import { repair } from '../../assets/src/a11y-patches';
import { batchFrom } from '../../assets/src/panels/history/index';
import { panelHref } from '../../assets/src/router';
import { ExportControl } from '../../assets/src/panels/catalogue/views';
import { defaultView } from '../../assets/src/panels/catalogue/fields';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

function Announcer( { done, total, finished = false } ) {
	useProgressAnnouncer( done, total, finished );
	return null;
}

describe( 'progress announcements', () => {
	let speak;
	beforeEach( () => {
		speak = jest.fn();
		window.wp = { a11y: { speak } };
	} );
	afterEach( () => {
		delete window.wp;
	} );

	it( 'finds the milestone reached', () => {
		expect( MILESTONES ).toEqual( [ 25, 50, 75 ] );
		expect( milestone( 0, 100 ) ).toBe( 0 );
		expect( milestone( 24, 100 ) ).toBe( 0 );
		expect( milestone( 25, 100 ) ).toBe( 25 );
		expect( milestone( 60, 100 ) ).toBe( 50 );
		expect( milestone( 99, 100 ) ).toBe( 75 );
		expect( milestone( 5, 0 ) ).toBe( 0 );
	} );

	it( 'speaks politely once per milestone, not on every read', () => {
		const { rerender } = render( <Announcer done={ 0 } total={ 1284 } /> );
		expect( speak ).not.toHaveBeenCalled();
		rerender( <Announcer done={ 330 } total={ 1284 } /> );
		rerender( <Announcer done={ 331 } total={ 1284 } /> );
		rerender( <Announcer done={ 340 } total={ 1284 } /> );
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith(
			// formatNumber() groups digits in the viewer's locale.
			expect.stringMatching(
				/^Applying the change: 330 of 1\D?284 done\.$/
			),
			'polite'
		);
		rerender( <Announcer done={ 1000 } total={ 1284 } /> );
		expect( speak ).toHaveBeenCalledTimes( 2 );
		rerender( <Announcer done={ 1284 } total={ 1284 } finished /> );
		expect( speak ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'says the progress in words', () => {
		expect( progressSentence( 5, 10 ) ).toBe(
			'Applying the change: 5 of 10 done.'
		);
	} );
} );

describe( 'the grid keys', () => {
	beforeEach( () => {
		document.body.innerHTML = `
			<table><thead><tr><th><input type="checkbox" id="all"></th><th>Name</th></tr></thead>
			<tbody>
				<tr><td><input type="checkbox" id="a1"></td><td><button id="b1">x</button><button id="c1">y</button></td></tr>
				<tr><td><input type="checkbox" id="a2"></td><td></td></tr>
				<tr><td><input type="checkbox" id="a3"></td><td><button id="b3">z</button><input type="text" id="t3"></td></tr>
			</tbody></table>`;
	} );
	const el = ( id ) => document.getElementById( id );

	it( 'moves along a row and within a column, skipping empty cells', () => {
		expect( arrowTarget( el( 'a1' ), 'ArrowRight' ) ).toBe( el( 'b1' ) );
		expect( arrowTarget( el( 'b1' ), 'ArrowRight' ) ).toBe( el( 'c1' ) );
		expect( arrowTarget( el( 'c1' ), 'ArrowRight' ) ).toBeNull();
		expect( arrowTarget( el( 'b1' ), 'ArrowLeft' ) ).toBe( el( 'a1' ) );
		expect( arrowTarget( el( 'a1' ), 'ArrowDown' ) ).toBe( el( 'a2' ) );
		expect( arrowTarget( el( 'b1' ), 'ArrowDown' ) ).toBe( el( 'b3' ) );
		expect( arrowTarget( el( 'b3' ), 'ArrowUp' ) ).toBe( el( 'b1' ) );
		expect( arrowTarget( el( 'a1' ), 'ArrowUp' ) ).toBeNull();
		expect( arrowTarget( el( 'all' ), 'ArrowDown' ) ).toBeNull();
	} );

	it( 'moves focus, but leaves a text field and modified keys alone', () => {
		const press = ( target, key, extra = {} ) => {
			const event = {
				key,
				target,
				preventDefault: jest.fn(),
				...extra,
			};
			onGridKeyDown( event );
			return event.preventDefault;
		};
		el( 'a1' ).focus();
		expect( press( el( 'a1' ), 'ArrowDown' ) ).toHaveBeenCalled();
		expect( document.activeElement ).toBe( el( 'a2' ) );
		expect( press( el( 't3' ), 'ArrowLeft' ) ).not.toHaveBeenCalled();
		expect(
			press( el( 'a2' ), 'ArrowDown', { shiftKey: true } )
		).not.toHaveBeenCalled();
		expect( press( el( 'a2' ), 'Enter' ) ).not.toHaveBeenCalled();
	} );
} );

describe( 'the table description', () => {
	it( 'adds a caption, row headers and aria-sort on sortable columns', () => {
		document.body.innerHTML = `
			<div id="w"><table><thead><tr><th></th><th>Name</th><th aria-sort="ascending">SKU</th><th>Status</th></tr></thead>
			<tbody><tr><td></td><td><div class="dataviews-title-field">Shoe</div></td><td>S1</td><td>On</td></tr></tbody></table></div>`;
		const wrapper = document.getElementById( 'w' );
		describeTable( wrapper, 'Products.', [ 'Name', 'SKU' ] );
		describeTable( wrapper, 'Products.', [ 'Name', 'SKU' ] );
		const table = wrapper.querySelector( 'table' );
		expect( table.querySelectorAll( 'caption' ) ).toHaveLength( 1 );
		expect( table.caption.textContent ).toBe( 'Products.' );
		const heads = table.querySelectorAll( 'thead th' );
		expect( heads[ 1 ].getAttribute( 'aria-sort' ) ).toBe( 'none' );
		expect( heads[ 2 ].getAttribute( 'aria-sort' ) ).toBe( 'ascending' );
		expect( heads[ 3 ].hasAttribute( 'aria-sort' ) ).toBe( false );
		expect(
			table
				.querySelector( 'tbody td:nth-child(2)' )
				.getAttribute( 'role' )
		).toBe( 'rowheader' );
	} );
} );

describe( 'component repairs', () => {
	it( 'names the filter chip remove button and hides validity delegates', () => {
		document.body.innerHTML = `
			<div class="dataviews-filters__summary-chip-container">
				<div class="dataviews-filters__summary-chip">Date is in the past: 30 days</div>
				<button class="dataviews-filters__summary-chip-remove"></button>
			</div>
			<select class="components-validated-control__error-delegate" tabindex="-1"></select>`;
		repair( document.body );
		expect(
			document
				.querySelector( '.dataviews-filters__summary-chip-remove' )
				.getAttribute( 'aria-label' )
		).toBe( 'Remove filter: Date is in the past: 30 days' );
		expect(
			document
				.querySelector(
					'.components-validated-control__error-delegate'
				)
				.getAttribute( 'aria-hidden' )
		).toBe( 'true' );
	} );
} );

describe( 'History links', () => {
	it( 'reads the linked batch and whether to start its undo', () => {
		expect( batchFrom( '?panel=history&batch=12&undo=1' ) ).toEqual( {
			id: 12,
			undo: true,
		} );
		expect( batchFrom( '?batch=7' ) ).toEqual( { id: 7, undo: false } );
		expect( batchFrom( '?batch=x' ) ).toBeNull();
		expect( batchFrom( '' ) ).toBeNull();
	} );

	it( 'does not carry one-time arguments to another panel', () => {
		const href = panelHref(
			'settings',
			'https://example.test/wp-admin/edit.php?page=bulkcat&panel=history&batch=4&undo=1&product=9#x'
		);
		expect( href ).toBe(
			'https://example.test/wp-admin/edit.php?page=bulkcat&panel=settings'
		);
	} );
} );

describe( 'the export dialog', () => {
	it( 'is a named dialog whose every control has a visible label, with the rows as a group', async () => {
		const apiFetch = require( '@wordpress/api-fetch' ).default;
		apiFetch.mockResolvedValue( {
			rows: 3,
			left_out: 0,
			guarded_identifiers: 0,
			guarded_names: 0,
			whole_catalogue: true,
			filename: 'catalogue-all-2026-09-29-1200.csv',
		} );
		const { REGISTRY } = require( './catalogue-fixture' );
		render(
			<ExportControl
				query={ {} }
				view={ defaultView( REGISTRY, { settings: {} } ) }
				registry={ REGISTRY }
				selection={ {
					kind: 'snapshot',
					token: '',
					key: '',
					count: 0,
					byType: { products: 0, variations: 0 },
					waiting: '',
				} }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Export CSV' } ) );
		const dialog = screen.getByRole( 'dialog', { name: 'Export CSV' } );
		await within( dialog ).findByRole( 'checkbox', {
			name: 'Yes, export the whole catalogue',
		} );
		expect(
			within( dialog ).getByRole( 'group', { name: 'Rows' } ).tagName
		).toBe( 'FIELDSET' );
		const controls = dialog.querySelectorAll( 'input, button, select' );
		expect( controls.length ).toBeGreaterThan( 4 );
		controls.forEach( ( control ) => {
			const labelled =
				control.getAttribute( 'aria-label' ) ||
				( control.id &&
					dialog.querySelector( `label[for="${ control.id }"]` )
						?.textContent ) ||
				control.textContent;
			expect( String( labelled || '' ).trim() ).not.toBe( '' );
		} );
		// Nothing selected: the unavailable choice says why, next to it.
		const selected = within( dialog ).getByRole( 'radio', {
			name: 'Selected',
		} );
		expect(
			document.getElementById(
				selected.getAttribute( 'aria-describedby' )
			).textContent
		).toBe( 'Nothing selected' );
		// The summary is announced politely.
		expect(
			dialog
				.querySelector( '.bulkcat-export-dialog__summary' )
				.getAttribute( 'aria-live' )
		).toBe( 'polite' );
	} );
} );
