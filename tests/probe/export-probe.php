<?php
/**
 * The CSV export's native helpers for tests/probe/probe.py (ticket 10,
 * T101-T102), run inside WordPress through WP-CLI on a disposable stack,
 * never on a real shop:
 *
 *     wp eval 'require "wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/export-probe.php";
 *         bulkcat_export_probe( "<fixture url>", "<action>", "<base64 JSON arguments>" );' [--user=<login>]
 *
 * It refuses to do anything unless the site's own `siteurl` is exactly the
 * fixture URL given, and that URL names a local development host
 * (localhost, 127.0.0.1, [::1], *.localhost or *.test): probe.py passes the
 * URL it was told the fixture is (BULKCAT_FIXTURE_URL, which must equal
 * --base-url), so a WP_CMD pointed anywhere else is refused before any PHP
 * or SQL of the probe's runs. It prints one line, `BULKCAT_OUT <json>`.
 *
 * Actions:
 *   fixtures           upsert the round-trip, recorded and token fixtures through
 *                      WooCommerce CRUD (keyed by `_bulkcat_probe_key`, so a rerun puts
 *                      each back to its seeded values) and answer their ids
 *   snapshot           the round trip's snapshot of the given ids
 *   mapping            the header row mapped as WooCommerce's import screen maps it:
 *                      auto_map_columns() filtered through get_mapping_options()
 *   import             import a CSV through WooCommerce's own importer, Update
 *                      existing products on, with that filtered mapping, as the
 *                      current user (wp eval --user)
 *   exporter           WooCommerce's own exporter's Type and Parent for the given ids
 *   query_counts       queries per page of the export's row sources at 25, 50 and 100 rows
 *   footprint          what an export must never change: request and selection rows,
 *                      the highest post id, a post_modified checksum, term counts and
 *                      non-transient options
 *   sql                a short list of fixture-only statements (lapse a lease, expire
 *                      a payload, bin or rename a token fixture, set the separator)
 *   time_limit         the web server's PHP limits, read by an export request
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A disposable-fixture probe run through WP-CLI; never shipped (export-ignore). base64 carries its JSON arguments and a CSV through the command line, not code.

if ( ! function_exists( 'bulkcat_export_probe' ) ) {
	/**
	 * Run one action on the fixture, or refuse.
	 *
	 * @param string $fixture The fixture's URL, as probe.py was told it.
	 * @param string $action  Action name.
	 * @param string $encoded Base64 JSON arguments.
	 */
	function bulkcat_export_probe( string $fixture, string $action, string $encoded = '' ): void {
		$fixture = untrailingslashit( $fixture );
		$host    = (string) wp_parse_url( $fixture, PHP_URL_HOST );
		$local   = 1 === preg_match( '/^(localhost|127\.0\.0\.1|\[?::1\]?|[a-z0-9.-]+\.localhost|[a-z0-9.-]+\.test)$/i', $host );
		if ( '' === $fixture || ! $local || untrailingslashit( (string) get_option( 'siteurl' ) ) !== $fixture ) {
			fwrite( STDERR, 'bulkcat export probe refused: this site is not the declared local fixture (' . $fixture . ").\n" );
			exit( 3 );
		}
		$args = '' === $encoded ? [] : json_decode( (string) base64_decode( $encoded, true ), true );
		$args = is_array( $args ) ? $args : [];
		$out  = match ( $action ) {
			'fixtures'     => bulkcat_export_probe_fixtures(),
			'snapshot'     => bulkcat_export_probe_snapshot( array_map( 'intval', (array) ( $args['ids'] ?? [] ) ) ),
			'mapping'      => bulkcat_export_probe_mapping( (array) ( $args['headers'] ?? [] ), (string) ( $args['locale'] ?? '' ) ),
			'import'       => bulkcat_export_probe_import( (string) ( $args['csv'] ?? '' ), (string) ( $args['delimiter'] ?? ',' ) ),
			'exporter'     => bulkcat_export_probe_exporter( array_map( 'intval', (array) ( $args['ids'] ?? [] ) ) ),
			'query_counts' => bulkcat_export_probe_query_counts( (int) ( $args['user'] ?? 0 ) ),
			'footprint'    => bulkcat_export_probe_footprint(),
			'sql'          => bulkcat_export_probe_sql( (string) ( $args['what'] ?? '' ), $args ),
			'time_limit'   => bulkcat_export_probe_time_limit(),
			default        => [ 'error' => 'unknown action' ],
		};
		echo "\nBULKCAT_OUT " . wp_json_encode( $out ) . "\n";
	}

	/**
	 * A term id by slug, created when missing.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @param string $name     Name.
	 */
	function bulkcat_export_probe_term( string $taxonomy, string $slug, string $name ): int {
		$found = get_term_by( 'slug', $slug, $taxonomy );
		if ( $found ) {
			return (int) $found->term_id;
		}
		$made = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
		return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
	}

	/**
	 * The product or variation carrying a probe key, in any status, or 0.
	 *
	 * @param string $key Probe key.
	 */
	function bulkcat_export_probe_find( string $key ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bulkcat_probe_key' WHERE m.meta_value = %s AND p.post_type IN ( 'product', 'product_variation' ) ORDER BY p.ID LIMIT 1", $key ) );
	}

	/**
	 * Upsert one product through CRUD.
	 *
	 * @param string              $key   Probe key.
	 * @param string              $class Product class.
	 * @param array<string,mixed> $props Props for set_props().
	 * @param int                 $parent Parent id for a variation.
	 */
	function bulkcat_export_probe_upsert( string $key, string $class, array $props, int $parent = 0 ): int {
		$id = bulkcat_export_probe_find( $key );
		if ( $id > 0 && 'trash' === get_post_status( $id ) ) {
			wp_untrash_post( $id );
		}
		$product = $id > 0 ? wc_get_product( $id ) : new $class();
		if ( ! $product || get_class( $product ) !== $class ) {
			$product = new $class( $id > 0 ? $id : 0 );
		}
		if ( $parent > 0 ) {
			$product->set_parent_id( $parent );
		}
		// A SKU is set last and freed first, so reruns never clash with a fixture's earlier SKU.
		$sku = $props['sku'] ?? '';
		unset( $props['sku'] );
		$product->set_props( $props + [ 'status' => 'publish' ] );
		$product->set_sku( '' );
		$product->save();
		$product->set_sku( $sku );
		$product->update_meta_data( '_bulkcat_probe_key', $key );
		$product->save();
		return (int) $product->get_id();
	}

	/**
	 * Upsert every export fixture and answer their ids.
	 *
	 * @return array<string, mixed>
	 */
	function bulkcat_export_probe_fixtures(): array {
		global $wpdb;
		$round    = bulkcat_export_probe_term( 'product_cat', 'bulkcat-probe-roundtrip', 'Probe Round Trip' );
		$recorded = bulkcat_export_probe_term( 'product_cat', 'bulkcat-probe-recorded', 'Probe Recorded' );
		$token    = bulkcat_export_probe_term( 'product_cat', 'bulkcat-probe-token', 'Probe Token' );
		$tag      = bulkcat_export_probe_term( 'product_tag', 'bulkcat-rt-tag', 'Round trip tag' );
		$ship     = bulkcat_export_probe_term( 'product_shipping_class', 'bulkcat-rt-ship', 'Round trip parcel' );
		$simple   = static fn( string $name, float $price, bool $downloadable, bool $virtual, string $sku ): array => [
			'name'               => $name,
			'sku'                => $sku,
			'regular_price'      => number_format( $price, 2, '.', '' ),
			'sale_price'         => number_format( $price - 1, 2, '.', '' ),
			'downloadable'       => $downloadable,
			'virtual'            => $virtual,
			'weight'             => '1.5',
			'length'             => '10',
			'width'              => '20',
			'height'             => '30',
			'tax_status'         => 'taxable',
			'tax_class'          => 'reduced-rate',
			'shipping_class_id'  => $ship,
			'tag_ids'            => [ $tag ],
			'featured'           => $downloadable && ! $virtual,
			'category_ids'       => [ $round ],
			'catalog_visibility' => 'visible',
			'manage_stock'       => false,
			'stock_status'       => 'instock',
			'reviews_allowed'    => true,
		];
		$ids      = [];
		foreach ( [
			'rt-simple-plain'      => [ 'RT Simple Plain', 10.0, false, false, 'bulkcat-rt-simple-plain' ],
			'rt-simple-dl'         => [ 'RT Simple Downloadable', 11.0, true, false, 'bulkcat-rt-simple-dl' ],
			'rt-simple-virtual'    => [ 'RT Simple Virtual', 12.0, false, true, 'bulkcat-rt-simple-virtual' ],
			'rt-simple-dl-virtual' => [ 'RT Simple Downloadable Virtual', 13.0, true, true, 'bulkcat-rt-simple-dl-virtual' ],
			'rt-mug'               => [ 'Tom & Jerry\'s "Classic" Mug', 14.0, false, false, 'bulkcat-rt-mug' ],
			'rt-curly'             => [ "The \u{201C}Deluxe\u{201D} \u{2018}Edition\u{2019} Mug", 15.0, false, false, 'bulkcat-rt-curly' ],
		] as $key => $spec ) {
			$ids[ $key ] = bulkcat_export_probe_upsert( $key, WC_Product_Simple::class, $simple( ...$spec ) );
		}

		$variable            = static function ( string $key, string $name, string $sku, int $category, array $children ) use ( &$ids ): int {
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( 'Size' );
			$attribute->set_options( array_keys( $children ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$parent = bulkcat_export_probe_upsert(
				$key,
				WC_Product_Variable::class,
				[
					'name'         => $name,
					'sku'          => $sku,
					'category_ids' => [ $category ],
					'attributes'   => [ $attribute ],
					'tax_class'    => '',
				]
			);
			foreach ( $children as $value => $child ) {
				$ids[ $key . '-' . strtolower( $value ) ] = bulkcat_export_probe_upsert(
					$key . '-' . strtolower( $value ),
					WC_Product_Variation::class,
					$child + [
						'attributes'   => [ 'size' => $value ],
						'manage_stock' => false,
						'stock_status' => 'instock',
					],
					$parent
				);
			}
			WC_Product_Variable::sync( $parent );
			return $parent;
		};
		$ids['rt-var']       = $variable(
			'rt-var',
			'RT Variable',
			'bulkcat-rt-var',
			$round,
			[
				'S' => [
					'sku'           => 'bulkcat-rt-var-s',
					'regular_price' => '20.00',
					'sale_price'    => '19.00',
					'tax_class'     => 'zero-rate',
				],
				'M' => [
					'sku'           => 'bulkcat-rt-var-m',
					'regular_price' => '21.00',
					'virtual'       => true,
					'weight'        => '2',
				],
				'L' => [
					'sku'           => '',
					'regular_price' => '22.00',
					'downloadable'  => true,
					'virtual'       => true,
					'tax_class'     => 'parent',
				],
			]
		);
		$ids['rt-var-nosku'] = $variable(
			'rt-var-nosku',
			'RT Variable Without SKU',
			'',
			$round,
			[
				'Red'  => [
					'sku'           => 'bulkcat-rt-var-nosku-red',
					'regular_price' => '30.00',
				],
				'Blue' => [
					'sku'           => '',
					'regular_price' => '31.00',
				],
			]
		);
		$ids['rt-grouped']   = bulkcat_export_probe_upsert(
			'rt-grouped',
			WC_Product_Grouped::class,
			[
				'name'         => 'RT Grouped',
				'sku'          => 'bulkcat-rt-grouped',
				'category_ids' => [ $round ],
				'children'     => [ $ids['rt-simple-plain'], $ids['rt-simple-dl'] ],
			]
		);
		$ids['rt-external']  = bulkcat_export_probe_upsert(
			'rt-external',
			WC_Product_External::class,
			[
				'name'          => 'RT External',
				'sku'           => 'bulkcat-rt-external',
				'category_ids'  => [ $round ],
				'regular_price' => '40.00',
				'product_url'   => 'https://example.test/rt-external',
				'button_text'   => 'Buy elsewhere',
			]
		);
		// A simple product with a post parent: Parent must round-trip (amendment 10).
		$ids['rt-child'] = bulkcat_export_probe_upsert( 'rt-child', WC_Product_Simple::class, $simple( 'RT Child', 16.0, false, false, 'bulkcat-rt-child' ), $ids['rt-grouped'] );

		// Recorded behaviour, kept out of the strict round trip.
		$ids['rec-guarded-sku'] = bulkcat_export_probe_upsert(
			'rec-guarded-sku',
			WC_Product_Simple::class,
			[
				'name'          => 'REC Guarded SKU',
				'sku'           => '-P1',
				'regular_price' => '5.00',
				'category_ids'  => [ $recorded ],
			]
		);
		foreach ( [
			'rec-v1'     => [ 'REC Guarded Parent', '=V1' ],
			'rec-zero'   => [ 'REC Zero SKU', '0' ],
			'rec-id-sku' => [ 'REC Id SKU', 'id:999999' ],
			'rec-titled' => [ 'REC Titled', 'bulkcat-rec-titled' ],
		] as $key => [ $name, $sku ] ) {
			$ids[ $key ] = $variable(
				$key,
				$name,
				$sku,
				$recorded,
				[
					'One' => [
						'sku'           => '',
						'regular_price' => '6.00',
					],
				]
			);
		}
		$ids['rec-at-home'] = bulkcat_export_probe_upsert(
			'rec-at-home',
			WC_Product_Simple::class,
			[
				'name'          => '@home',
				'sku'           => 'bulkcat-rec-at-home',
				'regular_price' => '7.00',
				'category_ids'  => [ $recorded ],
			]
		);
		// A variation whose stored title is not the generated one (amendment 9): set in the table, as no CRUD save keeps it.
		$wpdb->update( $wpdb->posts, [ 'post_title' => 'A custom variation title' ], [ 'ID' => $ids['rec-titled-one'] ] );
		clean_post_cache( $ids['rec-titled-one'] );

		// Token fixtures (T101 token-exact): A, B and E match "CSV Token"; C and D do not, yet.
		foreach ( [
			'tok-a' => 'CSV Token A',
			'tok-b' => 'CSV Token B',
			'tok-c' => 'CSV Extra C',
			'tok-d' => 'CSV Other D',
			'tok-e' => 'CSV Token E',
		] as $key => $name ) {
			$ids[ $key ] = bulkcat_export_probe_upsert(
				$key,
				WC_Product_Simple::class,
				[
					'name'          => $name,
					'sku'           => 'bulkcat-' . $key,
					'regular_price' => '3.00',
					'category_ids'  => [ $token ],
				]
			);
		}
		$page        = get_page_by_path( 'bulkcat-csv-probe-page', OBJECT, 'page' );
		$ids['page'] = $page ? (int) $page->ID : (int) wp_insert_post(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'CSV probe page',
				'post_name'   => 'bulkcat-csv-probe-page',
			]
		);
		wc_delete_product_transients();
		return [
			'ids'        => $ids,
			'categories' => [
				'roundtrip' => $round,
				'recorded'  => $recorded,
				'token'     => $token,
			],
			'max_id'     => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ),
		];
	}

	/**
	 * The round trip's snapshot: every registry field, the flags, type, post
	 * parent and status of each id; the highest post id; the importing
	 * placeholders; each product taxonomy's term count and highest term id.
	 *
	 * @param list<int> $ids Ids.
	 * @return array<string, mixed>
	 */
	function bulkcat_export_probe_snapshot( array $ids ): array {
		global $wpdb;
		wp_cache_flush();
		$fields = \BulkCatalogueManager\Fields\Registry::instance()->all();
		$rows   = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				$rows[ $id ] = null;
				continue;
			}
			$row = [];
			foreach ( $fields as $key => $field ) {
				if ( 'date_modified' === $key ) {
					continue; // The importer saves every row, so Last modified always moves.
				}
				$value       = $field->read( $product );
				$row[ $key ] = is_object( $value ) ? ( method_exists( $value, 'date' ) ? $value->date( 'c' ) : (string) wp_json_encode( $value ) ) : $value;
			}
			$row['_virtual']      = get_post_meta( $id, '_virtual', true );
			$row['_downloadable'] = get_post_meta( $id, '_downloadable', true );
			$row['type']          = $product->get_type();
			$row['post_parent']   = (int) get_post_field( 'post_parent', $id );
			$row['post_status']   = get_post_status( $id );
			$rows[ $id ]          = $row;
		}
		$terms = [];
		foreach ( [ 'product_cat', 'product_tag', 'product_shipping_class', 'product_type', 'product_visibility' ] as $taxonomy ) {
			$terms[ $taxonomy ] = [
				'count'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ),
				'max_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(term_id) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ),
			];
		}
		return [
			'rows'      => $rows,
			'max_id'    => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ),
			'importing' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'importing'" ),
			'terms'     => $terms,
		];
	}

	/**
	 * WooCommerce's importer screen controller, with its admin dependencies.
	 */
	function bulkcat_export_probe_controller(): WC_Product_CSV_Importer_Controller {
		require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		include_once WC_ABSPATH . 'includes/admin/importers/class-wc-product-csv-importer-controller.php';
		include_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		return new WC_Product_CSV_Importer_Controller();
	}

	/**
	 * The header row mapped as the import screen maps it (amendment 8):
	 * auto_map_columns(), then each result kept only if it is an option the
	 * mapping screen's select offers for it (get_mapping_options(), option
	 * groups flattened, as views/html-csv-import-mapping.php does), else ''
	 * ("Do not import"). Under a locale, for a non-English importing user.
	 *
	 * @param list<string> $headers Header row.
	 * @param string       $locale  Locale to map under ('' for the current one).
	 * @return array<int, string> Column index => field, or ''.
	 */
	function bulkcat_export_probe_mapping( array $headers, string $locale = '' ): array {
		$switched = '' !== $locale && switch_to_locale( $locale );
		try {
			$controller = bulkcat_export_probe_controller();
			$auto       = new ReflectionMethod( $controller, 'auto_map_columns' );
			$options    = new ReflectionMethod( $controller, 'get_mapping_options' );
			$auto->setAccessible( true );
			$options->setAccessible( true );
			$mapped = (array) $auto->invoke( $controller, $headers );
			foreach ( $mapped as $index => $field ) {
				$offered = [];
				foreach ( (array) $options->invoke( $controller, $field ) as $key => $value ) {
					if ( is_array( $value ) ) {
						foreach ( array_keys( (array) ( $value['options'] ?? [] ) ) as $sub ) {
							$offered[] = (string) $sub;
						}
					} else {
						$offered[] = (string) $key;
					}
				}
				$mapped[ $index ] = in_array( (string) $field, $offered, true ) ? (string) $field : '';
			}
			return $mapped;
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Import a CSV through WooCommerce's importer as the current user, with
	 * Update existing products and the screen-equivalent mapping. Refuses a
	 * mapping that is not filtered (every target '' or an offered option).
	 *
	 * @param string $csv       The file's bytes, base64.
	 * @param string $delimiter "," or ";".
	 * @return array<string, mixed>
	 */
	function bulkcat_export_probe_import( string $csv, string $delimiter ): array {
		global $wpdb;
		$bytes = (string) base64_decode( $csv, true );
		$file  = trailingslashit( get_temp_dir() ) . 'bulkcat-export-probe-' . wp_generate_password( 8, false ) . '.csv';
		file_put_contents( $file, $bytes );
		try {
			$controller = bulkcat_export_probe_controller();
			$reader     = WC_Product_CSV_Importer_Controller::get_importer(
				$file,
				[
					'lines'     => 1,
					'delimiter' => $delimiter,
				]
			);
			$headers    = $reader->get_raw_keys();
			$mapped     = bulkcat_export_probe_mapping( $headers );
			if ( count( $mapped ) !== count( $headers ) ) {
				return [ 'refused' => 'the mapping does not cover every column' ];
			}
			$before_max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
			$importer   = WC_Product_CSV_Importer_Controller::get_importer(
				$file,
				[
					'delimiter'        => $delimiter,
					'start_pos'        => 0,
					'mapping'          => [
						'from' => $headers,
						'to'   => array_values( $mapped ),
					],
					'update_existing'  => true,
					'lines'            => -1,
					'parse'            => true,
					'prevent_timeouts' => false,
				]
			);
			unset( $controller );
			$result = $importer->import();
			return [
				'user'      => get_current_user_id(),
				'mapping'   => array_combine( $headers, array_values( $mapped ) ),
				'imported'  => count( $result['imported'] ?? [] ) + count( $result['imported_variations'] ?? [] ),
				'updated'   => count( $result['updated'] ?? [] ),
				'failed'    => array_map( static fn( $e ): string => is_wp_error( $e ) ? $e->get_error_message() : (string) $e, $result['failed'] ?? [] ),
				'skipped'   => array_map( static fn( $e ): string => is_wp_error( $e ) ? $e->get_error_message() : (string) $e, $result['skipped'] ?? [] ),
				'new_posts' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $before_max ) ),
				'importing' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'importing'" ),
			];
		} finally {
			wp_delete_file( $file );
		}
	}

	/**
	 * WooCommerce's own exporter's Type and Parent cells for these ids.
	 *
	 * @param list<int> $ids Ids.
	 * @return array<int, array{type: string, parent: string}>
	 */
	function bulkcat_export_probe_exporter( array $ids ): array {
		include_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
		$exporter = new WC_Product_CSV_Exporter();
		$type     = new ReflectionMethod( $exporter, 'get_column_value_type' );
		$parent   = new ReflectionMethod( $exporter, 'get_column_value_parent_id' );
		$type->setAccessible( true );
		$parent->setAccessible( true );
		$out = [];
		foreach ( $ids as $id ) {
			$product    = wc_get_product( $id );
			$out[ $id ] = $product ? [
				'type'   => (string) $type->invoke( $exporter, $product ),
				'parent' => (string) $parent->invoke( $exporter, $product ),
			] : null;
		}
		return $out;
	}

	/**
	 * Queries per page of the export's row sources with the identifier
	 * switch on, cold object cache, at 25, 50 and 100 rows: the filter
	 * reader (as the POST export reads /products) and explicit ids (IdRows),
	 * in each mode, each wrapped in ReimportIdentifiers.
	 *
	 * @param int $user The shop manager.
	 * @return array<string, mixed>
	 */
	function bulkcat_export_probe_query_counts( int $user ): array {
		global $wpdb;
		wp_set_current_user( $user );
		$registry = \BulkCatalogueManager\Fields\Registry::instance();
		$plan     = new \BulkCatalogueManager\Export\ColumnPlan( $registry, [ 'name', 'sku', 'regular_price', 'stock_status', 'category_ids', 'type' ], true );
		$reader   = new ReflectionMethod( \BulkCatalogueManager\Rest\ExportController::class, 'products_reader' );
		$reader->setAccessible( true );
		$filter = new \BulkCatalogueManager\Export\CallableRowSource( $reader->invoke( null, new \BulkCatalogueManager\Rest\ProductsController( $registry ) ) );
		$out    = [];
		foreach ( [ 'products', 'products_and_variations', 'variations' ] as $mode ) {
			// A fresh count for this mode: a 60-second count transient written by an earlier row could
			// otherwise expire inside the measured window and add its recount to one page.
			\BulkCatalogueManager\Query\CountCache::invalidate();
			$types = 'products' === $mode ? "'product'" : ( 'variations' === $mode ? "'product_variation'" : "'product', 'product_variation'" );
			$ids   = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types ) AND post_status IN ( 'publish', 'private' ) ORDER BY ID LIMIT 100" ) );
			$read  = [
				'mode'    => $mode,
				'orderby' => [ 'id' ],
				'order'   => [ 'asc' ],
				'fields'  => $plan->fields(),
			];
			$rows  = \BulkCatalogueManager\Export\ExportRows::ids( $ids, $registry );
			foreach ( [
				'filter' => $filter,
				'ids'    => $rows,
			] as $name => $source ) {
				$decorated = new \BulkCatalogueManager\Export\ReimportIdentifiers( $source );
				// Warms, outside the measured window, the shared count and WooCommerce's own term-count
				// transient (wc_term_counts, which product saves clear and whose get_terms filter otherwise
				// reads term meta for every term it lacks) for every row a measured page can show.
				$decorated->page( $read, 1, 100 );
				foreach ( [ 25, 50, 100 ] as $n ) {
					wp_cache_flush();
					$decorated->page( $read, 1, 1 );
					wp_cache_flush();
					$before                      = $wpdb->num_queries;
					$page                        = $decorated->page( $read, 1, $n );
					$out[ $mode ][ $name ][ $n ] = [
						'queries' => $wpdb->num_queries - $before,
						'rows'    => count( $page['items'] ),
						'parents' => count( array_filter( array_column( $page['items'], 'parent_id' ) ) ),
					];
				}
			}
		}
		return $out;
	}

	/**
	 * What an export must never change.
	 *
	 * @return array<string, mixed>
	 */
	function bulkcat_export_probe_footprint(): array {
		global $wpdb;
		$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' ORDER BY option_name", ARRAY_A );
		$hash    = [];
		foreach ( $options as $option ) {
			$hash[ $option['option_name'] ] = md5( (string) $option['option_value'] );
		}
		return [
			'requests'    => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bulkcat_requests ORDER BY user_id, uuid", ARRAY_A ),
			'selections'  => $wpdb->get_results( "SELECT id, user_id, object_count, created_at, authorization_epoch, authorization_expires_at, retained_until, released_at, MD5(members) AS members FROM {$wpdb->prefix}bulkcat_selections ORDER BY id", ARRAY_A ),
			'max_post'    => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ),
			'modified'    => (string) $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(CONCAT(ID, ':', post_modified_gmt) ORDER BY ID)) FROM {$wpdb->posts}" ),
			'term_counts' => (string) $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(CONCAT(term_taxonomy_id, ':', count) ORDER BY term_taxonomy_id)) FROM {$wpdb->term_taxonomy}" ),
			'options'     => $hash,
			'postmeta'    => (string) $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(CONCAT(meta_id, ':', MD5(meta_value)) ORDER BY meta_id)) FROM {$wpdb->postmeta}" ),
		];
	}

	/**
	 * Fixture-only statements.
	 *
	 * @param string              $what Which.
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	function bulkcat_export_probe_sql( string $what, array $args ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bulkcat_selections';
		$id    = (string) ( $args['snapshot'] ?? '' );
		switch ( $what ) {
			case 'lapse':
				$done = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET authorization_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = %s", $id ) );
				break;
			case 'expire':
				$done = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET retained_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = %s", $id ) );
				break;
			case 'trash':
				$done = (bool) wp_trash_post( (int) $args['id'] );
				break;
			case 'rename':
				$product = wc_get_product( (int) $args['id'] );
				$product->set_name( (string) $args['name'] );
				$done = $product->save();
				break;
			case 'separator':
				$settings                  = (array) get_option( 'bulkcat_settings', [] );
				$settings['csv_separator'] = 'semicolon' === $args['value'] ? 'semicolon' : 'comma';
				$done                      = update_option( 'bulkcat_settings', $settings );
				break;
			case 'sku':
				$product = wc_get_product( (int) $args['id'] );
				$product->set_sku( (string) $args['sku'] );
				$done = $product->save();
				break;
			default:
				$done = false;
		}
		return [ 'done' => false !== $done ];
	}

	/**
	 * The web server's PHP limits, as an export request sees them.
	 *
	 * @return array<string, mixed>
	 */
	function bulkcat_export_probe_time_limit(): array {
		return [
			'cli_max_execution_time' => ini_get( 'max_execution_time' ),
			'set_time_limit_enabled' => ! in_array( 'set_time_limit', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ),
			'sapi'                   => PHP_SAPI,
		];
	}
}
