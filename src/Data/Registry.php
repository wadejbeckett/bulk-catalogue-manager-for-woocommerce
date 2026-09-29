<?php
/**
 * The data registry (SPEC §15 Data lifecycle): every table, option, user meta
 * key, term meta key, transient prefix and recurring scheduled action the
 * plugin stores or schedules. The migrator creates the tables from it and
 * uninstall removes exactly what it lists, so anything the plugin stores must
 * be added here in the same change that first stores it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Data;

use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin stores, by kind.
 */
final class Registry {
	/**
	 * The Action Scheduler group every action the plugin schedules belongs to:
	 * the full plugin slug, so no other plugin's group can share it (uninstall
	 * cancels every action in it).
	 */
	public const ACTION_GROUP = 'bulk-catalogue-manager-for-woocommerce';

	/**
	 * Tables, unprefixed name => the body of its `CREATE TABLE` statement:
	 * one column or index per line in the form dbDelta() requires (two spaces
	 * after PRIMARY KEY, KEY rather than INDEX, no trailing comma). The
	 * migrator adds `$wpdb->prefix` and the charset. The three §9.2 tables,
	 * defined in Schema.
	 */
	private const TABLES = Schema::TABLES;

	/**
	 * Options.
	 */
	private const OPTIONS = [
		Settings::OPTION,
		Migrator::VERSION_OPTION,
		FoundationStorage::EPOCH_OPTION,
	];

	/**
	 * User meta keys: each user's saved views (T52).
	 */
	private const USER_META = [
		\BulkCatalogueManager\Rest\ViewsController::META_KEY,
	];

	/**
	 * Term meta keys: the category tree's own sibling order (T64).
	 */
	private const TERM_META = [
		\BulkCatalogueManager\Categories\CategoryTree::TREE_ORDER_META,
	];

	/**
	 * Post meta keys: the Off record on a product (SPEC §5.5, T88). Protected
	 * meta, so the key starts with an underscore (`_bulkcat_`).
	 */
	private const POST_META = [
		\BulkCatalogueManager\Availability\OffRecord::META_KEY,
	];

	/**
	 * Transient name prefixes; every transient whose name starts with one is the plugin's.
	 */
	private const TRANSIENT_PREFIXES = [
		\BulkCatalogueManager\Categories\Cache::PREFIX,
		\BulkCatalogueManager\Query\CountCache::PREFIX,
		\BulkCatalogueManager\Engine\BatchFactory::PREFIX,
		\BulkCatalogueManager\Selection\SelectionToken::PREFIX,
	];

	/**
	 * Recurring scheduled action hooks, all in ACTION_GROUP, which deactivation
	 * unschedules. The daily history prune of SPEC §9.5.
	 */
	private const RECURRING_ACTIONS = [
		'bulkcat_prune_history',
		'bulkcat_prune_selections',
		'bulkcat_prune_requests',
	];

	/**
	 * Tables, unprefixed name => CREATE TABLE body.
	 *
	 * @var array<string, string>
	 */
	private array $tables;

	/**
	 * Names by kind: options, user_meta, term_meta, transient_prefixes, recurring_actions.
	 *
	 * @var array<string, string[]>
	 */
	private array $names;

	/**
	 * Post meta keys, each `_bulkcat_` followed by lower-case letters, digits and underscores.
	 *
	 * @var string[]
	 */
	private array $post_meta;

	/**
	 * Build a registry. Every name must be `bulkcat_` followed by lower-case
	 * letters, digits and underscores, so each is safe as an SQL identifier
	 * and cannot match another plugin's data.
	 *
	 * @param array<string, string> $tables             Unprefixed table name => CREATE TABLE body.
	 * @param string[]              $options            Option names.
	 * @param string[]              $user_meta          User meta keys.
	 * @param string[]              $term_meta          Term meta keys.
	 * @param string[]              $transient_prefixes Transient name prefixes.
	 * @param string[]              $recurring_actions  Recurring action hooks.
	 * @param string[]              $post_meta          Post meta keys, each `_bulkcat_` then lower-case letters, digits and underscores (protected meta).
	 * @throws \InvalidArgumentException When a name is not a valid `bulkcat_` name, or a post meta key not a valid `_bulkcat_` one.
	 */
	public function __construct( array $tables, array $options, array $user_meta, array $term_meta, array $transient_prefixes, array $recurring_actions, array $post_meta = [] ) {
		$names = [
			'options'            => $options,
			'user_meta'          => $user_meta,
			'term_meta'          => $term_meta,
			'transient_prefixes' => $transient_prefixes,
			'recurring_actions'  => $recurring_actions,
		];
		foreach ( array_merge( array_keys( $tables ), ...array_values( $names ) ) as $name ) {
			if ( ! self::valid_name( $name ) ) {
				throw new \InvalidArgumentException( esc_html( sprintf( 'Registry name "%s" must be bulkcat_ followed by lower-case letters, digits and underscores.', (string) $name ) ) );
			}
		}
		foreach ( $post_meta as $key ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^_bulkcat_[a-z0-9_]+$/', $key ) ) {
				throw new \InvalidArgumentException( esc_html( sprintf( 'Post meta key "%s" must be _bulkcat_ followed by lower-case letters, digits and underscores.', (string) $key ) ) );
			}
		}
		$this->tables    = $tables;
		$this->names     = array_map( static fn( array $keys ): array => array_values( array_unique( $keys ) ), $names );
		$this->post_meta = array_values( array_unique( $post_meta ) );
	}

	/**
	 * The plugin's own registry.
	 */
	public static function plugin(): self {
		return new self( self::TABLES, self::OPTIONS, self::USER_META, self::TERM_META, self::TRANSIENT_PREFIXES, self::RECURRING_ACTIONS, self::POST_META );
	}

	/**
	 * Whether a name is `bulkcat_` followed by lower-case letters, digits and underscores.
	 *
	 * @param mixed $name Candidate name.
	 */
	public static function valid_name( mixed $name ): bool {
		return is_string( $name ) && 1 === preg_match( '/^bulkcat_[a-z0-9_]+$/', $name );
	}

	/**
	 * Tables, unprefixed name => CREATE TABLE body.
	 *
	 * @return array<string, string>
	 */
	public function tables(): array {
		return $this->tables;
	}

	/**
	 * The full name of a registry table on this site: `$wpdb->prefix` plus the
	 * registered name. This is the only way the repositories name a table, so
	 * an identifier in their SQL is always one the registry allows.
	 *
	 * @param string $name Unprefixed table name.
	 * @throws \InvalidArgumentException When the table is not in the registry.
	 */
	public function table( string $name ): string {
		if ( ! array_key_exists( $name, $this->tables ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Table "%s" is not in the registry.', $name ) ) );
		}
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Option names.
	 *
	 * @return string[]
	 */
	public function options(): array {
		return $this->names['options'];
	}

	/**
	 * User meta keys.
	 *
	 * @return string[]
	 */
	public function user_meta(): array {
		return $this->names['user_meta'];
	}

	/**
	 * Term meta keys.
	 *
	 * @return string[]
	 */
	public function term_meta(): array {
		return $this->names['term_meta'];
	}

	/**
	 * Post meta keys.
	 *
	 * @return string[]
	 */
	public function post_meta(): array {
		return $this->post_meta;
	}

	/**
	 * Transient name prefixes.
	 *
	 * @return string[]
	 */
	public function transient_prefixes(): array {
		return $this->names['transient_prefixes'];
	}

	/**
	 * Recurring scheduled action hooks in ACTION_GROUP.
	 *
	 * @return string[]
	 */
	public function recurring_actions(): array {
		return $this->names['recurring_actions'];
	}
}
