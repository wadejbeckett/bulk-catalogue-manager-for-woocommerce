/**
 * Each setting's client-side effect (T73): the localised settings reach the
 * grid's starting page size, the confirm policy, the category panel's
 * starting state and the apply-to-variations switches through useSettings(),
 * and a save from the Settings tab reaches every reader without a reload.
 */

/**
 * React's own act from the module registry the modules are loaded into:
 * each test resets modules, so act must come from the same fresh React.
 *
 * @param {Function} callback Work that renders.
 * @return {Promise<void>} Settled when React has flushed.
 */
const act = ( callback ) => require( 'react' ).act( callback );

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn( () => new Promise( () => {} ) );
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

/**
 * Load the settings module afresh over these localised values.
 *
 * @param {Object|undefined} values window.bcmSettings.settings.
 * @return {Object} The settings panel module.
 */
function load( values ) {
	window.bcmSettings = { root: '', nonce: '', settings: values };
	return require( '../../assets/src/panels/settings' );
}

describe( 'settings effects', () => {
	let errors;

	beforeEach( () => {
		jest.resetModules();
		global.IS_REACT_ACT_ENVIRONMENT = true;
		document.body.innerHTML = '';
		errors = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		errors.mockRestore();
		delete window.bcmSettings;
	} );

	it( 'rows per page sets the grid’s starting page size', async () => {
		const { getSettings, initialPerPage } = load( { rows_per_page: 50 } );
		expect( initialPerPage( getSettings() ) ).toBe( 50 );
		expect( initialPerPage( { rows_per_page: 30 } ) ).toBe( 25 );
		expect( initialPerPage( {} ) ).toBe( 25 );

		const apiFetch = require( '@wordpress/api-fetch' ).default;
		apiFetch.mockImplementation( ( { url } ) =>
			Promise.resolve(
				url.startsWith( 'fields' )
					? { fields: [] }
					: { items: [], pagination: { total: 0, totalPages: 0 } }
			)
		);
		const { readSettings } = require( '../../assets/src/settings' );
		const Catalogue =
			require( '../../assets/src/panels/catalogue' ).default;
		const { createRoot } = require( '@wordpress/element' );
		const element = document.createElement( 'div' );
		document.body.appendChild( element );
		await act( async () => {
			createRoot( element ).render(
				<Catalogue settings={ readSettings() } />
			);
		} );
		const products = apiFetch.mock.calls
			.map( ( [ options ] ) => options.url )
			.filter( ( url ) => url.startsWith( 'products' ) );
		expect( products.length ).toBeGreaterThan( 0 );
		expect( products[ 0 ] ).toContain( 'per_page=50' );
	} );

	it( 'confirm above this many rows sets the second-click threshold', async () => {
		const { useSettings, needsSecondClick, setSettings } = load( {
			confirm_threshold: 10,
		} );
		expect( needsSecondClick( 10, { confirm_threshold: 10 } ) ).toBe(
			false
		);
		expect( needsSecondClick( 11, { confirm_threshold: 10 } ) ).toBe(
			true
		);
		expect( needsSecondClick( 200, {} ) ).toBe( false );
		expect( needsSecondClick( 201, {} ) ).toBe( true );
		expect( needsSecondClick( 1, {}, true ) ).toBe( true );
		expect( needsSecondClick( 1, { confirm_threshold: 0 } ) ).toBe( true );

		function Policy() {
			return needsSecondClick( 50, useSettings() ) ? 'ask' : 'apply';
		}
		const { createRoot } = require( '@wordpress/element' );
		const element = document.createElement( 'div' );
		document.body.appendChild( element );
		await act( async () => {
			createRoot( element ).render( <Policy /> );
		} );
		expect( element.textContent ).toBe( 'ask' );
		await act( async () => {
			setSettings( { confirm_threshold: 500 } );
		} );
		expect( element.textContent ).toBe( 'apply' );
	} );

	it( 'the category panel default sets its starting state', () => {
		const closed = load( { category_panel: false } );
		expect( closed.categoryPanelOpen( closed.getSettings() ) ).toBe(
			false
		);

		jest.resetModules();
		const open = load( undefined );
		expect( open.getSettings() ).toEqual( {} );
		expect( open.categoryPanelOpen( open.getSettings() ) ).toBe( true );
	} );

	it( 'the apply-to-variations defaults set each switch’s starting position', () => {
		const { applyToVariationsDefault, getSettings } = load( {
			variations_status: false,
			variations_price: true,
		} );
		const values = getSettings();
		expect( applyToVariationsDefault( 'status', values ) ).toBe( false );
		expect( applyToVariationsDefault( 'visibility', values ) ).toBe( true );
		expect( applyToVariationsDefault( 'price', values ) ).toBe( true );
		expect( applyToVariationsDefault( 'stock', values ) ).toBe( false );
		expect( applyToVariationsDefault( 'name', values ) ).toBe( false );
		expect( applyToVariationsDefault( 'status', {} ) ).toBe( true );
	} );
} );
