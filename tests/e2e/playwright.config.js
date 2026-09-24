/**
 * Playwright for the browser checks of SPEC §16.4 and T75: keyboard-only
 * journeys and axe-core on every screen, Chromium only, one worker (the specs
 * write to one shared site and put back what they change).
 *
 * The site is any WordPress + WooCommerce install with this plugin active and
 * tests/probe/seed.sh run against it: wp-env in CI, or the throwaway docker
 * stack of tests/probe/README.md locally.
 *
 * Environment:
 *   BCM_E2E_BASE_URL  the site. Default http://localhost:8888 (wp-env).
 *   BCM_E2E_USER      an administrator's login. Default admin.
 *   BCM_E2E_PASSWORD  its password. Default password.
 *   BCM_E2E_CHROMIUM  a Chromium binary to use instead of Playwright's own.
 */
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

const executablePath = process.env.BCM_E2E_CHROMIUM || undefined;

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: '*.spec.js',
	outputDir: path.join( __dirname, '.results' ),
	globalSetup: require.resolve( './global-setup.js' ),
	fullyParallel: false,
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: 0,
	timeout: 90_000,
	expect: { timeout: 15_000 },
	reporter: process.env.CI ? [ [ 'list' ], [ 'github' ] ] : 'list',
	use: {
		baseURL: process.env.BCM_E2E_BASE_URL || 'http://localhost:8888',
		storageState: path.join( __dirname, '.results', 'admin.json' ),
		viewport: { width: 1440, height: 900 },
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 1440, height: 900 },
				launchOptions: executablePath ? { executablePath } : {},
			},
		},
	],
} );
