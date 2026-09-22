<?php
/**
 * Boot: register the plugin's hooks exactly once per request, however many
 * times boot() is called.
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
	public const VERSION = '0.1.0';

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
	 * Every hook the plugin registers: hook name, callback, priority, accepted arguments.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: int, 3: int}>
	 */
	public function hooks(): array {
		return [
			[ 'before_woocommerce_init', [ $this, 'declare_compatibility' ], 10, 0 ],
		];
	}

	/**
	 * Tell WooCommerce the plugin works with its order tables and its cart and
	 * checkout blocks, so it shows no incompatible-plugin notice.
	 */
	public function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		$file = defined( 'BCM_FILE' ) ? (string) BCM_FILE : dirname( __DIR__ ) . '/bulk-catalogue-manager-for-woocommerce.php';
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $file, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $file, true );
	}
}
