<?php
/**
 * The one place a refusal is built (SPEC §4.8, §12.1, §13). Every refusal the
 * plugin creates is a `WP_Error` whose code starts `bulkcat_`, whose message is a
 * plain-English sentence naming the thing, what happened and what to do, and
 * whose data carries an HTTP `status`: 401 for a visitor who is not logged in,
 * 403 for a logged-in user without the right, 400 for an invalid value.
 * The native-validation adapter preserves WordPress's `rest_invalid_param` code.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Refusal factory.
 */
final class Refusal {
	/**
	 * Every refusal code starts with this.
	 */
	public const PREFIX = 'bulkcat_';

	/**
	 * The code for a request the current user may not make.
	 */
	public const FORBIDDEN = 'bulkcat_forbidden';

	/**
	 * The WooCommerce log source unexpected errors are written under.
	 */
	public const LOG_SOURCE = 'bulk-catalogue-manager-for-woocommerce';

	/**
	 * A refusal. Refuses, as a programming error, a code without the `bulkcat_`
	 * prefix, an empty message or a status that is not a 4xx or 5xx.
	 *
	 * @param string               $code    `bulkcat_` error code.
	 * @param string               $message Plain-English sentence (SPEC §4.8), already translated.
	 * @param int                  $status  HTTP status, 400 to 599.
	 * @param array<string, mixed> $data    Extra data for the technical details line; `status` always wins.
	 * @throws \InvalidArgumentException When the code, message or status breaks the contract.
	 */
	public static function create( string $code, string $message, int $status, array $data = [] ): \WP_Error {
		if ( ! str_starts_with( $code, self::PREFIX ) || strlen( $code ) <= strlen( self::PREFIX ) ) {
			throw new \InvalidArgumentException( 'A refusal code must start with bulkcat_.' );
		}
		if ( '' === trim( $message ) ) {
			throw new \InvalidArgumentException( 'A refusal needs a plain-English message.' );
		}
		if ( $status < 400 || $status > 599 ) {
			throw new \InvalidArgumentException( 'A refusal status must be between 400 and 599.' );
		}
		return self::error( $code, $message, [ 'status' => $status ] + $data );
	}

	/**
	 * Preserve the native inline field-validation response without relaxing create().
	 *
	 * @param string $message Native validation message, already translated.
	 * @param array<string, string> $params Native field messages.
	 * @param string $field Invalid field key.
	 */
	public static function native_validation( string $message, array $params, string $field ): \WP_Error {
		return self::error(
			'rest_invalid_param',
			$message,
			[
				'status' => 400,
				'params' => $params,
				'field'  => $field,
			]
		);
	}

	/**
	 * Copy an inline refusal only after its receipt has committed.
	 *
	 * @param \WP_Error $error Original validation or permission refusal, with array data.
	 */
	public static function committed_inline( \WP_Error $error ): \WP_Error {
		return self::error(
			$error->get_error_code(),
			$error->get_error_message(),
			$error->get_error_data() + [
				'receipt_state' => 'refused',
				'replayed'      => false,
			]
		);
	}

	/**
	 * Construct only validated plugin refusals or the explicit native adapters above.
	 *
	 * @param string|int $code Existing response code.
	 * @param string $message Existing response message.
	 * @param array<string, mixed> $data Existing response data.
	 */
	private static function error( string|int $code, string $message, array $data ): \WP_Error {
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * A refusal for something that went wrong unexpectedly: the throwable's
	 * text (which may name a table, a query or a file path from another
	 * plugin's save hook or the database) goes to the WooCommerce log under
	 * the plugin's source, when WooCommerce's logger is there, and never to
	 * the client, which gets only the plain sentence.
	 *
	 * @param string               $code    `bulkcat_` error code.
	 * @param string               $message Plain-English sentence, already translated.
	 * @param int                  $status  HTTP status, 400 to 599.
	 * @param \Throwable           $error   What went wrong.
	 * @param array<string, mixed> $data    Extra data that is safe to send.
	 */
	public static function unexpected( string $code, string $message, int $status, \Throwable $error, array $data = [] ): \WP_Error {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( '%s: %s (%s:%d)', get_class( $error ), $error->getMessage(), $error->getFile(), $error->getLine() ),
				[ 'source' => self::LOG_SOURCE ]
			);
		}
		return self::create( $code, $message, $status, $data );
	}

	/**
	 * A permission refusal, `bulkcat_forbidden`: 401 with a log-in sentence for a
	 * visitor who is not logged in, otherwise 403 with the given sentence.
	 *
	 * @param string $message Sentence for a logged-in user, saying what they may not do and what to do.
	 */
	public static function forbidden( string $message ): \WP_Error {
		if ( ! is_user_logged_in() ) {
			$message = __( 'You need to log in to use Bulk Catalogue Manager. Log in and try again.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		return self::create( self::FORBIDDEN, $message, rest_authorization_required_code() );
	}

	/**
	 * A refusal of an invalid value or request, status 400.
	 *
	 * @param string               $code    `bulkcat_` error code.
	 * @param string               $message Plain-English sentence.
	 * @param array<string, mixed> $data    Extra data.
	 */
	public static function invalid( string $code, string $message, array $data = [] ): \WP_Error {
		return self::create( $code, $message, 400, $data );
	}
}
