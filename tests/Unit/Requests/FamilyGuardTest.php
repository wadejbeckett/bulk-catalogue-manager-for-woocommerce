<?php
/**
 * S2 acceptance regression; exercises the accepted typed request contract.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Requests\RequestService;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/V2/load.php';

final class FamilyGuardTest extends TestCase {
	public function test_typed_guard_is_fourth_and_does_not_reinterpret_disclosure(): void {
		$parameters = ( new \ReflectionMethod( RequestService::class, 'guard' ) )->getParameters();
		$this->assertSame( [ 'operation', 'change', 'disclosure', 'typed' ], array_map( static fn( \ReflectionParameter $p ): string => $p->getName(), $parameters ) );
		$this->assertFalse( $parameters[2]->getDefaultValue() );
		$this->assertFalse( $parameters[3]->getDefaultValue() );
	}
	public function test_direct_service_rechecks_recorded_actor_native_target_and_false_family(): void {
		$s = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$s->product(
			41,
			[
				'a'    => [],
				'b'    => [],
				'c'    => [],
				'mode' => 'plain',
			]
		);
		$body = [
			'serializer' => 2,
			'external'   => [
				'contract' => 2,
				'provider' => 'sample',
				'identity' => 'org.example.sample',
			],
			'entry'      => 'product.bulk',
			'targets'    => [
				[
					'kind'     => 'product',
					'taxonomy' => null,
					'id'       => 41,
				],
			],
			'operations' => [ $s->step( 'a', 'add', [ 'v1' ] ) ],
		];
		$this->assertTrue( is_array( $s->service->preview( $body ) ) );
		$GLOBALS['bulkcat_test']['user_caps'][3]['edit_post:41'] = false;
		$GLOBALS['bulkcat_test']['user_caps'][3]['edit_post']    = false;
		$this->assertInstanceOf( \WP_Error::class, $s->service->preview( $body ) );
		$GLOBALS['bulkcat_test']['user_caps'][3]['edit_post'] = true;
		unset( $GLOBALS['bulkcat_test']['user_caps'][3]['edit_products'] );
		$this->assertTrue( true === \BulkCatalogueManager\Rest\Access::user_can_family( 3, 'category' ) );
		$error = $s->service->preview( $body );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$body['entry']  = 'category.bulk';
		$body['family'] = 'category';
		$this->assertInstanceOf( \WP_Error::class, $s->service->preview( $body ) );
		$this->assertCount( 0, $s->database->batches );
		$this->assertCount( 0, $s->database->receipts );
	}

	public function test_captured_denial_keeps_failed_slot_and_never_reads_its_provider_value(): void {
		$s       = new \BulkCatalogueManager\Tests\Support\Extension\V2\Scenario();
		$targets = [];
		foreach ( [ 41, 42 ] as $id ) {
			$s->product(
				$id,
				[
					'a'    => [],
					'b'    => [],
					'c'    => [],
					'mode' => 'plain',
				]
			);
			$targets[] = [
				'kind'     => 'product',
				'taxonomy' => null,
				'id'       => $id,
			]; }
		$row                                = ( new \BulkCatalogueManager\Selection\SnapshotRepository() )->create(
			[
				'version' => 3,
				'targets' => $targets,
			],
			3,
			time()
		);
		$token                              = \BulkCatalogueManager\Selection\SnapshotToken::issue( $row );
		$s->store->faults['denied_targets'] = [ 'product::42' ];
		$input                              = [
			'serializer'  => 2,
			'external'    => [
				'contract' => 2,
				'provider' => 'sample',
				'identity' => 'org.example.sample',
			],
			'entry'       => 'product.bulk',
			'typed_token' => $token,
			'operations'  => [ $s->step( 'a', 'add', [ 'v1' ] ) ],
		];
		$p                                  = $s->service->preview( $input );
		$this->assertTrue( is_array( $p ) );
		$this->assertSame( 2, $p['totals']['matched'] );
		$this->assertSame( 1, $p['unavailable_count'] );
		$this->assertSame( [ 'product::41' ], $s->store->reads );
		$answer = $s->accept( $p );
		$this->assertTrue( is_array( $answer ) );
		$this->assertSame( 2, $answer['total_items'] );
		$batch = ( new \BulkCatalogueManager\Data\BatchRepository() )->find_strict( $answer['batch_id'] );
		$this->assertSame( 1, $batch['failed_items'] );
		unset( $input['typed_token'] );
		$input['targets'] = [ $targets[1] ];
		$this->assertInstanceOf( \WP_Error::class, $s->service->preview( $input ) );
		$this->assertSame( [ 'product::41' ], $s->store->reads );
	}
}
