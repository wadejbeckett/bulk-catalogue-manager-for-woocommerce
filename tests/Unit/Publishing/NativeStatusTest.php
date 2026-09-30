<?php
/**
 * Plan 14 Task 1: deliberately RED until the new native Status engine lands.
 * Expectations come from native-status.json and the guarded native HTTP probe;
 * these doubles exercise only the proposed contract, never prove WP behaviour.
 * The legacy status-switch fixture/tests remain unchanged.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Publishing;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Publishing\PublicationPassword;
use BulkCatalogueManager\Publishing\StatusPermission;
use BulkCatalogueManager\Publishing\StatusTransition;
use BulkCatalogueManager\Tests\Support\SavedProduct;
use PHPUnit\Framework\TestCase;

final class NativeStatusTest extends TestCase {
	private const ID  = 501;
	private const NOW = 1790683200;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['caps'] = [
			'edit_post'        => true,
			'edit_products'    => true,
			'publish_products' => true,
		];
	}

	/** @return array<string, mixed> Native oracle comparison table. */
	private static function fixture(): array {
		return json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/native-status.json' ), true );
	}

	/** @return array<string, mixed> New request, not a stored accepted operation. */
	private static function step( string $value, array $extra = [] ): array {
		$steps = ( new Registry( new Fields() ) )->validate_list(
			[
				[
					'action' => 'set_value',
					'args'   => $extra + [
						'field'               => 'status',
						'value'               => $value,
						'apply_to_variations' => false,
					],
				],
			]
		);
		self::assertTrue( is_array( $steps ) );
		return $steps[0];
	}

	private static function product( string $status = 'draft', ?int $date = null, string $kind = 'simple' ): SavedProduct {
		return new SavedProduct(
			$kind,
			[
				'id'            => self::ID,
				'parent_id'     => 'variation' === $kind ? 500 : 0,
				'status'        => $status,
				'date_created'  => new \DateTimeImmutable( '@' . ( $date ?? self::NOW - 86400 ) ),
				'post_password' => 'synthetic-password',
			]
		);
	}

	public function test_matrix_covers_every_previous_and_writable_target_and_all_record_shapes(): void {
		$rows = self::fixture()['rows'];
		foreach ( [ 'publish', 'pending', 'private', 'draft', 'future' ] as $initial ) {
			foreach ( self::fixture()['writable'] as $target ) {
				$this->assertContains( $initial . '-to-' . $target, array_column( $rows, 'case' ) );
			}
		}
		foreach ( [ 'private', 'pending' ] as $initial ) {
			foreach ( [ 'none', 'own', 'foreign', 'malformed' ] as $shape ) {
				$this->assertContains( $initial . '-draft-publish-' . $shape, array_column( $rows, 'case' ) );
			}
		}
	}

	public function test_new_status_requests_have_a_distinct_identity_without_changing_other_actions(): void {
		$this->assertSame( 'set_status', self::step( 'publish' )['action'] );
		$steps = ( new Registry( new Fields() ) )->validate_list(
			[
				[
					'action' => 'set_value',
					'args'   => [
						'field' => 'weight',
						'value' => '1.25',
					],
				],
			]
		);
		$this->assertSame( 'set_value', $steps[0]['action'] );
	}

	public function test_publish_does_not_restore_remembered_private_or_pending(): void {
		foreach ( [ 'private', 'pending' ] as $remembered ) {
			$product                              = self::product();
			$product->meta[ OffRecord::META_KEY ] = OffRecord::create( self::ID, $remembered, '2026-09-01T08:00:00Z', 7 )->to_json();
			$step                                 = self::step( 'publish' );
			$operation                            = ( new Registry( new Fields() ) )->get( $step['action'] );
			$result                               = $operation->compute( $product, $step['args'] );
			$this->assertSame( Result::CHANGED, $result->state() );
			$this->assertSame( 'publish', $result->new(), 'Native editor publishes; release restores ' . $remembered );
			$follow = $operation->follow( $product, $result, $step['args'] );
			$this->assertCount( 1, $follow );
			$this->assertSame( [ 'availability_off', '' ], [ $follow[0]->field(), $follow[0]->new() ] );
		}
	}

	public function test_draft_creates_no_new_remembered_record(): void {
		$product   = self::product( 'private' );
		$step      = self::step( 'draft' );
		$operation = ( new Registry( new Fields() ) )->get( $step['action'] );
		$result    = $operation->compute( $product, $step['args'] );
		$this->assertSame( 'draft', $result->new() );
		$this->assertSame( [], $operation->follow( $product, $result, $step['args'] ) );
	}

	public function test_genuine_noop_preserves_owned_foreign_and_malformed_records_without_save(): void {
		foreach ( [ OffRecord::create( self::ID, 'private', '2026-09-01T08:00:00Z', 7 )->to_json(), OffRecord::create( self::ID + 1, 'pending', '2026-09-01T08:00:00Z', 7 )->to_json(), '{malformed' ] as $raw ) {
			$product                              = self::product();
			$product->meta[ OffRecord::META_KEY ] = $raw;
			$step                                 = self::step( 'draft' );
			$operation                            = ( new Registry( new Fields() ) )->get( $step['action'] );
			$result                               = $operation->compute( $product, $step['args'] );
			$this->assertSame( Result::ALREADY, $result->state() );
			$this->assertSame( [], $operation->follow( $product, $result, $step['args'] ) );
			$this->assertSame( $raw, $product->meta[ OffRecord::META_KEY ] );
			$this->assertSame( [], $product->saves );
		}
	}

	public function test_explicit_old_keep_intent_requires_fresh_review(): void {
		$list = ( new Registry( new Fields() ) )->validate_list(
			[
				[
					'action' => 'set_value',
					'args'   => [
						'field'               => 'status',
						'value'               => 'publish',
						'keep_changed_status' => true,
					],
				],
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $list );
	}

	public function test_native_prediction_covers_the_product_transition_matrix(): void {
		foreach ( self::fixture()['rows'] as $row ) {
			if ( ! str_contains( $row['case'], '-to-' ) || isset( $row['password'] ) ) {
				continue;
			}
			$date       = 'ahead' === $row['date'] ? self::NOW + 86400 : self::NOW - 86400;
			$product    = self::product( $row['initial'], $date );
			$prediction = StatusTransition::predict( $product, $row['requested'][0], self::NOW );
			$this->assertSame( $row['requested'][0], $prediction['requested'], $row['case'] );
			$this->assertSame( $row['effective'], $prediction['effective'], $row['case'] );
			$this->assertSame( [], $product->sets, $row['case'] );
		}
	}

	public function test_native_prediction_uses_the_sixty_second_boundary_with_an_injected_clock(): void {
		foreach ( self::fixture()['boundaries'] as $row ) {
			$date    = self::NOW + $row['offset'];
			$product = self::product( 'draft', $date );
			$this->assertSame(
				[
					'requested' => 'publish',
					'effective' => $row['effective'],
					'date_gmt'  => gmdate( 'Y-m-d H:i:s', $date ),
				],
				StatusTransition::predict( $product, 'publish', self::NOW ),
				(string) $row['offset']
			);
			$this->assertSame( [], $product->sets );
		}
	}

	public function test_native_prediction_reads_wc_absolute_date_across_timezones_and_dst_fold(): void {
		foreach ( self::fixture()['date_cases'] as $row ) {
			$date                           = new \DateTimeImmutable( $row['gmt'], new \DateTimeZone( 'UTC' ) );
			$product                        = self::product();
			$product->props['date_created'] = $date->setTimezone( new \DateTimeZone( $row['timezone'] ) );
			$this->assertSame( $row['local'], $product->props['date_created']->format( 'Y-m-d H:i:s' ) );
			$prediction = StatusTransition::predict( $product, 'publish', self::NOW );
			$this->assertSame( $row['gmt'], $prediction['date_gmt'] );
			$this->assertSame( 'future', $prediction['effective'] );
		}
	}

	public function test_no_date_uses_the_injected_clock_instead_of_predicting_an_epoch_schedule(): void {
		$product                        = self::product();
		$product->props['date_created'] = null;
		$this->assertSame(
			[
				'requested' => 'publish',
				'effective' => 'publish',
				'date_gmt'  => gmdate( 'Y-m-d H:i:s', self::NOW ),
			],
			StatusTransition::predict( $product, 'publish', self::NOW )
		);
	}

	public function test_new_publication_needs_publish_capability_but_editable_published_resaves_do_not(): void {
		$GLOBALS['bulkcat_test']['caps']['publish_products'] = false;
		foreach ( [ 'draft', 'pending', 'private' ] as $status ) {
			$this->assertInstanceOf( \WP_Error::class, StatusPermission::check( null, self::product( $status ), 'publish' ) );
		}
		foreach ( [ 'publish', 'future' ] as $status ) {
			$this->assertNull( StatusPermission::check( null, self::product( $status ), 'publish' ) );
		}
		$this->assertInstanceOf( \WP_Error::class, StatusPermission::check( null, self::product(), 'private' ) );
	}

	public function test_recorded_actor_and_revocation_do_not_borrow_the_current_administrator(): void {
		$product                                  = self::product();
		$GLOBALS['bulkcat_test']['user_caps'][42] = [
			'edit_post'        => true,
			'publish_products' => true,
		];
		$this->assertNull( StatusPermission::check( 42, $product, 'publish' ) );
		$GLOBALS['bulkcat_test']['user_caps'][42] = [];
		$this->assertInstanceOf( \WP_Error::class, StatusPermission::check( 42, $product, 'publish' ) );
		$this->assertInstanceOf( \WP_Error::class, StatusPermission::check( 0, $product, 'publish' ) );
	}

	public function test_variation_enable_is_separate_from_product_publication_and_requires_parent_edit(): void {
		$GLOBALS['bulkcat_test']['caps']['publish_products'] = false;
		$product = self::product( 'private', null, 'variation' );
		$this->assertNull( StatusPermission::check( null, $product, 'publish' ) );
		$GLOBALS['bulkcat_test']['caps']['edit_post:500'] = false;
		$this->assertInstanceOf( \WP_Error::class, StatusPermission::check( null, $product, 'publish' ) );
	}

	public function test_internal_password_field_uses_verified_wc_methods_and_redacts_public_values(): void {
		$field = PublicationPassword::field();
		$this->assertTrue( $field->internal() );
		$this->assertSame( 'get_post_password', $field->getter() );
		$this->assertSame( 'set_post_password', $field->setter() );
		$this->assertStringNotContainsString( 'synthetic-password', PublicationPassword::display( 'synthetic-password' ) );
	}
}
