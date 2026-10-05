/**
 * A change panel opened with its change already chosen (1.4.1): an add-on's
 * row entry, or any other preset (the selection bar's quick actions, a
 * selection dropped on a category). The panel opens at that action's
 * arguments with the first one focused and in view; the full chooser is
 * folded behind "Choose a different change", which unfolds it with the
 * chosen action still chosen. A panel opened without a preset is as before.
 */
import { fireEvent, render, screen, within } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';
import BulkChangePanel from '../../assets/src/panels/bulk';
import { providerRowChange } from '../../assets/src/panels/catalogue/actions';
import { visibilityOperation } from '../../assets/src/panels/catalogue/selection/quick-actions';

const { IDS, REGISTRY, SETTINGS } = require( './bulk-fixtures' );
const PICKS = require( '../Unit/Extension/fields-picks.snapshot.json' );

jest.mock( '@wordpress/api-fetch', () => {
	const mock = jest.fn( () => new Promise( () => {} ) );
	mock.use = jest.fn();
	mock.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: mock };
} );

const add = PICKS.actions.find( ( action ) => action.id === 'x_picks_add' );

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	window.Element.prototype.scrollIntoView = jest.fn();
} );

/**
 * Open the panel as a product row's add-on entry does.
 *
 * @return {Object} The change the row entry opened.
 */
function openRowEntry() {
	const change = providerRowChange( PICKS, SETTINGS, add, { id: 42 } );
	render(
		<BulkChangePanel
			selection={ change.selection }
			registry={ PICKS }
			settings={ SETTINGS }
			initialOperations={ change.initialOperations }
			object={ change.object }
			onClose={ jest.fn() }
			onApplied={ jest.fn() }
		/>
	);
	return change;
}

describe( 'a preset change', () => {
	it( 'opens a row entry at its arguments, the picker focused and the chooser folded', () => {
		openRowEntry();
		const dialog = screen.getByRole( 'dialog', { name: 'Bulk change' } );
		const picker = within( dialog ).getByRole( 'combobox', {
			name: 'Recipients',
		} );
		expect( document.activeElement ).toBe( picker );
		// The chosen action is named; the other choices are folded away.
		expect( dialog.textContent ).toContain( 'Add recipients' );
		expect( within( dialog ).queryAllByRole( 'radio' ) ).toHaveLength( 0 );
		const other = within( dialog ).getByRole( 'button', {
			name: 'Choose a different change',
		} );
		expect( other.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		// The picker comes before the way back to the chooser.
		expect(
			// eslint-disable-next-line no-bitwise
			picker.compareDocumentPosition( other ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	it( 'unfolds the chooser on request, with the action still chosen and focused', () => {
		openRowEntry();
		const dialog = screen.getByRole( 'dialog', { name: 'Bulk change' } );
		fireEvent.click(
			within( dialog ).getByRole( 'button', {
				name: 'Choose a different change',
			} )
		);
		const chosen = within( dialog ).getByRole( 'radio', {
			name: /^Add recipients/,
		} );
		expect( chosen.checked ).toBe( true );
		expect( document.activeElement ).toBe( chosen );
		expect(
			within( dialog ).queryByRole( 'button', {
				name: 'Choose a different change',
			} )
		).toBeNull();
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: /^Remove recipients/,
			} )
		);
		expect(
			within( dialog ).getByRole( 'radio', {
				name: /^Remove recipients/,
			} ).checked
		).toBe( true );
	} );

	it( 'opens a quick action at its first argument too', () => {
		const step = visibilityOperation( REGISTRY, SETTINGS, false );
		render(
			<BulkChangePanel
				selection={ { kind: 'ids', ids: IDS } }
				registry={ REGISTRY }
				settings={ SETTINGS }
				initialOperations={ [ step ] }
			/>
		);
		const dialog = screen.getByRole( 'dialog', { name: 'Bulk change' } );
		expect( within( dialog ).queryAllByRole( 'radio' ) ).toHaveLength( 0 );
		const body = dialog.querySelector( '.bulkcat-bulk__cbody' );
		expect( body ).not.toBeNull();
		expect( body.contains( document.activeElement ) ).toBe( true );
		expect( window.Element.prototype.scrollIntoView ).toHaveBeenCalled();
	} );

	it( 'opens without a preset at the heading, with the whole chooser', () => {
		render(
			<BulkChangePanel
				selection={ { kind: 'ids', ids: IDS } }
				registry={ PICKS }
				settings={ SETTINGS }
			/>
		);
		expect( document.activeElement ).toBe(
			screen.getByRole( 'heading', { name: 'Bulk change' } )
		);
		expect( screen.getAllByRole( 'radio' ).length ).toBeGreaterThan( 2 );
		expect(
			screen.queryByRole( 'button', {
				name: 'Choose a different change',
			} )
		).toBeNull();
	} );
} );
