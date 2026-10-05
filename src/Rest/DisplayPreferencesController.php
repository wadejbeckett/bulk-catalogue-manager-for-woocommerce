<?php
/**
 * Personal display preferences (SPEC §4.1, ticket 19): the signed-in user's
 * visible columns and their order, rows per page and row density, kept for
 * that user on this site and restored when they return. Separate from named
 * saved views: writing preferences never changes a saved view, and nothing
 * here touches products.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * GET and PUT /display-preferences.
 */
final class DisplayPreferencesController implements ReadController {
	/**
	 * Route below the namespace.
	 */
	public const ROUTE = '/display-preferences';

	/**
	 * Per-site user option name (stored under the site's table prefix).
	 */
	public const OPTION = 'bulkcat_display_preferences';

	/**
	 * Document format.
	 */
	public const VERSION = 1;

	/**
	 * Rows per page offered, as for saved views.
	 */
	public const PAGE_SIZES = [ 25, 50, 100 ];

	/**
	 * Row densities the table offers.
	 */
	public const DENSITIES = [ 'compact', 'balanced', 'comfortable' ];

	/**
	 * Most columns kept.
	 */
	public const MAX_FIELDS = 100;

	/**
	 * A column id: a plain field key, never a value.
	 */
	private const FIELD_PATTERN = '/^[A-Za-z0-9_\-]{1,64}$/';

	/**
	 * Refusal code for a malformed display.
	 */
	public const INVALID = 'bulkcat_invalid_display';

	/**
	 * Refusal code for a write that did not stick.
	 */
	public const UNSAVED = 'bulkcat_display_unsaved';

	/**
	 * Register both methods behind the screen's read capability.
	 *
	 * @param Routes $routes Registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$read = [ Access::class, 'can_read' ];
		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => $read,
					'args'                => [],
				],
				[
					'methods'             => 'PUT, PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => $read,
					'args'                => [
						// Left untyped so sanitize_display() refuses a malformed display in plain words.
						'display' => [
							'description' => __( 'Your display choices: columns in order, rows per page and row density.', 'bulk-catalogue-manager-for-woocommerce' ),
							'required'    => true,
						],
					],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * The response schema.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bulkcat-display-preferences',
			'type'       => 'object',
			'properties' => [
				'version' => [
					'description' => __( 'Format of the stored choices.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
					'readonly'    => true,
				],
				'display' => [
					'description' => __( 'Your display choices, or null when none are saved.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => [ 'object', 'null' ],
				],
			],
		];
	}

	/**
	 * The signed-in user's stored display, or null.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function get_item( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$display = self::load();
		return new \WP_REST_Response( self::payload( $display ), 200 );
	}

	/**
	 * Replace the signed-in user's display and confirm it by reading it back.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( null !== $request->get_param( 'user_id' ) || null !== $request->get_param( 'user' ) ) {
			return self::invalid();
		}
		$display = self::sanitize_display( $request->get_param( 'display' ) );
		if ( $display instanceof \WP_Error ) {
			return $display;
		}
		$user = get_current_user_id();
		$json = (string) wp_json_encode(
			[
				'version' => self::VERSION,
				'display' => (object) $display,
			]
		);
		$done = update_user_option( $user, self::OPTION, wp_slash( $json ) );
		if ( false === $done && get_user_option( self::OPTION, $user ) !== $json ) {
			return Refusal::create( self::UNSAVED, __( 'Your display choices could not be saved. Try again.', 'bulk-catalogue-manager-for-woocommerce' ), 503 );
		}
		return new \WP_REST_Response( self::payload( $display ), 200 );
	}

	/**
	 * A display as sent, checked key by key; keys left out are not stored.
	 *
	 * @param mixed $value Display.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function sanitize_display( mixed $value ): array|\WP_Error {
		if ( ! is_array( $value ) || ( [] !== $value && array_is_list( $value ) ) ) {
			return self::invalid();
		}
		$display = [];
		foreach ( $value as $key => $item ) {
			switch ( $key ) {
				case 'fields':
					if ( ! is_array( $item ) || ! array_is_list( $item ) || count( $item ) > self::MAX_FIELDS ) {
						return self::invalid();
					}
					foreach ( $item as $field ) {
						if ( ! is_string( $field ) || 1 !== preg_match( self::FIELD_PATTERN, $field ) ) {
							return self::invalid();
						}
					}
					if ( count( array_unique( $item ) ) !== count( $item ) ) {
						return self::invalid();
					}
					$display['fields'] = $item;
					break;
				case 'perPage':
					if ( ! is_int( $item ) || ! in_array( $item, self::PAGE_SIZES, true ) ) {
						return self::invalid();
					}
					$display['perPage'] = $item;
					break;
				case 'density':
					if ( ! is_string( $item ) || ! in_array( $item, self::DENSITIES, true ) ) {
						return self::invalid();
					}
					$display['density'] = $item;
					break;
				default:
					return self::invalid();
			}
		}
		return $display;
	}

	/**
	 * The signed-in user's stored display on this site, or null when none
	 * is stored or it cannot be read. Never repairs what it reads.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function load(): ?array {
		$raw     = get_user_option( self::OPTION, get_current_user_id() );
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $decoded ) || self::VERSION !== ( $decoded['version'] ?? null ) || ! is_array( $decoded['display'] ?? null ) ) {
			return null;
		}
		$display = self::sanitize_display( $decoded['display'] );
		return $display instanceof \WP_Error ? null : $display;
	}

	/**
	 * The answer for a display.
	 *
	 * @param array<string, mixed>|null $display Display.
	 * @return array{version: int, display: object|null}
	 */
	private static function payload( ?array $display ): array {
		return [
			'version' => self::VERSION,
			'display' => null === $display ? null : (object) $display,
		];
	}

	/**
	 * The plain refusal for a malformed display.
	 */
	private static function invalid(): \WP_Error {
		return Refusal::invalid( self::INVALID, __( 'Those display choices could not be saved because they were not in the expected form. Reload the page and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
	}
}
