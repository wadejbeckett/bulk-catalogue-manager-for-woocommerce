<?php
/**
 * How large a change the screen can promise to undo on this host (T85).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * An undo plan (Planner::plan()) holds every item of the batch in the one
 * web request that previews or accepts the undo: about 2.7 KB an item,
 * measured on a 20,013-item plan (T85). Until the plan is paged, the screen
 * promises undo only for changes whose items fit, conservatively, in what a
 * web request may use here: its memory limit less its own footprint, three
 * quarters of the rest.
 */
final class Capacity {
	/**
	 * Memory an undo plan holds per item, in bytes.
	 */
	public const ITEM_BYTES = 2765;

	/**
	 * A web request's own footprint with a margin: WordPress, WooCommerce
	 * and the plugin loaded (about 16 MB here) and the rest of the undo
	 * request.
	 */
	public const REQUEST_BYTES = 33554432;

	/**
	 * The share of the remaining memory the plan may take.
	 */
	public const SHARE = 0.75;

	/**
	 * The most items an undo can plan in one web request, or null when
	 * memory is not limited.
	 *
	 * @param string|null $limit A memory limit in PHP's shorthand; the web request's by default.
	 */
	public static function items( ?string $limit = null ): ?int {
		$bytes = self::bytes( $limit ?? self::web_limit() );
		if ( $bytes < 0 ) {
			return null;
		}
		return max( 0, (int) floor( ( $bytes - self::REQUEST_BYTES ) * self::SHARE / self::ITEM_BYTES ) );
	}

	/**
	 * The memory limit a REST request runs under: PHP's configured value,
	 * raised to WP_MEMORY_LIMIT when that is higher (WordPress raises the
	 * limit further only on admin pages, not for REST requests).
	 */
	public static function web_limit(): string {
		$configured = get_cfg_var( 'memory_limit' );
		$limit      = is_string( $configured ) && '' !== $configured ? $configured : (string) ini_get( 'memory_limit' );
		if ( defined( 'WP_MEMORY_LIMIT' ) && self::bytes( $limit ) >= 0 && self::bytes( (string) WP_MEMORY_LIMIT ) > self::bytes( $limit ) ) {
			$limit = (string) WP_MEMORY_LIMIT;
		}
		return $limit;
	}

	/**
	 * Bytes of a value in PHP's shorthand (`128M`, `1G`, `512K`, bytes);
	 * -1 for no limit.
	 *
	 * @param string $value Shorthand.
	 */
	public static function bytes( string $value ): int {
		$value = strtolower( trim( $value ) );
		if ( '' === $value || str_starts_with( $value, '-' ) ) {
			return -1;
		}
		$number = (int) $value;
		return match ( substr( $value, -1 ) ) {
			'g'     => $number * 1073741824,
			'm'     => $number * 1048576,
			'k'     => $number * 1024,
			default => $number,
		};
	}
}
