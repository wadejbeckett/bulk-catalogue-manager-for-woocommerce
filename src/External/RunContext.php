<?php
/**
 * The core's Context (docs/EXTENSIONS.md §4, §9): what the core is doing, for
 * whom and in which batch. Validation outside a preview runs as `apply` for
 * the current user; the preview, the writer and undo name their own run, and
 * within() makes a run the one validation sees while it lasts.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\Context;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable run.
 */
final class RunContext implements Context {
	/**
	 * The run within() set, innermost last.
	 *
	 * @var list<Context>
	 */
	private static array $stack = [];

	/**
	 * A run.
	 *
	 * @param string $mode     One of Context's modes.
	 * @param int    $user_id  The user the work is done for.
	 * @param int    $batch_id The batch, or 0.
	 */
	public function __construct( private readonly string $mode, private readonly int $user_id, private readonly int $batch_id = 0 ) {}

	/**
	 * A preview for a user.
	 *
	 * @param int $user_id The user.
	 */
	public static function preview( int $user_id ): self {
		return new self( self::PREVIEW, $user_id );
	}

	/**
	 * Acceptance or a chunk of a batch, for a user.
	 *
	 * @param int $user_id  The user.
	 * @param int $batch_id The batch, or 0 before it exists.
	 */
	public static function apply( int $user_id, int $batch_id ): self {
		return new self( self::APPLY, $user_id, $batch_id );
	}

	/**
	 * The run validation sees: the innermost within(), else `apply` for the current user.
	 */
	public static function current(): Context {
		return [] === self::$stack ? self::apply( get_current_user_id(), 0 ) : self::$stack[ count( self::$stack ) - 1 ];
	}

	/**
	 * Do some work with a run as the one validation sees.
	 *
	 * @param Context  $context The run.
	 * @param callable $work    The work.
	 * @return mixed What the work returned.
	 */
	public static function within( Context $context, callable $work ): mixed {
		self::$stack[] = $context;
		try {
			return $work();
		} finally {
			array_pop( self::$stack );
		}
	}

	/**
	 * The mode.
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * The user.
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * The batch, or 0.
	 */
	public function batch_id(): int {
		return $this->batch_id;
	}
}
