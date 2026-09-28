<?php
/**
 * Request identity routes enforce the existing write-capability boundary.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Rest\Routes;
use BulkCatalogueManager\Rest\Access;
use PHPUnit\Framework\TestCase;
final class RequestRouteTest extends TestCase {
	public function test_both_identity_endpoints_are_registered_and_refuse_visitors(): void {
		bulkcat_test_reset();
		Routes::plugin()->register();
		$registered = $GLOBALS['bulkcat_test']['routes'];
		$this->assertNotEmpty( $registered );
		foreach ( [ '/bulkcat/v1/requests', '/bulkcat/v1/requests/resolve' ] as $route ) {
			$this->assertArrayHasKey( $route, $registered );
			$this->assertSame( [ Access::class, 'can_write' ], $registered[ $route ][0]['permission_callback'] );
		}
		$this->assertInstanceOf( \WP_Error::class, Access::can_write() );
	}
}
