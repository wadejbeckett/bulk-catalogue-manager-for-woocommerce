<?php
/**
 * The refusal factory (SPEC §4.8, §12.1): every refusal carries a bulkcat_ code,
 * a plain sentence and an HTTP status, and it is the only place a WP_Error is built.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Rest;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Refusal;
use PHPUnit\Framework\TestCase;

final class RefusalTest extends TestCase {
	protected function setUp(): void {
		bulkcat_test_reset();
	}

	/**
	 * Assert a refusal's shape: bulkcat_ code, a sentence that ends in a full stop
	 * and shows no code, and the status.
	 */
	private function check_refusal( mixed $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertStringStartsWith( 'bulkcat_', (string) $result->get_error_code() );
		$message = $result->get_error_message();
		$this->assertNotSame( '', $message );
		$this->assertStringEndsWith( '.', $message );
		$this->assertStringNotContainsString( 'bulkcat_', $message, 'The main text shows no code (SPEC §4.8).' );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	public function test_create_carries_code_message_and_status(): void {
		$error = Refusal::create( 'bulkcat_example', 'The example failed. Try again.', 409, [ 'ids' => [ 3 ] ] );
		$this->check_refusal( $error, 'bulkcat_example', 409 );
		$this->assertSame( 'The example failed. Try again.', $error->get_error_message() );
		$this->assertSame(
			[
				'status' => 409,
				'ids'    => [ 3 ],
			],
			$error->get_error_data()
		);
		$this->assertSame( 409, Refusal::create( 'bulkcat_example', 'Stop.', 409, [ 'status' => 200 ] )->get_error_data()['status'], 'Extra data cannot override the status.' );
	}

	public function test_create_refuses_a_code_message_or_status_outside_the_contract(): void {
		$bad = [
			[ 'rest_forbidden', 'Stop.', 403 ],
			[ 'bulkcat_', 'Stop.', 403 ],
			[ 'BULKCAT_forbidden', 'Stop.', 403 ],
			[ 'bulkcat_example', '  ', 403 ],
			[ 'bulkcat_example', 'Stop.', 200 ],
			[ 'bulkcat_example', 'Stop.', 302 ],
			[ 'bulkcat_example', 'Stop.', 600 ],
		];
		foreach ( $bad as [ $code, $message, $status ] ) {
			$thrown = false;
			try {
				Refusal::create( $code, $message, $status );
			} catch ( \InvalidArgumentException $e ) {
				$thrown = true;
			}
			$this->assertTrue( $thrown, "Refusal::create( '$code', '$message', $status ) was accepted." );
		}
	}

	public function test_forbidden_is_401_with_a_log_in_sentence_for_a_visitor_and_403_for_a_user(): void {
		$anonymous = Refusal::forbidden( 'Your account cannot do this. Ask an administrator.' );
		$this->check_refusal( $anonymous, 'bulkcat_forbidden', 401 );
		$this->assertSame( 'You need to log in to use Bulk Catalogue Manager. Log in and try again.', $anonymous->get_error_message() );

		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$user                                 = Refusal::forbidden( 'Your account cannot do this. Ask an administrator.' );
		$this->check_refusal( $user, 'bulkcat_forbidden', 403 );
		$this->assertSame( 'Your account cannot do this. Ask an administrator.', $user->get_error_message() );
		$this->assertSame( [ 'status' => 403 ], $user->get_error_data() );
	}

	public function test_invalid_is_400(): void {
		$this->check_refusal( Refusal::invalid( 'bulkcat_invalid_value', 'Stock quantity has to be a whole number. Enter a number like 12.' ), 'bulkcat_invalid_value', 400 );
	}

	public function test_the_plugin_refusals_all_come_from_the_factory_with_their_status(): void {
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$this->check_refusal( Access::can_read(), 'bulkcat_forbidden', 403 );
		$this->check_refusal( Access::can_write(), 'bulkcat_forbidden', 403 );
		$this->check_refusal( Access::can_manage_terms(), 'bulkcat_forbidden', 403 );
		$this->check_refusal( Access::can_manage_settings(), 'bulkcat_forbidden', 403 );
		$this->check_refusal( Access::can_edit_object( 7 ), 'bulkcat_cannot_edit', 403 );

		$registry = new Registry();
		$this->check_refusal( $registry->get( 'stock_quantity' )->validate( '1.5' ), 'bulkcat_invalid_value', 400 );
		$this->check_refusal( $registry->get( 'id' )->validate( 5 ), 'bulkcat_read_only', 400 );
		$this->check_refusal( Registry::check_prices( '10.00', '12.00' ), 'bulkcat_invalid_value', 400 );
	}

	public function test_only_the_factory_builds_a_wp_error(): void {
		$root  = dirname( __DIR__, 3 );
		$files = array_merge( (array) glob( $root . '/src/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE ), [ $root . '/bulk-catalogue-manager-for-woocommerce.php', $root . '/uninstall.php' ] );
		$found = [];
		foreach ( $files as $file ) {
			$tokens = array_values( array_filter( token_get_all( (string) file_get_contents( $file ) ), static fn( $t ) => ! is_array( $t ) || T_WHITESPACE !== $t[0] ) );
			foreach ( $tokens as $i => $token ) {
				$next = $tokens[ $i + 1 ] ?? null;
				if ( is_array( $token ) && T_NEW === $token[0] && is_array( $next ) && 'WP_Error' === ltrim( $next[1], '\\' ) ) {
					$found[] = substr( $file, strlen( $root ) + 1 );
				}
			}
		}
		$this->assertSame( [ 'src/Rest/Refusal.php' ], $found, 'Build refusals through Rest\Refusal.' );
	}
}
