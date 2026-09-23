<?php
/**
 * What one operation would do to one object (SPEC §6, §9.1, §9.3): the field
 * it touches, the value now and the value after, and a state — changed,
 * already at the target value, skipped or failed — with a plain reason for
 * the last two. Preview shows it, the writer applies it and the journal
 * records it, so all three work from the same immutable value.
 *
 * Old and new values are in the field's canonical form (Field::read() and
 * Field::clean()), so they compare directly and round-trip through the
 * journal's value codec.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable computed result.
 */
final class Result {
	/**
	 * The value would change.
	 */
	public const CHANGED = 'changed';

	/**
	 * The object already holds the target value (T29: neither saved nor journalled when skip_already is on).
	 */
	public const ALREADY = 'already';

	/**
	 * The object was left alone for a stated reason, such as a missing value to adjust.
	 */
	public const SKIPPED = 'skipped';

	/**
	 * The object could not take the change, such as a SKU another product holds.
	 */
	public const FAILED = 'failed';

	/**
	 * Every state.
	 */
	public const STATES = [ self::CHANGED, self::ALREADY, self::SKIPPED, self::FAILED ];

	/**
	 * Build through changed(), already(), skipped() or failed().
	 *
	 * @param string $field     Field key.
	 * @param mixed  $old       Current value, canonical.
	 * @param mixed  $new_value Value after the operation, canonical; equal to $old unless changed.
	 * @param string $state     One of STATES.
	 * @param string $reason    Plain-English sentence (SPEC §4.8) for skipped and failed; '' otherwise.
	 * @param string $code      `bcm_` code for the technical details line, or ''.
	 * @param bool   $replaces  Whether the change replaces a whole list, which the preview states in bold (SPEC §6).
	 * @throws \InvalidArgumentException When the parts are inconsistent.
	 */
	private function __construct(
		private readonly string $field,
		private readonly mixed $old,
		private readonly mixed $new_value,
		private readonly string $state,
		private readonly string $reason,
		private readonly string $code,
		private readonly bool $replaces
	) {
		$needs_reason = self::SKIPPED === $state || self::FAILED === $state;
		$problem      = match ( true ) {
			1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $field )  => 'the field key is invalid',
			! in_array( $state, self::STATES, true )                => 'the state is unknown',
			$needs_reason && '' === trim( $reason )                 => 'a skipped or failed result needs a reason',
			! $needs_reason && ( '' !== $reason || '' !== $code )   => 'only a skipped or failed result carries a reason',
			'' !== $code && ! str_starts_with( $code, 'bcm_' )      => 'the code must start with bcm_',
			str_contains( $reason, 'bcm_' )                         => 'the reason is shown to people, so its code belongs in the code field',
			self::CHANGED === $state && $old === $new_value               => 'a change must change the value; use already()',
			$replaces && self::CHANGED !== $state                   => 'only a change can replace a list',
			default                                                 => '',
		};
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( 'Result is invalid: ' . $problem . '.' ) );
		}
	}

	/**
	 * The value would change from $old to $new_value.
	 *
	 * @param string $field     Field key.
	 * @param mixed  $old       Current value.
	 * @param mixed  $new_value New value, not identical to $old.
	 * @param bool   $replaces  Whether it replaces a whole list (term replace).
	 */
	public static function changed( string $field, mixed $old, mixed $new_value, bool $replaces = false ): self {
		return new self( $field, $old, $new_value, self::CHANGED, '', '', $replaces );
	}

	/**
	 * The object already holds the target value.
	 *
	 * @param string $field Field key.
	 * @param mixed  $value The current, and target, value.
	 */
	public static function already( string $field, mixed $value ): self {
		return new self( $field, $value, $value, self::ALREADY, '', '', false );
	}

	/**
	 * The object is left alone for a reason, such as having no value to adjust.
	 *
	 * @param string $field  Field key.
	 * @param mixed  $old    Current value.
	 * @param string $reason Plain-English sentence.
	 * @param string $code   `bcm_` code for the details line, or ''.
	 */
	public static function skipped( string $field, mixed $old, string $reason, string $code = '' ): self {
		return new self( $field, $old, $old, self::SKIPPED, $reason, $code, false );
	}

	/**
	 * The object cannot take the change, such as a clashing SKU.
	 *
	 * @param string $field  Field key.
	 * @param mixed  $old    Current value.
	 * @param string $reason Plain-English sentence.
	 * @param string $code   `bcm_` code for the details line, or ''.
	 */
	public static function failed( string $field, mixed $old, string $reason, string $code = '' ): self {
		return new self( $field, $old, $old, self::FAILED, $reason, $code, false );
	}

	/**
	 * The field key.
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * The current value.
	 */
	public function old(): mixed {
		return $this->old;
	}

	/**
	 * The value after the operation; the current value unless changed.
	 */
	public function new(): mixed {
		return $this->new_value;
	}

	/**
	 * One of STATES.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Whether the value would change.
	 */
	public function is_changed(): bool {
		return self::CHANGED === $this->state;
	}

	/**
	 * The plain reason for a skipped or failed result; '' otherwise.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The `bcm_` code behind a skipped or failed result, or ''.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Whether the change replaces a whole list.
	 */
	public function replaces(): bool {
		return $this->replaces;
	}

	/**
	 * The result as a preview row or journal entry carries it.
	 *
	 * @return array{field: string, old: mixed, new: mixed, state: string, reason: string, code: string, replaces: bool}
	 */
	public function to_array(): array {
		return [
			'field'    => $this->field,
			'old'      => $this->old,
			'new'      => $this->new_value,
			'state'    => $this->state,
			'reason'   => $this->reason,
			'code'     => $this->code,
			'replaces' => $this->replaces,
		];
	}
}
