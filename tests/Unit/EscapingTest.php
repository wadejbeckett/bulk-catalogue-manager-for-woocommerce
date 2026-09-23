<?php
/**
 * Escaping and markup safety (T7; SPEC §13, §15), beside the phpcs escaping,
 * input-validation, nonce and SQL sniffs at error severity.
 *
 * A product named `<img src=x onerror=alert(1)>`, with a SKU and a category
 * name carrying quotes and angle brackets, goes through every read route
 * the screen renders: the `/products` item, the `/products/{id}/variations`
 * parent name and attribute summary, the `/preview` sample rows, the
 * `/batches/{id}` summary and `/batches/{id}/changes` name and message, the
 * `/audit` rows, the `/categories` node names and the CSV export cell.
 * REST returns data, so every JSON value carries the raw text, never an
 * entity: escaping happens where the text is rendered, and the screen
 * renders text as text. Where the plugin writes a sentence around a name or
 * SKU (a `message`, `reason` or summary `sentence`), the sentence is plain
 * text with the value inserted verbatim and no markup of its own. Where the
 * plugin itself emits HTML (the dependency guard's admin notice), the text
 * is escaped. The CSV cell carries the raw text, quoted, with the formula
 * guard in front of a value a spreadsheet would read as a formula.
 *
 * Two source scans: no `dangerouslySetInnerHTML` anywhere under assets/
 * (trivially true until the screen's scripts land in T8, then live), and no
 * `echo`, `print`, `printf` or other output call in the plugin's PHP that
 * prints a variable or a call not wrapped in an esc_* or wp_kses* function.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit;

use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Export\CsvWriter;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Rest\ExportController;
use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use BulkCatalogueManager\Tests\Support\WatchedProduct;
use BulkCatalogueManager\Tests\Unit\Categories\CatalogueFixture;
use BulkCatalogueManager\Tests\Unit\Engine\RunnerStore;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Query/SeededCatalogue.php';
require_once __DIR__ . '/Categories/CatalogueFixture.php';
require_once __DIR__ . '/Engine/RunnerStore.php';

final class EscapingTest extends TestCase {
	/**
	 * The hostile product name.
	 */
	private const NAME = '<img src=x onerror=alert(1)>';

	/**
	 * A stored SKU with quotes and angle brackets, starting like a formula.
	 */
	private const SKU = '=SKU "1" <b>&\'2\'</b>';

	/**
	 * A SKU another product holds, sent in a change: quotes, an angle bracket
	 * and an ampersand, all of which the SKU sanitiser keeps.
	 */
	private const CLASH = 'SKU-"Q"&\'R\'>2';

	/**
	 * A category name with quotes and angle brackets.
	 */
	private const CATEGORY = 'Men\'s "Best" <b>Deals</b> & more';

	/**
	 * A custom attribute value and an attribute term name.
	 */
	private const ATTRIBUTE = '<b>"XL"</b> & \'up\'';

	private const TERM = 'Navy "Blue" <i>';

	/**
	 * The hostile products in the seeded catalogue: a simple product, a
	 * variable product and its variation.
	 */
	private const SIMPLE = 109;

	private const VARIABLE = 110;

	private const VARIATION = 301;

	/**
	 * The admin notice's text, which a translation may replace.
	 */
	private const NOTICE_TEXT = 'Bulk Catalogue Manager for WooCommerce needs WooCommerce 10.0 or later. Install or update WooCommerce to use it.';

	/**
	 * Output constructs and functions the source scan checks.
	 */
	private const OUTPUT_FUNCTIONS = [ 'printf', 'vprintf', 'print_r', 'var_dump', 'var_export', '_e', '_ex', 'wp_die', 'trigger_error' ];

	/**
	 * Wrappers whose result may be printed: every esc_* function, the kses
	 * filters, and integer casts.
	 */
	private const SAFE_WRAPPER = '/^(esc_\w+|wp_kses\w*|absint|intval)$/';

	/**
	 * Deliberate output of something other than a literal or a wrapped value,
	 * as "path:line" => the one-sentence reason. Empty: the screen's mount
	 * element (T8) is a literal and needs no entry.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_OUTPUT = [];

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in']                  = true;
		$GLOBALS['bcm_test']['user_id']                    = 12;
		$GLOBALS['bcm_test']['caps']['manage_woocommerce'] = true;
		$GLOBALS['bcm_test']['caps']['edit_products']      = true;
		$GLOBALS['bcm_test']['caps']['edit_post']          = true;
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Boot the plugin and register its routes.
	 */
	private static function boot(): void {
		( new Plugin() )->boot();
		do_action( 'rest_api_init' );
	}

	/**
	 * The seeded catalogue plus the hostile products, with a hostile attribute term name.
	 */
	private static function seed(): void {
		$product = static fn( string $title, int $day ): array => [
			'post_type'         => 'product',
			'post_title'        => $title,
			'post_status'       => 'publish',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'comment_status'    => 'open',
			'post_date_gmt'     => sprintf( '2026-09-%02d 08:00:00', $day ),
			'post_modified_gmt' => sprintf( '2026-09-%02d 08:00:00', $day ),
		];
		SeededCatalogue::seed(
			[
				self::SIMPLE    => [ $product( self::NAME, 9 ), [ self::SKU, 12.0, 12.0, 'instock', 3, '' ], [ 11, 60 ], [] ],
				self::VARIABLE  => [ $product( self::NAME, 10 ), [ 'VAR-1', 5.0, 5.0, 'instock', null, '' ], [ 61 ], [] ],
				self::VARIATION => [
					[
						'post_type'         => 'product_variation',
						'post_title'        => self::NAME . ' - variation',
						'post_status'       => 'publish',
						'post_parent'       => self::VARIABLE,
						'menu_order'        => 0,
						'comment_status'    => 'closed',
						'post_date_gmt'     => '2026-09-14 08:00:00',
						'post_modified_gmt' => '2026-09-14 08:00:00',
					],
					[ self::SKU, 5.0, 5.0, 'instock', 1, '' ],
					[],
					[
						'attribute_pa_colour' => 'blue',
						'attribute_size'      => self::ATTRIBUTE,
					],
				],
			]
		);
		foreach ( $GLOBALS['bcm_test']['term_list'] as $term ) {
			if ( 70 === $term->term_id ) {
				$term->name = self::TERM;
			}
		}
	}

	/**
	 * The data of a 200 response.
	 *
	 * @return array<string, mixed>
	 */
	private function ok( \WP_REST_Response|\WP_Error $response ): array {
		$this->assertInstanceOf( \WP_REST_Response::class, $response, $response instanceof \WP_Error ? $response->get_error_code() . ': ' . $response->get_error_message() : '' );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private static function dispatch( string $method, string $path, array $params = [] ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( $method, '/bcm/v1' . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * No string anywhere in a value carries an HTML entity or a character
	 * reference: the REST data is raw text. `price_html` is WooCommerce's own
	 * markup, rendered by it, and is left out.
	 *
	 * @param mixed  $value Response data.
	 * @param string $path  Where in the data, for the message.
	 */
	private function assert_raw( mixed $value, string $path = '' ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( 'price_html' !== $key ) {
					$this->assert_raw( $item, $path . '.' . $key );
				}
			}
			return;
		}
		if ( is_string( $value ) ) {
			$this->assertSame( 0, preg_match( '/&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $value ), "$path carries an entity: $value" );
		}
	}

	/**
	 * A sentence the plugin wrote around a value: exactly the expected plain
	 * text, the value inserted verbatim (not escaped, stripped or re-encoded),
	 * and no markup or entity of the plugin's own around it.
	 *
	 * @param string $expected The sentence built from the untranslated template.
	 * @param string $inserted The name or SKU inserted.
	 * @param string $actual   The sentence returned.
	 */
	private function assert_plain_sentence( string $expected, string $inserted, string $actual ): void {
		$this->assertSame( $expected, $actual );
		$this->assertStringContainsString( $inserted, $actual, 'The value is inserted verbatim.' );
		$own = str_replace( $inserted, '', $actual );
		$this->assertSame( 0, preg_match( '/[<>]|&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $own ), "The plugin's own text carries markup: $own" );
	}

	public function test_the_products_item_carries_the_raw_name_and_sku(): void {
		self::boot();
		self::seed();
		$data  = $this->ok( self::dispatch( 'GET', '/products', [ 'per_page' => 100 ] ) );
		$items = array_column( $data['items'], null, 'id' );
		$this->assertArrayHasKey( self::SIMPLE, $items );
		$this->assertSame( self::NAME, $items[ self::SIMPLE ]['name'] );
		$this->assertSame( self::SKU, $items[ self::SIMPLE ]['sku'] );
		$this->assertSame( [ 11 ], $items[ self::SIMPLE ]['category_ids'], 'Categories go by id; their names come from /categories.' );
		$this->assert_raw( $items[ self::SIMPLE ], 'products' );
	}

	public function test_a_variation_carries_the_raw_parent_name_and_attribute_summary(): void {
		self::boot();
		self::seed();
		$data = $this->ok( self::dispatch( 'GET', '/products/' . self::VARIABLE . '/variations' ) );
		$this->assertSame( [ self::VARIATION ], array_column( $data['items'], 'id' ) );
		$row = $data['items'][0];
		$this->assertSame( self::NAME, $row['parent_name'] );
		$this->assertSame( self::TERM . ' / ' . self::ATTRIBUTE, $row['attribute_summary'] );
		$this->assertSame( self::SKU, $row['sku'] );
		$this->assert_raw( $row, 'variations' );
	}

	public function test_the_preview_sample_carries_the_raw_name_and_a_plain_reason(): void {
		self::boot();
		self::seed();
		$GLOBALS['bcm_test']['products'][ self::SIMPLE ] = new WatchedProduct(
			'simple',
			[
				'id'     => self::SIMPLE,
				'name'   => self::NAME,
				'sku'    => self::SKU,
				'status' => 'publish',
			]
		);
		$GLOBALS['bcm_test']['skus'][ self::CLASH ]      = 999;

		$data = $this->ok(
			self::dispatch(
				'POST',
				'/preview',
				[
					'operations' => [
						[
							'action' => 'set_value',
							'args'   => [
								'field' => 'sku',
								'value' => self::CLASH,
							],
						],
					],
					'ids'        => [ self::SIMPLE ],
				]
			)
		);
		$this->assertCount( 1, $data['sample'] );
		$row = $data['sample'][0];
		$this->assertSame( [ self::SIMPLE, self::NAME, 'sku', self::SKU, self::SKU, 'failed' ], [ $row['object_id'], $row['name'], $row['field'], $row['current'], $row['new'], $row['state'] ], 'A refused row keeps its current value.' );
		$reason = 'A product with the SKU ' . self::CLASH . ' already exists, so this SKU was not applied.';
		$this->assertSame( $reason, Field::clash_reason( self::CLASH ) );
		$this->assert_plain_sentence( $reason, self::CLASH, $row['reason'] );
		$this->assertSame( [ $reason ], array_keys( (array) $data['totals']['would_fail'] ) );
		$this->assert_raw( $data['sample'], 'preview' );
	}

	public function test_the_batch_summary_changes_feed_and_audit_carry_raw_names_and_plain_messages(): void {
		self::boot();
		$owner                                      = 3;
		$GLOBALS['bcm_test']['user_caps']           = [ $owner => [ 'edit_post' => true ] ];
		$GLOBALS['bcm_test']['scheduled']           = [];
		$GLOBALS['bcm_test']['products'][1001]      = new SavedProduct(
			'simple',
			[
				'id'            => 1001,
				'name'          => self::NAME,
				'sku'           => self::SKU,
				'regular_price' => '10',
			]
		);
		$GLOBALS['bcm_test']['posts'][1001]         = [
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => self::NAME,
		];
		$GLOBALS['bcm_test']['skus'][ self::CLASH ] = 999;
		$operations                                 = ( new Registry( new Fields() ) )->validate_list(
			[
				[
					'action' => 'adjust',
					'args'   => [
						'field'     => 'regular_price',
						'direction' => 'increase',
						'by'        => 'percent',
						'amount'    => '10',
					],
				],
				[
					'action' => 'set_value',
					'args'   => [
						'field' => 'sku',
						'value' => self::CLASH,
					],
				],
			]
		);
		$this->assertTrue( is_array( $operations ) );
		$store = new RunnerStore( 12, [ 1001 ], json_decode( (string) wp_json_encode( $operations ), true ), $owner );
		$clock = 1790150430.0;
		$this->assertSame(
			'partial',
			( new ChunkRunner(
				null,
				new Registry( new Fields() ),
				null,
				new Settings( [] ),
				static function () use ( &$clock ): float {
					return $clock += 1.0;
				},
				30
			) )->run( 12 )
		);
		$this->assertSame( self::SKU, $GLOBALS['bcm_test']['products'][1001]->props['sku'], 'The clashing SKU was not written.' );

		$reason = Field::clash_reason( self::CLASH );

		// The batch report's grouped summary: the message, and a sentence around it.
		$report = $this->ok( self::dispatch( 'GET', '/batches/12' ) );
		$group  = array_column( $report['summary'], null, 'message' )[ $reason ] ?? null;
		$this->assertNotNull( $group, 'The SKU refusal is summarised.' );
		$this->assert_plain_sentence( $reason, self::CLASH, $group['message'] );
		$this->assert_plain_sentence( 'A product with the SKU ' . self::CLASH . ' already exists, so this SKU was not applied to 1 product.', self::CLASH, $group['sentence'] );

		// The changes feed: the raw name on every row, the refusal as plain text.
		$feed = $this->ok( self::dispatch( 'GET', '/batches/12/changes' ) );
		$rows = array_column( $feed['items'], null, 'field' );
		$this->assertSame( [ 'regular_price', 'sku' ], array_keys( $rows ) );
		$this->assertSame( [ self::NAME ], array_values( array_unique( array_column( $feed['items'], 'name' ) ) ) );
		$this->assertSame( [ 'failed', self::SKU, self::SKU ], [ $rows['sku']['result'], $rows['sku']['old_value'], $rows['sku']['new_value'] ], 'A refused row keeps its current value.' );
		$this->assert_plain_sentence( $reason, self::CLASH, $rows['sku']['message'] );
		$this->assert_raw( $feed['items'], 'changes' );

		// The audit log: the same rows across batches.
		$audit = $this->ok( self::dispatch( 'GET', '/audit', [ 'product' => 1001 ] ) );
		$rows  = array_column( $audit['items'], null, 'field' );
		ksort( $rows );
		$this->assertSame( [ 'regular_price', 'sku' ], array_keys( $rows ) );
		$this->assertSame( [ self::NAME ], array_values( array_unique( array_column( $audit['items'], 'name' ) ) ) );
		$this->assert_plain_sentence( $reason, self::CLASH, $rows['sku']['message'] );
		$this->assertSame( self::SKU, $rows['sku']['old_value'] );
		$this->assert_raw( $audit['items'], 'audit' );
		unset( $store );
	}

	public function test_category_nodes_carry_the_raw_name(): void {
		$GLOBALS['bcm_test']['term_list']                                 = CatalogueFixture::terms( [ 3 => [ self::CATEGORY, 'shirts', 2 ] ] + CatalogueFixture::TERMS );
		$GLOBALS['wpdb']->responses['/^SELECT a\.ancestor AS term_id, /'] = CatalogueFixture::answer();
		self::boot();
		$data  = $this->ok( self::dispatch( 'GET', '/categories' ) );
		$names = [];
		$walk  = static function ( array $nodes ) use ( &$walk, &$names ): void {
			foreach ( $nodes as $node ) {
				$names[ $node['id'] ] = $node['name'];
				$walk( $node['children'] ?? [] );
			}
		};
		$walk( $data['items'] ?? $data );
		$this->assertSame( self::CATEGORY, $names[3] ?? null );
		$this->assert_raw( $names, 'categories' );
	}

	public function test_the_csv_cell_carries_the_raw_text_behind_the_formula_guard(): void {
		self::seed();
		$controller = new ExportController( new Fields() );
		( new Routes( [ $controller ] ) )->register();
		$response = self::dispatch( 'GET', '/export', [ 'columns' => [ 'id', 'name', 'sku' ] ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		$server = new class() {
			/**
			 * Headers sent.
			 *
			 * @var array<string, string>
			 */
			public array $headers = [];

			public function send_header( string $name, string $value ): void {
				$this->headers[ $name ] = $value;
			}
		};
		$stream = fopen( 'php://memory', 'w+b' );
		$this->assertTrue( $controller->serve( false, $response, null, $server, $stream ) );
		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );
		$this->assertStringStartsWith( CsvWriter::BOM, $csv );

		$rows = [];
		foreach ( explode( CsvWriter::EOL, substr( $csv, strlen( CsvWriter::BOM ) ) ) as $line ) {
			if ( '' !== $line ) {
				$cells             = str_getcsv( $line, ',', '"', '' );
				$rows[ $cells[0] ] = $cells;
			}
		}
		$this->assertArrayHasKey( (string) self::SIMPLE, $rows );
		$this->assertSame( [ (string) self::SIMPLE, self::NAME, CsvWriter::GUARD . self::SKU ], $rows[ (string) self::SIMPLE ], 'The name raw; the SKU raw behind the formula guard.' );
		$this->assertStringContainsString( '"' . self::NAME . '"', $csv, 'A CSV is not HTML: the name is quoted, not entity-encoded.' );
		$this->assertStringContainsString( '"' . CsvWriter::GUARD . str_replace( '"', '""', self::SKU ) . '"', $csv );
	}

	public function test_the_admin_notice_escapes_its_text(): void {
		$GLOBALS['bcm_test']['caps']['activate_plugins']       = true;
		$GLOBALS['bcm_test']['translate'][ self::NOTICE_TEXT ] = 'Needs ' . self::NAME . ' "10.0" & \'later\'';
		ob_start();
		( new Plugin() )->render_requirement_notice();
		$html = (string) ob_get_clean();
		$this->assertSame( '<div class="notice notice-error"><p>Needs &lt;img src=x onerror=alert(1)&gt; &quot;10.0&quot; &amp; &#039;later&#039;</p></div>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertSame( [ '<div class="notice notice-error">', '<p>', '</p>', '</div>' ], preg_match_all( '/<[^>]*>/', $html, $tags ) > 0 ? $tags[0] : [], 'Only the notice\'s own markup is HTML.' );

		// Nothing for someone who cannot manage plugins.
		$GLOBALS['bcm_test']['caps']['activate_plugins'] = false;
		ob_start();
		( new Plugin() )->render_requirement_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_no_dangerously_set_inner_html_under_assets(): void {
		$assets = self::root() . '/assets';
		$found  = [];
		if ( is_dir( $assets ) ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $assets, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				$path = (string) $file->getPathname();
				if ( str_contains( $path, '/node_modules/' ) || ! $file->isFile() ) {
					continue;
				}
				if ( str_contains( (string) file_get_contents( $path ), 'dangerouslySetInnerHTML' ) ) {
					$found[] = substr( $path, strlen( self::root() ) + 1 );
				}
			}
		}
		$this->assertSame( [], $found, 'SPEC §13: the screen renders text as text; dangerouslySetInnerHTML is never used.' );
	}

	/**
	 * The plugin file, uninstall.php and every PHP file under src/.
	 *
	 * @return list<string> Paths relative to the plugin root.
	 */
	private static function sources(): array {
		$files = [ 'bulk-catalogue-manager-for-woocommerce.php', 'uninstall.php' ];
		$src   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $src as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = substr( (string) $file->getPathname(), strlen( self::root() ) + 1 );
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Output statements in PHP code that print a variable or an unwrapped
	 * call: each `echo`, `print`, `<?=` and output function call, up to the
	 * end of its statement, with every esc_*, wp_kses* and integer-cast call
	 * removed, must hold nothing but literals, constants and operators.
	 *
	 * @param string $code PHP source.
	 * @return list<int> Lines of offending statements.
	 */
	private static function unescaped_output( string $code ): array {
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		$lines  = [];
		$next   = static function ( int $index ) use ( $tokens, $count ): int {
			for ( ++$index; $index < $count; ++$index ) {
				if ( ! is_array( $tokens[ $index ] ) || ! in_array( $tokens[ $index ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
					return $index;
				}
			}
			return $count;
		};
		$close  = static function ( int $open ) use ( $tokens, $count ): int {
			$nested = 0;
			for ( $index = $open; $index < $count; ++$index ) {
				$nested += '(' === $tokens[ $index ] ? 1 : ( ')' === $tokens[ $index ] ? -1 : 0 );
				if ( 0 === $nested ) {
					return $index;
				}
			}
			return $count;
		};
		for ( $i = 0; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				continue;
			}
			$start = null;
			if ( in_array( $token[0], [ T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO ], true ) ) {
				$start = $i + 1;
			} elseif ( T_STRING === $token[0] && in_array( strtolower( $token[1] ), self::OUTPUT_FUNCTIONS, true ) ) {
				$previous = $i - 1;
				while ( $previous >= 0 && is_array( $tokens[ $previous ] ) && T_WHITESPACE === $tokens[ $previous ][0] ) {
					--$previous;
				}
				$method = $previous >= 0 && is_array( $tokens[ $previous ] ) && in_array( $tokens[ $previous ][0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ], true );
				$open   = $next( $i );
				if ( ! $method && $open < $count && '(' === $tokens[ $open ] ) {
					$start = $open + 1;
				}
			}
			if ( null === $start ) {
				continue;
			}
			for ( $j = $start; $j < $count; ++$j ) {
				$part = $tokens[ $j ];
				if ( ';' === $part || ( is_array( $part ) && T_CLOSE_TAG === $part[0] ) ) {
					break;
				}
				if ( is_array( $part ) && T_STRING === $part[0] && 1 === preg_match( self::SAFE_WRAPPER, $part[1] ) ) {
					$open = $next( $j );
					if ( $open < $count && '(' === $tokens[ $open ] ) {
						// Skip the wrapped call, to its closing bracket.
						$j = $close( $open );
						continue;
					}
				}
				if ( is_array( $part ) && ( T_VARIABLE === $part[0] || ( T_STRING === $part[0] && ( $next( $j ) < $count && '(' === $tokens[ $next( $j ) ] ) ) ) ) {
					$lines[] = $token[2];
					break;
				}
			}
		}
		return array_values( array_unique( $lines ) );
	}

	public function test_the_output_scan_catches_unescaped_output_and_passes_escaped_output(): void {
		foreach ( [
			'<?php echo $name;',
			'<?php print get_the_title( 1 );',
			'<?php printf( \'<p>%s</p>\', $name );',
			'<?php printf( \'<p>%s</p>\', esc_html( $a ), $b );',
			'<?php echo \'<p>\' . __( \'Hi\', \'d\' ) . \'</p>\';',
			'<?php echo $this->label();',
			'<?php _e( $text, \'d\' );',
			'<p><?= $name ?></p>',
		] as $code ) {
			$this->assertSame( [ 1 ], self::unescaped_output( $code ), $code );
		}
		foreach ( [
			'<?php printf( \'<div class="notice"><p>%s</p></div>\', esc_html__( \'Hi\', \'d\' ) );',
			'<?php echo \'<div id="bcm-root"></div>\';',
			'<?php echo esc_html( $name ), \' \', esc_attr( get_the_title( $id ) );',
			'<?php echo wp_kses_post( $html ) . PHP_EOL;',
			'<?php echo absint( $count );',
			'<?php $writer->printf( $x ); function printf_like( $x ) {}',
		] as $code ) {
			$this->assertSame( [], self::unescaped_output( $code ), $code );
		}
	}

	public function test_no_output_in_the_plugin_prints_an_unescaped_value(): void {
		$offenders = [];
		$scanned   = 0;
		foreach ( self::sources() as $path ) {
			++$scanned;
			foreach ( self::unescaped_output( (string) file_get_contents( self::root() . '/' . $path ) ) as $line ) {
				if ( ! isset( self::ALLOWED_OUTPUT[ "$path:$line" ] ) ) {
					$offenders[] = "$path:$line";
				}
			}
		}
		$this->assertGreaterThan( 50, $scanned, 'The scan reads the plugin file, uninstall.php and src/.' );
		$this->assertSame( [], $offenders, 'Wrap printed values in an esc_* function (SPEC §13).' );
	}
}
