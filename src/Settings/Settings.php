<?php
/**
 * Typed reader for the `bcm_settings` option, its registration and its save
 * sanitiser (SPEC §11). Callers read settings only through the typed methods;
 * a missing or corrupt stored value reads as its default.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's settings.
 */
final class Settings {
	/**
	 * The one option holding every setting.
	 */
	public const OPTION = 'bcm_settings';

	/**
	 * Valid stored values, every schema key present.
	 *
	 * @var array<string, bool|int|string>
	 */
	private array $values;

	/**
	 * Build from a stored option value, keeping only keys of the schema whose
	 * values pass it and filling the rest with defaults.
	 *
	 * @param mixed $stored The option as stored.
	 */
	public function __construct( mixed $stored ) {
		$values = Schema::defaults();
		if ( is_array( $stored ) ) {
			foreach ( Schema::keys() as $key ) {
				if ( ! array_key_exists( $key, $stored ) ) {
					continue;
				}
				[ $ok, $normal ] = Schema::check( $key, $stored[ $key ] );
				if ( $ok ) {
					$values[ $key ] = $normal;
				}
			}
		}
		$this->values = $values;
	}

	/**
	 * The settings as currently stored.
	 */
	public static function load(): self {
		return new self( get_option( self::OPTION, [] ) );
	}

	/**
	 * Register the option with WordPress so every save runs sanitize().
	 */
	public static function register(): void {
		register_setting(
			self::OPTION,
			self::OPTION,
			[
				'type'              => 'object',
				'description'       => __( 'Bulk Catalogue Manager settings.', 'bulk-catalogue-manager-for-woocommerce' ),
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'show_in_rest'      => false,
				'default'           => Schema::defaults(),
			]
		);
	}

	/**
	 * Sanitise a submitted option before WordPress stores it. Valid values
	 * replace the stored ones; a refused value keeps the stored one and is
	 * reported by name; keys outside the schema are dropped. The result always
	 * holds exactly the schema's keys.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, bool|int|string>
	 */
	public static function sanitize( mixed $input ): array {
		$result = Schema::validate( $input );
		foreach ( $result->errors() as $key => $message ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'bcm_invalid_' . sanitize_key( (string) $key ), $message );
			}
		}
		return array_merge( self::load()->values, $result->values() );
	}

	/**
	 * Every setting's current value, keyed by schema key: validated values
	 * only, never the raw stored option.
	 *
	 * @return array<string, bool|int|string>
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Whether only administrators may use the screen.
	 */
	public function administrators_only(): bool {
		return (bool) $this->values[ Schema::ADMINISTRATORS_ONLY ];
	}

	/**
	 * Default grid page size: 25, 50 or 100.
	 */
	public function rows_per_page(): int {
		return (int) $this->values[ Schema::ROWS_PER_PAGE ];
	}

	/**
	 * Products per background batch, 10 to 100.
	 */
	public function batch_size(): int {
		return (int) $this->values[ Schema::BATCH_SIZE ];
	}

	/**
	 * Variations per background batch, 10 to 100 (SPEC §12.4).
	 */
	public function variation_batch_size(): int {
		return (int) $this->values[ Schema::VARIATION_BATCH_SIZE ];
	}

	/**
	 * Days to keep history; 0 keeps it indefinitely.
	 */
	public function history_days(): int {
		return (int) $this->values[ Schema::HISTORY_DAYS ];
	}

	/**
	 * Whether history is kept indefinitely.
	 */
	public function keeps_history_indefinitely(): bool {
		return 0 === $this->history_days();
	}

	/**
	 * Row count above which Apply needs a second click.
	 */
	public function confirm_threshold(): int {
		return (int) $this->values[ Schema::CONFIRM_THRESHOLD ];
	}

	/**
	 * Starting position of the "Also apply to variations" switch for a field:
	 * status, visibility, price or stock. Any other field starts off.
	 *
	 * @param string $field Field name.
	 */
	public function apply_to_variations( string $field ): bool {
		$key = Schema::VARIATION_FIELDS[ $field ] ?? null;
		return null !== $key && (bool) $this->values[ $key ];
	}

	/**
	 * Whether unchanged rows are recorded in the audit log.
	 */
	public function record_unchanged_rows(): bool {
		return (bool) $this->values[ Schema::RECORD_UNCHANGED ];
	}

	/**
	 * Whether the category panel starts open.
	 */
	public function category_panel_open(): bool {
		return (bool) $this->values[ Schema::CATEGORY_PANEL ];
	}

	/**
	 * The CSV separator character: "," or ";".
	 */
	public function csv_separator(): string {
		return 'semicolon' === $this->values[ Schema::CSV_SEPARATOR ] ? ';' : ',';
	}

	/**
	 * Whether deleting the plugin removes all its data.
	 */
	public function remove_data_on_delete(): bool {
		return (bool) $this->values[ Schema::REMOVE_DATA ];
	}
}
