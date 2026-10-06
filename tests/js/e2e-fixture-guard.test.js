/**
 * T85: the lifecycle and scale browser specs run PHP and SQL on the test
 * site through WP_CMD and install a must-use plugin there, so they refuse
 * any site that is not a local fixture explicitly opted in
 * (BULKCAT_E2E_FIXTURE=1) and whose own site URL, read through WP_CMD, is
 * the address the browser uses.
 */
const { fixtureRefusal } = require( '../e2e/lifecycle/guard' );

const LOCAL = {
	optIn: '1',
	wpCmd: 'wp-local.sh',
	baseUrl: 'http://localhost:18925',
	siteUrl: () => 'http://localhost:18925',
};

describe( 'the e2e fixture guard', () => {
	it( 'lets a local fixture that opted in run', () => {
		expect( fixtureRefusal( LOCAL ) ).toBe( '' );
		expect(
			fixtureRefusal( {
				...LOCAL,
				baseUrl: 'http://127.0.0.1:8888',
				siteUrl: () => 'http://127.0.0.1:8888/',
			} )
		).toBe( '' );
	} );

	it( 'refuses without the opt-in, before asking WP_CMD anything', () => {
		const siteUrl = jest.fn();
		expect( fixtureRefusal( { ...LOCAL, optIn: '', siteUrl } ) ).toMatch(
			/BULKCAT_E2E_FIXTURE=1/
		);
		expect( siteUrl ).not.toHaveBeenCalled();
	} );

	it( 'refuses without WP_CMD', () => {
		expect( fixtureRefusal( { ...LOCAL, wpCmd: '' } ) ).toMatch( /WP_CMD/ );
	} );

	it( 'refuses a browser address that is not a local host', () => {
		const siteUrl = jest.fn();
		expect(
			fixtureRefusal( {
				...LOCAL,
				baseUrl: 'https://shop.example.com',
				siteUrl,
			} )
		).toMatch( /not a local test site/ );
		expect( siteUrl ).not.toHaveBeenCalled();
	} );

	it( 'refuses when WP_CMD reaches a different site than the browser', () => {
		expect(
			fixtureRefusal( {
				...LOCAL,
				siteUrl: () => 'https://www.example.com',
			} )
		).toMatch( /WP_CMD reaches https:\/\/www\.example\.com/ );
		expect(
			fixtureRefusal( {
				...LOCAL,
				siteUrl: () => 'http://localhost:8888',
			} )
		).toMatch( /not http:\/\/localhost:18925/ );
		expect(
			fixtureRefusal( {
				...LOCAL,
				siteUrl: () => {
					throw new Error( 'no site' );
				},
			} )
		).toMatch( /could not read the site URL/ );
	} );
} );
