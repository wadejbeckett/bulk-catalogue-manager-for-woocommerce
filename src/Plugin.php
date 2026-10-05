<?php
/**
 * Boot: register the plugin's hooks exactly once per request, however many
 * times boot() is called. Without WooCommerce at the minimum version the only
 * hook is one admin notice naming the requirement.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin lifecycle.
 */
final class Plugin {
	/**
	 * Must equal the `Version:` header of the main plugin file.
	 */
	public const VERSION = '1.3.3';

	/**
	 * Lowest WooCommerce version the plugin runs on. Must equal the
	 * `WC requires at least:` header of the main plugin file.
	 */
	public const MIN_WC_VERSION = '11.1';

	/**
	 * The instance the main plugin file boots.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether boot() has registered the hooks.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * The admin screen, one per plugin instance so its menu and its assets share the screen id.
	 *
	 * @var Admin\Screen|null
	 */
	private ?Admin\Screen $screen = null;

	/**
	 * The single instance used at runtime. Tests construct their own.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register every hook in hooks(). A second call does nothing.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		foreach ( $this->hooks() as [ $hook, $callback, $priority, $accepted_args ] ) {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
		$this->booted = true;
	}

	/**
	 * Whether boot() has run on this instance.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Whether WooCommerce is loaded at MIN_WC_VERSION or later. WooCommerce
	 * defines WC_VERSION as it loads, before plugins_loaded fires.
	 */
	public static function woocommerce_supported(): bool {
		return defined( 'WC_VERSION' ) && version_compare( (string) constant( 'WC_VERSION' ), self::MIN_WC_VERSION, '>=' );
	}

	/**
	 * Every hook the plugin registers: hook name, callback, priority, accepted arguments.
	 * Without a supported WooCommerce that is the requirement notice alone.
	 * The settings register on init so every save, from any context, is sanitised.
	 * The category tree cache's invalidation hooks register on init too, so a
	 * category edited from any screen, REST or WP-CLI renews it, and so do the
	 * filtered count cache's, for a product saved from anywhere.
	 * The migrator runs on admin_init as well as on activation, because an
	 * update replaces the files without activating the plugin again. The REST
	 * routes register on rest_api_init. The admin screen registers on
	 * admin_menu and loads its assets on admin_enqueue_scripts, for its own
	 * screen id only; its Heartbeat ticks get a fresh REST nonce on
	 * heartbeat_received. WooCommerce's Duplicate is told to leave the Off
	 * record out of the copy (SPEC §5.5).
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int, 3: int}>
	 */
	public function hooks(): array {
		if ( ! self::woocommerce_supported() ) {
			return [
				[ 'admin_notices', [ $this, 'render_requirement_notice' ], 10, 0 ],
			];
		}
		return [
			[ 'before_woocommerce_init', [ $this, 'declare_compatibility' ], 10, 0 ],
			[ 'admin_action_duplicate_product', [ Admin\ProductRowActionGuard::class, 'duplicate' ], 0, 0 ],
			[ 'load-post.php', [ Admin\ProductRowActionGuard::class, 'trash' ], 0, 0 ],
			[ 'init', [ $this, 'register_settings' ], 10, 0 ],
			[ 'init', [ Categories\Cache::class, 'register_hooks' ], 10, 0 ],
			[ 'init', [ Query\CountCache::class, 'register_hooks' ], 10, 0 ],
			[ 'admin_init', [ $this, 'migrate' ], 10, 0 ],
			[ 'rest_api_init', [ $this, 'register_routes' ], 10, 0 ],
			[ 'admin_menu', [ $this->screen(), 'register' ], 10, 0 ],
			[ 'admin_enqueue_scripts', [ $this->screen(), 'enqueue' ], 10, 1 ],
			[ 'admin_enqueue_scripts', [ Admin\TermEmbed::class, 'enqueue' ], 10, 1 ],
			[ 'admin_body_class', [ Admin\TermEmbed::class, 'body_class' ], 10, 1 ],
			[ 'product_cat_pre_edit_form', [ Admin\TermEmbed::class, 'warn' ], 10, 2 ],
			[ 'edit_terms', [ Categories\NativeForm::class, 'before' ], 10, 2 ],
			[ 'edited_product_cat', [ Categories\NativeForm::class, 'after' ], PHP_INT_MAX, 1 ],
			[ Admin\Heartbeat::HOOK, [ Admin\Heartbeat::class, 'received' ], 10, 2 ],
			[ Engine\BatchFactory::CREATED, [ Engine\ChunkRunner::class, 'enqueue' ], 10, 1 ],
			[ Engine\ChunkRunner::HOOK, [ Engine\ChunkRunner::class, 'handle' ], 10, 2 ],
			[ Engine\Pruner::HOOK, [ Engine\Pruner::class, 'handle' ], 10, 0 ],
			[ 'bulkcat_prune_selections', [ Requests\Cleanup::class, 'selections' ], 10, 0 ],
			[ 'bulkcat_prune_requests', [ Requests\Cleanup::class, 'requests' ], 10, 0 ],
			[ 'woocommerce_duplicate_product_exclude_meta', [ Availability\OffRecord::class, 'exclude_from_duplicate' ], 10, 1 ],
		];
	}

	/**
	 * The admin screen: Products → Bulk Catalogue Manager.
	 */
	public function screen(): Admin\Screen {
		if ( null === $this->screen ) {
			$this->screen = new Admin\Screen();
		}
		return $this->screen;
	}

	/**
	 * Register the `bulkcat_settings` option and its sanitiser.
	 */
	public function register_settings(): void {
		Settings\Settings::register();
	}

	/**
	 * Bring the plugin's tables up to the code's schema version; a no-op once
	 * current. When it migrated, repair the schedules (repair_schedules()):
	 * a network activation runs the activation hooks on the main site only,
	 * so a sub-site's first migration is where it gets them. At the current
	 * schema nothing is checked, so other admin pages cost no query.
	 */
	public function migrate(): void {
		$migrator = new Data\Migrator( Data\Registry::plugin() );
		if ( $migrator->is_current() ) {
			return;
		}
		if ( $migrator->run() ) {
			self::repair_schedules();
		}
	}

	/**
	 * Make sure the recurring clean-ups (the request clean-up and the daily
	 * history prune) are scheduled and queue again any batch a deactivation
	 * interrupted; each step is idempotent. Runs after a migration and each
	 * time the manager screen loads (Admin\Screen::register()), so a lost
	 * schedule comes back without reactivating the plugin.
	 */
	public static function repair_schedules(): void {
		if ( ! ( new Data\Migrator( Data\Registry::plugin() ) )->is_current() ) {
			return;
		}
		Requests\Cleanup::schedule();
		Engine\Pruner::schedule();
		Engine\ChunkRunner::resume_interrupted();
	}

	/**
	 * Register the `bulkcat/v1` REST routes, all through the one registrar.
	 */
	public function register_routes(): void {
		Rest\Routes::plugin()->register();
	}

	/**
	 * Tell someone who can manage plugins that WooCommerce is missing or too old.
	 */
	public function render_requirement_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Bulk Catalogue Manager for WooCommerce needs WooCommerce 11.1 or later. Install or update WooCommerce to use it.', 'bulk-catalogue-manager-for-woocommerce' )
		);
	}

	/**
	 * Tell WooCommerce the plugin works with its order tables and its cart and
	 * checkout blocks, so it shows no incompatible-plugin notice. Declared
	 * against the plugin file's real path, which is how WooCommerce keys plugins.
	 */
	public function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		$file = defined( 'BULKCAT_FILE' ) ? (string) BULKCAT_FILE : dirname( __DIR__ ) . '/bulk-catalogue-manager-for-woocommerce.php';
		$real = realpath( $file );
		$file = false === $real ? $file : $real;
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $file, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $file, true );
	}
}
