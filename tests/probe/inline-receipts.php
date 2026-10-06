<?php
/**
 * Receipt qualification, loaded only in an explicitly owned WP-CLI process.
 * Four phases: inspect, before, assert, cleanup. Never install this as a plugin.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );

/** Read exactly one bounded MySQL packet, without retaining/logging its payload. */
function bulkcat_receipt_packet( $stream ): string {
	$header = '';
	$length = 0;
	while ( $length < 4 && ! feof( $stream ) ) {
		$header .= fread( $stream, 4 - $length );
		$length  = strlen( $header );
		if ( stream_get_meta_data( $stream )['timed_out'] ) {
			throw new RuntimeException( 'Packet header timeout.' ); }
	}
	if ( 4 !== strlen( $header ) ) {
		throw new RuntimeException( 'Incomplete packet header.' ); }
	$size = ord( $header[0] ) + ( ord( $header[1] ) << 8 ) + ( ord( $header[2] ) << 16 );
	if ( $size > 1048576 ) {
		throw new RuntimeException( 'Packet exceeds bounded receipt probe.' ); }
	$payload = '';
	$length  = 0;
	while ( $length < $size && ! feof( $stream ) ) {
		$payload .= fread( $stream, $size - $length );
		$length   = strlen( $payload );
		if ( stream_get_meta_data( $stream )['timed_out'] ) {
			throw new RuntimeException( 'Packet body timeout.' ); }
	}
	if ( strlen( $payload ) !== $size ) {
		throw new RuntimeException( 'Incomplete packet body.' ); }
	return $header . $payload;
}

/** Exact one-client proxy, private loopback inside the ephemeral CLI container. */
function bulkcat_receipt_proxy( string $host ): void {
	$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
	if ( ! $server ) {
		throw new RuntimeException( 'Cannot bind ephemeral loopback proxy.' ); }
	echo json_encode(
		[
			'event'   => 'proxy_listening',
			'address' => stream_socket_get_name( $server, false ),
			'pid'     => getmypid(),
		]
	) . "\n";
	flush();
	$client = stream_socket_accept( $server, 20 );
	$db     = stream_socket_client( 'tcp://' . $host, $errno, $error, 20 );
	if ( ! $client || ! $db ) {
		throw new RuntimeException( 'Cannot connect owned proxy.' ); }
	stream_set_timeout( $client, 20 );
	stream_set_timeout( $db, 20 );
	$deadline = microtime( true ) + 60;
	$commit   = false;
	try {
		while ( microtime( true ) < $deadline ) {
			$read   = [ $client, $db ];
			$write  = null;
			$except = null;
			if ( ! stream_select( $read, $write, $except, 10 ) ) {
				continue; }
			foreach ( $read as $source ) {
				$packet = bulkcat_receipt_packet( $source );
				if ( $source === $client && substr( $packet, 4 ) === "\x03COMMIT" ) {
					$commit = true; }
				if ( $source === $db && $commit ) {
					// Actual server OK is read here, never delivered to mysqli in the parent.
					if ( ord( $packet[4] ) !== 0 ) {
						throw new RuntimeException( 'COMMIT did not return OK.' ); }
					$observation = fopen( 'php://fd/3', 'w' );
					fwrite(
						$observation,
						json_encode(
							[
								'event'    => 'commit_ok_discarded',
								'sequence' => ord( $packet[3] ),
								'bytes'    => strlen( $packet ),
								'time'     => microtime( true ),
							]
						) . "\n"
					);
					fflush( $observation );
					fclose( $observation );
					return;
				}
				$target = $source === $client ? $db : $client;
				$offset = 0;
				$length = strlen( $packet );
				while ( $offset < $length ) {
					$sent = fwrite( $target, substr( $packet, $offset ) );
					if ( ! $sent ) {
						throw new RuntimeException( 'Proxy write failed.' ); }
					$offset += $sent;
				}
			}
		}
		throw new RuntimeException( 'Proxy exceeded its deadline.' );
	} finally {
		fclose( $client );
		fclose( $db );
		fclose( $server ); }
}

// The standalone child receives no credentials and never loads WordPress.
if ( PHP_SAPI === 'cli' && ( $argv[1] ?? '' ) === '--wire-proxy' && preg_match( '/^[a-f0-9-]{36}$/D', $argv[3] ?? '' ) ) {
	bulkcat_receipt_proxy( $argv[2] );
	exit;
}

/** Emit identities/counters only. Never emit credentials or SQL/wire payloads. */
function bulkcat_receipt_event( array $event ): void {
	echo wp_json_encode( $event + [ 'at' => microtime( true ) ] ) . "\n";
	flush();
}

require_once __DIR__ . '/fixture.php';
$a = $bulkcat_receipt_args ?? [];
putenv( 'BULKCAT_PROBE_SITEURL=' . ( $a['siteurl'] ?? '' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Explicit local guard in this test-only CLI process.
if ( ! bulkcat_probe_fixture() || get_option( 'siteurl' ) !== ( $a['siteurl'] ?? '' ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $a['run'] ?? '' ) || ! in_array( $a['phase'] ?? '', [ 'inspect', 'before', 'assert', 'cleanup' ], true ) ) {
	throw new RuntimeException( 'Explicit local site, phase and run UUID required.' );
}
$bulkcat_native_args = [
	'run'   => $a['run'],
	'phase' => 'library',
];
require_once __DIR__ . '/native-status.php';
$a = $bulkcat_receipt_args; // The imported CLI library uses a local $a too.
global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Exact owned fixture rows; never application code or business-data SQL writes.
bulkcat_receipt_event(
	[
		'event'      => 'process',
		'container'  => gethostname(),
		'pid'        => getmypid(),
		'connection' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ),
	]
);

/** Current product, exact batch/item/receipt identities and exact queue args. */
function bulkcat_receipt_observe( array $args ): array {
	global $wpdb;
	$products = [];
	foreach ( $args['ids'] as $id ) {
		bulkcat_native_owned( $id, $args['run'] );
		$p          = wc_get_product( $id );
		$products[] = [
			'id'         => $id,
			'price'      => $p->get_regular_price(),
			'visibility' => $p->get_catalog_visibility(),
		];
	}
	$batches = $wpdb->get_results( $wpdb->prepare( "SELECT batch_id,uuid,status,total_items,done_items,changed_items,failed_items,selection FROM {$wpdb->prefix}bulkcat_batches WHERE user_id=%d ORDER BY batch_id", $args['user'] ), ARRAY_A );
	$items   = [];
	$queue   = [];
	foreach ( $batches as $batch ) {
		$id           = (int) $batch['batch_id'];
		$items[ $id ] = $wpdb->get_results( $wpdb->prepare( "SELECT item_id,object_id,state,attempts FROM {$wpdb->prefix}bulkcat_batch_items WHERE batch_id=%d ORDER BY item_id", $id ), ARRAY_A );
		$queue[ $id ] = as_get_scheduled_actions(
			[
				'hook'     => 'bulkcat_run_chunk',
				'args'     => [ $id ],
				'per_page' => 100,
			],
			'ids'
		);
	}
	$receipts = $wpdb->get_results( $wpdb->prepare( "SELECT uuid,operation,state,result_kind,result_id FROM {$wpdb->prefix}bulkcat_requests WHERE user_id=%d ORDER BY uuid", $args['user'] ), ARRAY_A );
	return compact( 'products', 'batches', 'items', 'queue', 'receipts' );
}

/** A bounded STDIN barrier; timeout fails rather than releasing the original. */
function bulkcat_receipt_barrier( string $boundary ): void {
	bulkcat_receipt_event(
		[
			'event'    => 'barrier',
			'boundary' => $boundary,
		]
	);
	$stdin = fopen( 'php://stdin', 'r' );
	if ( ! is_resource( $stdin ) ) {
		throw new RuntimeException( 'Cannot open owned barrier input.' );
	}
	try {
		if ( ! stream_set_blocking( $stdin, false ) ) {
			throw new RuntimeException( 'Cannot make owned barrier input nonblocking.' );
		}
		$deadline = hrtime( true ) + 45 * 1000000000;
		$token    = "release\n";
		$buffer   = '';
		while ( true ) {
			$left = $deadline - hrtime( true );
			if ( $left <= 0 ) {
				throw new RuntimeException( 'Owned barrier not released within 45 seconds.' );
			}
			$read   = [ $stdin ];
			$write  = null;
			$except = null;
			$ready  = stream_select( $read, $write, $except, intdiv( $left, 1000000000 ), intdiv( $left % 1000000000, 1000 ) );
			if ( false === $ready ) {
				throw new RuntimeException( 'Cannot select owned barrier input.' );
			}
			if ( 0 === $ready ) {
				continue;
			}
			if ( hrtime( true ) >= $deadline ) {
				throw new RuntimeException( 'Owned barrier not released within 45 seconds.' );
			}
			$part = fread( $stdin, strlen( $token ) + 1 - strlen( $buffer ) );
			if ( false === $part || ( '' === $part && feof( $stdin ) ) ) {
				throw new RuntimeException( 'Owned barrier input closed before release.' );
			}
			$buffer .= $part;
			if ( ! str_starts_with( $token, $buffer ) ) {
				throw new RuntimeException( 'Invalid owned barrier release token.' );
			}
			if ( $buffer === $token ) {
				return;
			}
		}
	} finally {
		fclose( $stdin );
	}
}

/** Dispatch actual registered REST controllers, with real native storage. */
function bulkcat_receipt_rest( string $method, string $route, array $data ): array {
	$r = new WP_REST_Request( $method, '/bulkcat/v1/' . $route );
	$r->set_header( 'Content-Type', 'application/json' );
	$r->set_body( wp_json_encode( $data ) );
	$response = rest_do_request( $r );
	return [
		'status' => $response->get_status(),
		'json'   => $response->get_data(),
	];
}

if ( 'inspect' === $a['phase'] ) {
	bulkcat_receipt_event(
		[
			'event' => 'result',
			'data'  => [
				'baseline'      => bulkcat_native_baseline(),
				'versions'      => [
					'wp'      => get_bloginfo( 'version' ),
					'wc'      => WC_VERSION,
					'php'     => PHP_VERSION,
					'db'      => $wpdb->get_var( 'SELECT VERSION()' ),
					'manager' => \BulkCatalogueManager\Plugin::VERSION,
				],
				'native_hashes' => [
					'writer'  => hash_file( 'sha256', dirname( __DIR__, 2 ) . '/src/Engine/Writer.php' ),
					'service' => hash_file( 'sha256', dirname( __DIR__, 2 ) . '/src/Requests/RequestService.php' ),
					'bundle'  => hash_file( 'sha256', dirname( __DIR__, 2 ) . '/assets/build/index.js' ),
				],
			],
		]
	);
	return;
}
if ( 'before' === $a['phase'] ) {
	bulkcat_receipt_event(
		[
			'event' => 'baseline',
			'data'  => bulkcat_native_baseline(),
		]
	);
	$user = wp_insert_user(
		[
			'user_login' => 'receipt-' . $a['run'],
			'user_pass'  => $a['password'],
			'user_email' => 'receipt-' . $a['run'] . '@example.test',
			'role'       => 'administrator',
		]
	);
	if ( is_wp_error( $user ) ) {
		throw new RuntimeException( 'Cannot create owned actor.' );
	}
	update_user_meta( $user, '_bulkcat_native_probe', $a['run'] );
	bulkcat_receipt_event(
		[
			'event' => 'owned_user',
			'id'    => $user,
		]
	);
	wp_set_current_user( $user );
	for ( $i = 0; $i < 14; ++$i ) {
		$p = 12 === $i ? new WC_Product_Variable() : ( 13 === $i ? new WC_Product_Variation() : new WC_Product_Simple() );
		if ( 13 === $i ) {
			$p->set_parent_id( $parent ); }
		$p->set_name( 'Receipt ' . $a['run'] . ' ' . $i );
		$p->set_status( 'publish' );
		$p->set_regular_price( '17.25' );
		$p->set_catalog_visibility( 'hidden' );
		$p->update_meta_data( '_bulkcat_native_probe', $a['run'] );
		$p->save();
		$parent = $p->get_id();
		bulkcat_receipt_event(
			[
				'event' => 'owned_product',
				'id'    => $p->get_id(),
				'name'  => $p->get_name(),
			]
		);
	}
	bulkcat_receipt_event(
		[
			'event' => 'result',
			'data'  => true,
		]
	);
	return;
}
if ( get_user_meta( $a['user'], '_bulkcat_native_probe', true ) !== $a['run'] ) {
	throw new RuntimeException( 'Actor not owned.' );
}
foreach ( $a['ids'] as $id ) {
	bulkcat_native_owned( $id, $a['run'] );
}
wp_set_current_user( $a['user'] );
if ( 'cleanup' === $a['phase'] ) {
	foreach ( array_reverse( $a['ids'] ) as $id ) {
		wc_get_product( $id )->delete( true );
	}
	$drafts = get_posts(
		[
			'author'      => $a['user'],
			'post_type'   => 'any',
			'post_status' => 'auto-draft',
			'numberposts' => -1,
		]
	);
	foreach ( $drafts as $draft ) {
		wp_delete_post( $draft->ID, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $a['user'] );
	bulkcat_receipt_event(
		[
			'event' => 'result',
			'data'  => [
				'remaining'    => array_values( array_filter( $a['ids'], 'get_post' ) ),
				'user_removed' => ! get_user_by( 'id', $a['user'] ),
				'baseline'     => bulkcat_native_baseline(),
			],
		]
	);
	return;
}
if ( 'barrier_check' === ( $a['action'] ?? '' ) ) {
	bulkcat_receipt_barrier( 'deadline_qualification' );
	bulkcat_receipt_event(
		[
			'event' => 'result',
			'data'  => true,
		]
	);
	return;
}

$hooks        = 0;
$post_updates = 0;
add_action(
	'woocommerce_after_product_object_save',
	static function ( $product ) use ( &$hooks, $a ): void {
		if ( in_array( $product->get_id(), $a['ids'], true ) ) {
			++$hooks;
			bulkcat_receipt_event(
				[
					'event'      => 'save_hook',
					'id'         => $product->get_id(),
					'count'      => $hooks,
					'tax_status' => $product->get_tax_status( 'edit' ),
				]
			);
			if ( 'mid_write' === ( $a['fault'] ?? '' ) ) {
				bulkcat_receipt_barrier( 'after_native_save_before_journal_settlement' );
			}
		}
	}
);
add_action(
	'post_updated',
	static function ( $id ) use ( &$post_updates, $a ): void {
		if ( in_array( $id, $a['ids'], true ) ) {
			++$post_updates;
		}
	}
);
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true ); }
add_filter(
	'log_query_custom_data',
	static function ( array $data, string $query, float $seconds ) use ( $a ): array {
		if ( str_starts_with( $query, 'INSERT INTO' ) && str_contains( $query, 'bulkcat_requests' ) ) {
			bulkcat_receipt_event(
				[
					'event'   => 'claim_statement_returned',
					'seconds' => $seconds,
					'time'    => microtime( true ),
				]
			);
		}
		return $data;
	},
	10,
	3
);
$commits  = 0;
$accepted = false;
add_filter(
	'query',
	static function ( string $sql ) use ( &$commits, &$accepted, $a ): string {
		if ( $accepted ) {
			$accepted = false;
			if ( 'accepted' === ( $a['fault'] ?? '' ) ) {
				bulkcat_receipt_barrier( 'confirmed_acceptance_before_execution' );
			}
		}
		if ( str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, 'bulkcat_requests' ) ) {
			bulkcat_receipt_event(
				[
					'event' => 'claim_statement_entered',
					'time'  => microtime( true ),
				]
			);
		}
		if ( 'COMMIT' === $sql && 1 === ++$commits ) {
			if ( 'claim' === ( $a['fault'] ?? '' ) ) {
				bulkcat_receipt_barrier( 'uncommitted_unique_claim' );
			}
			$accepted = true;
		}
		return $sql;
	}
);
$action = $a['action'] ?? 'observe';
$data   = null;
if ( 'observe' === $action ) {
	$data = bulkcat_receipt_observe( $a );
} elseif ( 'compete' === $action ) {
	if ( ! in_array( $a['object'], $a['ids'], true ) ) {
		throw new RuntimeException( 'Competing object not owned.' ); }
	$product = wc_get_product( $a['object'] );
	if ( 'parent-tax' === ( $a['change'] ?? '' ) ) {
		$product->set_tax_status( 'none' ); } else {
		$product->set_regular_price( '15' ); }
		$product->save();
		$data = true;
} elseif ( 'rights' === $action ) {
	$u = new WP_User( $a['user'] );
	if ( $a['deny'] ) {
		$u->add_cap( 'edit_products', false );
		$u->add_cap( 'edit_published_products', false );
	} else {
		$u->remove_cap( 'edit_products' );
		$u->remove_cap( 'edit_published_products' );
	}
	$data = [ 'can_edit' => user_can( $u, 'edit_products' ) ]; // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Native WooCommerce capability.
} elseif ( 'deliver' === $action ) {
	$batch = ( new \BulkCatalogueManager\Data\BatchRepository() )->find( $a['batch'] );
	if ( ! $batch || $batch['user_id'] !== $a['user'] || ! in_array( $batch['selection']['inline_receipt']['object_id'] ?? 0, $a['ids'], true ) ) {
		throw new RuntimeException( 'Batch not owned.' );
	}
	$data = [ 'queued' => \BulkCatalogueManager\Engine\ChunkRunner::ensure_queued( $a['batch'] ) ];
	\BulkCatalogueManager\Engine\ChunkRunner::handle( $a['batch'] );
	$data['cancel']      = ( new \BulkCatalogueManager\Engine\Progress() )->cancel( $a['batch'] );
	$data['undo']        = bulkcat_receipt_rest( 'POST', 'batches/' . $a['batch'] . '/undo', [ 'preview' => true ] );
	$data['history']     = bulkcat_receipt_rest( 'GET', 'batches/' . $a['batch'], [] );
	$data['observation'] = bulkcat_receipt_observe( $a );
} elseif ( 'prune' === $action ) {
	// Simulate an exact receipt being absent; never invoke global retention cleanup.
	$uuid = $a['grant']['uuid'];
	$data = $wpdb->delete(
		$wpdb->prefix . 'bulkcat_requests',
		[
			'user_id' => $a['user'],
			'uuid'    => $uuid,
		],
		[ '%d', '%s' ]
	);
} elseif ( 'rest' === $action ) {
	$route = $a['route'];
	if ( ! in_array( $route, [ 'requests', 'requests/resolve' ], true ) && ! in_array( $route, array_map( static fn( $id ) => 'products/' . $id, $a['ids'] ), true ) ) {
		throw new RuntimeException( 'Only owned receipt routes allowed.' );
	}
	if ( isset( $a['data']['intent']['object_id'] ) && ! in_array( $a['data']['intent']['object_id'], $a['ids'], true ) ) {
		throw new RuntimeException( 'Intent object not owned.' );
	}
	if ( 'commit_ack' === ( $a['fault'] ?? '' ) ) {
		// Reconnect only this CLI process through its private one-client loopback proxy.
		$host   = $wpdb->dbhost;
		$target = str_contains( $host, ':' ) ? $host : $host . ':3306';
		$proxy  = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Owned one-client fault process; unavailable support must fail qualification.
			[ PHP_BINARY, __FILE__, '--wire-proxy', $target, $a['run'] ],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
				// Survives native wpdb fatal exit before our shutdown handler can run.
				3 => STDOUT,
			],
			$pipes
		);
		if ( ! is_resource( $proxy ) ) {
			throw new RuntimeException( 'No scoped proxy process capability.' ); }
		stream_set_timeout( $pipes[1], 30 );
		$hello = json_decode( fgets( $pipes[1] ), true );
		if ( 'proxy_listening' !== ( $hello['event'] ?? '' ) ) {
			throw new RuntimeException( 'Proxy failed to start.' ); }
		bulkcat_receipt_event( $hello );
		$finished     = false;
		$finish_proxy = static function () use ( &$finished, $pipes, $proxy ): void {
			if ( $finished ) {
				return; }
			$finished = true;
			foreach ( explode( "\n", trim( stream_get_contents( $pipes[1] ) ) ) as $line ) {
				if ( '' !== $line ) {
					bulkcat_receipt_event( json_decode( $line, true, 512, JSON_THROW_ON_ERROR ) ); }
			}
			$diagnostic = stream_get_contents( $pipes[2] );
			foreach ( $pipes as $pipe ) {
				fclose( $pipe ); }
			$exit = proc_close( $proxy );
			bulkcat_receipt_event(
				[
					'event'      => 'proxy_exit',
					'exit'       => $exit,
					'diagnostic' => $diagnostic,
				]
			);
		};
		// Native wpdb/WP-CLI may exit on connection loss rather than throw.
		register_shutdown_function( $finish_proxy );
		$wpdb->close();
		$wpdb->dbhost = $hello['address'];
		if ( ! $wpdb->db_connect( false ) ) {
			throw new RuntimeException( 'Proxy connection failed.' ); }
		$errors = $wpdb->suppress_errors( true );
		try {
			$data = bulkcat_receipt_rest( $a['method'], $route, $a['data'] ); } finally {
					$finish_proxy();
					$wpdb->dbhost = $host;
			$wpdb->db_connect( false );
			$wpdb->suppress_errors( $errors );
			}
	} else {
		$data = bulkcat_receipt_rest( $a['method'], $route, $a['data'] );
	}
} elseif ( 'lock' === $action ) {
	// SHOW PROCESSLIST requires no extra privilege for this user's own sessions.
	$data = [];
	foreach ( $wpdb->get_results( 'SHOW PROCESSLIST', ARRAY_A ) as $row ) {
		if ( in_array( (int) $row['Id'], [ $a['connection'], $a['original'] ], true ) ) {
			$data[] = [
				'id'          => (int) $row['Id'],
				'command'     => $row['Command'],
				'state'       => $row['State'],
				'seconds'     => (int) $row['Time'],
				'observed_at' => microtime( true ),
			];
		}
	}
} else {
	throw new RuntimeException( 'Unknown owned action.' );
}
bulkcat_receipt_event(
	[
		'event'        => 'result',
		'data'         => $data,
		'hooks'        => $hooks,
		'post_updates' => $post_updates,
	]
);
