/**
 * The entry mounts the screen into #bcm-app and is a silent no-op on a page
 * without it (T8, T9).
 */
import { screen } from '@testing-library/react';

/**
 * React's own act from the module registry the entry is loaded into: each
 * test resets modules, so the entry gets a fresh React and act must match it.
 *
 * @param {Function} callback Work that renders.
 * @return {Promise<void>} Settled when React has flushed.
 */
const act = ( callback ) => require( 'react' ).act( callback );

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn( () => new Promise( () => {} ) );
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn( ( nonce ) => {
		const middleware = ( options, next ) => next( options );
		middleware.nonce = nonce;
		return middleware;
	} );
	return { __esModule: true, default: apiFetch };
} );

const SETTINGS = {
	root: 'https://example.test/wp-json/bcm/v1/',
	nonce: 'abc123def4',
	settings: { rows_per_page: 25 },
	fieldsEndpoint: 'https://example.test/wp-json/bcm/v1/fields',
	user: { id: 1, name: 'Admin' },
	wpMajor: 7,
};

describe( 'entry', () => {
	let errors;

	beforeEach( () => {
		jest.resetModules();
		global.IS_REACT_ACT_ENVIRONMENT = true;
		document.body.innerHTML = '';
		window.bcmSettings = SETTINGS;
		errors = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		errors.mockRestore();
		delete window.bcmSettings;
	} );

	it( 'does nothing on a page without the mount element', () => {
		let entry;
		expect( () => {
			entry = require( '../../assets/src/index.js' );
		} ).not.toThrow();
		expect( entry.mount() ).toBeNull();
		// Only the live regions @wordpress/a11y adds to every admin page.
		expect( document.querySelector( '.bcm-app' ) ).toBeNull();
		expect( document.body.textContent ).toBe( 'Notifications' );
		expect(
			require( '@wordpress/api-fetch' ).default
		).not.toHaveBeenCalled();
		// jsdom cannot parse some of the bundled components' CSS; nothing else may complain.
		expect(
			errors.mock.calls.filter(
				( [ first ] ) =>
					! String( first ).includes(
						'Could not parse CSS stylesheet'
					)
			)
		).toEqual( [] );
	} );

	it( 'mounts the screen into #bcm-app with the nonce middleware wired', async () => {
		const element = document.createElement( 'div' );
		element.id = 'bcm-app';
		document.body.appendChild( element );

		await act( async () => {
			require( '../../assets/src/index.js' );
		} );

		const apiFetch = require( '@wordpress/api-fetch' ).default;
		expect( apiFetch.createNonceMiddleware ).toHaveBeenCalledWith(
			'abc123def4'
		);
		expect( apiFetch.use ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'navigation', {
				name: 'Bulk Catalogue Manager sections',
			} )
		).toBeTruthy();
		for ( const tab of [
			'Catalogue',
			'History',
			'Categories',
			'Settings',
		] ) {
			expect( screen.getByRole( 'link', { name: tab } ) ).toBeTruthy();
		}
		expect(
			screen
				.getByRole( 'link', { name: 'Catalogue' } )
				.getAttribute( 'aria-current' )
		).toBe( 'page' );
		expect( apiFetch ).toHaveBeenCalledWith( {
			url: 'https://example.test/wp-json/bcm/v1/fields',
		} );
	} );

	it( 'moves between panels without reloading', async () => {
		const element = document.createElement( 'div' );
		element.id = 'bcm-app';
		document.body.appendChild( element );
		await act( async () => {
			require( '../../assets/src/index.js' );
		} );

		await act( async () => {
			screen.getByRole( 'link', { name: 'History' } ).click();
		} );
		expect( window.location.search ).toContain( 'panel=history' );
		expect(
			screen.getByRole( 'heading', { name: 'History' } )
		).toBeTruthy();

		await act( async () => {
			screen.getByRole( 'link', { name: 'Catalogue' } ).click();
		} );
		expect( window.location.search ).not.toContain( 'panel=' );
	} );
} );
