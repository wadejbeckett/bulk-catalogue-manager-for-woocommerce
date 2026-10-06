<?php
/**
 * Lifecycle routes retain the common authorization and bounded schemas.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Rest\Access;
use PHPUnit\Framework\TestCase;
final class LifecycleRouteTest extends TestCase {
	public function test_routes_are_guarded_and_bounded(): void {
		bulkcat_test_reset();
		Routes::plugin()->register();
		$registered = $GLOBALS['bulkcat_test']['routes'];
		foreach ( [ 'compose', 'inspect', 'renew', 'release' ] as $operation ) {
			$key = '/bulkcat/v1/selection/' . $operation;
			$this->assertArrayHasKey( $key, $registered );
			$this->assertSame( [ Access::class, 'can_write' ], $registered[ $key ][0]['permission_callback'] );
			$this->assertSame( 'POST', $registered[ $key ][0]['methods'] );
		}
		$this->assertSame( 100, $registered['/bulkcat/v1/selection/inspect'][0]['args']['probe_ids']['maxItems'] );
		$this->assertSame( 4, $registered['/bulkcat/v1/selection/release'][0]['args']['tokens']['maxItems'] );
		$this->assertContains( 'selection.compose', $registered['/bulkcat/v1/requests'][0]['args']['operation']['enum'] );
		$this->assertArrayHasKey( 'operation', $registered['/bulkcat/v1/requests/resolve'][0]['args'] );
	}
}
