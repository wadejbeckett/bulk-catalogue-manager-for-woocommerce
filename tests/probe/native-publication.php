<?php
/**
 * Guarded native publication lifecycle checks on UUID-owned fixtures only.
 * Loaded through native-publication.py; never installed in WordPress.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Engine\InlineWrite;
use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\Writer;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Publishing\PublicationPassword;
use BulkCatalogueManager\Publishing\StatusTransition;
use BulkCatalogueManager\Undo\Planner;

/** A real native product with a test-only interruption at its status setter. */
class Bulkcat_Native_Revoke_Product extends WC_Product_Simple {
	public static int $actor               = 0;
	public static bool $password_failure   = false;
	public static bool $revoke_on_password = false;
	public function set_post_password( $password ) {
		if ( self::$password_failure && $this->get_object_read() && '' === $password ) {
			throw new WC_Data_Exception( 'invalid_password', 'Owned synthetic refusal' );}
		parent::set_post_password( $password );
		if ( self::$revoke_on_password && $this->get_object_read() ) {
			( new WP_User( self::$actor ) )->add_cap( 'publish_products', false );}
	}
	public function set_status( $status ) {
		parent::set_status( $status );
		if ( $this->get_object_read() && 'publish' === $status ) {
			( new WP_User( self::$actor ) )->add_cap( 'publish_products', false ); }
	}
}

if ( ! bulkcat_probe_fixture() ) {
	throw new RuntimeException( 'Owned local fixture required.' ); }
$a = $bulkcat_publication_args;
bulkcat_native_owned( (int) $a['id'], $a['run'] );
$id    = (int) $a['id'];
$actor = (int) $a['actor'];
if ( get_user_meta( $actor, '_bulkcat_native_probe', true ) !== $a['run'] ) {
	throw new RuntimeException( 'Owned actor required.' ); }
$secret        = "  <opaque> ' \" & \\ path\\tail  ";
$checks        = [];
$batches       = [];
$fields        = new Fields();
$actions       = new Registry( $fields );
$changes       = new ChangeRepository();
$journal       = new Journal( $changes, $fields, false );
$original_user = get_current_user_id();
$hook_shape    = static fn(): array => array_map( 'array_keys', $GLOBALS['wp_filter']['wp_insert_post_data']->callbacks ?? [] );
$hooks_before  = $hook_shape();
$observations  = [];
$counts        = [
	'crud' => 0,
	'post' => 0,
];
$watch_crud    = static function ( $p ) use ( $id, &$counts ): void {
	if ( (int) $p->get_id() === $id ) {
		++$counts['crud'];
	} };
$watch_post    = static function ( $pid ) use ( $id, &$counts ): void {
	if ( (int) $pid === $id ) {
		++$counts['post'];
	} };
$grant         = static function () use ( $actor ): void {
	$user = new WP_User( $actor );
	foreach ( [ 'read', 'manage_woocommerce', 'edit_products', 'edit_published_products', 'edit_private_products', 'read_private_products', 'publish_products' ] as $cap ) {
		$user->add_cap( $cap ); }
};
$reset         = static function ( string $status = 'draft', bool $record = false ) use ( $id, $secret, $actor ): void {
	wp_update_post(
		wp_slash(
			[
				'ID'            => $id,
				'post_status'   => $status,
				'post_password' => $secret,
				'post_author'   => $actor,
				'post_date'     => '2026-01-15 12:00:00',
				'post_date_gmt' => '2026-01-15 12:00:00',
			]
		)
	);
	$p = wc_get_product( $id );
	if ( $record ) {
		$p->update_meta_data( OffRecord::META_KEY, OffRecord::create( $id, 'private', '2026-09-01T08:00:00Z', 7 )->to_json() ); } else {
		$p->delete_meta_data( OffRecord::META_KEY ); }
		// Fixture metadata only: saving this via WC would itself lose the password before the tested writer.
		$p->save_meta_data();
		clean_post_cache( $id );
};
$read          = static function () use ( $id ): array {
	clean_post_cache( $id );
	$p = wc_get_product( $id );
	return [
		'status'   => $p->get_status( 'edit' ),
		'password' => $p->get_post_password( 'edit' ),
		'record'   => OffRecord::raw( $p ),
	];
};
$step          = static function ( string $action, string $value ) use ( $actions ): array {
	return [
		[
			'action' => $action,
			'args'   => $actions->get( $action )->validate(
				[
					'field' => 'status',
					'value' => $value,
				]
			)->args(),
		],
	];
};
$check         = static function ( string $name, bool $ok ) use ( &$checks ): void {
	$checks[ $name ] = $ok;
};
add_action( 'woocommerce_before_product_object_save', $watch_crud );
add_action( 'pre_post_update', $watch_post );
try {
	$grant();
	if ( 'scale-setup' === $a['case'] ) {
		wp_set_current_user( $actor );
		$ids   = [];
		$start = microtime( true );
		for ( $i = 0;$i < 1001;++$i ) {
			$p = new WC_Product_Simple();
			$p->set_name( 'Publication binding ' . $a['run'] . ' ' . $i );
			$p->set_status( 'draft' );
			$p->set_date_created( time() + 86400 );
			$p->update_meta_data( '_bulkcat_native_probe', $a['run'] );
			$ids[] = $p->save();
			if ( 0 === $i % 100 ) {
				\BulkCatalogueManager\Engine\Memory::release(); }
		}
		bulkcat_out(
			[
				'extra_ids'     => $ids,
				'setup_seconds' => microtime( true ) - $start,
			]
		);
		return;
	} elseif ( 'binding' === $a['case'] ) {
		wp_set_current_user( $actor );
		$ids = array_map( 'intval', $a['ids'] );
		foreach ( $ids as $pid ) {
			bulkcat_native_owned( $pid, $a['run'] );}
		$objects = array_map( static fn( $pid )=>[ $pid,'product' ], $ids );
		$ops     = $step( 'set_status', 'publish' );
		$now     = time();
		\BulkCatalogueManager\Engine\Memory::release();
		$start       = microtime( true );
		$memory      = memory_get_usage( true );
		$query_start = get_num_queries();
		$binding     = \BulkCatalogueManager\Publishing\PublicationBinding::of( $objects, $ops, $now );
		$cost        = [
			'members'        => count( $ids ),
			'seconds'        => microtime( true ) - $start,
			'queries'        => get_num_queries() - $query_start,
			'retained_bytes' => memory_get_usage( true ) - $memory,
			'peak_bytes'     => memory_get_peak_usage( true ),
		];
		$check( 'stable_order_and_one_second', \BulkCatalogueManager\Publishing\PublicationBinding::of( array_reverse( $objects ), $ops, $now + 1 ) === $binding );
		$p = wc_get_product( end( $ids ) );
		$p->set_date_created( time() - 86400 );
		$p->save();
		$check( 'last_exact_member_invalidates', \BulkCatalogueManager\Publishing\PublicationBinding::of( $objects, $ops, $now ) !== $binding );
		bulkcat_out(
			[
				'checks'  => $checks,
				'cost'    => $cost,
				'batches' => [],
			]
		);
		return;
	} elseif ( 'date' === $a['case'] ) {
		$p = wc_get_product( $id );
		$p->set_date_created( time() + (int) $a['ahead'] );
		$p->save();
		bulkcat_out( [ 'changed' => true ] );
		return;
	} elseif ( 'http' === $a['case'] ) {
		$reset();
		$check( 'HTTP_setup_exact_native_password', $read()['password'] === $secret );
	} elseif ( 'http-read' === $a['case'] ) {
		$state = $read();
		bulkcat_out(
			[
				'status'         => $state['status'],
				'password_empty' => '' === $state['password'],
				'password_exact' => $secret === $state['password'],
			]
		);
		return;
	} elseif ( 'transport' === $a['case'] ) {
		wp_set_current_user( 0 );
		$reset();
		$before = $read();
		$counts = [
			'crud' => 0,
			'post' => 0,
		];
		$out    = ( new Writer( $actions, null, $actor ) )->write( $id, $step( 'set_status', 'pending' ) );
		$after  = $read();
		$check( 'actual_native_preimage_exact', $before['password'] === $secret );
		$check( 'untouched_status_password_roundtrip', $after['password'] === $before['password'] );
		$check(
			'one_CRUD_one_native_update',
			( 1 === $counts['crud'] && 1 === $counts['post'] )
		);
		$check( 'authorised_pending_slug', get_post_field( 'post_name', $id, 'raw' ) === $a['slug'] );
		$counts = [
			'crud' => 0,
			'post' => 0,
		];
		( new Writer( $actions, null, $actor ) )->write( $id, $step( 'set_status', 'pending' ) );
		$check(
			'same_second_no_save',
			( 0 === $counts['crud'] && 0 === $counts['post'] )
		);
		$check( 'no_lifecycle_filter_leaked', $hook_shape() === $hooks_before );
	} elseif ( 'private-undo' === $a['case'] ) {
		wp_set_current_user( $actor );
		$reset();
		$before = $read();
		$reply  = ( new InlineWrite() )->write( $id, [ 'status' => 'private' ], 'inline', $actor );
		if ( is_wp_error( $reply ) ) {
			throw new RuntimeException( $reply->get_error_message() ); }
		$batch         = (int) $reply['batch_id'];
		$batches[]     = $batch;
		$rows          = $changes->for_objects( $batch, [ $id ] );
		$password_rows = array_values( array_filter( $rows, static fn( $r )=>PublicationPassword::FIELD === $r['prop'] ) );
		$check( 'native_private_clears', '' === $read()['password'] && 'private' === $read()['status'] );
		$check( 'protected_journal_exact', count( $password_rows ) === 1 && $password_rows[0]['old_value'] === $before['password'] );
		$plan = ( new Planner() )->plan( $batch, $actor );
		if ( is_wp_error( $plan ) ) {
			throw new RuntimeException( $plan->get_error_message() ); }
		$check( 'internal_undo_exact_argument', $plan->items()[0]['operations'][0]['args']['password'] === $before['password'] );
		$public = [
			$reply,
			$plan->to_array(),
			( new \BulkCatalogueManager\Rest\UndoController() )->item( $plan->items()[0] ),
			\BulkCatalogueManager\External\Disclosure::operations( $plan->items()[0]['operations'], $actor, $actions->providers() ),
			array_map(
				static fn( array $row ): array => ( new \BulkCatalogueManager\Rest\BatchChangesController( new \BulkCatalogueManager\Data\BatchRepository(), new \BulkCatalogueManager\Data\ChangeRepository(), new Fields() ) )->change(
					$row + [
						'batch_id'     => $batch,
						'message'      => '',
						'modified_gmt' => '2026-01-15 12:00:00',
						'applied_gmt'  => gmdate( 'Y-m-d H:i:s' ),
					]
				),
				$rows
			),
		];
		$check( 'all_shared_serializers_redacted', ! str_contains( wp_json_encode( $public ), 'opaque' ) && ! str_contains( wp_json_encode( $public ), 'path' ) );
		$undo = ( new BatchFactory() )->create_undo( wp_generate_uuid4(), $actor, $plan, false );
		if ( is_wp_error( $undo ) ) {
			throw new RuntimeException( $undo->get_error_message() ); }
		$batches[] = (int) $undo['batch_id'];
		wp_set_current_user( 0 );
		ChunkRunner::handle( (int) $undo['batch_id'] );
		$check( 'native_exact_undo_roundtrip', $read() === $before );
		// A fresh clear, then an unrelated password change must conflict, including pre-write validation.
		wp_set_current_user( $actor );
		$reply     = ( new InlineWrite() )->write( $id, [ 'status' => 'private' ], 'inline', $actor );
		$batch     = (int) $reply['batch_id'];
		$batches[] = $batch;
		$old_plan  = ( new Planner() )->plan( $batch, $actor );
		// Simulate a later extension's native hook changing only the password.
		$later_hook = static function ( $data, $postarr ) use ( $id ): array {
			if ( (int) ( $postarr['ID'] ?? 0 ) === $id ) {
				$data['post_password'] = wp_slash( 'later <opaque> \\ password ' );
			} return $data;
		};
		add_filter( 'wp_insert_post_data', $later_hook, 10, 2 );
		try {
			wp_update_post(
				[
					'ID'          => $id,
					'post_status' => 'private',
				]
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $later_hook, 10 );}
		$later = $read();
		$plan  = ( new Planner() )->plan( $batch, $actor );
		$check( 'ordinary_undo_password_conflict', 1 === $plan->counts()['conflicted'] && in_array( PublicationPassword::FIELD, array_column( $plan->items()[0]['conflicts'], 'field' ), true ) );
		$counts = [
			'crud' => 0,
			'post' => 0,
		];
		$out    = ( new Writer( $actions, null, $actor ) )->write( $id, $old_plan->items()[0]['operations'] );
		$check(
			'prewrite_undo_conflict_no_save',
			( 0 === $counts['crud'] && 0 === $counts['post'] ) && $read() === $later && $out->results()[0]->state() === Result::SKIPPED
		);
		$check( 'conflict_serializers_redacted', ! str_contains( wp_json_encode( [ $plan->to_array(),( new \BulkCatalogueManager\Rest\UndoController() )->item( $plan->items()[0] ) ] ), 'opaque' ) );
	} elseif ( 'crash' === $a['case'] ) {
		foreach ( [ 'set_status', 'set_value' ] as $action ) {
			foreach ( [ 'before', 'after', 'partial' ] as $pause ) {
				foreach ( [ 'publish', 'edit' ] as $loss ) {
					$grant();
					wp_set_current_user( $actor );
					$reset( 'draft', true );
					$before = $read();
					$ops    = $step( $action, 'set_value' === $action ? 'publish' : 'private' );
					// A real header owns the protected intent rows; accepted legacy ops are passed unchanged to Writer.
					$batch     = ( new BatchFactory() )->open( $actor, $step( 'set_status', 'private' ), [ [ $id,'product' ] ] );
					$batches[] = $batch;
					$hook      = 'after' === $pause ? 'woocommerce_after_product_object_save' : 'woocommerce_before_product_object_save';
					$interrupt = static function ( $p ) use ( $id ): void {
						if ( (int) $p->get_id() === $id ) {
							throw new Error( 'Owned probe interruption' );
						} };
					add_action( $hook, $interrupt, 99 );
					wp_set_current_user( 0 );
					try {
						Writer::for_batch( $actions, $journal, $batch, $actor )->write( $id, $ops ); } catch ( Error $e ) {
						if ( 'Owned probe interruption' !== $e->getMessage() ) {
							throw $e; }
						} finally {
							remove_action( $hook, $interrupt, 99 ); }
						$pending = $journal->intents( $batch, $id );
						$check( $action . '_' . $pause . '_' . $loss . '_durable_intents', count( $pending ) === 3 );
						if ( 'partial' === $pause ) {
							// A partial native outcome: status and its native password clear landed, record did not.
							wp_update_post(
								[
									'ID'          => $id,
									'post_status' => 'private',
								]
							);
						}
						$landed = $read();
						$user   = new WP_User( $actor );
						$user->add_cap( 'publish' === $loss ? 'publish_products' : 'edit_products', false );
						if ( 'edit' === $loss ) {
							$user->add_cap( 'edit_private_products', false ); }
						$counts = [
							'crud' => 0,
							'post' => 0,
						];
						$out    = Writer::for_batch( $actions, $journal, $batch, $actor )->write( $id, $ops );
						$rows   = $changes->for_objects( $batch, [ $id ] );
						$states = array_column( $rows, 'result' );
						sort( $states );
						$expected = match ( $pause ) {
							'after'=>[ 'applied', 'applied', 'applied' ], 'partial'=>[ 'applied', 'applied', 'failed' ], default=>[ 'failed', 'failed', 'failed' ] };
					$check( $action . '_' . $pause . '_' . $loss . '_settlement', $states === $expected );
					$check(
						$action . '_' . $pause . '_' . $loss . '_no_new_save',
						( 0 === $counts['crud'] && 0 === $counts['post'] ) && $read() === $landed
					);
				}
			}
		}
	} elseif ( 'setter-failure' === $a['case'] ) {
		foreach ( [ false, true ] as $replay ) {
			$reset( 'draft', true );
			$before    = $read();
			$ops       = $step( 'set_status', 'private' );
			$batch     = ( new BatchFactory() )->open( $actor, $ops, [ [ $id,'product' ] ] );
			$batches[] = $batch;
			if ( $replay ) {
				$journal->intend(
					$batch,
					$id,
					'product',
					get_post_field( 'post_modified_gmt', $id, 'raw' ),
					[ [ Result::changed( 'status', 'draft', 'private' ),true ],[ Result::changed( PublicationPassword::FIELD, $before['password'], '' ),true ],[ Result::changed( OffRecord::FIELD, $before['record'], '' ),true ] ],
					[
						'status'                   => 'draft',
						PublicationPassword::FIELD => $before['password'],
						OffRecord::FIELD           => $before['record'],
					]
				); }
			Bulkcat_Native_Revoke_Product::$password_failure = true;
			$factory = static fn( $class, $type, $post_type, $pid )=> (int) $pid === $id ? Bulkcat_Native_Revoke_Product::class : $class;
			add_filter( 'woocommerce_product_class', $factory, 10, 4 );
			\BulkCatalogueManager\Engine\Memory::release();
			$observations['fault_class'][] = [ get_class( wc_get_product( $id ) ), wc_get_product( $id )->get_object_read() ];
			$counts                        = [
				'crud' => 0,
				'post' => 0,
			];
			try {
				$out = Writer::for_batch( $actions, $journal, $batch, $actor )->write( $id, $ops );
			} finally {
				remove_filter( 'woocommerce_product_class', $factory, 10 );
				Bulkcat_Native_Revoke_Product::$password_failure = false;}
			$observations[ $replay ? 'replay' : 'normal' ] = [
				'counts'               => $counts,
				'same_state'           => $read() === $before,
				'status'               => $read()['status'],
				'password_hash'        => hash( 'sha256', $read()['password'] ),
				'before_password_hash' => hash( 'sha256', $before['password'] ),
				'rows'                 => array_column( $changes->for_objects( $batch, [ $id ] ), 'result' ),
				'results'              => array_map( static fn( $r )=>[ $r->field(),$r->state(),$r->code() ], $out->results() ),
			];
			$check(
				( $replay ? 'replay' : 'normal' ) . '_native_setter_failure_coupled',
				( 0 === $counts['crud'] && 0 === $counts['post'] ) && $read() === $before && array_unique( array_column( $changes->for_objects( $batch, [ $id ] ), 'result' ) ) === [ 'failed' ]
			);
		}
	} elseif ( 'final-check' === $a['case'] ) {
		foreach ( [ 'draft', 'future' ] as $original ) {
			foreach ( [ false, true ] as $replay ) {
				$grant();
				wp_set_current_user( 0 );
				$reset();
				if ( 'future' === $original ) {
					wp_update_post(
						[
							'ID'            => $id,
							'post_status'   => 'future',
							'post_date'     => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
							'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
						]
					); }
				$ops       = $step( 'set_value', 'publish' );
				$batch     = ( new BatchFactory() )->open( $actor, $step( 'set_status', 'publish' ), [ [ $id,'product' ] ] );
				$batches[] = $batch;
				$revoke    = static function () use ( $actor ): void {
					( new WP_User( $actor ) )->add_cap( 'publish_products', false );
				};
				if ( $replay ) {
					$intents  = [ [ Result::changed( 'status', $original, 'publish' ), true ] ];
					$preimage = [ 'status' => $original ];
					if ( 'future' === $original ) {
						// Its status is already effective, but a protected password intent remains.
						$preimage[ PublicationPassword::FIELD ] = $read()['password'];
						$intents[]                              = [ Result::changed( PublicationPassword::FIELD, $preimage[ PublicationPassword::FIELD ], 'restored \\ opaque' ), true ];
					}
					$journal->intend( $batch, $id, 'product', get_post_field( 'post_modified_gmt', $id, 'raw' ), $intents, $preimage );
					Bulkcat_Native_Revoke_Product::$revoke_on_password = 'future' === $original;

					Bulkcat_Native_Revoke_Product::$actor = $actor;
					$factory                              = static fn( $class, $type, $post_type, $pid )=> (int) $pid === $id ? Bulkcat_Native_Revoke_Product::class : $class;
					add_filter( 'woocommerce_product_class', $factory, 10, 4 );
					\BulkCatalogueManager\Engine\Memory::release();
					$observations['fault_class'][] = [ get_class( wc_get_product( $id ) ), wc_get_product( $id )->get_object_read() ];
					$writer                        = Writer::for_batch( $actions, $journal, $batch, $actor );
				} else {
					$writer = new Writer( $actions, $revoke, $actor, $journal, $batch ); }
				$counts = [
					'crud' => 0,
					'post' => 0,
				];
				try {
					$out = $writer->write( $id, $ops ); } finally {
					if ( $replay ) {
						remove_filter( 'woocommerce_product_class', $factory, 10 );
						Bulkcat_Native_Revoke_Product::$revoke_on_password = false;}
					}
					$rows = $changes->for_objects( $batch, [ $id ] );
					$check( $original . '_' . ( $replay ? 'replay' : 'normal' ) . '_after_setters_revoked', ! user_can( $actor, 'publish_products' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Native WooCommerce capability.
					$check(
						$original . '_' . ( $replay ? 'replay' : 'normal' ) . '_trustworthy_preimage',
						'draft' === $original ? ( ( 0 === $counts['crud'] && 0 === $counts['post'] ) && 'draft' === $read()['status'] && 'failed' === $rows[0]['result'] ) : ( ( 1 === $counts['crud'] && 1 === $counts['post'] ) && 'future' === $read()['status'] && 'applied' === $rows[0]['result'] )
					);
			}
		}
	} elseif ( 'hooks' === $a['case'] ) {
		$reset();
		$wp_filter_before = $GLOBALS['wp_filter']['wp_insert_post_data'] ?? null;
		$p                = wc_get_product( $id );
		$p->set_status( 'pending' );
		$competing = static function ( $data, $postarr ) use ( $id ): array {
			if ( (int) ( $postarr['ID'] ?? 0 ) === $id ) {
				$data['post_password'] = wp_slash( ' intentional \\ replacement ' );
				$data['post_name']     = 'intentional-slug';
			} return $data;
		};
		add_filter( 'wp_insert_post_data', $competing, 10, 2 );
		try {
			$out = ( new Writer( $actions, null, $actor ) )->write( $id, $step( 'set_status', 'pending' ) ); } finally {
			remove_filter( 'wp_insert_post_data', $competing, 10 ); }
			$check( 'competing_hook_intent_preserved', ' intentional \\ replacement ' === $read()['password'] && 'intentional-slug' === get_post_field( 'post_name', $id, 'raw' ) );
			$reset();
			// Restore the actual synthetic slug before the later fingerprint check.
			wp_update_post(
				[
					'ID'        => $id,
					'post_name' => $a['slug'],
				]
			);
		$throw = static function ( $data, $postarr ) use ( $id ) {
			if ( (int) ( $postarr['ID'] ?? 0 ) === $id ) {
				throw new Error( 'Owned filter interruption' );
			} return $data;
		};
		add_filter( 'wp_insert_post_data', $throw, 10, 2 );
		try {
			( new Writer( $actions, null, $actor ) )->write( $id, $step( 'set_status', 'pending' ) ); } catch ( Error $e ) {
			$check( 'exception_reached', 'Owned filter interruption' === $e->getMessage() ); } finally {
				remove_filter( 'wp_insert_post_data', $throw, 10 ); }
			$check( 'exception_filter_cleanup', $hook_shape() === $hooks_before );
			// Private hook replaces the planned clear: readback and journal must describe the actual replacement.
			$reset();
			$batch     = ( new BatchFactory() )->open( $actor, $step( 'set_status', 'private' ), [ [ $id,'product' ] ] );
			$batches[] = $batch;
			add_filter( 'wp_insert_post_data', $competing, 10, 2 );
			try {
				$out = Writer::for_batch( $actions, $journal, $batch, $actor )->write( $id, $step( 'set_status', 'private' ) ); } finally {
						remove_filter( 'wp_insert_post_data', $competing, 10 ); }
				$rows = $changes->for_objects( $batch, [ $id ] );
				$pw   = array_values( array_filter( $rows, static fn( $r )=>PublicationPassword::FIELD === $r['prop'] ) );
				$r    = array_values( array_filter( $out->results(), static fn( $r )=>PublicationPassword::FIELD === $r->field() ) );
				$check( 'competing_hook_truthful_password_readback', $pw[0]['new_value'] === $read()['password'] && $r[0]->new() === $read()['password'] );
				wp_update_post(
					[
						'ID'        => $id,
						'post_name' => $a['slug'],
					]
				);
	} else {
		throw new RuntimeException( 'Unknown lifecycle case.' ); }
} finally {
	remove_action( 'woocommerce_before_product_object_save', $watch_crud );
	remove_action( 'pre_post_update', $watch_post );
	$grant();
	wp_set_current_user( $original_user );
}
bulkcat_out(
	[
		'checks'         => $checks,
		'batches'        => $batches,
		'password_hash'  => hash( 'sha256', $secret ),
		'password_bytes' => strlen( $secret ),
		'observations'   => $observations,
	]
);
