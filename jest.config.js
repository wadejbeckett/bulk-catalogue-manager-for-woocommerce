/**
 * Jest for `npm test` (wp-scripts test-unit-js): the wp-scripts unit
 * configuration, plus longer waits. Every suite runs in parallel and the
 * catalogue suites mount the whole screen, so under a loaded machine a
 * screen can take longer than Jest's 5 seconds per test or Testing
 * Library's 1 second per findBy*; the limits here are for a slow run, not a
 * slower test.
 *
 * Workers are capped: Jest's default is one per core less one (11 here),
 * each loading jsdom at about 0.8-1 GB, which froze the workstation on
 * 2026-10-08. Four workers keep a run near 4 GB; a worker that grows past
 * 1 GB is replaced between suites.
 */
const base = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...base,
	testTimeout: 30000,
	maxWorkers: 4,
	workerIdleMemoryLimit: '1GB',
	setupFilesAfterEnv: [
		'<rootDir>/tests/js/setup-timeouts.js',
		'<rootDir>/tests/js/setup-selection.js',
	],
};
