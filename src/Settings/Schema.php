<?php
/**
 * The settings schema (SPEC §11): every setting the plugin stores in the
 * `bcm_settings` option, its default and the values it accepts. Nothing else
 * may be stored in the option.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Schema and validation for every setting.
 */
final class Schema {
	/**
	 * Setting keys, one per row of SPEC §11. The apply-to-variations row holds
	 * one switch per field.
	 */
	public const ADMINISTRATORS_ONLY   = 'administrators_only';
	public const ROWS_PER_PAGE         = 'rows_per_page';
	public const BATCH_SIZE            = 'batch_size';
	public const HISTORY_DAYS          = 'history_days';
	public const CONFIRM_THRESHOLD     = 'confirm_threshold';
	public const VARIATIONS_STATUS     = 'variations_status';
	public const VARIATIONS_VISIBILITY = 'variations_visibility';
	public const VARIATIONS_PRICE      = 'variations_price';
	public const VARIATIONS_STOCK      = 'variations_stock';
	public const RECORD_UNCHANGED      = 'record_unchanged';
	public const CATEGORY_PANEL        = 'category_panel';
	public const CSV_SEPARATOR         = 'csv_separator';
	public const REMOVE_DATA           = 'remove_data_on_delete';

	/**
	 * The fields with an apply-to-variations default, mapped to their setting key.
	 */
	public const VARIATION_FIELDS = [
		'status'     => self::VARIATIONS_STATUS,
		'visibility' => self::VARIATIONS_VISIBILITY,
		'price'      => self::VARIATIONS_PRICE,
		'stock'      => self::VARIATIONS_STOCK,
	];

	/**
	 * Rules and defaults, without the translated labels. `type` is boolean,
	 * integer or choice; an integer has either `choices` or `min` with an
	 * optional `max`, plus `also` for values accepted outside that range.
	 */
	private const RULES = [
		self::ADMINISTRATORS_ONLY   => [
			'type'    => 'boolean',
			'default' => false,
		],
		self::ROWS_PER_PAGE         => [
			'type'    => 'integer',
			'default' => 25,
			'choices' => [ 25, 50, 100 ],
		],
		self::BATCH_SIZE            => [
			'type'    => 'integer',
			'default' => 50,
			'min'     => 10,
			'max'     => 100,
		],
		self::HISTORY_DAYS          => [
			'type'    => 'integer',
			'default' => 90,
			'min'     => 7,
			'max'     => 730,
			'also'    => [ 0 ],
		],
		self::CONFIRM_THRESHOLD     => [
			'type'    => 'integer',
			'default' => 200,
			'min'     => 0,
		],
		self::VARIATIONS_STATUS     => [
			'type'    => 'boolean',
			'default' => true,
		],
		self::VARIATIONS_VISIBILITY => [
			'type'    => 'boolean',
			'default' => true,
		],
		self::VARIATIONS_PRICE      => [
			'type'    => 'boolean',
			'default' => false,
		],
		self::VARIATIONS_STOCK      => [
			'type'    => 'boolean',
			'default' => false,
		],
		self::RECORD_UNCHANGED      => [
			'type'    => 'boolean',
			'default' => false,
		],
		self::CATEGORY_PANEL        => [
			'type'    => 'boolean',
			'default' => true,
		],
		self::CSV_SEPARATOR         => [
			'type'    => 'choice',
			'default' => 'comma',
			'choices' => [ 'comma', 'semicolon' ],
		],
		self::REMOVE_DATA           => [
			'type'    => 'boolean',
			'default' => false,
		],
	];

	/**
	 * Every setting key, in schema order.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::RULES );
	}

	/**
	 * Whether a key belongs to the schema.
	 *
	 * @param string $key Setting key.
	 */
	public static function has( string $key ): bool {
		return isset( self::RULES[ $key ] );
	}

	/**
	 * One setting's rule as the Settings tab describes it: `type`, `default`
	 * and, where they apply, `choices`, `min`, `max` and `also`. Empty for a
	 * key outside the schema.
	 *
	 * @param string $key Setting key.
	 * @return array<string, mixed>
	 */
	public static function rule( string $key ): array {
		return self::RULES[ $key ] ?? [];
	}

	/**
	 * Every setting's default.
	 *
	 * @return array<string, bool|int|string>
	 */
	public static function defaults(): array {
		return array_map( static fn( array $rule ): bool|int|string => $rule['default'], self::RULES );
	}

	/**
	 * The plain-English name of a setting, as the Settings tab shows it.
	 *
	 * @param string $key Setting key.
	 */
	public static function label( string $key ): string {
		return match ( $key ) {
			self::ADMINISTRATORS_ONLY   => __( 'Only administrators can use this screen', 'bulk-catalogue-manager-for-woocommerce' ),
			self::ROWS_PER_PAGE         => __( 'Rows per page', 'bulk-catalogue-manager-for-woocommerce' ),
			self::BATCH_SIZE            => __( 'Products per background batch', 'bulk-catalogue-manager-for-woocommerce' ),
			self::HISTORY_DAYS          => __( 'Keep history for', 'bulk-catalogue-manager-for-woocommerce' ),
			self::CONFIRM_THRESHOLD     => __( 'Confirm above this many rows', 'bulk-catalogue-manager-for-woocommerce' ),
			self::VARIATIONS_STATUS     => __( 'Apply status changes to variations by default', 'bulk-catalogue-manager-for-woocommerce' ),
			self::VARIATIONS_VISIBILITY => __( 'Apply visibility changes to variations by default', 'bulk-catalogue-manager-for-woocommerce' ),
			self::VARIATIONS_PRICE      => __( 'Apply price changes to variations by default', 'bulk-catalogue-manager-for-woocommerce' ),
			self::VARIATIONS_STOCK      => __( 'Apply stock changes to variations by default', 'bulk-catalogue-manager-for-woocommerce' ),
			self::RECORD_UNCHANGED      => __( 'Record unchanged rows in the audit log', 'bulk-catalogue-manager-for-woocommerce' ),
			self::CATEGORY_PANEL        => __( 'Show the category panel by default', 'bulk-catalogue-manager-for-woocommerce' ),
			self::CSV_SEPARATOR         => __( 'CSV separator', 'bulk-catalogue-manager-for-woocommerce' ),
			self::REMOVE_DATA           => __( 'Remove all data when the plugin is deleted', 'bulk-catalogue-manager-for-woocommerce' ),
			default                     => __( 'Unknown setting', 'bulk-catalogue-manager-for-woocommerce' ),
		};
	}

	/**
	 * What a setting accepts, in plain words, for a rejection message.
	 *
	 * @param string $key Setting key.
	 */
	private static function rule_text( string $key ): string {
		return match ( $key ) {
			self::ROWS_PER_PAGE     => __( 'choose 25, 50 or 100.', 'bulk-catalogue-manager-for-woocommerce' ),
			self::BATCH_SIZE        => __( 'enter a whole number from 10 to 100.', 'bulk-catalogue-manager-for-woocommerce' ),
			self::HISTORY_DAYS      => __( 'enter a number of days from 7 to 730, or 0 to keep history indefinitely.', 'bulk-catalogue-manager-for-woocommerce' ),
			self::CONFIRM_THRESHOLD => __( 'enter a whole number of rows, 0 or more.', 'bulk-catalogue-manager-for-woocommerce' ),
			self::CSV_SEPARATOR     => __( 'choose comma or semicolon.', 'bulk-catalogue-manager-for-woocommerce' ),
			default                 => __( 'choose on or off.', 'bulk-catalogue-manager-for-woocommerce' ),
		};
	}

	/**
	 * The message rejecting a value, naming the setting.
	 *
	 * @param string $key Setting key.
	 */
	public static function rejection( string $key ): string {
		if ( ! self::has( $key ) ) {
			return sprintf(
				/* translators: %s: the name that was sent, which is not a setting. */
				__( '"%s" is not a setting this plugin has, so it was not saved.', 'bulk-catalogue-manager-for-woocommerce' ),
				$key
			);
		}
		return sprintf(
			/* translators: 1: a setting's name, 2: what it accepts, such as "choose 25, 50 or 100." */
			__( '%1$s was not saved: %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
			self::label( $key ),
			self::rule_text( $key )
		);
	}

	/**
	 * Normalise one value against its setting's rule.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value to check.
	 * @return array{0: bool, 1: bool|int|string|null} Whether it is valid, and the value in its stored type.
	 */
	public static function check( string $key, mixed $value ): array {
		if ( ! self::has( $key ) ) {
			return [ false, null ];
		}
		$rule = self::RULES[ $key ];
		switch ( $rule['type'] ) {
			case 'boolean':
				$normal = self::to_bool( $value );
				return [ null !== $normal, $normal ];
			case 'integer':
				$normal = self::to_int( $value );
				return [ null !== $normal && self::int_allowed( $rule, $normal ), $normal ];
			default:
				$ok = is_string( $value ) && in_array( $value, $rule['choices'], true );
				return [ $ok, $ok ? $value : null ];
		}
	}

	/**
	 * Validate submitted settings. Keys outside the schema and invalid values
	 * are rejected by name; keys not submitted are simply absent.
	 *
	 * @param mixed $input Submitted settings.
	 */
	public static function validate( mixed $input ): Validation {
		if ( ! is_array( $input ) ) {
			return new Validation(
				[],
				[
					Settings::OPTION => __( 'The settings were not saved because they did not arrive as a list of settings.', 'bulk-catalogue-manager-for-woocommerce' ),
				]
			);
		}
		$values = [];
		$errors = [];
		foreach ( $input as $key => $value ) {
			$key = (string) $key;
			if ( ! self::has( $key ) ) {
				$errors[ $key ] = self::rejection( $key );
				continue;
			}
			[ $ok, $normal ] = self::check( $key, $value );
			if ( $ok ) {
				$values[ $key ] = $normal;
			} else {
				$errors[ $key ] = self::rejection( $key );
			}
		}
		return new Validation( $values, $errors );
	}

	/**
	 * A boolean from a boolean, 0 or 1, or their string forms.
	 *
	 * @param mixed $value Value.
	 */
	private static function to_bool( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 0 === $value || '0' === $value || 'false' === $value ) {
			return false;
		}
		if ( 1 === $value || '1' === $value || 'true' === $value ) {
			return true;
		}
		return null;
	}

	/**
	 * An integer from an integer or a string holding only one.
	 *
	 * @param mixed $value Value.
	 */
	private static function to_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ) {
			$int = filter_var( $value, FILTER_VALIDATE_INT );
			return false === $int ? null : $int;
		}
		return null;
	}

	/**
	 * Whether an integer is within its rule.
	 *
	 * @param array<string, mixed> $rule  Rule from RULES.
	 * @param int                  $value Value.
	 */
	private static function int_allowed( array $rule, int $value ): bool {
		if ( isset( $rule['choices'] ) ) {
			return in_array( $value, $rule['choices'], true );
		}
		if ( in_array( $value, $rule['also'] ?? [], true ) ) {
			return true;
		}
		return $value >= $rule['min'] && ( ! isset( $rule['max'] ) || $value <= $rule['max'] );
	}
}
