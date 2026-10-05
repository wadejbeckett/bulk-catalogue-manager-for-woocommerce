<?php
/**
 * Load the sample provider's classes (unit tests, the native probe and the
 * test must-use plugin): the core's Extension interfaces must already be
 * loadable. Test code only; never shipped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

foreach ( [ 'SampleStore', 'MemoryStore', 'MetaStore', 'SampleFaults', 'LabelsField', 'NoteField', 'SampleStoreField', 'LabelsAction', 'NoteAction', 'SampleProvider', 'TermMetaStore', 'PicksFaults', 'PicksDirectory', 'PicksField', 'PicksAction', 'PicksProvider' ] as $bulkcat_sample_class ) {
	require_once __DIR__ . '/' . $bulkcat_sample_class . '.php';
}
unset( $bulkcat_sample_class );
