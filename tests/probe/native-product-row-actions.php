<?php
/** UUID-owned native row-action fixture and readback. Never packaged. @package BulkCatalogueManager */
declare( strict_types = 1 );

/** Refuse fixture helpers outside an explicitly local CLI invocation. */
function bulkcat_row_probe_guard( string $run ): void {
	$host = wp_parse_url( site_url(), PHP_URL_HOST );
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $run ) ) {
		throw new RuntimeException( 'Owned local fixture required.' );
	}
}

/** Each operation checks the UUID instead of trusting an ID received from the browser. */
function bulkcat_row_probe_owned( int $id, string $run ): WC_Product {
	bulkcat_row_probe_guard( $run );
	clean_post_cache( $id );
	$product = wc_get_product( $id );
	if ( ! $product || $run !== $product->get_meta( '_bulkcat_row_probe', true, 'edit' ) ) {
		throw new RuntimeException( 'Unowned product refused.' );
	}
	return $product;
}

/** Native readback, including children and product identity reset. */
function bulkcat_row_probe_read( int $id, string $run ): array {
	$p        = bulkcat_row_probe_owned( $id, $run );
	$children = [];
	foreach ( $p->get_children() as $child ) {
		$c          = wc_get_product( $child );
		$children[] = [
			'id'         => $child,
			'parent'     => $c->get_parent_id(),
			'status'     => $c->get_status(),
			'sku'        => $c->get_sku(),
			'global_id'  => $c->get_global_unique_id(),
			'attributes' => $c->get_attributes(),
		];
	}
	return [
		'id'        => $id,
		'type'      => $p->get_type(),
		'status'    => $p->get_status(),
		'sku'       => $p->get_sku(),
		'global_id' => $p->get_global_unique_id(),
		'parent'    => $p->get_parent_id(),
		'price'     => $p->get_regular_price(),
		'children'  => $children,
		'permalink' => get_permalink( $id ),
		'excluded'  => $p->get_meta( '_bulkcat_row_probe_excluded', true, 'edit' ),
	];
}

/** Paired sources permit comparison with native admin controls, not simulated cloning. */
function bulkcat_row_probe_setup( string $run ): array {
	bulkcat_row_probe_guard( $run );
	$observer = WPMU_PLUGIN_DIR . '/bulkcat-row-observer-' . $run . '.php';
	if ( file_exists( $observer ) || get_option( 'bulkcat_row_probe_armed_' . $run ) ) {
		throw new RuntimeException( 'Existing observer refused.' );
	}
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	if ( ! copy( __DIR__ . '/native-row-observer.php', $observer ) ) {
		throw new RuntimeException( 'Could not install owned fixture observer.' );
	}
	update_option( 'bulkcat_row_probe_armed_' . $run, true, false );
	$ids   = [];
	$names = [];
	foreach ( [ 'simple', 'variable', 'grouped', 'external' ] as $type ) {
		foreach ( [ 'manager', 'native' ] as $entry ) {
			$key   = $entry . '-' . $type;
			$class = 'WC_Product_' . ucfirst( $type );
			$p     = new $class();
			$p->set_name( 'Row probe ' . $run . ' ' . $key );
			$p->set_status( 'publish' );
			$p->set_sku( $run . '-' . $key );
			$p->set_regular_price( '17.25' );
			$p->update_meta_data( '_bulkcat_row_probe', $run );
			$p->update_meta_data( '_bulkcat_row_probe_excluded', 'must-not-copy' );
			if ( 'variable' === $type ) {
				$a = new WC_Product_Attribute();
				$a->set_name( 'Size' );
				$a->set_options( [ 'Small', 'Large' ] );
				$a->set_variation( true );
				$p->set_attributes( [ $a ] );
			}
			if ( 'external' === $type ) {
				$p->set_product_url( 'https://example.test/fixture-item' );
			}
			$ids[ $key ]   = $p->save();
			$names[ $key ] = $p->get_name();
			if ( 'variable' === $type ) {
				foreach ( [ 'Small', 'Large' ] as $size ) {
					$v = new WC_Product_Variation();
					$v->set_parent_id( $p->get_id() );
					$v->set_attributes( [ 'size' => $size ] );
					$v->set_regular_price( '17.25' );
					$v->set_sku( $run . '-' . $key . '-' . $size );
					$v->update_meta_data( '_bulkcat_row_probe', $run );
					$ids[ $key . '-' . $size ] = $v->save();
				}
			}
		}
	}
	foreach ( [ 'draft', 'future', 'private', 'password', 'sold', 'outside' ] as $name ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'Row probe ' . $run . ' ' . $name );
		$p->set_regular_price( '17.25' );
		$p->set_status( in_array( $name, [ 'draft', 'future', 'private' ], true ) ? $name : 'publish' );
		if ( 'future' === $name ) {
			$p->set_date_created( time() + DAY_IN_SECONDS );
		}
		if ( 'password' === $name ) {
			$p->set_post_password( 'fixture-only-password' );
		}
		if ( 'sold' === $name ) {
			$p->set_total_sales( 2 );
		}
		$p->update_meta_data( '_bulkcat_row_probe', $run );
		$ids[ $name ]   = $p->save();
		$names[ $name ] = $p->get_name();
	}
	return [
		'ids'   => $ids,
		'names' => $names,
	];
}

/** Includes native duplicates carrying the fixture marker; never delete an unrelated object. */
function bulkcat_row_probe_cleanup( string $run ): array {
	bulkcat_row_probe_guard( $run );
	$ids = get_posts(
		[
			'post_type'   => [ 'product', 'product_variation' ],
			'post_status' => array_keys( get_post_stati() ),
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_bulkcat_row_probe',
			'meta_value'  => $run,
			'orderby'     => 'ID',
			'order'       => 'DESC',
		]
	);
	foreach ( $ids as $id ) {
		bulkcat_row_probe_owned( (int) $id, $run )->delete( true );
	}
	$users = get_users(
		[
			'meta_key'   => '_bulkcat_row_probe',
			'meta_value' => $run,
			'fields'     => 'ID',
		]
	);
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) {
		if ( get_user_meta( (int) $id, '_bulkcat_row_probe', true ) !== $run ) {
			throw new RuntimeException( 'Unowned user refused.' );
		}
		wp_delete_user( (int) $id );
	}
	$observer = WPMU_PLUGIN_DIR . '/bulkcat-row-observer-' . $run . '.php';
	if ( file_exists( $observer ) ) {
		$expected = hash_file( 'sha256', __DIR__ . '/native-row-observer.php' );
		if ( hash_file( 'sha256', $observer ) !== $expected ) {
			throw new RuntimeException( 'Changed observer preserved for inspection.' );
		}
		wp_delete_file( $observer );
	}
	delete_option( 'bulkcat_row_probe_armed_' . $run );
	delete_option( 'bulkcat_row_probe_log_' . $run );
	return $ids;
}

/** Create a disposable actor without changing any existing account or role. */
function bulkcat_row_probe_actor( string $run, string $suffix, string $password ): array {
	bulkcat_row_probe_guard( $run );
	if ( ! preg_match( '/^[a-z]+$/D', $suffix ) ) {
		throw new RuntimeException( 'Invalid actor suffix.' );
	}
	$login = 'row-' . $run . '-' . $suffix;
	if ( username_exists( $login ) ) {
		throw new RuntimeException( 'Existing actor refused.' );
	}
	$id = wp_insert_user(
		[
			'user_login' => $login,
			'user_pass'  => $password,
			'role'       => 'shop_manager',
		]
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Fixture actor could not be created.' );
	}
	update_user_meta( $id, '_bulkcat_row_probe', $run );
	return [
		'id'    => $id,
		'login' => $login,
	];
}
