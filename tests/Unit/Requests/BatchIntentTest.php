<?php
/**
 * Durable batch intents (T83): the canonical Apply and undo payload a grant
 * signs excludes credentials, keeps every default explicit and is stable
 * when normalised again.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Requests\BatchIntent;
use BulkCatalogueManager\Requests\CaptureIntent;
use PHPUnit\Framework\TestCase;

final class BatchIntentTest extends TestCase {
	public function test_explicit_ids_and_normalized_operations_are_stable(): void {
		bulkcat_test_reset();
		$input  = [
			'ids'        => [ 5, 2, 5 ],
			'operations' => [
				[
					'action' => 'set_value',
					'args'   => [
						'field' => 'regular_price',
						'value' => '12',
					],
				],
			],
			'scope_hash' => str_repeat( 'a', 64 ),
		];
		$intent = BatchIntent::normalize( 'batch.apply', $input, 1 );
		$this->assertTrue( is_array( $intent ), 'A valid Apply intent normalises.' );
		$this->assertSame( [ 2, 5 ], $intent['ids'] );
		$this->assertSame( 'bulk', $intent['source'] );
		$this->assertSame( $intent, BatchIntent::normalize( 'batch.apply', $intent, 1 ) );
		$other               = $intent;
		$other['scope_hash'] = str_repeat( 'b', 64 );
		$this->assertNotSame( CaptureIntent::fingerprint( $intent ), CaptureIntent::fingerprint( $other ) );
	}

	public function test_an_apply_intent_carrying_the_internal_undo_step_is_refused(): void {
		// T90: restore_status runs only as an undo item the planner made; a client's Apply cannot name it.
		bulkcat_test_reset();
		$refused = BatchIntent::normalize(
			'batch.apply',
			[
				'ids'        => [ 5 ],
				'operations' => [
					[
						'action' => 'restore_status',
						'args'   => [
							'field' => 'status',
							'value' => 'publish',
						],
					],
				],
				'scope_hash' => str_repeat( 'a', 64 ),
			],
			1
		);
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'bulkcat_unknown_action', $refused->get_error_code() );
		$this->assertInstanceOf(
			\WP_Error::class,
			BatchIntent::current_operations(
				[
					'operations' => [
						[
							'action' => 'restore_status',
							'args'   => [
								'field' => 'status',
								'value' => 'publish',
							],
						],
					],
				]
			)
		);
	}

	public function test_released_status_intent_stays_exact_but_needs_new_identity_before_acceptance(): void {
		bulkcat_test_reset();
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/released-status-receipts.json' ), true );
		foreach ( $fixture['batches'] as $case ) {
			$intent = $case['intent'];
			$this->assertSame( $case['receipt']['fingerprint'], CaptureIntent::fingerprint( $intent ) );
			$this->assertSame( $intent, BatchIntent::normalize( 'batch.apply', $intent, (int) $case['receipt']['user_id'] ) );
			$this->assertFalse( BatchIntent::current_operations( $intent ), 'Unaccepted released grants must require a new preview.' );
			$raw = $intent;
			unset( $raw['version'] );
			$new = BatchIntent::normalize( 'batch.apply', $raw, (int) $case['receipt']['user_id'] );
			$this->assertSame( 'set_status', $new['operations'][0]['action'] );
			$this->assertArrayNotHasKey( 'keep_changed_status', $new['operations'][0]['args'] );
			$this->assertNotSame( $case['receipt']['fingerprint'], CaptureIntent::fingerprint( $new ) );
			$this->assertSame( $new['operations'], BatchIntent::current_operations( $new ) );
		}
	}

	public function test_undo_intent_binds_force_original_and_plan(): void {
		bulkcat_test_reset();
		$intent = BatchIntent::normalize(
			'batch.undo',
			[
				'batch_id'  => 8,
				'plan_hash' => str_repeat( 'a', 64 ),
				'force'     => false,
			],
			1
		);
		$this->assertSame( $intent, BatchIntent::normalize( 'batch.undo', $intent, 1 ) );
		$this->assertSame( 'undo', $intent['source'] );
		try {
			BatchIntent::normalize( 'batch.undo', $intent + [ 'preview_hash' => str_repeat( 'b', 64 ) ], 1 );
			$this->fail( 'A transport value was accepted into the undo intent.' );
		} catch ( \InvalidArgumentException $error ) {
			$this->assertNotEmpty( $error->getMessage() );
		}
	}

	/**
	 * A canonical Apply intent over explicit ids.
	 *
	 * @return array<string, mixed>
	 */
	private static function canonical(): array {
		bulkcat_test_reset();
		$intent = BatchIntent::normalize(
			'batch.apply',
			[
				'ids'        => [ 7, 3 ],
				'operations' => [
					[
						'action' => 'set_value',
						'args'   => [
							'field' => 'regular_price',
							'value' => '12',
						],
					],
				],
				'scope_hash' => str_repeat( 'c', 64 ),
			],
			1
		);
		return is_array( $intent ) ? $intent : [];
	}

	public function test_a_canonical_intent_is_never_normalised_again(): void {
		// T83: an action the registry no longer takes must not stop an accepted request resolving.
		$intent                             = self::canonical();
		$retired                            = $intent;
		$retired['operations'][0]['action'] = 'retired_action';
		$this->assertSame( $retired, BatchIntent::normalize( 'batch.apply', $retired, 1 ), 'Only its shape is checked; its fingerprint is what was signed.' );
		$refusal = BatchIntent::current_operations( $retired );
		$this->assertInstanceOf( \WP_Error::class, $refusal, 'A new acceptance asks the registry.' );
		$this->assertSame( 'bulkcat_unknown_action', $refusal->get_error_code() );
		$this->assertSame( $intent['operations'], BatchIntent::current_operations( $intent ) );

		$drifted = $intent;
		unset( $drifted['operations'][0]['args']['skip_already'] );
		$this->assertFalse( BatchIntent::current_operations( $drifted ), 'A list the registry would now fill in differently is stale.' );
	}

	public function test_what_a_new_acceptance_records_is_the_registrys_own_list_not_the_shape_sent(): void {
		// Review finding: the fingerprint sorts map keys, so it cannot tell a list from a map with
		// the same numbered keys, nor one key order from another; the registry's list is recorded.
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['terms'] = [ 'product_cat' => [ 10, 11 ] ];
		$intent                           = BatchIntent::normalize(
			'batch.apply',
			[
				'ids'        => [ 3 ],
				'operations' => [
					[
						'action' => 'add_terms',
						'args'   => [
							'field' => 'category_ids',
							'terms' => [ 10, 11 ],
						],
					],
				],
				'scope_hash' => str_repeat( 'c', 64 ),
			],
			1
		);
		$this->assertTrue( is_array( $intent ), $intent instanceof \WP_Error ? $intent->get_error_message() : '' );

		$reordered                          = $intent;
		$reordered['operations'][0]['args'] = array_reverse( $intent['operations'][0]['args'], true );
		$this->assertSame( CaptureIntent::fingerprint( $intent ), CaptureIntent::fingerprint( $reordered ), 'Both key orders carry the same signature.' );
		$this->assertNotSame( $intent['operations'], $reordered['operations'] );
		$this->assertSame( $intent['operations'], BatchIntent::current_operations( $reordered ), 'The registry\'s list is what is recorded.' );

		$mapped                                   = $intent;
		$mapped['operations'][0]['args']['terms'] = [
			1 => 11,
			0 => 10,
		];
		$this->assertSame( CaptureIntent::fingerprint( $intent ), CaptureIntent::fingerprint( $mapped ), 'A numbered map carries the same signature as the list.' );
		$refused = BatchIntent::current_operations( $mapped );
		$this->assertInstanceOf( \WP_Error::class, $refused, 'A map where a list belongs is never accepted.' );
	}

	public function test_a_canonical_intent_has_exactly_its_shape(): void {
		$intent = self::canonical();
		$this->assertSame( [ 3, 7 ], $intent['ids'] );
		$cases = [
			'an extra key'         => [ 'preview_hash' => str_repeat( 'a', 64 ) ] + $intent,
			'unsorted ids'         => [ 'ids' => [ 7, 3 ] ] + $intent,
			'repeated ids'         => [ 'ids' => [ 3, 3, 7 ] ] + $intent,
			'ids as text'          => [ 'ids' => [ '3', '7' ] ] + $intent,
			'another source'       => [ 'source' => 'undo' ] + $intent,
			'another version'      => [ 'version' => 2 ] + $intent,
			'a bad scope hash'     => [ 'scope_hash' => 'abc' ] + $intent,
			'no operations'        => [ 'operations' => [] ] + $intent,
			'a step without args'  => [ 'operations' => [ [ 'action' => 'set_value' ] ] ] + $intent,
			'ids and a snapshot'   => [ 'snapshot' => str_repeat( 'a', 32 ) ] + $intent,
			'a malformed snapshot' => [ 'snapshot' => 'nope' ] + array_diff_key( $intent, [ 'ids' => 0 ] ),
		];
		foreach ( $cases as $case => $input ) {
			try {
				BatchIntent::normalize( 'batch.apply', $input, 1 );
				$this->fail( "Accepted $case." );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertNotEmpty( $error->getMessage(), $case );
			}
		}
		$undo = BatchIntent::normalize(
			'batch.undo',
			[
				'batch_id'  => 4,
				'plan_hash' => str_repeat( 'd', 64 ),
			],
			1
		);
		$this->assertSame( false, $undo['force'], 'Force is written out.' );
		foreach ( [ [ 'force' => 1 ] + $undo, [ 'batch_id' => '4' ] + $undo, array_diff_key( $undo, [ 'force' => 0 ] ) ] as $index => $input ) {
			try {
				BatchIntent::normalize( 'batch.undo', $input, 1 );
				$this->fail( "Accepted undo case $index." );
			} catch ( \InvalidArgumentException $error ) {
				$this->assertNotEmpty( $error->getMessage() );
			}
		}
	}
}
