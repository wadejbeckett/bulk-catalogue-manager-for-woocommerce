/**
 * The Settings tab (T73): every §11 row rendered from GET /settings in plain
 * words, the PUT payload with the busy state and success notice, the
 * server's refusals shown against their settings, and read-only mode.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

import Settings, {
	changedValues,
	getSettings,
} from '../../assets/src/panels/settings';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

import {
	coreJson,
	expectNoRawText,
	expectPlain,
	html500,
	htmlPage,
} from './error-fixtures';

const apiFetch = require( '@wordpress/api-fetch' ).default;

const VALUES = {
	administrators_only: false,
	rows_per_page: 25,
	batch_size: 50,
	history_days: 90,
	confirm_threshold: 200,
	variations_status: true,
	variations_visibility: true,
	variations_price: false,
	variations_stock: false,
	record_unchanged: false,
	category_panel: true,
	csv_separator: 'comma',
	remove_data_on_delete: false,
};

const boolean = ( key, group, label ) => ( {
	key,
	label,
	help: `What ${ label.toLowerCase() } does.`,
	type: 'boolean',
	group,
	default: VALUES[ key ],
} );

/** The shape GET /settings answers (SettingsController::payload()). */
const FIXTURE = {
	values: VALUES,
	settings: [
		{
			...boolean(
				'administrators_only',
				'access',
				'Who can use this screen'
			),
			choices: [
				{ value: false, label: 'Shop managers and administrators' },
				{ value: true, label: 'Administrators only' },
			],
		},
		{
			key: 'rows_per_page',
			label: 'Rows per page',
			help: 'How many products the catalogue shows at first.',
			type: 'integer',
			group: 'grid',
			default: 25,
			choices: [ 25, 50, 100 ].map( ( value ) => ( {
				value,
				label: String( value ),
			} ) ),
		},
		boolean(
			'category_panel',
			'grid',
			'Show the category panel by default'
		),
		{
			key: 'confirm_threshold',
			label: 'Confirm above this many rows',
			help: 'Above this many rows, Apply needs one more click.',
			type: 'integer',
			group: 'bulk',
			default: 200,
			min: 0,
			unit: 'rows',
		},
		boolean(
			'variations_status',
			'bulk',
			'Apply status changes to variations by default'
		),
		boolean(
			'variations_visibility',
			'bulk',
			'Apply visibility changes to variations by default'
		),
		boolean(
			'variations_price',
			'bulk',
			'Apply price changes to variations by default'
		),
		boolean(
			'variations_stock',
			'bulk',
			'Apply stock changes to variations by default'
		),
		{
			key: 'batch_size',
			label: 'Products per background batch',
			help: 'How many products each background run saves.',
			type: 'integer',
			group: 'bulk',
			default: 50,
			min: 10,
			max: 100,
			unit: 'products',
		},
		{
			key: 'history_days',
			label: 'Keep history for',
			help: 'Older changes are removed from the history.',
			type: 'integer',
			group: 'history',
			default: 90,
			min: 7,
			max: 730,
			special: [ { value: 0, label: 'Indefinitely' } ],
			unit: 'days',
		},
		boolean(
			'record_unchanged',
			'history',
			'Record unchanged rows in the audit log'
		),
		{
			key: 'csv_separator',
			label: 'CSV separator',
			help: 'Choose semicolon if your spreadsheet uses commas in numbers.',
			type: 'choice',
			group: 'export',
			default: 'comma',
			choices: [
				{ value: 'comma', label: 'Comma' },
				{ value: 'semicolon', label: 'Semicolon' },
			],
		},
		{
			...boolean(
				'remove_data_on_delete',
				'data',
				'Remove all data when the plugin is deleted'
			),
			warning:
				'When this is on, deleting the plugin permanently removes its settings, history and undo records.',
		},
	],
	groups: [
		{ id: 'access', label: 'Access' },
		{ id: 'grid', label: 'Catalogue' },
		{ id: 'bulk', label: 'Bulk changes' },
		{ id: 'history', label: 'History' },
		{ id: 'export', label: 'Export' },
		{ id: 'data', label: 'Your data' },
	],
	canEdit: true,
};

/**
 * A promise and the functions settling it.
 *
 * @return {Object} { promise, resolve, reject }.
 */
function deferred() {
	const out = {};
	out.promise = new Promise( ( resolve, reject ) => {
		out.resolve = resolve;
		out.reject = reject;
	} );
	return out;
}

/**
 * Render the tab over a GET answer and wait for it.
 *
 * @param {Object} answer GET /settings answer.
 */
async function renderTab( answer = FIXTURE ) {
	apiFetch.mockImplementationOnce( () => Promise.resolve( answer ) );
	await act( async () => {
		render( <Settings /> );
	} );
}

/** The tab's own notice text (the live region repeats it elsewhere). */
const noticeText = () =>
	document.querySelector( '.bulkcat-settings > .components-notice' )
		?.textContent || '';

const saveButton = () => screen.getByRole( 'button', { name: /Sav/ } );

describe( 'Settings tab', () => {
	let errors;

	beforeEach( () => {
		apiFetch.mockReset();
		errors = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		errors.mockRestore();
	} );

	it( 'renders every row of the GET answer in plain words with no raw key', async () => {
		await renderTab();
		expect( apiFetch ).toHaveBeenCalledWith( { url: 'settings' } );

		FIXTURE.settings.forEach( ( setting ) => {
			expect(
				screen.getAllByText( setting.label ).length
			).toBeGreaterThan( 0 );
			expect( screen.getByText( setting.help ) ).toBeTruthy();
			expect( document.body.textContent ).not.toContain( setting.key );
		} );
		FIXTURE.groups.forEach( ( group ) =>
			expect(
				screen.getByRole( 'heading', { level: 3, name: group.label } )
			).toBeTruthy()
		);
		expect( document.querySelectorAll( '[data-setting]' ).length ).toBe(
			FIXTURE.settings.length
		);

		expect(
			screen.getByRole( 'radio', {
				name: 'Shop managers and administrators',
			} ).checked
		).toBe( true );
		expect( screen.getByLabelText( 'Rows per page' ).value ).toBe( '25' );
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Apply status changes to variations by default',
			} ).checked
		).toBe( true );
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Apply price changes to variations by default',
			} ).checked
		).toBe( false );
		expect( screen.getByLabelText( 'Number of days' ).value ).toBe( '90' );
		expect( screen.getByText( 'days' ) ).toBeTruthy();
		expect(
			screen.getByRole( 'radio', { name: 'Indefinitely' } ).checked
		).toBe( false );
		expect( screen.getByLabelText( 'CSV separator' ).value ).toBe(
			'comma'
		);
		// The warning shows once the switch is on.
		expect( screen.queryByText( /permanently removes/ ) ).toBeNull();
		expect( saveButton().getAttribute( 'aria-disabled' ) ).toBe( 'true' );
	} );

	it( 'sends only the changed values, shows a busy state and then a success notice', async () => {
		await renderTab();
		fireEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Show the category panel by default',
			} )
		);
		fireEvent.change( screen.getByLabelText( 'Rows per page' ), {
			target: { value: '100' },
		} );
		fireEvent.click(
			screen.getByRole( 'radio', { name: 'Indefinitely' } )
		);
		expect( screen.queryByLabelText( 'Number of days' ) ).toBeNull();
		fireEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Remove all data when the plugin is deleted',
			} )
		);
		expect(
			screen.getAllByText( /permanently removes/ ).length
		).toBeGreaterThan( 0 );
		expect( saveButton().getAttribute( 'aria-disabled' ) ).toBeNull();

		const put = deferred();
		apiFetch.mockImplementationOnce( () => put.promise );
		await act( async () => {
			fireEvent.click( saveButton() );
		} );
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			method: 'PUT',
			url: 'settings',
			data: {
				values: {
					category_panel: false,
					rows_per_page: 100,
					history_days: 0,
					remove_data_on_delete: true,
				},
			},
		} );
		expect( saveButton().textContent ).toBe( 'Saving…' );
		expect( saveButton().getAttribute( 'aria-disabled' ) ).toBe( 'true' );

		const saved = {
			...VALUES,
			category_panel: false,
			rows_per_page: 100,
			history_days: 0,
			remove_data_on_delete: true,
		};
		await act( async () => {
			put.resolve( { ...FIXTURE, values: saved } );
		} );
		expect( noticeText() ).toContain( 'Settings saved.' );
		expect( saveButton().textContent ).toBe( 'Save settings' );
		expect( saveButton().getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( getSettings() ).toMatchObject( {
			rows_per_page: 100,
			category_panel: false,
		} );

		fireEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Record unchanged rows in the audit log',
			} )
		);
		expect( noticeText() ).toBe( '' );
		expect( saveButton().getAttribute( 'aria-disabled' ) ).toBeNull();
	} );

	it( 'shows each refusal against its setting and saves nothing', async () => {
		await renderTab();
		const input = screen.getByLabelText( 'Products per background batch' );
		fireEvent.change( input, {
			target: { value: '101' },
		} );
		const message =
			'Products per background batch was not saved: enter a whole number from 10 to 100.';
		apiFetch.mockImplementationOnce( () =>
			Promise.reject( {
				code: 'bulkcat_invalid_setting',
				message,
				data: { status: 400, errors: { batch_size: message } },
			} )
		);
		await act( async () => {
			fireEvent.click( saveButton() );
		} );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data ).toEqual( {
			values: { batch_size: 101 },
		} );
		const row = document.querySelector( '[data-setting="batch_size"]' );
		expect( row.textContent ).toContain( message );
		expect( row.className ).toContain( 'bulkcat-settings__row--error' );
		expect( noticeText() ).toContain(
			'Nothing was saved. Correct the settings marked below and save again.'
		);
		expect( document.body.textContent ).not.toContain( 'bulkcat_' );
		expect( saveButton().getAttribute( 'aria-disabled' ) ).toBeNull();

		fireEvent.change(
			screen.getByLabelText( 'Products per background batch' ),
			{
				target: { value: '100' },
			}
		);
		expect( row.textContent ).not.toContain( message );
	} );

	it( 'shows the server sentence when a save fails without a named setting', async () => {
		await renderTab();
		fireEvent.change( screen.getByLabelText( 'CSV separator' ), {
			target: { value: 'semicolon' },
		} );
		apiFetch.mockImplementationOnce( () =>
			Promise.reject( {
				code: 'bulkcat_forbidden',
				message:
					'Only an administrator can change the Bulk Catalogue Manager settings. Ask an administrator to change them.',
				data: { status: 403 },
			} )
		);
		await act( async () => {
			fireEvent.click( saveButton() );
		} );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data ).toEqual( {
			values: { csv_separator: 'semicolon' },
		} );
		expect(
			document.querySelector( '.bulkcat-settings .components-notice' )
				.textContent
		).toContain( 'Only an administrator can change' );
	} );

	it.each( [
		[ 'a server’s HTML error page', html500 ],
		[ 'a proxy’s HTML page', htmlPage ],
		[ 'a JSON error that is not the plugin’s own refusal', coreJson ],
	] )(
		'says %s in plain words, never its text (ticket 11)',
		async ( label, failure ) => {
			await renderTab();
			fireEvent.change( screen.getByLabelText( 'CSV separator' ), {
				target: { value: 'semicolon' },
			} );
			apiFetch.mockImplementationOnce( () =>
				Promise.reject( failure() )
			);
			await act( async () => {
				fireEvent.click( saveButton() );
			} );
			expect( apiFetch.mock.calls[ 1 ][ 0 ].data ).toEqual( {
				values: { csv_separator: 'semicolon' },
			} );
			expectPlain(
				document.querySelector(
					'.bulkcat-settings .components-notice'
				),
				'The settings could not be saved. Try again in a moment.'
			);
			expectNoRawText();
		}
	);

	it( 'renders read-only with an explanatory line for someone without manage_options', async () => {
		await renderTab( { ...FIXTURE, canEdit: false } );
		expect(
			screen.getByText(
				'Only an administrator can change these settings. You can see how they are set here.'
			)
		).toBeTruthy();
		expect( screen.queryByRole( 'button', { name: /Sav/ } ) ).toBeNull();
		const controls = document.querySelectorAll(
			'.bulkcat-settings__form input, .bulkcat-settings__form select'
		);
		expect( controls.length ).toBeGreaterThan( 13 );
		controls.forEach( ( control ) =>
			expect( control.disabled ).toBe( true )
		);
	} );

	it( 'offers a retry when the settings cannot be loaded', async () => {
		apiFetch.mockImplementationOnce( () => Promise.reject( {} ) );
		await act( async () => {
			render( <Settings /> );
		} );
		expect(
			document.querySelector( '.bulkcat-settings' ).textContent
		).toContain( 'The settings could not be loaded.' );
		apiFetch.mockImplementationOnce( () => Promise.resolve( FIXTURE ) );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Try again' } )
			);
		} );
		expect( screen.getByText( 'Rows per page' ) ).toBeTruthy();
	} );

	it( 'compares values strictly when working out what changed', () => {
		expect(
			changedValues(
				{ rows_per_page: 50, batch_size: 50, history_days: 0 },
				{ rows_per_page: 25, batch_size: 50, history_days: 90 }
			)
		).toEqual( { rows_per_page: 50, history_days: 0 } );
	} );
} );
