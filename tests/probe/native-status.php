<?php
/**
 * Native Status fixture setup/readback. Loaded only by native-status.py through
 * guarded WP-CLI eval; never installed as a plugin or included by runtime code.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! bulkcat_probe_fixture() || ! preg_match( '/^[a-f0-9-]{36}$/D', $bulkcat_native_args['run'] ?? '' ) ) {
	throw new RuntimeException( 'Native Status needs an explicitly owned local fixture and UUID.' );
}

/** Exact run ownership, verified again before reads, mutations and deletion. */
function bulkcat_native_owned( int $id, string $run ): void {
	if ( get_post_meta( $id, '_bulkcat_native_probe', true ) !== $run ) {
		throw new RuntimeException( 'Refusing an object outside this probe UUID.' );
	}
}

/** Native state and unrelated values; no password in public report files. */
function bulkcat_native_read( int $id, string $run, int $actor ): array {
	bulkcat_native_owned( $id, $run );
	clean_post_cache( $id );
	$p    = wc_get_product( $id );
	$post = get_post( $id );
	$data = $p->get_data();
	$keep = array_intersect_key( $data, array_flip( [ 'name', 'sku', 'description', 'short_description', 'regular_price', 'sale_price', 'stock_status', 'stock_quantity', 'manage_stock', 'catalog_visibility', 'category_ids', 'tag_ids', 'weight', 'length', 'width', 'height', 'slug', 'menu_order', 'attributes', 'virtual', 'downloadable' ] ) );
	return [
		'id'                    => $id,
		'kind'                  => $post->post_type,
		'parent'                => $p->get_parent_id(),
		'status'                => $post->post_status,
		'date'                  => $post->post_date,
		'date_gmt'              => $post->post_date_gmt,
		'crud_date'             => $p->get_date_created( 'edit' ) ? $p->get_date_created( 'edit' )->getTimestamp() : null,
		'password_set'          => '' !== $post->post_password,
		'password_sha256'       => hash( 'sha256', $post->post_password ),
		'crud_password_matches' => $post->post_password === $p->get_post_password( 'edit' ),
		'record'                => get_post_meta( $id, '_bulkcat_availability_off', true ),
		'unrelated'             => $keep,
		'fingerprint'           => hash( 'sha256', wp_json_encode( $keep ) ),
		'modified_gmt'          => $post->post_modified_gmt,
		'event'                 => [
			'hook'      => 'publish_future_post',
			'args'      => [ $id ],
			'timestamp' => wp_next_scheduled( 'publish_future_post', [ $id ] ),
		],
		'actor'                 => $actor,
		'mapped_edit_caps'      => map_meta_cap( 'edit_post', $actor, $id ),
		'caps'                  => [
			'edit_products'    => user_can( $actor, 'edit_products' ), // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			'edit_post'        => user_can( $actor, 'edit_post', $id ),
			'edit_parent'      => ! $p->get_parent_id() || user_can( $actor, 'edit_post', $p->get_parent_id() ),
			'publish_products' => user_can( $actor, 'publish_products' ), // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
		],
	];
}

/** All non-probe catalogue records for before/after identity verification. */
function bulkcat_native_baseline(): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Uncached native fixture identity, not application access.
	global $wpdb;
	$posts = $wpdb->get_results( "SELECT ID,post_type,post_status,post_title,post_date,post_date_gmt,SHA2(post_password,256) AS password_sha256,post_parent FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID", ARRAY_A );
	$terms = $wpdb->get_results( "SELECT t.term_id,t.name,t.slug,tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat' ORDER BY t.term_id", ARRAY_A );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	return [
		'products'   => $posts,
		'categories' => $terms,
	];
}

$a = $bulkcat_native_args;
switch ( $a['phase'] ) {
	case 'inspect':
		$methods = [];
		foreach ( [ 'get_post_password', 'set_post_password', 'get_date_created', 'set_date_created' ] as $name ) {
			$m                = new ReflectionMethod( WC_Product::class, $name );
			$methods[ $name ] = [
				'public' => $m->isPublic(),
				'class'  => $m->getDeclaringClass()->getName(),
				'line'   => $m->getStartLine(),
				'file'   => str_replace( ABSPATH, '', $m->getFileName() ),
			];
		}
		$api = new WC_Product_Simple();
		$api->set_post_password( 'native-api-check' );
		$api->set_date_created( 1793511000 );
		bulkcat_out(
			[
				'api_in_memory' => [
					'password_roundtrip' => 'native-api-check' === $api->get_post_password( 'edit' ),
					'date_timestamp'     => $api->get_date_created( 'edit' )->getTimestamp(),
					'saved'              => false,
				],
				'wp'            => get_bloginfo( 'version' ),
				'wc'            => WC_VERSION,
				'php'           => PHP_VERSION,
				'manager'       => \BulkCatalogueManager\Plugin::VERSION,
				'methods'       => $methods,
				'timezone'      => get_option( 'timezone_string' ),
				'offset'        => get_option( 'gmt_offset' ),
				'baseline'      => bulkcat_native_baseline(),
			]
		);
		break;
	case 'users':
		$users = [];
		foreach ( [ 'publisher', 'editor', 'other', 'removed', 'variation_editor', 'deleted' ] as $name ) {
			$login = 'native-' . $a['run'] . '-' . $name;
			$id    = wp_insert_user(
				[
					'user_login' => $login,
					'user_pass'  => $a['password'],
					'user_email' => $login . '@example.test',
					'role'       => 'subscriber',
				]
			);
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( $id->get_error_message() ); }
			update_user_meta( $id, '_bulkcat_native_probe', $a['run'] );
			$user = new WP_User( $id );
			foreach ( [ 'manage_woocommerce', 'edit_products', 'edit_published_products', 'edit_private_products', 'read_private_products' ] as $cap ) {
				$user->add_cap( $cap ); }
			if ( in_array( $name, [ 'publisher', 'removed', 'deleted' ], true ) ) {
				$user->add_cap( 'publish_products' ); }
			if ( in_array( $name, [ 'variation_editor', 'other' ], true ) ) {
				$user->add_cap( 'edit_product' );
			}
			$users[ $name ] = [
				'id'    => $id,
				'login' => $login,
			];
		}
		bulkcat_out( $users );
		break;
	case 'timezone':
		update_option( 'timezone_string', $a['timezone'] );
		bulkcat_out( get_option( 'timezone_string' ) );
		break;
	case 'create':
		// Fixture setup only: native draft/pending slug rules depend on current
		// user. This identity is never borrowed by manager HTTP or worker saves.
		wp_set_current_user( $a['fixture_admin'] );
		$r       = $a['row'];
		$ids     = [];
		$parents = [];
		$date    = match ( $r['date'] ) {
			'ahead' => time() + 86400, 'dst' => strtotime( '2026-11-01 05:30:00 UTC' ), default => strtotime( '2026-01-15 12:00:00 UTC' ) };
		if ( isset( $r['fixed_gmt'] ) ) {
			$date = strtotime( $r['fixed_gmt'] . ' UTC' );
		}
		foreach ( [ 'native', 'manager' ] as $side ) {
			$parent = 0;
			if ( 'variation' === ( $r['kind'] ?? '' ) ) {
				$p = new WC_Product_Variable();
				$p->set_name( 'Native Status ' . $a['run'] );
				$p->set_status( 'publish' );
				$p->update_meta_data( '_bulkcat_native_probe', $a['run'] );
				$parent    = $p->save();
				$parents[] = $parent;
				wp_update_post(
					[
						'ID'          => $parent,
						'post_author' => $a['author'],
					]
				);
			}
			$p = $parent ? new WC_Product_Variation() : new WC_Product_Simple();
			$p->set_name( 'Native Status ' . $a['run'] );
			$p->set_parent_id( $parent );
			$p->set_slug( 'native-' . $a['run'] . '-' . $r['case'] . '-' . $side );
			if ( $parent ) {
				$p->set_menu_order( 1 ); }
			$p->set_status( $r['initial'] );
			$p->set_date_created( $date );
			$p->set_regular_price( '17.25' );
			$p->set_weight( '1.25' );
			$p->set_description( 'Unrelated description' );
			$p->set_post_password( $r['password'] ?? '' );
			$p->update_meta_data( '_bulkcat_native_probe', $a['run'] );
			$id = $p->save();
			wp_update_post(
				[
					'ID'          => $id,
					'post_author' => $a['author'],
				]
			);
			if ( in_array( $r['record'], [ 'own', 'foreign' ], true ) ) {
				$record = [
					'id'     => 'own' === $r['record'] ? $id : $id + 1000000,
					'status' => $r['remembered'] ?? $r['initial'],
					'at'     => '2026-09-01T08:00:00Z',
					'batch'  => 7,
				];
				update_post_meta( $id, '_bulkcat_availability_off', wp_json_encode( $record ) );
			} elseif ( 'malformed' === $r['record'] ) {
				update_post_meta( $id, '_bulkcat_availability_off', '{malformed' ); }
			// Explicit malformed-date fixture; not a product implementation write.
			if ( isset( $r['gmt'] ) ) {
				global $wpdb;
				$wpdb->update( $wpdb->posts, [ 'post_date_gmt' => '0000-00-00 00:00:00' ], [ 'ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Deliberate malformed-date fixture, never a runtime writer.
				clean_post_cache( $id );
			}
			$ids[ $side ] = $id;
		}
		bulkcat_out(
			[
				'ids'     => $ids,
				'parents' => $parents,
				'before'  => array_map( static fn( $id ) => bulkcat_native_read( $id, $a['run'], $a['actor'] ), $ids ),
			]
		);
		break;
	case 'read':
		bulkcat_out( array_map( static fn( $id ) => bulkcat_native_read( (int) $id, $a['run'], $a['actor'] ), $a['ids'] ) );
		break;
	case 'revoke':
		if ( get_user_meta( $a['actor'], '_bulkcat_native_probe', true ) !== $a['run'] ) {
			throw new RuntimeException( 'Not an owned actor.' ); }
		if ( ! empty( $a['delete'] ) ) {
			// Preserve the exact owned fixture objects when deleting this actor;
			// deleting a WordPress author without reassignment deletes their posts.
			foreach ( get_posts(
				[
					'post_type'   => [ 'product', 'product_variation' ],
					'post_status' => array_keys( get_post_stati() ),
					'author'      => $a['actor'],
					'fields'      => 'ids',
					'numberposts' => -1,
				]
			) as $id ) {
				bulkcat_native_owned( (int) $id, $a['run'] );
			}
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $a['actor'], $a['fixture_admin'] );
			bulkcat_out( ! get_userdata( $a['actor'] ) );
			break;
		}
		$user = new WP_User( $a['actor'] );
		$user->remove_all_caps();
		$user->set_role( 'subscriber' );
		bulkcat_out( true );
		break;
	case 'cleanup':
		// Recover even a partially returned create/users phase by exact UUID,
		// never by a prefix or by the fixture's baseline IDs.
		if ( ! empty( $a['final'] ) ) {
			$a['ids']   = get_posts(
				[
					'post_type'   => [ 'product_variation', 'product' ],
					'post_status' => array_keys( get_post_stati() ),
					'fields'      => 'ids',
					'numberposts' => -1,
					'meta_key'    => '_bulkcat_native_probe',
					'meta_value'  => $a['run'],
					'orderby'     => 'post_parent',
					'order'       => 'DESC',
				]
			);
			$a['users'] = get_users(
				[
					'fields'     => 'ID',
					'meta_key'   => '_bulkcat_native_probe',
					'meta_value' => $a['run'],
				]
			);
		}
		foreach ( $a['ids'] as $id ) {
			bulkcat_native_owned( (int) $id, $a['run'] );
			wp_delete_post( (int) $id, true ); }
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $a['users'] ?? [] as $id ) {
			if ( get_user_meta( $id, '_bulkcat_native_probe', true ) !== $a['run'] ) {
				throw new RuntimeException( 'Not an owned actor.' ); }
			wp_delete_user( $id );
		}
		bulkcat_out(
			[
				'remaining'       => array_values( array_filter( $a['ids'], 'get_post' ) ),
				'remaining_users' => array_values( array_filter( $a['users'] ?? [], 'get_userdata' ) ),
				'baseline'        => bulkcat_native_baseline(),
			]
		);
		break;
	default:
		throw new RuntimeException( 'Unknown native Status probe phase.' );
}
