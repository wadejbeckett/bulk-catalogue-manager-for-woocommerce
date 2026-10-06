<?php
/**
 * Signed acceptance windows survive deletion of request receipts.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Requests\RequestGrant;
use PHPUnit\Framework\TestCase;

final class RequestGrantTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	public function test_signed_deadline_cannot_be_refreshed_by_retrying_an_old_identity(): void {
		$this->assertTrue( class_exists( RequestGrant::class ), 'Durable requests need a verifiable acceptance window.' );
		$issuer = new RequestGrant( 'secret', 'site-1', 'install-1' );
		$grant  = $issuer->issue( 'selection.capture', str_repeat( 'a', 64 ), 7, 1000 );
		$claims = $issuer->verify( $grant['uuid'], $grant['request_proof'], 'selection.capture', str_repeat( 'a', 64 ), 7 );
		$this->assertTrue( is_array( $claims ) );
		$this->assertSame( 87400, $claims['accept_until'] );
		$this->assertSame( $claims, $issuer->verify( $grant['uuid'], $grant['request_proof'], 'selection.capture', str_repeat( 'a', 64 ), 7 ) );
		$this->assertNotSame( $grant['uuid'], $issuer->issue( 'selection.capture', str_repeat( 'a', 64 ), 7, 2000 )['uuid'] );
	}

	public function test_grant_is_bound_to_intent_identity_owner_site_and_installation(): void {
		$this->assertTrue( class_exists( RequestGrant::class ) );
		$issuer = new RequestGrant( 'secret', 'site-1', 'install-1' );
		$grant  = $issuer->issue( 'selection.capture', str_repeat( 'a', 64 ), 7, 1000 );
		foreach ( [
			[ $issuer, $grant['uuid'], $grant['request_proof'], 'selection.capture', str_repeat( 'b', 64 ), 7 ],
			[ $issuer, $grant['uuid'], $grant['request_proof'], 'batch.apply', str_repeat( 'a', 64 ), 7 ],
			[ $issuer, $grant['uuid'], $grant['request_proof'], 'selection.capture', str_repeat( 'a', 64 ), 8 ],
			[ new RequestGrant( 'secret', 'site-2', 'install-1' ), $grant['uuid'], $grant['request_proof'], 'selection.capture', str_repeat( 'a', 64 ), 7 ],
			[ new RequestGrant( 'secret', 'site-1', 'install-2' ), $grant['uuid'], $grant['request_proof'], 'selection.capture', str_repeat( 'a', 64 ), 7 ],
			[ $issuer, $grant['uuid'], $grant['request_proof'] . 'x', 'selection.capture', str_repeat( 'a', 64 ), 7 ],
		] as [ $verifier, $uuid, $proof, $operation, $fingerprint, $owner ] ) {
			$this->assertInstanceOf( \WP_Error::class, $verifier->verify( $uuid, $proof, $operation, $fingerprint, $owner ) );
		}
	}
}
