<?php
/**
 * The settings routes (SPEC §11, §12.1, §13; T73):
 *
 * - `GET /bulkcat/v1/settings` (Access::can_read, the screen capability) answers
 *   `{ values, settings, groups, canEdit }`: every §11 value as stored and
 *   validated, and each setting's description for the Settings tab — its
 *   plain-English label, help text, type, choices, minimum and maximum,
 *   special values (0 days keeps history indefinitely) and any warning — in
 *   the tab's groups, plus whether the current user may change them.
 * - `PUT` (or `POST`) `/bulkcat/v1/settings` (Access::can_manage_settings,
 *   `manage_options` only) takes `{ values: { … } }`, validates it through
 *   Settings\Schema::validate() and saves nothing unless every value passes:
 *   a refusal is 400 `bulkcat_invalid_setting` whose message names each refused
 *   setting with its §11 rule and whose `data.errors` holds the same messages
 *   keyed by setting. On success it answers the GET payload with the saved values.
 *
 * The option is written through `update_option()`, so Settings::sanitize()
 * runs as it does for every other save.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Settings\Schema;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The settings routes.
 */
final class SettingsController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/settings';

	/**
	 * The refusal code for a value a setting does not accept.
	 */
	public const INVALID = 'bulkcat_invalid_setting';

	/**
	 * The Settings tab's groups in order, each with its settings in order.
	 */
	private const GROUPS = [
		'access'  => [ Schema::ADMINISTRATORS_ONLY ],
		'grid'    => [ Schema::ROWS_PER_PAGE, Schema::CATEGORY_PANEL ],
		'bulk'    => [
			Schema::CONFIRM_THRESHOLD,
			Schema::VARIATIONS_STATUS,
			Schema::VARIATIONS_VISIBILITY,
			Schema::VARIATIONS_PRICE,
			Schema::VARIATIONS_STOCK,
			Schema::BATCH_SIZE,
			Schema::VARIATION_BATCH_SIZE,
		],
		'history' => [ Schema::HISTORY_DAYS, Schema::RECORD_UNCHANGED ],
		'export'  => [ Schema::CSV_SEPARATOR ],
		'data'    => [ Schema::REMOVE_DATA ],
	];

	/**
	 * Add the route, a read and a write endpoint, through the registrar.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => [],
				],
				[
					'methods'             => 'PUT, POST',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ Access::class, 'can_manage_settings' ],
					'args'                => [
						// Left untyped so Schema::validate() refuses a missing or malformed list in plain words.
						'values' => [
							'description' => __( 'The settings to save, each under its name.', 'bulk-catalogue-manager-for-woocommerce' ),
						],
					],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * The current values and every setting's description.
	 *
	 * @param mixed $request The REST request; unused.
	 */
	public function get_item( mixed $request = null ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response( self::payload( Settings::load() ), 200 );
	}

	/**
	 * Validate and save submitted values, all or nothing.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 */
	public function update_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = Schema::validate( $request->get_param( 'values' ) );
		if ( $result->has_errors() ) {
			$errors = $result->errors();
			return Refusal::invalid( self::INVALID, implode( ' ', $errors ), [ 'errors' => $errors ] );
		}
		update_option( Settings::OPTION, array_merge( Settings::load()->values(), $result->values() ) );
		return new \WP_REST_Response( self::payload( Settings::load() ), 200 );
	}

	/**
	 * The GET answer for some settings.
	 *
	 * @param Settings $settings The settings.
	 * @return array{values: array<string, bool|int|string>, settings: list<array<string, mixed>>, groups: list<array{id: string, label: string}>, canEdit: bool}
	 */
	public static function payload( Settings $settings ): array {
		$described = [];
		$groups    = [];
		foreach ( self::GROUPS as $group => $keys ) {
			$groups[] = [
				'id'    => $group,
				'label' => self::group_label( $group ),
			];
			foreach ( $keys as $key ) {
				$described[] = self::describe( $key, $group );
			}
		}
		return [
			'values'   => $settings->values(),
			'settings' => $described,
			'groups'   => $groups,
			'canEdit'  => current_user_can( Access::SETTINGS_CAPABILITY ),
		];
	}

	/**
	 * Every setting key the tab shows, in its order. Always the whole schema.
	 *
	 * @return list<string>
	 */
	public static function keys(): array {
		return array_merge( ...array_values( self::GROUPS ) );
	}

	/**
	 * One setting as the tab renders it.
	 *
	 * @param string $key   Setting key.
	 * @param string $group The group it sits in.
	 * @return array<string, mixed>
	 */
	private static function describe( string $key, string $group ): array {
		$rule = Schema::rule( $key );
		$out  = [
			'key'     => $key,
			'label'   => Schema::label( $key ),
			'help'    => self::help( $key ),
			'type'    => $rule['type'],
			'group'   => $group,
			'default' => $rule['default'],
		];
		foreach ( [ 'min', 'max' ] as $bound ) {
			if ( isset( $rule[ $bound ] ) ) {
				$out[ $bound ] = $rule[ $bound ];
			}
		}
		$choices = self::choices( $key, $rule );
		if ( [] !== $choices ) {
			$out['choices'] = $choices;
		}
		if ( Schema::HISTORY_DAYS === $key ) {
			$out['special'] = [
				[
					'value' => 0,
					'label' => __( 'Indefinitely', 'bulk-catalogue-manager-for-woocommerce' ),
				],
			];
		}
		$unit = self::unit( $key );
		if ( '' !== $unit ) {
			$out['unit'] = $unit;
		}
		if ( Schema::ADMINISTRATORS_ONLY === $key ) {
			$out['label'] = __( 'Who can use this screen', 'bulk-catalogue-manager-for-woocommerce' );
		}
		if ( Schema::REMOVE_DATA === $key ) {
			$out['warning'] = __( 'When this is on, deleting the plugin permanently removes its settings, history and undo records. They cannot be brought back, even by installing the plugin again.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		return $out;
	}

	/**
	 * The choices a setting offers, each a value and its plain name; empty for a free value.
	 *
	 * @param string               $key  Setting key.
	 * @param array<string, mixed> $rule The setting's rule.
	 * @return list<array{value: bool|int|string, label: string}>
	 */
	private static function choices( string $key, array $rule ): array {
		if ( Schema::ADMINISTRATORS_ONLY === $key ) {
			return [
				[
					'value' => false,
					'label' => __( 'Shop managers and administrators', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				[
					'value' => true,
					'label' => __( 'Administrators only', 'bulk-catalogue-manager-for-woocommerce' ),
				],
			];
		}
		if ( Schema::CSV_SEPARATOR === $key ) {
			return [
				[
					'value' => 'comma',
					'label' => __( 'Comma', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				[
					'value' => 'semicolon',
					'label' => __( 'Semicolon', 'bulk-catalogue-manager-for-woocommerce' ),
				],
			];
		}
		if ( isset( $rule['choices'] ) ) {
			return array_map(
				static fn( int|string $value ): array => [
					'value' => $value,
					'label' => (string) $value,
				],
				$rule['choices']
			);
		}
		return [];
	}

	/**
	 * The unit a number setting counts, or ''.
	 *
	 * @param string $key Setting key.
	 */
	private static function unit( string $key ): string {
		return match ( $key ) {
			Schema::BATCH_SIZE           => __( 'products', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::VARIATION_BATCH_SIZE => __( 'variations', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::HISTORY_DAYS         => __( 'days', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::CONFIRM_THRESHOLD    => __( 'rows', 'bulk-catalogue-manager-for-woocommerce' ),
			default                      => '',
		};
	}

	/**
	 * A group's heading.
	 *
	 * @param string $group Group id.
	 */
	private static function group_label( string $group ): string {
		return match ( $group ) {
			'access'  => __( 'Access', 'bulk-catalogue-manager-for-woocommerce' ),
			'grid'    => __( 'Catalogue', 'bulk-catalogue-manager-for-woocommerce' ),
			'bulk'    => __( 'Bulk changes', 'bulk-catalogue-manager-for-woocommerce' ),
			'history' => __( 'History', 'bulk-catalogue-manager-for-woocommerce' ),
			'export'  => __( 'Export', 'bulk-catalogue-manager-for-woocommerce' ),
			default   => __( 'Your data', 'bulk-catalogue-manager-for-woocommerce' ),
		};
	}

	/**
	 * What a setting does, in one or two plain sentences.
	 *
	 * @param string $key Setting key.
	 */
	private static function help( string $key ): string {
		return match ( $key ) {
			Schema::ADMINISTRATORS_ONLY   => __( 'Applies to this screen and everything it can change. Settings can only ever be changed by an administrator.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::ROWS_PER_PAGE         => __( 'How many products the catalogue shows at first. Anyone can still change it while they work.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::BATCH_SIZE            => __( 'How many products each background run saves, from 10 to 100. Lower this if your host is slow; it is also lowered automatically when a run nears the time limit.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::VARIATION_BATCH_SIZE  => __( 'How many variations each background run saves after its products, from 10 to 100. Variations are lighter to save than products, so this can usually stay higher.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::HISTORY_DAYS          => __( 'Older changes are removed from the history, along with what is needed to undo them.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::CONFIRM_THRESHOLD     => __( 'At or above this many rows, Apply shows the count again and needs one more click. Changes that set Status to Draft or Pending Review, hide products or empty a field always ask.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::VARIATIONS_STATUS,
			Schema::VARIATIONS_VISIBILITY,
			Schema::VARIATIONS_PRICE,
			Schema::VARIATIONS_STOCK      => __( 'Where the "Also apply to variations" switch starts. You can still turn it on or off for each change.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::RECORD_UNCHANGED      => __( 'On makes the history complete, listing rows that already had the new value, but much larger.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::CATEGORY_PANEL        => __( 'Whether the category tree is open beside the catalogue when the screen loads.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::CSV_SEPARATOR         => __( 'Choose semicolon if your spreadsheet uses commas in numbers, as many European spreadsheets do.', 'bulk-catalogue-manager-for-woocommerce' ),
			Schema::REMOVE_DATA           => __( 'Off keeps history and undo records if you reinstall the plugin.', 'bulk-catalogue-manager-for-woocommerce' ),
			default                       => '',
		};
	}

	/**
	 * The schema of the answer.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$values = [];
		foreach ( Schema::keys() as $key ) {
			$rule           = Schema::rule( $key );
			$values[ $key ] = [
				'type'        => 'choice' === $rule['type'] ? 'string' : $rule['type'],
				'description' => Schema::label( $key ),
			];
		}
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bulkcat-settings',
			'type'       => 'object',
			'properties' => [
				'values'   => [
					'type'       => 'object',
					'properties' => $values,
				],
				'settings' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'key'     => [ 'type' => 'string' ],
							'label'   => [ 'type' => 'string' ],
							'help'    => [ 'type' => 'string' ],
							'type'    => [
								'type' => 'string',
								'enum' => [ 'boolean', 'integer', 'choice' ],
							],
							'group'   => [
								'type' => 'string',
								'enum' => array_keys( self::GROUPS ),
							],
							'default' => [ 'type' => [ 'boolean', 'integer', 'string' ] ],
							'min'     => [ 'type' => 'integer' ],
							'max'     => [ 'type' => 'integer' ],
							'choices' => [ 'type' => 'array' ],
							'special' => [ 'type' => 'array' ],
							'unit'    => [ 'type' => 'string' ],
							'warning' => [ 'type' => 'string' ],
						],
					],
				],
				'groups'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'    => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
						],
					],
				],
				'canEdit'  => [ 'type' => 'boolean' ],
			],
		];
	}
}
