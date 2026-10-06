<?php
/**
 * Exact native save transport guards and cleanup; native probes prove the API.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Publishing;

use BulkCatalogueManager\Publishing\StatusTransition;
use PHPUnit\Framework\TestCase;

final class NativeSaveContextTest extends TestCase {
	public function test_only_exact_unaltered_transport_is_corrected_and_scope_always_closes(): void {
		foreach ( [ false, true ] as $throws ) {
			bulkcat_test_reset();
			$GLOBALS['bulkcat_test']['user_caps'][23]    = [ 'publish_post' => true ];
			$GLOBALS['bulkcat_test']['post_fields'][501] = [ 'post_name' => 'retained-slug' ];
			$secret                                      = "  <opaque> ' \" \\ path  ";
			$exercise                                    = function () use ( $secret, $throws ): void {
				$raw               = [
					'ID'            => 501,
					'post_type'     => 'product',
					'post_status'   => 'pending',
					'post_name'     => 'retained-slug',
					'post_password' => $secret,
				];
				$data              = $raw;
				$data['post_name'] = '';
				$fixed             = apply_filters( 'wp_insert_post_data', $data, $raw, $raw, true );
				$this->assertSame( 'retained-slug', $fixed['post_name'] );
				$this->assertSame( wp_slash( $secret ), $fixed['post_password'] );
				foreach ( [ 'other-id', 'other-type', 'insert', 'changed-password', 'changed-submission', 'private', 'changed-slug' ] as $case ) {
					$d      = $data;
					$r      = $raw;
					$update = true;
					switch ( $case ) {
						case 'other-id':
							$r['ID'] = 502;
							break;
						case 'other-type':
							$r['post_type'] = 'page';
							$d['post_type'] = 'page';
							break;
						case 'insert':
							$update = false;
							break;
						case 'changed-password':
							$d['post_password'] = 'intentional';
							break;
						case 'changed-submission':
							$r['post_password'] = 'other';
							break;
						case 'private':
							$d['post_status']   = 'private';
							$d['post_password'] = '';
							break;
						case 'changed-slug':
							$r['post_name'] = 'other';
							$d['post_name'] = 'intentional';
							break;
					}
					$got = apply_filters( 'wp_insert_post_data', $d, $r, $r, $update );
					if ( 'changed-slug' === $case ) {
						$this->assertSame( 'intentional', $got['post_name'] ); } else {
						$this->assertSame( $d['post_password'], $got['post_password'], $case ); }
				}
				if ( $throws ) {
					throw new \RuntimeException( 'Interrupted native save' ); }
			};
			$p        = new class($secret,$exercise) {
				public function __construct( private string $password, private \Closure $exercise ) {}
				public function get_id(): int {
					return 501;}
				public function get_slug(): string {
					return 'retained-slug';}
				public function get_status(): string {
					return 'pending';}
				public function get_post_password(): string {
					return $this->password;}
				public function is_type( string $type ): bool {
					return 'simple' === $type;}
				public function save(): void {
					( $this->exercise )();}
			};
			$existing = static fn( array $data ): array=>$data;
			add_filter( 'wp_insert_post_data', $existing, 20 );
			try {
				StatusTransition::save( $p, 23 );
			} catch ( \RuntimeException $e ) {
				$this->assertTrue( $throws );}
			$this->assertSame( [ [ $existing,20,1 ] ], $GLOBALS['bulkcat_test']['filters']['wp_insert_post_data'] );
		}
	}
}
