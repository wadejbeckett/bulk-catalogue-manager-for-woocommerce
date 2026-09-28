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
}
