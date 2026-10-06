/**
 * Which site the lifecycle and scale specs may run WP-CLI against (T85).
 * They run PHP and SQL through WP_CMD and install a throwaway must-use
 * plugin, so they refuse anything but a local test site explicitly opted in
 * with BULKCAT_E2E_FIXTURE=1, whose own site URL, read through WP_CMD, is the
 * address the browser uses.
 */

/** Host names of a site on this machine. */
const LOCAL_HOSTS = [ 'localhost', '127.0.0.1', '[::1]' ];

/**
 * Whether a host name is this machine's.
 *
 * @param {string} host URL host name.
 * @return {boolean} Local.
 */
function isLocal( host ) {
	return (
		LOCAL_HOSTS.includes( host ) ||
		host.endsWith( '.localhost' ) ||
		host.endsWith( '.test' )
	);
}

/**
 * Why the specs may not touch the site, or '' when they may.
 *
 * @param {Object}   options         Options.
 * @param {string}   options.optIn   BULKCAT_E2E_FIXTURE.
 * @param {string}   options.wpCmd   WP_CMD.
 * @param {string}   options.baseUrl BULKCAT_E2E_BASE_URL.
 * @param {Function} options.siteUrl () => the site URL WP_CMD reports; asked last.
 * @return {string} The refusal, or ''.
 */
function fixtureRefusal( { optIn, wpCmd, baseUrl, siteUrl } ) {
	if ( optIn !== '1' ) {
		return 'These specs run WP-CLI against the test site; set BULKCAT_E2E_FIXTURE=1 to confirm it is a throwaway fixture.';
	}
	if ( ! wpCmd ) {
		return 'These specs need WP_CMD to reach the test site.';
	}
	let base;
	try {
		base = new URL( baseUrl );
	} catch {
		return `BULKCAT_E2E_BASE_URL (${ baseUrl }) is not an address.`;
	}
	if ( ! isLocal( base.hostname ) ) {
		return `${ base.origin } is not a local test site.`;
	}
	let site;
	try {
		site = new URL( String( siteUrl() ).trim() );
	} catch {
		return 'WP_CMD could not read the site URL.';
	}
	if ( site.origin !== base.origin ) {
		return `WP_CMD reaches ${ site.origin }, not ${ base.origin }.`;
	}
	return '';
}

module.exports = { fixtureRefusal };
