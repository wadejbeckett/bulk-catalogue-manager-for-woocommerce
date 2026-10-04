<?php
/**
 * Load neutral typed fixture classes; never shipped.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
require_once dirname( __DIR__ ) . '/load.php';
foreach ( [ 'MemoryStore', 'SampleLegacyProvider', 'CompositeField', 'ChoiceSource', 'AssignAction', 'SampleTypedProvider', 'ProcessInterrupted', 'Scenario' ] as $bulkcat_typed_fixture ) {
	require_once __DIR__ . '/' . $bulkcat_typed_fixture . '.php';
}
unset( $bulkcat_typed_fixture );
