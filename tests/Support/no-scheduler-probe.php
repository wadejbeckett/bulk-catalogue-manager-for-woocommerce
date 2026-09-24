<?php
/**
 * Runs deactivation and uninstall, with the remove-all-data setting on, in a
 * fresh PHP process where Action Scheduler's functions do not exist, and
 * prints what was deleted and unscheduled as JSON. Used by UninstallTest.
 *
 *     php tests/Support/no-scheduler-probe.php
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

define( 'ABSPATH', '/' );
define( 'BULKCAT_TEST_NO_ACTION_SCHEDULER', true );
define( 'WP_UNINSTALL_PLUGIN', 'bulk-catalogue-manager-for-woocommerce/bulk-catalogue-manager-for-woocommerce.php' );

require __DIR__ . '/wpdb-stub.php';
require __DIR__ . '/wp-stubs.php';
bulkcat_test_reset();

$GLOBALS['bulkcat_test']['options'] = [
	'bulkcat_settings'   => [ 'remove_data_on_delete' => true ],
	'bulkcat_db_version' => '1',
];

require dirname( __DIR__, 2 ) . '/uninstall.php';
\BulkCatalogueManager\Data\Cleanup::deactivate();

echo json_encode(
	[
		'scheduler'   => function_exists( 'as_unschedule_all_actions' ),
		'unscheduled' => $GLOBALS['bulkcat_test']['unscheduled'],
		'deleted'     => $GLOBALS['bulkcat_test']['deleted'],
	]
);
