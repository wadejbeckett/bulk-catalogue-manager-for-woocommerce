/**
 * T53: the Export control downloads the current view through GET /export
 * with the grid's filters, search, sort and mode and the visible columns in
 * the owner's order, the wp_rest nonce as `_wpnonce` (core's cookie check
 * reads it before the header); it is busy while the file is produced, the
 * rest of the screen untouched, and a refusal shows a §4.8 notice.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import { getQueryArgs } from '@wordpress/url';

import {
	ExportControl,
	exportArgs,
	exportColumns,
	exportUrl,
} from '../../assets/src/panels/catalogue/views';
import {
	currentNonce,
	download,
	refusalIn,
} from '../../assets/src/panels/catalogue/views/export';
import {
	defaultView,
	descriptorsOf,
} from '../../assets/src/panels/catalogue/fields';
import { toQuery } from '../../assets/src/panels/catalogue/query';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { REGISTRY } = require( './catalogue-fixture' );

const BASE = defaultView( REGISTRY, { settings: { rows_per_page: 25 } } );
const VIEW = {
	...BASE,
	page: 3,
	search: 'golf',
	sort: { field: 'regular_price', direction: 'desc' },
	fields: [ 'stock_status', 'sku', 'regular_price' ],
	mode: 'variations',
};
const CATEGORY = { category_ids: { operator: 'isAny', value: [ 3, 4 ] } };
const QUERY = toQuery( VIEW, descriptorsOf( REGISTRY ), CATEGORY );

afterEach( () => {
	delete apiFetch.nonceMiddleware;
	delete window.bulkcatSettings;
	jest.useRealTimers();
} );

describe( 'export arguments', () => {
	it( 'are the grid query without paging, with the columns in the owner order', () => {
		const args = exportArgs( QUERY, VIEW, REGISTRY );
		expect( args ).toEqual( {
			search: 'golf',
			orderby: [ 'regular_price' ],
			order: [ 'desc' ],
			mode: 'variations',
			category_ids: { operator: 'isAny', value: [ 3, 4 ] },
			columns: [ 'name', 'stock_status', 'sku', 'regular_price' ],
		} );
		expect( args.page ).toBeUndefined();
		expect( args.per_page ).toBeUndefined();
		expect( args.fields ).toBeUndefined();
	} );

	it( 'keep a mode the query already carries', () => {
		expect(
			exportArgs( { ...QUERY, mode: 'products' }, VIEW, REGISTRY ).mode
		).toBe( 'products' );
		// toQuery() carries the view's mode since T67; a query without one
		// and a view without one export without one.
		const { mode, ...bare } = QUERY;
		expect( mode ).toBe( 'variations' );
		expect(
			exportArgs( bare, { ...VIEW, mode: undefined }, REGISTRY ).mode
		).toBeUndefined();
	} );

	it( 'name each registry column once and never the picture or an unknown key', () => {
		const descriptors = descriptorsOf( REGISTRY );
		expect(
			exportColumns(
				{
					...VIEW,
					fields: [ 'thumbnail', 'sku', 'name', 'nope', 'sku' ],
				},
				descriptors
			)
		).toEqual( [ 'name', 'sku' ] );
	} );

	it( 'compose a URL whose query PHP reads back as the arguments, nonce included', () => {
		const url = exportUrl(
			exportArgs( QUERY, VIEW, REGISTRY ),
			'abc123def0'
		);
		expect( url.split( '?' )[ 0 ] ).toBe( 'export' );
		const query = getQueryArgs( url );
		expect( query._wpnonce ).toBe( 'abc123def0' );
		expect( query.columns ).toEqual( [
			'name',
			'stock_status',
			'sku',
			'regular_price',
		] );
		expect( query.category_ids ).toEqual( {
			operator: 'isAny',
			value: [ '3', '4' ],
		} );
		expect( query.orderby ).toEqual( [ 'regular_price' ] );
		expect( query.search ).toBe( 'golf' );
		expect( url ).toContain(
			'columns%5B0%5D=name&columns%5B1%5D=stock_status'
		);
	} );

	it( 'take the nonce apiFetch holds, else the page one', () => {
		window.bulkcatSettings = { nonce: 'pagenonce1' };
		expect( currentNonce() ).toBe( 'pagenonce1' );
		apiFetch.nonceMiddleware = { nonce: 'freshnonce' };
		expect( currentNonce() ).toBe( 'freshnonce' );
	} );
} );

describe( 'the Export control', () => {
	function setup() {
		const started = [];
		const start = jest.fn( ( url, handlers ) => {
			started.push( { url, ...handlers } );
			return jest.fn();
		} );
		render(
			<>
				<ExportControl
					query={ QUERY }
					view={ VIEW }
					registry={ REGISTRY }
					getNonce={ () => 'nonce00001' }
					start={ start }
				/>
				<button type="button">Elsewhere</button>
			</>
		);
		return { start, started };
	}

	it( 'downloads the current view and is busy until the file is on its way', () => {
		const { start, started } = setup();
		fireEvent.click( screen.getByRole( 'button', { name: 'Export CSV' } ) );
		expect( start ).toHaveBeenCalledTimes( 1 );
		expect( started[ 0 ].url ).toBe(
			exportUrl( exportArgs( QUERY, VIEW, REGISTRY ), 'nonce00001' )
		);
		const busy = screen.getByRole( 'button', { name: 'Preparing CSV…' } );
		expect( busy.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		// The rest of the screen stays usable.
		expect(
			screen.getByRole( 'button', { name: 'Elsewhere' } ).disabled
		).toBe( false );
		act( () => started[ 0 ].onDone() );
		expect(
			screen.getByRole( 'button', { name: 'Export CSV' } )
		).toBeTruthy();
		expect( screen.queryByText( /could not/ ) ).toBeNull();
	} );

	it( 'shows a refusal in plain words with its details, and tries again', () => {
		const { start, started } = setup();
		fireEvent.click( screen.getByRole( 'button', { name: 'Export CSV' } ) );
		act( () =>
			started[ 0 ].onError(
				Object.assign(
					new Error(
						'These columns could not be exported. Choose the columns again and try again.'
					),
					{ code: 'bulkcat_invalid_value' }
				)
			)
		);
		expect(
			screen.getByText(
				'These columns could not be exported. Choose the columns again and try again.'
			)
		).toBeTruthy();
		expect( screen.getByText( 'Show details' ) ).toBeTruthy();
		expect( screen.getByText( /^bulkcat_invalid_value:/ ) ).toBeTruthy();
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect( start ).toHaveBeenCalledTimes( 2 );
		expect( document.querySelector( '.bulkcat-error' ) ).toBeNull();
	} );

	it( 'words a refusal from core in its own sentence', () => {
		const { started } = setup();
		fireEvent.click( screen.getByRole( 'button', { name: 'Export CSV' } ) );
		act( () =>
			started[ 0 ].onError(
				Object.assign( new Error( 'Cookie check failed' ), {
					code: 'rest_cookie_invalid_nonce',
				} )
			)
		);
		expect(
			screen.getByText(
				'The CSV file could not be made. Reload the page and try again.'
			)
		).toBeTruthy();
	} );
} );

describe( 'the hidden frame', () => {
	it( 'loads the URL in a hidden frame and settles when the browser reports nothing', () => {
		jest.useFakeTimers();
		const onDone = jest.fn();
		const onError = jest.fn();
		const stop = download( 'about:blank#export', {
			onDone,
			onError,
			settle: 1000,
		} );
		const frame = document.querySelector( 'iframe.bulkcat-export__frame' );
		expect( frame.hidden ).toBe( true );
		expect( frame.getAttribute( 'src' ) ).toBe( 'about:blank#export' );
		jest.advanceTimersByTime( 1000 );
		expect( onDone ).toHaveBeenCalledTimes( 1 );
		expect( onError ).not.toHaveBeenCalled();
		stop();
		expect(
			document.querySelector( 'iframe.bulkcat-export__frame' )
		).toBeNull();
	} );

	it( 'reads a JSON refusal from the frame', () => {
		const onDone = jest.fn();
		const onError = jest.fn();
		const stop = download( 'about:blank', { onDone, onError } );
		const frame = document.querySelector( 'iframe.bulkcat-export__frame' );
		frame.contentDocument.body.textContent = JSON.stringify( {
			code: 'bulkcat_forbidden',
			message: 'Your account does not have permission.',
		} );
		frame.dispatchEvent( new window.Event( 'load' ) );
		expect( onError ).toHaveBeenCalledTimes( 1 );
		expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe( 'bulkcat_forbidden' );
		expect( onDone ).not.toHaveBeenCalled();
		stop();
	} );

	it( 'treats a page that is not a refusal as done', () => {
		expect(
			refusalIn( { contentDocument: { body: { textContent: 'a,b' } } } )
		).toBeNull();
		expect(
			refusalIn( {
				get contentDocument() {
					throw new Error( 'cross-origin' );
				},
			} )
		).toBeNull();
	} );
} );
