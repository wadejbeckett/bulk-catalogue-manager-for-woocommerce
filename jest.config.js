/**
 * Jest for `npm test` (wp-scripts test-unit-js): the wp-scripts unit
 * configuration, plus longer waits. Every suite runs in parallel and the
 * catalogue suites mount the whole screen, so under a loaded machine a
 * screen can take longer than Jest's 5 seconds per test or Testing
 * Library's 1 second per findBy*; the limits here are for a slow run, not a
 * slower test.
 */
const base = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...base,
	testTimeout: 30000,
	setupFilesAfterEnv: [ '<rootDir>/tests/js/setup-timeouts.js' ],
};
