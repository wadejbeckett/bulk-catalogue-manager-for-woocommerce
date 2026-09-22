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
define( 'BCM_TEST_NO_ACTION_SCHEDULER', true );
define( 'WP_UNINSTALL_PLUGIN', 'bulk-catalogue-manager-for-woocommerce/bulk-catalogue-manager-for-woocommerce.php' );

require __DIR__ . '/wpdb-stub.php';
require __DIR__ . '/wp-stubs.php';
bcm_test_reset();

$GLOBALS['bcm_test']['options'] = [
	'bcm_settings'   => [ 'remove_data_on_delete' => true ],
	'bcm_db_version' => '1',
];

require dirname( __DIR__, 2 ) . '/uninstall.php';
\BulkCatalogueManager\Data\Cleanup::deactivate();

echo json_encode(
	[
		'scheduler'   => function_exists( 'as_unschedule_all_actions' ),
		'unscheduled' => $GLOBALS['bcm_test']['unscheduled'],
		'deleted'     => $GLOBALS['bcm_test']['deleted'],
	]
);
