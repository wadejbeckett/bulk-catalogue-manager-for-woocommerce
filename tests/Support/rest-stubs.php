<?php
/**
 * Minimal WP_Error, WP_REST_Request and WP_REST_Response for unit tests.
 * Under WordPress this file defines nothing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

// WordPress's own class names, declared together inside one guard.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:disable Generic.Classes.OpeningBraceSameLine

// Declarations sit inside the condition so PHP does not hoist them before the check.
if ( ! class_exists( 'WP_Error' ) ) {

	class WP_Error {
		private array $errors = [];
		private array $data   = [];

		public function __construct( string|int $code = '', string $message = '', mixed $data = '' ) {
			if ( '' === $code ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->data[ $code ] = $data;
			}
		}

		public function get_error_code(): string|int {
			$codes = array_keys( $this->errors );
			return $codes[0] ?? '';
		}

		public function get_error_message( string|int $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : $code;
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( string|int $code = '' ): mixed {
			$code = '' === $code ? $this->get_error_code() : $code;
			return $this->data[ $code ] ?? null;
		}
	}

	class WP_REST_Request {
		private array $params = [];

		public function __construct( private string $method = 'GET', private string $route = '' ) {}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_params(): array {
			return $this->params;
		}
	}

	class WP_REST_Response {
		public function __construct( private mixed $data = null, private int $status = 200 ) {}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}
