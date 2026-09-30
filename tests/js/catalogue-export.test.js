/**
 * T53: the export arguments are the grid's filters, search, sort and mode and
 * the visible columns in the owner's order; the wp_rest nonce goes as
 * `_wpnonce` (core's cookie check reads it before the header); and the
 * hidden frame History's GET exports load. The Export control itself opens
 * the export dialog since T103: catalogue-export-dialog.test.js.
 */
import { getQueryArgs } from '@wordpress/url';

import {
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

	it( 'reads a server’s HTML error page in the frame as a failure, never as done (ticket 11)', () => {
		const onDone = jest.fn();
		const onError = jest.fn();
		const stop = download( 'about:blank', { onDone, onError } );
		const frame = document.querySelector( 'iframe.bulkcat-export__frame' );
		frame.contentDocument.body.innerHTML =
			'<div class="wp-die-message"><p>There has been a critical error on this website.</p></div>';
		frame.dispatchEvent( new window.Event( 'load' ) );
		expect( onDone ).not.toHaveBeenCalled();
		expect( onError ).toHaveBeenCalledTimes( 1 );
		expect( onError.mock.calls[ 0 ][ 0 ].code ).not.toMatch( /^bulkcat_/ );
		expect( onError.mock.calls[ 0 ][ 0 ].message ).toBe( '' );
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
