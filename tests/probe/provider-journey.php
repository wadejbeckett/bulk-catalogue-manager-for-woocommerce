<?php
/**
 * External action contract (T109; docs/EXTENSIONS.md §15.2) native journey,
 * on a disposable stack only: the neutral test provider "Sample labels"
 * (tests/fixtures/mu-plugins/bulkcat-sample-provider.php, copied into
 * wp-content/mu-plugins) through the plugin's own services, REST routes,
 * Action Scheduler queue, journal and undo, and every provider-missing
 * case. It covers the integration halves of E5–E12 and E16:
 *
 * - E5: an exact selection built from two captured searches and an explicit
 *   identity, one member made unavailable, previewed without any write or
 *   flush; the exact available identities reach compute(); a selected
 *   variation meets the parent-only field and is skipped by the core; text
 *   rows, notes, aggregated warnings and the confirm flag;
 * - E6: the state token, version and format bound to Apply; scope drift;
 *   the capability lost between preview and Apply; the same grant replayed;
 *   changed arguments under one id; an accepted Apply resolving after the
 *   provider has gone;
 * - E7: intent, write and read-back settle; conflict, write error and
 *   exception failing one object each while the chunk carries on; a value
 *   that did not stick; one flush() per chunk; products never saved;
 * - E8: a chunk stopped between write and settle replays without applying
 *   `x_sample_add` twice;
 * - E9: History, batch changes, audit and CSV from the manifest and text;
 * - E10: undo leaving the provider's storage byte-identical, conflicts,
 *   Revert anyway, redo, the undo of a partial batch, an older format;
 * - E11: the provider missing, unavailable or incompatible at screen load,
 *   preview, Apply, mid-job, History and undo, and recovery when it returns;
 *   a provider registered but unavailable between Apply and the chunk
 *   (the coordinator's D-1) stops the job, leaves each item and refuses the
 *   undo with its own reason and `provider_unavailable`, apart from a
 *   missing one (`provider_missing`, "not active");
 * - a member binned between preview and Apply refuses Apply as stale
 *   (`scope_changed`), and one binned after Apply fails unwritten at the chunk;
 * - E12: anonymous and read-only users refused on every route, an object the
 *   user may not edit, authorise() narrowing, background work under the
 *   batch's recorded user, a right revoked before the job ran; a History
 *   reader without the action's capability gets no add-on argument values;
 * - E16: migration 7 → 8 idempotent with history untouched.
 *
 * Run from the WordPress root with WP-CLI (`eval` with `require`, so the
 * file's `strict_types` holds), the stack's URL named in the environment:
 *
 *     BULKCAT_PROBE_SITEURL=http://localhost:8904 wp eval '$phase = "journey"; require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/provider-journey.php";'
 *
 * Phases: `journey` (the default); `uninstall_before` and `uninstall_after`,
 * run around `wp plugin uninstall --skip-delete` with *Remove all data* on,
 * prove uninstall leaves the provider's own post meta alone (E16). It prints
 * its checks as JSON and exits 1 on the first failure; the journey removes
 * the products, users, role and options it made.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.DB -- Controlled native fixture checksums and readback.
// phpcs:disable WordPress.PHP.YodaConditions -- Native assertions compare observed values directly.
// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI diagnostics go to STDERR.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- A CLI probe's own variables.

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Extension\Registrar;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Tests\Support\Extension\SampleFaults;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use BulkCatalogueManager\Undo\Planner;

require_once __DIR__ . '/fixture.php';
if ( ! bulkcat_probe_fixture() ) {
	fwrite( STDERR, "Not a disposable fixture: set BULKCAT_PROBE_SITEURL to this site's local URL.\n" );
	exit( 1 );
}

global $wpdb;
$phase  ??= 'journey';
$checks   = [];
$check    = static function ( bool $ok, string $label ) use ( &$checks ): void {
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
	$checks[] = $label;
};
$accepted = static function ( mixed $value, string $label ) use ( $check ): array {
	if ( is_wp_error( $value ) ) {
		fwrite( STDERR, $label . ' refused: ' . $value->get_error_code() . ' ' . $value->get_error_message() . ' ' . wp_json_encode( $value->get_error_data() ) . "\n" );
	}
	$check( is_array( $value ), $label );
	return $value;
};
$refused  = static function ( mixed $value, string $code, string $label ) use ( $check ): WP_Error {
	if ( ! is_wp_error( $value ) || $code !== $value->get_error_code() ) {
		fwrite( STDERR, $label . ' answered: ' . wp_json_encode( is_wp_error( $value ) ? [ $value->get_error_code(), $value->get_error_data() ] : $value ) . "\n" );
	}
	$check( is_wp_error( $value ) && $code === $value->get_error_code(), $label );
	return $value;
};

// The provider's own values of some objects, as the database holds them.
$sample_meta = static function ( array $ids ) use ( $wpdb ): array {
	$in = implode( ',', array_map( 'intval', $ids ) );
	return $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('_sample_labels','_sample_note') ORDER BY post_id, meta_key, meta_id", ARRAY_A );
};

if ( 'uninstall_before' === $phase || 'uninstall_after' === $phase ) {
	// E16: uninstall with Remove all data on never touches a provider's data.
	$ids = array_map( 'intval', (array) get_option( 'sample_provider_probe_ids', [] ) );
	if ( 'uninstall_before' === $phase ) {
		if ( [] === $ids ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'Provider probe uninstall' );
			$product->set_status( 'publish' );
			$ids = [ $product->save() ];
			update_post_meta( $ids[0], '_sample_labels', wp_slash( '["blue"]' ) );
			update_post_meta( $ids[0], '_sample_note', 'Kept by uninstall' );
			update_option( 'sample_provider_probe_ids', $ids, false );
		}
		$settings                          = (array) get_option( 'bulkcat_settings', [] );
		$settings['remove_data_on_delete'] = true;
		update_option( 'bulkcat_settings', $settings );
		update_option( 'sample_provider_probe_meta', $sample_meta( $ids ), false );
		$check( 2 === count( $sample_meta( $ids ) ), 'E16: the provider\'s meta is in place before uninstall.' );
	} else {
		$check( $sample_meta( $ids ) === get_option( 'sample_provider_probe_meta' ), 'E16: uninstall with Remove all data on left the provider\'s post meta exactly as it was.' );
		$check( false === get_option( 'bulkcat_db_version' ), 'E16: and it did remove the plugin\'s own data.' );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		delete_option( 'sample_provider_probe_ids' );
		delete_option( 'sample_provider_probe_meta' );
	}
	WP_CLI::line(
		wp_json_encode(
			[
				'phase'  => $phase,
				'checks' => $checks,
			]
		)
	);
	return;
}

if ( ! class_exists( SampleProvider::class ) ) {
	fwrite( STDERR, "Copy tests/fixtures/mu-plugins/bulkcat-sample-provider.php into wp-content/mu-plugins first.\n" );
	exit( 1 );
}

// Turn the provider on or off for the rest of this request: the registry is built afresh, the hook fired again.
$provider = static function ( bool $on ): void {
	remove_all_actions( 'bulkcat_register_providers' );
	if ( $on ) {
		add_action(
			'bulkcat_register_providers',
			static function ( Registrar $registrar ): void {
				$registrar->register( new SampleProvider() );
			}
		);
	}
	$class = new ReflectionClass( Actions::class );
	$class->setStaticPropertyValue( 'instance', null );
	$class->setStaticPropertyValue( 'fired', false );
	$GLOBALS['wp_rest_server'] = null;
	Actions::instance();
};
$faults   = static function ( array $faults ): void {
	update_option( SampleFaults::OPTION, $faults, false );
};
$rest     = static function ( string $method, string $route, array $params = [], int $user = 1 ): WP_REST_Response {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, '/bulkcat/v1' . $route );
	foreach ( $params as $name => $value ) {
		$request->set_param( $name, $value );
	}
	return rest_do_request( $request );
};
$pending  = static function ( int $batch_id ): array {
	return as_get_scheduled_actions(
		[
			'hook'     => ChunkRunner::HOOK,
			'args'     => [ $batch_id ],
			'group'    => Registry::ACTION_GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 5,
		],
		'ids'
	);
};
// Run this batch's chunks through Action Scheduler until it finishes, with no current user, as background work runs.
$drain    = static function ( int $batch_id ) use ( $pending ): string {
	$batches = new BatchRepository();
	wp_set_current_user( 0 );
	for ( $round = 0; $round < 40; $round++ ) {
		$status = (string) $batches->status( $batch_id );
		if ( ! in_array( $status, [ 'queued', 'running' ], true ) ) {
			wp_set_current_user( 1 );
			return $status;
		}
		$actions = $pending( $batch_id );
		if ( [] === $actions ) {
			throw new RuntimeException( "Batch $batch_id is $status with nothing queued." );
		}
		foreach ( $actions as $action_id ) {
			ActionScheduler::runner()->process_action( (int) $action_id, 'provider-journey probe' );
		}
		wp_cache_flush();
	}
	throw new RuntimeException( "Batch $batch_id did not finish." );
};
$checksum = static function ( array $ids ) use ( $wpdb ): string {
	$in = implode( ',', array_map( 'intval', $ids ) );
	return md5(
		(string) wp_json_encode(
			[
				$wpdb->get_results( "SELECT ID, post_status, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN ($in) ORDER BY ID", ARRAY_A ),
				$wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) ORDER BY meta_id", ARRAY_A ),
			]
		)
	);
};
$modified = static function ( array $ids ) use ( $wpdb ): array {
	$in = implode( ',', array_map( 'intval', $ids ) );
	return $wpdb->get_results( "SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN ($in) ORDER BY ID", ARRAY_A );
};
$labels   = static function ( int $id ): array {
	wp_cache_delete( $id, 'post_meta' );
	$raw = get_post_meta( $id, '_sample_labels', true );
	return is_string( $raw ) && '' !== $raw ? (array) json_decode( $raw, true ) : [];
};
$add      = static fn( array $chosen ): array => [
	'action' => 'x_sample_add',
	'args'   => [
		'field'  => 'x_sample_labels',
		'labels' => $chosen,
	],
];
$service  = new RequestService();

$ids   = [];
$users = [];
try {
	$provider( true );
	$faults( [] );
	SampleProvider::reset();
	$check( null !== Actions::instance()->providers()->get( 'sample' ), 'The test provider registered through bulkcat_register_providers.' );

	// Fixture: four simple products (a1 with the label Blue), a variable one with two variations, one to go to the bin.
	$make = static function ( string $sku, string $name, string $class = WC_Product_Simple::class ): int {
		$product = new $class();
		$product->set_name( $name );
		$product->set_sku( $sku . '-' . wp_generate_password( 6, false, false ) );
		$product->set_status( 'publish' );
		if ( WC_Product_Simple::class === $class ) {
			$product->set_regular_price( '10' );
		}
		return $product->save();
	};
	$a    = [];
	foreach ( [ 1, 2, 3, 4 ] as $n ) {
		$a[ $n ] = $make( "bulkcat-ext-a$n", "Provider probe simple $n" );
	}
	$v  = $make( 'bulkcat-ext-v', 'Provider probe variable', WC_Product_Variable::class );
	$vv = [];
	foreach ( [ 1, 2 ] as $n ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $v );
		$variation->set_status( 'publish' );
		$variation->set_regular_price( '20' );
		$vv[ $n ] = $variation->save();
	}
	$t   = $make( 'bulkcat-ext-t', 'Provider probe gone' );
	$ids = [ ...array_values( $a ), $v, ...array_values( $vv ), $t ];
	update_post_meta( $a[1], '_sample_labels', wp_slash( '["blue"]' ) );
	wp_cache_flush();

	// E3 / E1 live: /fields lists the provider's entries only while it is registered.
	$fields = $rest( 'GET', '/fields' )->get_data();
	$check( in_array( 'x_sample_add', array_column( $fields['actions'], 'id' ), true ) && 'sample' === ( $fields['providers'][0]['id'] ?? '' ), 'E3: /fields lists the provider\'s actions and the provider.' );
	$check( 'x_sample_labels' === end( $fields['groups'] )['id'] && 'x_sample_labels' === array_values( array_filter( $fields['fields'], static fn( array $f ): bool => 'external' === ( $f['source'] ?? '' ) ) )[0]['key'], 'E3: its group follows the native groups; its field is marked external.' );

	// ---- E5: preview over an exact selection built from two searches and one identity ----
	$first  = $accepted( $service->capture( $service->issue( 'selection.capture', [ 'query' => [ 'search' => 'Provider probe simple' ] ] ) ), 'Search 1 captured.' );
	$second = $accepted( $service->capture( $service->issue( 'selection.capture', [ 'query' => [ 'search' => 'Provider probe gone' ] ] ) ), 'Search 2 captured.' );
	$grant  = $service->issue(
		'selection.compose',
		[
			'token'     => $first['token'],
			'add_token' => $second['token'],
		]
	);
	$union  = $accepted(
		$service->compose(
			$grant + [
				'token'     => $first['token'],
				'add_token' => $second['token'],
			]
		),
		'The two searches composed into one selection.'
	);
	$grant  = $service->issue(
		'selection.compose',
		[
			'token'   => $union['token'],
			'add_ids' => [ $vv[1] ],
		]
	);
	$token  = $accepted( $service->compose( $grant + [ 'token' => $union['token'] ] ), 'A variation added by identity (off the searches\' pages).' )['token'];
	$check( 6 === ( $service->handle( 'inspect', [ 'token' => $token ] )['count'] ?? 0 ), 'The exact selection holds a1–a4, the product to go to the bin and one variation.' );
	wp_trash_post( $t );
	wp_cache_flush();

	$before  = $checksum( $ids );
	$preview = $accepted(
		$service->preview(
			[
				'token'       => $token,
				'operations'  => [ $add( [ 'red' ] ) ],
				'sample_size' => 100,
			]
		),
		'E5: the external preview answers.'
	);
	$check( $before === $checksum( $ids ), 'E5: the preview wrote nothing: products, meta and the provider\'s storage unchanged.' );
	$check( [] === SampleProvider::$flushed, 'E5: flush() is never called by a preview.' );
	$computed = array_values( array_unique( array_column( SampleProvider::$computed, 1 ) ) );
	sort( $computed );
	$expected = array_values( $a );
	sort( $expected );
	$check( $expected === $computed, 'E5: exactly the available selected products reached compute(): not the binned one, not the variation.' );
	$check( in_array( $t, $preview['unreadable'], true ) && 1 === $preview['unavailable_count'], 'E5: the binned member is reported by the core as unavailable.' );
	$rows = array_column( $preview['sample'], null, 'object_id' );
	$check( 'skipped' === $rows[ $vv[1] ]['state'] && '“Sample labels” is kept on the product, not on each variation.' === $rows[ $vv[1] ]['reason'], 'E5: the variation meets the parent-only field and is skipped with the core\'s sentence.' );
	$check( 'Blue' === $rows[ $a[1] ]['current'] && 'Blue, Red' === $rows[ $a[1] ]['new'] && 'Adds Red' === $rows[ $a[1] ]['change'] && [ 'Keeps 1 existing label.' ] === $rows[ $a[1] ]['notes'], 'E5: rows carry the provider\'s text, its change sentence and its notes.' );
	$check( ! str_contains( (string) wp_json_encode( $preview['sample'] ), '["blue"' ), 'E5: no raw value is sent.' );
	$check( 'Sample labels will change on 4 products.' === $preview['summary'], 'E5: the summary names the external field.' );
	$faults( [ 'unknown' => [ $a[2] ] ] );
	$unknown = $accepted(
		$service->preview(
			[
				'token'      => $token,
				'operations' => [ $add( [ 'red' ] ) ],
			]
		),
		'Preview with an unreadable value.'
	);
	$check( 'failed' === array_column( $unknown['sample'], null, 'object_id' )[ $a[2] ]['state'], 'E5: an Unknown value is listed as cannot change, never as empty.' );
	$faults( [] );
	$sale = $accepted(
		$service->preview(
			[
				'token'      => $token,
				'operations' => [ $add( [ 'sale' ] ) ],
			]
		),
		'Preview adding the Sale label.'
	);
	$check( 4 === ( $sale['external']['warnings'][0]['count'] ?? 0 ) && true === $sale['external']['confirm'], 'E5: warnings aggregate by code with a count; confirm is flagged.' );

	// ---- E6: Apply binds to the preview ----
	$submit_for       = static function ( array $preview ) use ( $service, $token ): array {
		$grant = $service->issue(
			'batch.apply',
			[
				'token'      => $token,
				'operations' => $preview['operations'],
				'scope_hash' => $preview['scope_hash'],
			]
		);
		if ( is_wp_error( $grant ) ) {
			throw new RuntimeException( 'Grant refused: ' . $grant->get_error_code() );
		}
		return $grant + [
			'token'        => $token,
			'preview_hash' => $preview['hash'],
		];
	};
	$external_batches = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE actions LIKE %s', $wpdb->prefix . 'bulkcat_batches', '%,x\_sample\_add,%' ) );
	$batches_before   = $external_batches();
	foreach ( [
		'state'   => [ 'state' => 'moved' ],
		'version' => [ 'version' => '1.0.1' ],
		'format'  => [ 'format' => 2 ],
	] as $what => $fault ) {
		$submit = $submit_for( $preview );
		$faults( $fault );
		$provider( true );
		$stale = $refused( $service->apply( $submit ), 'bulkcat_preview_stale', "E6: a changed provider $what refuses Apply as stale." );
		$check( 'provider_changed' === ( $stale->get_error_data()['reason'] ?? '' ), "E6: … with data.reason provider_changed ($what)." );
		$faults( [] );
		$provider( true );
	}
	$submit = $submit_for( $preview );
	wp_untrash_post( $a[4] );
	wp_trash_post( $a[4] );
	wp_cache_flush();
	$stale = $refused( $service->apply( $submit ), 'bulkcat_preview_stale', 'E6: a member gone since the preview refuses Apply as stale.' );
	$check( 'scope_changed' === ( $stale->get_error_data()['reason'] ?? '' ), 'E6: … with data.reason scope_changed.' );
	wp_untrash_post( $a[4] );
	wp_publish_post( $a[4] );
	wp_cache_flush();
	$check( $batches_before === $external_batches(), 'E6: no refused Apply left a batch.' );

	$preview = $accepted(
		$service->preview(
			[
				'token'       => $token,
				'operations'  => [ $add( [ 'red' ] ) ],
				'sample_size' => 100,
			]
		),
		'Preview again.'
	);
	$submit  = $submit_for( $preview );
	$stamped = $modified( $ids );
	SampleProvider::reset();
	$result = $accepted( $service->apply( $submit ), 'E6: Apply is accepted.' );
	$batch  = (int) $result['batch_id'];
	$again  = $accepted( $service->apply( $submit ), 'E6: the same grant again.' );
	$check( $batch === $again['batch_id'] && true === $again['replayed'], 'E6: the same grant replays one batch.' );
	$changed = $submit;
	$changed['intent']['operations'][0]['args']['labels'] = [ 'green' ];
	$refused( $service->apply( $changed ), 'bulkcat_request_conflict', 'E6: changed arguments under the same id conflict.' );
	$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT extensions, actions FROM %i WHERE batch_id = %d', $wpdb->prefix . 'bulkcat_batches', $batch ), ARRAY_A );
	$manifest = json_decode( (string) $row['extensions'], true );
	$check( 'Sample labels' === ( $manifest['sample']['label'] ?? '' ) && 1 === ( $manifest['sample']['fields']['x_sample_labels']['format'] ?? 0 ) && ',x_sample_add,' === $row['actions'], 'E6: the manifest and the action list committed with the batch.' );
	SampleProvider::reset();
	$provider( false );
	$gone = $accepted( $service->apply( $submit ), 'E6: with the provider gone, the accepted Apply resolves.' );
	$check( $batch === $gone['batch_id'] && true === $gone['replayed'] && [] === SampleProvider::$validated, 'E6: … to the same batch, without asking the provider.' );
	$provider( true );

	// ---- E7: the chunk path ----
	$check( 'partial' === $drain( $batch ), 'E7: the job finishes partial: the selected variation was skipped, the binned member unavailable.' );
	$check( [ 'blue', 'red' ] === $labels( $a[1] ) && [ 'red' ] === $labels( $a[2] ) && [ 'red' ] === $labels( $a[4] ), 'E7: the provider\'s storage reads back the change.' );
	$journal = ( new ChangeRepository() )->for_batch( $batch, 0, 50 );
	$by      = array_column( $journal, null, 'object_id' );
	$check(
		'applied' === $by[ $a[1] ]['result'] && [
			'external' => 1,
			'format'   => 1,
			'value'    => [ 'blue' ],
			'text'     => 'Blue',
		] === $by[ $a[1] ]['old_value'] && 'Blue, Red' === $by[ $a[1] ]['new_value']['text'],
		'E7: the journal holds envelopes, before and after.'
	);
	$check( $stamped === $modified( $ids ), 'E7: no product was saved: every modified date is as it was.' );
	$check( 1 === count( SampleProvider::$flushed ) && 4 === count( SampleProvider::$flushed[0][0] ), 'E7: flush() once for the chunk, with the four products written.' );
	$faults(
		[
			'write_conflict' => [ $a[1] ],
			'write_error'    => [ $a[2] ],
			'throw_compute'  => [ $a[3] ],
			'not_stored'     => [ $a[4] ],
		]
	);
	$ids_only = [ 'ids' => array_values( $a ) ];
	$plain    = $accepted( $service->preview( $ids_only + [ 'operations' => [ $add( [ 'green' ] ) ] ] ), 'Preview for the faults run.' );
	$grant    = $service->issue(
		'batch.apply',
		$ids_only + [
			'operations' => $plain['operations'],
			'scope_hash' => $plain['scope_hash'],
		]
	);
	$faulty   = (int) $accepted( $service->apply( $grant + [ 'preview_hash' => $plain['hash'] ] ), 'The faults run is accepted.' )['batch_id'];
	$check( 'failed' === $drain( $faulty ), 'E7: every object failed in its own way, and the batch says failed.' );
	$items = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, state, message FROM %i WHERE batch_id = %d', $wpdb->prefix . 'bulkcat_batch_items', $faulty ), OBJECT_K );
	$check( str_contains( $items[ $a[1] ]->message, 'changed by someone else' ) && str_contains( $items[ $a[2] ]->message, 'could not be saved' ) && str_contains( $items[ $a[3] ]->message, 'could not work out this change' ) && str_contains( $items[ $a[4] ]->message, 'did not stick' ), 'E7: conflict, write error, exception and not-stored each failed only their object.' );
	$check( [ 'blue', 'red' ] === $labels( $a[1] ) && [ 'red' ] === $labels( $a[2] ), 'E7: nothing was written where it failed.' );
	$faults( [] );

	// ---- E8: a chunk stopped between write and settle ----
	$replay_preview = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[2] ],
				'operations' => [ $add( [ 'green' ] ) ],
			]
		),
		'Preview for the replay.'
	);
	$grant          = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[2] ],
			'operations' => $replay_preview['operations'],
			'scope_hash' => $replay_preview['scope_hash'],
		]
	);
	$replay         = (int) $accepted( $service->apply( $grant + [ 'preview_hash' => $replay_preview['hash'] ] ), 'The replay run is accepted.' )['batch_id'];
	// The run that died: its intent row written, the provider's write landed, then nothing.
	( new ChangeRepository() )->append(
		$replay,
		$a[2],
		'product',
		'x_sample_labels',
		[
			'external' => 1,
			'format'   => 1,
			'value'    => [ 'red' ],
			'text'     => 'Red',
		],
		[
			'external' => 1,
			'format'   => 1,
			'value'    => [ 'green', 'red' ],
			'text'     => 'Green, Red',
		],
		'0000-00-00 00:00:00',
		Schema::CHANGE_INTENT
	);
	update_post_meta( $a[2], '_sample_labels', wp_slash( '["green","red"]' ) );
	$writes = 0;
	$count  = static function ( $meta_id, $object_id, $key ) use ( &$writes ): void {
		$writes += '_sample_labels' === $key ? 1 : 0;
	};
	add_action( 'updated_post_meta', $count, 10, 3 );
	$check( 'complete' === $drain( $replay ), 'E8: the retried chunk finishes.' );
	remove_action( 'updated_post_meta', $count, 10 );
	$check( 0 === $writes && [ 'green', 'red' ] === $labels( $a[2] ), 'E8: the retry settled the landed write and applied nothing a second time.' );
	$check( 1 === ( new ChangeRepository() )->count_for_batch( $replay, 'applied' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE batch_id = %d AND result = %s', $wpdb->prefix . 'bulkcat_changes', $replay, Schema::CHANGE_INTENT ) ), 'E8: one applied row, no intent left.' );

	// ---- E9: History, changes, audit and CSV ----
	$changes = $rest( 'GET', "/batches/$batch/changes", [ 'per_page' => 50 ] )->get_data();
	$first   = array_column( $changes['items'], null, 'object_id' )[ $a[1] ];
	$check( 'Sample labels' === $first['field_label'] && 'Blue' === $first['old_value'] && 'Blue, Red' === $first['new_value'], 'E9: the changes feed reads the manifest\'s label and the journalled text.' );
	$audit = $rest(
		'GET',
		'/audit',
		[
			'field'    => 'x_sample_labels',
			'per_page' => 50,
		]
	);
	$check( 200 === $audit->get_status() && count( $audit->get_data()['items'] ) >= 4, 'E9: the audit log filters by the external field.' );
	$report = $rest( 'GET', "/batches/$batch" )->get_data();
	$check( 'Add labels' === ( $report['external']['actions']['x_sample_add'] ?? '' ), 'E9: the batch report carries the manifest\'s labels.' );
	$csv    = $rest(
		'GET',
		'/export',
		[
			'source'  => 'changes',
			'batch'   => $batch,
			'columns' => [ 'name', 'field_label', 'old_value', 'new_value' ],
		]
	);
	$stream = fopen( 'php://memory', 'w+b' );
	$csv->get_data()->stream( $stream );
	rewind( $stream );
	$csv_text = (string) stream_get_contents( $stream );
	$check( str_contains( $csv_text, 'Blue, Red' ) && ! str_contains( $csv_text, '"external"' ) && ! str_contains( $csv_text, '[""blue""' ), 'E9: the CSV carries text, never the value.' );
	foreach ( [ $changes, $audit->get_data(), $report ] as $body ) {
		$check( ! str_contains( (string) wp_json_encode( $body ), '"external":1' ), 'E9: no envelope in a REST answer.' );
	}
	$provider( false );
	$bare = $rest( 'GET', "/batches/$batch/changes", [ 'per_page' => 50 ] )->get_data();
	$check( 'Sample labels' === array_column( $bare['items'], null, 'object_id' )[ $a[1] ]['field_label'] && 'Blue, Red' === array_column( $bare['items'], null, 'object_id' )[ $a[1] ]['new_value'], 'E9/E11: with the provider gone, History still reads.' );
	$provider( true );

	// ---- E10: undo, conflicts, Revert anyway, redo, a partial batch ----
	$storage_before = [
		$a[1] => [ 'blue' ],
		$a[2] => [ 'green', 'red' ],
		$a[3] => [ 'red' ],
		$a[4] => [ 'red' ],
	];
	update_post_meta( $a[3], '_sample_labels', wp_slash( '["red","sale"]' ) );
	$undo_preview = $rest( 'POST', "/batches/$batch/undo", [ 'preview' => true ] )->get_data();
	$conflict     = array_column( array_filter( $undo_preview['items'], static fn( array $item ): bool => 'conflicted' === $item['state'] ), null, 'object_id' );
	$check( [ $a[2], $a[3] ] === array_values( array_intersect( [ $a[2], $a[3] ], array_keys( $conflict ) ) ) && 2 === count( $conflict ) && 'conflicted' === $undo_preview['items'][0]['state'], 'E10: the two values changed since (the replay batch and an outside edit) are listed first.' );
	$check( 'Red' === $conflict[ $a[3] ]['conflicts'][0]['expected'] && 'Red, Sale' === $conflict[ $a[3] ]['conflicts'][0]['current'] && 'Sample labels' === $conflict[ $a[3] ]['conflicts'][0]['field_label'], 'E10: … as text, with the field\'s label.' );
	$undo_for = static function ( int $of, bool $force ) use ( $service, $accepted ): int {
		$plan  = ( new Planner() )->plan( $of, 1 );
		$grant = $accepted(
			$service->issue(
				'batch.undo',
				[
					'batch_id'  => $of,
					'plan_hash' => $plan->hash(),
					'force'     => $force,
				]
			),
			'Undo grant.'
		);
		return (int) $accepted( $service->undo( $grant ), 'Undo accepted.' )['batch_id'];
	};
	$undo     = $undo_for( $batch, false );
	$drain( $undo );
	$check( [ 'blue' ] === $labels( $a[1] ) && [ 'green', 'red' ] === $labels( $a[2] ) && [ 'red', 'sale' ] === $labels( $a[3] ) && [] === $labels( $a[4] ), 'E10: undo put back every value it could and left the conflicts alone.' );
	$forced_prev = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[3] ],
				'operations' => [ $add( [ 'green' ] ) ],
			]
		),
		'A batch to revert anyway.'
	);
	$grant       = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[3] ],
			'operations' => $forced_prev['operations'],
			'scope_hash' => $forced_prev['scope_hash'],
		]
	);
	$forced      = (int) $accepted( $service->apply( $grant + [ 'preview_hash' => $forced_prev['hash'] ] ), 'Accepted.' )['batch_id'];
	$drain( $forced );
	update_post_meta( $a[3], '_sample_labels', wp_slash( '["blue"]' ) );
	$drain( $undo_for( $forced, true ) );
	$check( [ 'red', 'sale' ] === $labels( $a[3] ), 'E10: Revert anyway writes the old value over a value changed since.' );
	$redo = $undo_for( $undo, false );
	$drain( $redo );
	$check( [ 'blue', 'red' ] === $labels( $a[1] ) && [ 'green', 'red' ] === $labels( $a[2] ) && [ 'red' ] === $labels( $a[4] ), 'E10: redo (the undo of the undo) put the change back.' );
	unset( $storage_before );

	$meta_before = $sample_meta( $ids );
	$full_prev   = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[1], $a[4] ],
				'operations' => [ $add( [ 'green' ] ) ],
			]
		),
		'Preview for the round trip.'
	);
	$grant       = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[1], $a[4] ],
			'operations' => $full_prev['operations'],
			'scope_hash' => $full_prev['scope_hash'],
		]
	);
	$trip        = (int) $accepted( $service->apply( $grant + [ 'preview_hash' => $full_prev['hash'] ] ), 'Round trip accepted.' )['batch_id'];
	$drain( $trip );
	$check( $meta_before !== $sample_meta( $ids ), 'The round trip changed the provider\'s storage.' );
	$faults( [ 'format' => 2 ] );
	$provider( true );
	$drain( $undo_for( $trip, false ) );
	$check( $meta_before === $sample_meta( $ids ), 'E10: undo left the provider\'s storage byte-identical, its values upgraded from format 1 to 2 on the way.' );
	$faults( [] );
	$provider( true );

	$partial_prev = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[1], $a[2] ],
				'operations' => [ $add( [ 'sale' ] ) ],
			]
		),
		'Preview for the partial batch.'
	);
	$faults( [ 'write_error' => [ $a[2] ] ] );
	$grant   = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[1], $a[2] ],
			'operations' => $partial_prev['operations'],
			'scope_hash' => $partial_prev['scope_hash'],
		]
	);
	$partial = (int) $accepted( $service->apply( $grant + [ 'preview_hash' => $partial_prev['hash'] ] ), 'Partial accepted.' )['batch_id'];
	$check( 'partial' === $drain( $partial ), 'A partial batch.' );
	$faults( [] );
	$drain( $undo_for( $partial, false ) );
	$check( [ 'blue', 'red' ] === $labels( $a[1] ) && [ 'green', 'red' ] === $labels( $a[2] ), 'E10: the undo of a partial batch put back the part applied.' );

	// ---- E11: provider missing, unavailable or incompatible ----
	$provider( false );
	$fields = $rest( 'GET', '/fields' )->get_data();
	$check( ! array_key_exists( 'providers', $fields ) && ! in_array( 'x_sample_add', array_column( $fields['actions'], 'id' ), true ), 'E11: missing at screen load: its actions are absent and /fields is the native one.' );
	$refused(
		$service->preview(
			[
				'ids'        => [ $a[1] ],
				'operations' => [ $add( [ 'red' ] ) ],
			]
		),
		'bulkcat_provider_unavailable',
		'E11: missing at preview: 409.'
	);
	$faults( [ 'unavailable' => true ] );
	$provider( true );
	$fields = $rest( 'GET', '/fields' )->get_data();
	$check( false === array_column( $fields['actions'], null, 'id' )['x_sample_add']['available'] && 'Sample labels needs its settings saved before it can be used.' === array_column( $fields['actions'], null, 'id' )['x_sample_add']['unavailableReason'], 'E11: unavailable at screen load: disabled with its reason.' );
	$unavailable = $refused(
		$service->preview(
			[
				'ids'        => [ $a[1] ],
				'operations' => [ $add( [ 'red' ] ) ],
			]
		),
		'bulkcat_provider_unavailable',
		'E11: unavailable at preview: 409.'
	);
	$check( 'Sample labels needs its settings saved before it can be used.' === $unavailable->get_error_message(), 'E11: … with the provider\'s own reason.' );
	$faults( [ 'malformed' => true ] );
	$provider( true );
	$check( ! Actions::instance()->providers()->any() && 1 === count( Actions::instance()->providers()->refusals() ), 'E11: incompatible: refused at registration.' );
	ob_start();
	$screen = new BulkCatalogueManager\Admin\Screen();
	$screen->render();
	$html = (string) ob_get_clean();
	$check( str_contains( $html, 'Sample labels could not be added to Bulk Catalogue Manager' ), 'E11: incompatible at screen load: one plain notice names it.' );
	$faults( [] );
	$provider( true );

	$mid_prev   = $accepted(
		$service->preview(
			[
				'ids'        => array_values( $a ),
				'operations' => [ $add( [ 'green' ] ) ],
			]
		),
		'Preview for the mid-job case.'
	);
	$grant      = $service->issue(
		'batch.apply',
		[
			'ids'        => array_values( $a ),
			'operations' => $mid_prev['operations'],
			'scope_hash' => $mid_prev['scope_hash'],
		]
	);
	$mid        = (int) $accepted( $service->apply( $grant + [ 'preview_hash' => $mid_prev['hash'] ] ), 'Accepted before the provider went.' )['batch_id'];
	$late_grant = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[1] ],
			'operations' => $mid_prev['operations'],
			'scope_hash' => str_repeat( 'b', 64 ),
		]
	);
	$provider( false );
	$refused(
		$service->issue(
			'batch.apply',
			[
				'ids'        => array_values( $a ),
				'operations' => $mid_prev['operations'],
				'scope_hash' => $mid_prev['scope_hash'],
			]
		),
		'bulkcat_provider_unavailable',
		'E11: missing at grant issuance: 409.'
	);
	$refused( $service->apply( $late_grant + [ 'preview_hash' => $mid_prev['hash'] ] ), 'bulkcat_provider_unavailable', 'E11: missing at a new acceptance: 409, no batch.' );
	$check( 'partial' === $drain( $mid ), 'E11: missing mid-job: the job stops, never complete.' );
	$note = ( new BatchRepository() )->find( $mid );
	$check( 'Stopped because Sample labels is not active. 0 products were changed; the rest were left alone.' === $note['note'] && 4 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE batch_id = %d AND state = 'skipped'", $wpdb->prefix . 'bulkcat_batch_items', $mid ) ), 'E11: … saying what it changed, the rest skipped.' );
	$undo_gone = $rest( 'POST', "/batches/$redo/undo", [ 'preview' => true ] );
	$check( 409 === $undo_gone->get_status() && 'provider_missing' === ( $undo_gone->get_data()['data']['reason'] ?? '' ) && str_contains( (string) $undo_gone->get_data()['message'], 'which is not active now' ), 'E11: missing at undo: 409 not reversible, provider_missing, "not active".' );
	$history = $rest( 'GET', "/batches/$redo" )->get_data();
	$check( 'provider_missing' === $history['reversibility']['reason'] && 'Sample labels' === $history['reversibility']['provider'], 'E11: History says which add-on it needs.' );
	$provider( true );
	$check( 200 === $rest( 'POST', "/batches/$redo/undo", [ 'preview' => true ] )->get_status() && null === ( $rest( 'GET', "/batches/$redo" )->get_data()['reversibility']['reason'] ?? null ), 'E11: with the provider back, undo works again.' );

	// D-1: registered but unavailable between Apply and the chunk: its own reason, not "not active".
	$d1_prev  = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[1], $a[2] ],
				'operations' => [ $add( [ 'green' ] ) ],
			]
		),
		'D-1: preview while available.'
	);
	$d1_grant = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[1], $a[2] ],
			'operations' => $d1_prev['operations'],
			'scope_hash' => $d1_prev['scope_hash'],
		]
	);
	$d1       = (int) $accepted( $service->apply( $d1_grant + [ 'preview_hash' => $d1_prev['hash'] ] ), 'D-1: Apply accepted while available.' )['batch_id'];
	$d1_meta  = $sample_meta( [ $a[1], $a[2] ] );
	$faults( [ 'unavailable' => true ] );
	$provider( true );
	$check( 'partial' === $drain( $d1 ), 'D-1: unavailable mid-job: the job stops, never complete.' );
	$d1_row = ( new BatchRepository() )->find( $d1 );
	$check( 'Stopped because Sample labels cannot run. 0 products were changed; the rest were left alone. Sample labels needs its settings saved before it can be used.' === $d1_row['note'], 'D-1: the note says it cannot run, with the add-on\'s own reason.' );
	$d1_items = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT message FROM %i WHERE batch_id = %d AND state = 'skipped'", $wpdb->prefix . 'bulkcat_batch_items', $d1 ) );
	$check( [ 'Sample labels cannot run now, so this was left alone. Sample labels needs its settings saved before it can be used.' ] === $d1_items, 'D-1: each item left alone says so, with the add-on\'s reason.' );
	$check( $d1_meta === $sample_meta( [ $a[1], $a[2] ] ), 'D-1: nothing was written.' );
	$d1_undo = $rest( 'POST', "/batches/$redo/undo", [ 'preview' => true ] );
	$check( 409 === $d1_undo->get_status() && 'provider_unavailable' === ( $d1_undo->get_data()['data']['reason'] ?? '' ) && 'This change was made with Sample labels, which cannot run now, so it cannot be undone yet. Sample labels needs its settings saved before it can be used.' === $d1_undo->get_data()['message'], 'D-1: the undo refusal says it cannot run, with its reason, and provider_unavailable.' );
	$d1_hist = $rest( 'GET', "/batches/$redo" )->get_data()['reversibility'];
	$check( 'provider_unavailable' === $d1_hist['reason'] && 'Sample labels' === $d1_hist['provider'] && 'Sample labels needs its settings saved before it can be used.' === $d1_hist['provider_reason'], 'D-1: History reads the add-on and its reason.' );
	$check( 200 === $rest( 'GET', "/batches/$d1/changes" )->get_status(), 'D-1: the stopped batch stays readable.' );
	$faults( [] );
	$provider( true );
	$check( 200 === $rest( 'POST', "/batches/$redo/undo", [ 'preview' => true ] )->get_status(), 'D-1: once it can run again, undo works.' );

	// Point (a): binned between preview and Apply refuses as stale; binned after Apply is never written.
	$bin_prev  = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[3], $a[4] ],
				'operations' => [ $add( [ 'sale' ] ) ],
			]
		),
		'(a): preview.'
	);
	$bin_grant = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[3], $a[4] ],
			'operations' => $bin_prev['operations'],
			'scope_hash' => $bin_prev['scope_hash'],
		]
	);
	wp_trash_post( $a[4] );
	wp_cache_flush();
	$bin_stale = $refused( $service->apply( $bin_grant + [ 'preview_hash' => $bin_prev['hash'] ] ), 'bulkcat_preview_stale', '(a): a member binned between preview and Apply (explicit ids) refuses Apply as stale.' );
	$check( 'scope_changed' === ( $bin_stale->get_error_data()['reason'] ?? '' ), '(a): … scope_changed.' );
	wp_untrash_post( $a[4] );
	wp_publish_post( $a[4] );
	wp_cache_flush();
	$bin_prev  = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[3], $a[4] ],
				'operations' => [ $add( [ 'sale' ] ) ],
			]
		),
		'(a): preview again.'
	);
	$bin_grant = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[3], $a[4] ],
			'operations' => $bin_prev['operations'],
			'scope_hash' => $bin_prev['scope_hash'],
		]
	);
	$bin       = (int) $accepted( $service->apply( $bin_grant + [ 'preview_hash' => $bin_prev['hash'] ] ), '(a): Apply accepted.' )['batch_id'];
	$before4   = $sample_meta( [ $a[4] ] );
	wp_trash_post( $a[4] );
	wp_cache_flush();
	$drain( $bin );
	$bin_item = $wpdb->get_row( $wpdb->prepare( 'SELECT state, message FROM %i WHERE batch_id = %d AND object_id = %d', $wpdb->prefix . 'bulkcat_batch_items', $bin, $a[4] ), ARRAY_A );
	$check( 'skipped' === $bin_item['state'] && BulkCatalogueManager\Engine\BatchFactory::unavailable_reason() === $bin_item['message'] && $before4 === $sample_meta( [ $a[4] ] ), '(a): a member binned after Apply is skipped unwritten, as an unavailable member.' );
	$check( in_array( 'sale', $labels( $a[3] ), true ), '(a): … and the rest of the batch is written.' );
	wp_untrash_post( $a[4] );
	wp_publish_post( $a[4] );
	wp_cache_flush();
	$again = $accepted(
		$service->preview(
			[
				'token'      => $token,
				'operations' => [ $add( [ 'green' ] ) ],
			]
		),
		'E11: the retained selection previews again once it is back.'
	);
	$check( 4 === $again['totals']['would_change'] + $again['totals']['already'], 'E11: … over the same exact members.' );

	// ---- E12: permissions ----
	foreach ( [
		'subscriber' => 'subscriber',
		'limited'    => null,
		'manager'    => 'shop_manager',
	] as $who => $role ) {
		$login         = 'bulkcat_ext_' . $who . '_' . wp_generate_password( 6, false, false );
		$users[ $who ] = wp_insert_user(
			[
				'user_login' => $login,
				'user_pass'  => wp_generate_password(),
				'user_email' => $login . '@example.com',
				'role'       => $role ?? 'subscriber',
			]
		);
	}
	add_role(
		'bulkcat_ext_limited',
		'Probe limited',
		[
			'read'               => true,
			'manage_woocommerce' => true,
			'edit_products'      => true,
		]
	);
	( new WP_User( $users['limited'] ) )->set_role( 'bulkcat_ext_limited' );

	// Point (b): a reader of History without the action's capability gets no add-on argument values.
	add_role(
		'bulkcat_ext_reader',
		'Probe reader',
		[
			'read'               => true,
			'manage_woocommerce' => true,
		]
	);
	$users['reader'] = wp_insert_user(
		[
			'user_login' => 'bulkcat_ext_reader_' . wp_generate_password( 6, false, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'reader-' . wp_generate_password( 6, false, false ) . '@example.test',
			'role'       => 'bulkcat_ext_reader',
		]
	);
	$as_admin        = $rest( 'GET', "/batches/$batch" )->get_data()['operations'][0]['args'] ?? [];
	$as_reader       = $rest( 'GET', "/batches/$batch", [], (int) $users['reader'] );
	$check( 200 === $as_reader->get_status() && [ 'red' ] === ( $as_admin['labels'] ?? null ) && ! array_key_exists( 'labels', $as_reader->get_data()['operations'][0]['args'] ?? [ 'labels' => 1 ] ) && 'x_sample_labels' === ( $as_reader->get_data()['operations'][0]['args']['field'] ?? '' ), '(b): History shows an add-on\'s argument values only to a user holding the action\'s capability.' );
	$listed = $rest( 'GET', '/batches', [ 'per_page' => 100 ], (int) $users['reader'] );
	$mine   = array_values( array_filter( (array) ( $listed->get_data()['items'] ?? [] ), static fn( mixed $row ): bool => is_array( $row ) && $batch === (int) ( $row['batch_id'] ?? 0 ) ) );
	$check( 200 === $listed->get_status() && 1 === count( $mine ) && ! array_key_exists( 'labels', $mine[0]['operations'][0]['args'] ?? [ 'labels' => 1 ] ), '(b): … in the list too.' );
	wp_set_current_user( 1 );
	foreach ( [
		0                    => 401,
		$users['subscriber'] => 403,
	] as $user => $status ) {
		foreach ( [
			[ 'GET', '/fields', [] ],
			[
				'POST',
				'/preview',
				[
					'ids'        => [ $a[1] ],
					'operations' => [ $add( [ 'red' ] ) ],
				],
			],
			[
				'POST',
				'/requests',
				[
					'operation' => 'batch.apply',
					'intent'    => [
						'ids'        => [ $a[1] ],
						'operations' => [ $add( [ 'red' ] ) ],
						'scope_hash' => str_repeat( 'a', 64 ),
					],
				],
			],
			[ 'GET', "/batches/$batch/changes", [] ],
			[ 'POST', "/batches/$batch/undo", [ 'preview' => true ] ],
		] as [ $method, $route, $params ] ) {
			$check( $status === $rest( $method, $route, $params, $user )->get_status(), "E12: $method $route refuses user $user with $status." );
		}
	}
	wp_set_current_user( $users['limited'] );
	$limited = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[1] ],
				'operations' => [ $add( [ 'red' ] ) ],
			]
		),
		'E12: a user who may not edit the product previews.'
	);
	$check( 'skipped' === $limited['sample'][0]['state'] && 'bulkcat_cannot_edit' === $limited['sample'][0]['code'] && '' === $limited['sample'][0]['current'], 'E12: the core skips the object it may not edit, showing nothing of it.' );
	wp_set_current_user( 1 );
	$faults( [ 'refuse' => [ $a[2] ] ] );
	$narrow = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[1], $a[2] ],
				'operations' => [ $add( [ 'sale' ] ) ],
			]
		),
		'E12: authorise() narrows.'
	);
	$check( 'skipped' === array_column( $narrow['sample'], null, 'object_id' )[ $a[2] ]['state'], 'E12: … the refused object is skipped with the provider\'s sentence.' );
	$faults( [] );
	wp_set_current_user( $users['manager'] );
	$man_prev     = $accepted(
		$service->preview(
			[
				'ids'        => [ $a[3] ],
				'operations' => [ $add( [ 'green' ] ) ],
			]
		),
		'E12: the manager previews.'
	);
	$man_sub      = $service->issue(
		'batch.apply',
		[
			'ids'        => [ $a[3] ],
			'operations' => $man_prev['operations'],
			'scope_hash' => $man_prev['scope_hash'],
		]
	) + [ 'preview_hash' => $man_prev['hash'] ];
	$shop_manager = get_role( 'shop_manager' );
	$shop_manager->remove_cap( 'edit_products' );
	wp_set_current_user( 0 );
	wp_set_current_user( $users['manager'] );
	$check( is_wp_error( $service->apply( $man_sub ) ), 'E12: a capability lost between preview and Apply is refused.' );
	$shop_manager->add_cap( 'edit_products' );
	wp_set_current_user( 0 );
	wp_set_current_user( $users['manager'] );
	$man_batch = (int) $accepted( $service->apply( $man_sub ), 'E12: the manager applies.' )['batch_id'];
	$shop_manager->remove_cap( 'edit_products' );
	$shop_manager->remove_cap( 'edit_others_products' );
	$check( in_array( $drain( $man_batch ), [ 'partial', 'failed' ], true ) && [ 'red', 'sale' ] === $labels( $a[3] ), 'E12: a right revoked before the job ran: the object is skipped, under the batch\'s recorded user and no current user.' );
	$shop_manager->add_cap( 'edit_products' );
	$shop_manager->add_cap( 'edit_others_products' );

	// ---- E16: migration 7 → 8 ----
	$native_null = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE extensions IS NOT NULL AND actions NOT LIKE %s AND actions <> ',restore_external,'", $wpdb->prefix . 'bulkcat_batches', '%,x\_%' ) );
	$rows_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'bulkcat_changes' ) );
	update_option( Migrator::VERSION_OPTION, '7' );
	$check( ( new Migrator( Registry::plugin() ) )->run() && '8' === get_option( Migrator::VERSION_OPTION ), 'E16: migration 7 → 8 runs.' );
	$check( ( new Migrator( Registry::plugin() ) )->run(), 'E16: and again, idempotent.' );
	$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $wpdb->prefix . 'bulkcat_batches' ) );
	$check( 1 === count( array_keys( $columns, 'extensions', true ) ), 'E16: one extensions column.' );
	$check( 0 === $native_null && $rows_before === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'bulkcat_changes' ) ), 'E16: native batches keep a NULL manifest, and the history is untouched.' );
} finally {
	$faults( [] );
	delete_option( SampleFaults::OPTION );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $users as $user ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $user );
	}
	remove_role( 'bulkcat_ext_limited' );
	remove_role( 'bulkcat_ext_reader' );
	$role = get_role( 'shop_manager' );
	if ( $role ) {
		$role->add_cap( 'edit_products' );
		$role->add_cap( 'edit_others_products' );
	}
	wp_set_current_user( 1 );
}
WP_CLI::line(
	wp_json_encode(
		[
			'phase'  => $phase,
			'passed' => count( $checks ),
			'checks' => $checks,
		],
		JSON_PRETTY_PRINT
	)
);
