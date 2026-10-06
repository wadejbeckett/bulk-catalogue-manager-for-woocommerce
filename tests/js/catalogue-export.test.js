/**
 * T53: the export arguments are the grid's filters, search, sort and mode and
 * the visible columns in the owner's order; the wp_rest nonce goes as
 * `_wpnonce` in a posted body, never an address (SECURITY-1); and how a
 * refusal is read back from the hidden frame. The Export control itself
 * opens the export dialog since T103: catalogue-export-dialog.test.js.
 */
import * as views from '../../assets/src/panels/catalogue/views';
import {
	currentNonce,
	refusalIn,
} from '../../assets/src/panels/catalogue/views/export';
import * as exporter from '../../assets/src/panels/catalogue/views/export';
import {
	historyExportArgs,
	historyFormFields,
} from '../../assets/src/panels/history/export';

const { exportArgs, exportColumns } = views;
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

	it( 'never put the nonce in an address: History posts it in the body (SECURITY-1)', () => {
		expect( views.exportUrl ).toBeUndefined();
		expect( exporter.exportUrl ).toBeUndefined();
		expect( exporter.download ).toBeUndefined();
		const fields = historyFormFields(
			historyExportArgs( 'audit', { user: 2, page: 3 } ),
			'abc123def0'
		);
		expect( fields[ 0 ] ).toEqual( [ '_wpnonce', 'abc123def0' ] );
		const posted = new URLSearchParams( fields );
		expect( posted.get( 'source' ) ).toBe( 'audit' );
		expect( posted.get( 'user' ) ).toBe( '2' );
		expect( posted.get( 'page' ) ).toBeNull();
		expect( posted.get( 'columns' ) ).toBe(
			'applied_gmt,batch_id,user_name,name,object_id,object_type,field_label,old_value,new_value,result,message'
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
	/**
	 * A hidden frame in the page, as postDownload() adds one.
	 *
	 * @return {HTMLIFrameElement} Frame.
	 */
	function frame() {
		const element = document.createElement( 'iframe' );
		document.body.appendChild( element );
		return element;
	}

	afterEach( () => {
		document
			.querySelectorAll( 'iframe' )
			.forEach( ( element ) => element.remove() );
	} );

	it( 'reads a JSON refusal from the frame', () => {
		const shown = frame();
		shown.contentDocument.body.textContent = JSON.stringify( {
			code: 'bulkcat_forbidden',
			message: 'Your account does not have permission.',
		} );
		const refusal = refusalIn( shown );
		expect( refusal.code ).toBe( 'bulkcat_forbidden' );
		expect( refusal.message ).toBe(
			'Your account does not have permission.'
		);
	} );

	it( 'reads a server’s HTML error page in the frame as a failure, never as done (ticket 11)', () => {
		const shown = frame();
		shown.contentDocument.body.innerHTML =
			'<div class="wp-die-message"><p>There has been a critical error on this website.</p></div>';
		const refusal = refusalIn( shown );
		expect( refusal.code ).not.toMatch( /^bulkcat_/ );
		expect( refusal.message ).toBe( '' );
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
