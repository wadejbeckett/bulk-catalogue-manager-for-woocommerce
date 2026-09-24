/**
 * Logs in once as the administrator through wp-login.php, the way the probe
 * does, and keeps the cookies for every spec.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { request } = require( '@playwright/test' );

module.exports = async ( config ) => {
	const { baseURL, storageState } = config.projects[ 0 ].use;
	fs.mkdirSync( path.dirname( storageState ), { recursive: true } );
	const context = await request.newContext( { baseURL } );
	// WordPress refuses a login without its test cookie.
	await context.get( '/wp-login.php' );
	const response = await context.post( '/wp-login.php', {
		form: {
			log: process.env.BULKCAT_E2E_USER || 'admin',
			pwd: process.env.BULKCAT_E2E_PASSWORD || 'password',
			'wp-submit': 'Log In',
			testcookie: '1',
			redirect_to: `${ baseURL }/wp-admin/`,
		},
		headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' },
	} );
	const cookies = ( await context.storageState() ).cookies;
	if (
		! response.ok() ||
		! cookies.some( ( cookie ) =>
			cookie.name.startsWith( 'wordpress_logged_in_' )
		)
	) {
		throw new Error(
			`Could not log in to ${ baseURL } (HTTP ${ response.status() }). Check BULKCAT_E2E_USER and BULKCAT_E2E_PASSWORD.`
		);
	}
	await context.storageState( { path: storageState } );
	await context.dispose();
};
