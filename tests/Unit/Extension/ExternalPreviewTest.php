<?php
/**
 * Preview of an external change (T106; docs/EXTENSIONS.md §6, §7.2, §10;
 * acceptance tests E5 and the unit half of E6): the exact selected
 * identities reach compute() one available member at a time, after the
 * core's own permission check, the provider's authorise() and the variation
 * rule; steps chain through an overlay; nothing is written or flushed; rows
 * carry text, never raw values; warnings aggregate by code; and the preview
 * hash binds the provider's identity and state token, so Apply can tell a
 * changed provider from a current one.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\External\Binding;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use BulkCatalogueManager\Tests\Support\WatchedProduct;
use BulkCatalogueManager\Tests\Unit\Query\SeededCatalogue;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';
require_once dirname( __DIR__ ) . '/Query/SeededCatalogue.php';

final class ExternalPreviewTest extends TestCase {
	private const USER = 7;

	private const PARENT_ONLY = '“Sample labels” is kept on the product, not on each variation.';

	private MemoryStore $store;

	protected function setUp(): void {
		bulkcat_test_reset();
		SampleProvider::reset();
		$GLOBALS['bulkcat_test']['user_id']                 = self::USER;
		$GLOBALS['bulkcat_test']['logged_in']               = true;
		$GLOBALS['bulkcat_test']['caps']                    = [ 'edit_post' => true ];
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [ 'edit_products' => true ];
		SeededCatalogue::seed();
		foreach ( [
			101 => [ 'simple', 'Blue Shirt', 0 ],
			102 => [ 'simple', 'Formal Shirt', 0 ],
			103 => [ 'variable', 'Rain Jacket', 0 ],
			201 => [ 'variation', 'Rain Jacket - Blue, Large', 103 ],
		] as $id => [ $type, $name, $parent ] ) {
			$GLOBALS['bulkcat_test']['products'][ $id ] = new WatchedProduct(
				$type,
				[
					'id'        => $id,
					'name'      => $name,
					'status'    => 'publish',
					'parent_id' => $parent,
				]
			);
		}
		$GLOBALS['bulkcat_test']['watched'] = [];
		$this->store                        = new MemoryStore();
		$this->store->data[101]             = [ '_sample_labels' => '["blue"]' ];
	}

	protected function tearDown(): void {
		global $wpdb;
		$this->assertSame( [], $this->store->writes, 'A preview wrote to the provider\'s storage.' );
		$this->assertSame( [], SampleProvider::$flushed, 'A preview flushed.' );
		$this->assertSame( [], $wpdb->writes, 'A preview wrote to the database.' );
		$this->assertSame( [], array_diff( array_column( $GLOBALS['bulkcat_test']['watched'] ?? [], 0 ), [ 'clone' ] ), 'A preview touched a product beyond copying it.' );
	}

	private function preview(): Preview {
		$actions = new Actions( new Fields(), null, Providers::of( [ new SampleProvider( $this->store ) ] ) );
		return new Preview( $actions, new BatchFactory( $actions ) );
	}

	/**
	 * A preview that answered.
	 *
	 * @param array<mixed> $operations Operations.
	 * @param Selection    $selection  Selection; 101, 102, 201 and the gone 999 by default.
	 * @return array<string, mixed>
	 */
	private function answer( array $operations, ?Selection $selection = null ): array {
		$selection ??= Selection::of_members(
			[
				'products'   => [ 101, 102, 999 ],
				'variations' => [ 201 ],
			]
		);
		$result      = $this->preview()->run( $selection, $operations, 1000, '', new Settings( [] ), true );
		$this->assertTrue( is_array( $result ), $result instanceof \WP_Error ? $result->get_error_code() . ' ' . $result->get_error_message() : '' );
		return $result;
	}

	private static function add( array $labels ): array {
		return [
			'action' => 'x_sample_add',
			'args'   => [
				'field'  => 'x_sample_labels',
				'labels' => $labels,
			],
		];
	}

	/**
	 * Sample rows as [ object id, current text, new text, state, reason ].
	 *
	 * @param array<string, mixed> $result A preview.
	 * @return list<array{0: int, 1: mixed, 2: mixed, 3: string, 4: string}>
	 */
	private static function rows( array $result ): array {
		return array_map( static fn( array $r ): array => [ $r['object_id'], $r['current'], $r['new'], $r['state'], $r['reason'] ], $result['sample'] );
	}

	public function test_the_exact_available_members_reach_the_provider_and_rows_are_text(): void {
		$result = $this->answer( [ self::add( [ 'red' ] ) ] );
		$this->assertSame(
			[
				[ 101, 'Blue', 'Blue, Red', 'changed', '' ],
				[ 102, 'No labels', 'Red', 'changed', '' ],
				[ 201, '', '', 'skipped', self::PARENT_ONLY ],
			],
			self::rows( $result )
		);
		$first = $result['sample'][0];
		$this->assertSame( 'x_sample_labels', $first['field'] );
		$this->assertSame( 'Sample labels', $first['fieldLabel'] );
		$this->assertSame( 'Adds Red', $first['change'] );
		$this->assertSame( [ 'Keeps 1 existing label.' ], $first['notes'] );
		$this->assertTrue( $first['external'] );
		$this->assertSame( [], $result['sample'][1]['notes'] );
		$this->assertSame( 'bulkcat_not_available', $result['sample'][2]['code'] );
		$this->assertSame( [ [ 'x_sample_add', 101, 'preview' ], [ 'x_sample_add', 102, 'preview' ] ], SampleProvider::$computed, 'Only available products are computed; the variation is skipped by the core.' );
		$this->assertSame( [ 101, 102, 201 ], SampleProvider::$authorised );
		$this->assertSame( [ [ 101, 102, 201 ] ], SampleProvider::$primed, 'One prime per page, of the available members.' );
		$this->assertSame( [ [ 3, [ 101, 102, 201 ] ] ], SampleProvider::$tokens, 'The state token sees the available members, ascending.' );
		$this->assertSame( [ 999 ], $result['unreadable'], 'The core reports the unavailable member; the provider never sees it.' );
		$this->assertSame( 2, $result['totals']['would_change'] );
		$this->assertSame( [ self::PARENT_ONLY => 1 ], $result['totals']['would_fail'] );
		$this->assertSame( 'Sample labels will change on 2 products.', $result['summary'] );
		$this->assertSame(
			[
				'sale_above_regular' => 0,
				'price_not_positive' => 0,
				'stock_negative'     => 0,
				'sku_duplicate'      => 0,
			],
			$result['warnings'],
			'The native warning counts stay as they are.'
		);
		$this->assertSame( 'sample', $result['external']['provider'] );
		$this->assertSame( 'Sample labels', $result['external']['label'] );
		$this->assertSame( [], $result['external']['warnings'] );
		$this->assertFalse( $result['external']['confirm'] );
		foreach ( $result['sample'] as $row ) {
			$this->assertTrue( is_string( $row['current'] ), 'No raw value is sent.' );
			$this->assertTrue( is_string( $row['new'] ) );
		}
	}

	public function test_steps_chain_through_the_overlay(): void {
		$result = $this->answer(
			[
				self::add( [ 'red' ] ),
				[
					'action' => 'x_sample_remove',
					'args'   => [
						'field'  => 'x_sample_labels',
						'labels' => [ 'blue' ],
					],
				],
			],
			Selection::of_ids( [ 101 ] )
		);
		$this->assertSame(
			[
				[ 101, 'Blue', 'Blue, Red', 'changed', '' ],
				[ 101, 'Blue, Red', 'Red', 'changed', '' ],
			],
			self::rows( $result )
		);
		$this->assertSame( 1, $result['totals']['would_change'] );
	}

	public function test_unknown_refused_and_failing_objects_are_listed_with_their_reason(): void {
		update_option(
			'sample_provider_faults',
			[
				'unknown' => [ 102 ],
				'refuse'  => [ 201 ],
			]
		);
		$result = $this->answer( [ self::add( [ 'red' ] ) ] );
		$this->assertSame( [ 102, '', '', 'failed', 'The sample labels of this product could not be read.' ], self::rows( $result )[1] );
		$this->assertSame( 'x_sample_unreadable', $result['sample'][1]['code'] );
		$this->assertSame( [ 201, '', '', 'skipped', 'The sample add-on does not allow changes to this product.' ], self::rows( $result )[2] );
		$this->assertSame( [ [ 'x_sample_add', 101, 'preview' ] ], SampleProvider::$computed, 'An Unknown value is never computed on.' );

		update_option(
			'sample_provider_faults',
			[
				'throw_compute' => [ 101 ],
				'wrong_field'   => [ 102 ],
			]
		);
		$result = $this->answer( [ self::add( [ 'red' ] ) ] );
		$this->assertSame( 'failed', $result['sample'][0]['state'] );
		$this->assertSame( 'bulkcat_external_error', $result['sample'][0]['code'] );
		$this->assertStringNotContainsString( 'on purpose', $result['sample'][0]['reason'], 'The exception stays in the log.' );
		$this->assertSame( 'bulkcat_external_error', $result['sample'][1]['code'], 'A result naming another field fails its step.' );
		$this->assertStringContainsString( 'The sample action failed on purpose.', implode( "\n", array_column( $GLOBALS['bulkcat_test']['logged'] ?? [], 1 ) ) );

		update_option( 'sample_provider_faults', [ 'huge' => [ 101 ] ] );
		$this->assertSame( 'bulkcat_value_too_large', $this->answer( [ self::add( [ 'red' ] ) ], Selection::of_ids( [ 101 ] ) )['sample'][0]['code'] );
	}

	public function test_the_cores_edit_check_comes_before_the_provider(): void {
		$GLOBALS['bulkcat_test']['caps']['edit_post:101'] = false;
		$result = $this->answer( [ self::add( [ 'red' ] ) ], Selection::of_ids( [ 101, 102 ] ) );
		$this->assertSame( 'skipped', $result['sample'][0]['state'] );
		$this->assertSame( 'bulkcat_cannot_edit', $result['sample'][0]['code'] );
		$this->assertSame( '', $result['sample'][0]['name'], 'Nothing of an object the user may not edit is shown.' );
		$this->assertSame( [ 102 ], SampleProvider::$authorised, 'The provider is never asked about it.' );
	}

	public function test_warnings_aggregate_by_code_and_confirmations_are_flagged(): void {
		$result = $this->answer( [ self::add( [ 'sale' ] ) ], Selection::of_ids( [ 101, 102 ] ) );
		$this->assertSame(
			[
				[
					'code'     => 'x_sample_sale',
					'sentence' => 'Adding the Sale label asks for a second click before it is applied.',
					'count'    => 2,
					'confirm'  => true,
					'by_type'  => [
						'product'   => 2,
						'variation' => 0,
					],
				],
			],
			$result['external']['warnings']
		);
		$this->assertTrue( $result['external']['confirm'] );
		$replace = $this->answer(
			[
				[
					'action' => 'x_sample_replace',
					'args'   => [
						'field'  => 'x_sample_labels',
						'labels' => [ 'red' ],
					],
				],
			],
			Selection::of_ids( [ 101 ] )
		);
		$this->assertTrue( $replace['external']['confirm'] );
		$this->assertSame( [ 'This replaces every label on the chosen products.' ], $replace['external']['reasons'] );
		$this->assertTrue( $replace['sample'][0]['replaces'] );
	}

	public function test_a_warning_counts_each_object_once_split_by_type(): void {
		// Ticket 11: a count that can include variations says so. Two steps
		// raising one code on the same object count it once.
		update_option(
			'sample_provider_choices',
			[
				'a' => 'A',
				'b' => 'B',
				'c' => 'C',
				'd' => 'D',
				'e' => 'E',
			]
		);
		$many = $this->answer( [ self::add( [ 'a', 'b', 'c', 'd' ] ), self::add( [ 'e' ] ) ], Selection::of_ids( [ 101, 102 ] ) );
		$this->assertSame(
			[
				[
					'code'     => 'x_sample_many',
					'sentence' => 'Some products will carry more than three labels.',
					'count'    => 2,
					'confirm'  => false,
					'by_type'  => [
						'product'   => 2,
						'variation' => 0,
					],
				],
			],
			$many['external']['warnings']
		);
		$loud = $this->answer(
			[
				[
					'action' => 'x_sample_set_note',
					'args'   => [
						'field'               => 'x_sample_note',
						'note'                => 'Hello!',
						'apply_to_variations' => true,
					],
				],
			],
			Selection::of_members(
				[
					'products'   => [ 101 ],
					'variations' => [ 201 ],
				]
			)
		);
		$this->assertSame(
			[
				'product'   => 1,
				'variation' => 1,
			],
			$loud['external']['warnings'][0]['by_type']
		);
		$this->assertSame( 2, $loud['external']['warnings'][0]['count'] );
	}

	public function test_an_unavailable_provider_refuses_the_preview(): void {
		update_option( 'sample_provider_faults', [ 'unavailable' => true ] );
		$refused = $this->preview()->run( Selection::of_ids( [ 101 ] ), [ self::add( [ 'red' ] ) ], 25, '', new Settings( [] ), true );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bulkcat_provider_unavailable', $refused->get_error_code() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );
		$this->assertSame( [], SampleProvider::$computed );
	}

	public function test_the_hash_binds_the_providers_identity_and_state(): void {
		$first = $this->answer( [ self::add( [ 'red' ] ) ] )['hash'];
		$this->assertSame( $first, $this->answer( [ self::add( [ 'red' ] ) ] )['hash'], 'Stable for the same provider and state.' );
		update_option( 'sample_provider_faults', [ 'state' => 'moved' ] );
		$this->assertNotSame( $first, $this->answer( [ self::add( [ 'red' ] ) ] )['hash'], 'A changed state token changes the hash.' );
		update_option( 'sample_provider_faults', [ 'version' => '1.0.1' ] );
		$this->assertNotSame( $first, $this->answer( [ self::add( [ 'red' ] ) ] )['hash'], 'A new provider version changes the hash.' );
		update_option( 'sample_provider_faults', [ 'format' => 2 ] );
		$this->assertNotSame( $first, $this->answer( [ self::add( [ 'red' ] ) ] )['hash'], 'A new value format changes the hash.' );
		delete_option( 'sample_provider_faults' );

		$settings = new Settings( [] );
		$this->assertSame( Preview::hash( [ 1 ], [], $settings, 'abc' ), Preview::hash( [ 1 ], [], $settings, 'abc', [] ), 'A native change hashes exactly as before.' );
		$binding = Binding::of( Providers::of( [ new SampleProvider( $this->store ) ] )->get( 'sample' ), [ self::add( [ 'red' ] ) ], [ 102, 101 ] );
		$this->assertSame(
			[ 'provider', 'version', 'contract', 'formats', 'state' ],
			array_keys( $binding )
		);
		$this->assertSame( [ 'x_sample_labels' => 1 ], $binding['formats'] );
		$this->assertNotSame( Preview::hash( [ 1 ], [], $settings, 'abc' ), Preview::hash( [ 1 ], [], $settings, 'abc', $binding ) );
		$this->assertSame( [ 101, 102 ], end( SampleProvider::$tokens )[1], 'The binding\'s scope is ascending.' );
	}
}
