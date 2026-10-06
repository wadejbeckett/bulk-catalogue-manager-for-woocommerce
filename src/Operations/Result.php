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
 * External actions (T106, docs/EXTENSIONS.md §4): a Result may also carry
 * up to three plain notes shown under its preview row (with_notes()) and at
 * most one warning the preview aggregates by code (with_warning()); an
 * external result's code may start `x_<provider id>_` as well as
 * `bulkcat_`. A Result with neither notes nor a warning is exactly what it
 * was before, in every array it gives.
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
	 * The keys a note's context may carry, in the order it keeps them (T89):
	 * the status an Off record saves or saved, the status an On restores, the
	 * product's status now, and a scheduled time (GMT, `Y-m-d H:i:s`).
	 */
	public const CONTEXT_KEYS = [ 'saved', 'restores', 'current', 'scheduled_gmt', 'requested_status', 'effective_status' ];

	/**
	 * A code a Result may carry: the plugin's own, or an external provider's (T106).
	 */
	private const CODE = '/^(bulkcat|x_[a-z][a-z0-9]{2,19})_[a-z0-9_]+$/';

	/**
	 * Most notes one Result carries (T106).
	 */
	public const MAX_NOTES = 3;

	/**
	 * Build through changed(), already(), skipped() or failed().
	 *
	 * @param string $field     Field key.
	 * @param mixed  $old       Current value, canonical.
	 * @param mixed  $new_value Value after the operation, canonical; equal to $old unless changed.
	 * @param string $state     One of STATES.
	 * @param string $reason    Plain-English sentence (SPEC §4.8) for skipped and failed; '' otherwise.
	 * @param string $code      `bulkcat_` code for the technical details line, or ''.
	 * @param bool   $replaces  Whether the change replaces a whole list, which the preview states in bold (SPEC §6).
	 * @param string $note      An outcome code the step carries (the Status switch's, T89), or ''.
	 * @param array<string, string|int|float|bool> $context What the note names, keyed by CONTEXT_KEYS.
	 * @param list<string>                         $notes   Plain notes for the preview row (T106).
	 * @param array{code: string, sentence: string, confirm: bool}|null $warning The one warning the preview aggregates (T106).
	 * @throws \InvalidArgumentException When the parts are inconsistent.
	 */
	private function __construct(
		private readonly string $field,
		private readonly mixed $old,
		private readonly mixed $new_value,
		private readonly string $state,
		private readonly string $reason,
		private readonly string $code,
		private readonly bool $replaces,
		private readonly string $note = '',
		private readonly array $context = [],
		private readonly array $notes = [],
		private readonly ?array $warning = null
	) {
		$needs_reason = self::SKIPPED === $state || self::FAILED === $state;
		$plain        = [] === array_filter( $context, static fn( mixed $value ): bool => ! is_scalar( $value ) );
		$short_note   = (bool) preg_match( '/^[a-z_]{0,40}$/', $note );
		$known_keys   = [] === array_diff( array_keys( $context ), self::CONTEXT_KEYS );
		$problem      = match ( true ) {
			1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $field )  => 'the field key is invalid',
			! in_array( $state, self::STATES, true )                => 'the state is unknown',
			$needs_reason && '' === trim( $reason )                 => 'a skipped or failed result needs a reason',
			! $needs_reason && ( '' !== $reason || '' !== $code )   => 'only a skipped or failed result carries a reason',
			'' !== $code && 1 !== preg_match( self::CODE, $code )    => 'the code must start with bulkcat_ or an external provider\'s x_ prefix',
			str_contains( $reason, 'bulkcat_' )                         => 'the reason is shown to people, so its code belongs in the code field',
			self::CHANGED === $state && $old === $new_value               => 'a change must change the value; use already()',
			$replaces && self::CHANGED !== $state                   => 'only a change can replace a list',
			! $short_note                                           => 'a note is a short lower-case code',
			! $known_keys                                           => 'its context names an unknown key',
			! $plain                                                => 'its context holds only plain values',
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
	 * @param string $code   `bulkcat_` code for the details line, or ''.
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
	 * @param string $code   `bulkcat_` code for the details line, or ''.
	 */
	public static function failed( string $field, mixed $old, string $reason, string $code = '' ): self {
		return new self( $field, $old, $old, self::FAILED, $reason, $code, false );
	}

	/**
	 * The same Result carrying an outcome code and what it names (T89): a new
	 * Result, this one left as it is. Context keys come back in CONTEXT_KEYS order.
	 *
	 * @param string                               $note    A short lower-case code, or ''.
	 * @param array<string, string|int|float|bool> $context Keyed by CONTEXT_KEYS.
	 */
	public function with_note( string $note, array $context = [] ): self {
		$ordered = [];
		foreach ( self::CONTEXT_KEYS as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$ordered[ $key ] = $context[ $key ];
			}
		}
		return new self( $this->field, $this->old, $this->new_value, $this->state, $this->reason, $this->code, $this->replaces, $note, $ordered + $context, $this->notes, $this->warning );
	}

	/**
	 * The same Result carrying plain notes for its preview row (T106): at most
	 * MAX_NOTES non-empty strings, kept in order; a new Result, this one left as it is.
	 *
	 * @param list<string> $notes Plain sentences.
	 * @throws \InvalidArgumentException When a note is not a non-empty string, or there are too many.
	 */
	public function with_notes( array $notes ): self {
		$notes = array_values( $notes );
		if ( count( $notes ) > self::MAX_NOTES || [] !== array_filter( $notes, static fn( mixed $note ): bool => ! is_string( $note ) || '' === trim( $note ) ) ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Result is invalid: at most %d notes, each a sentence.', self::MAX_NOTES ) ) );
		}
		return new self( $this->field, $this->old, $this->new_value, $this->state, $this->reason, $this->code, $this->replaces, $this->note, $this->context, $notes, $this->warning );
	}

	/**
	 * The same Result carrying one warning the preview aggregates by code with
	 * a count (T106); `$confirm` makes Apply need the second click. A new
	 * Result, this one left as it is.
	 *
	 * @param string $code     `bulkcat_` or `x_<provider id>_` code.
	 * @param string $sentence Plain sentence.
	 * @param bool   $confirm  Whether it always asks before Apply.
	 * @throws \InvalidArgumentException When the code or the sentence is invalid.
	 */
	public function with_warning( string $code, string $sentence, bool $confirm = false ): self {
		if ( 1 !== preg_match( self::CODE, $code ) || '' === trim( $sentence ) || str_contains( $sentence, 'bulkcat_' ) ) {
			throw new \InvalidArgumentException( esc_html( 'Result is invalid: a warning needs a known code and a plain sentence.' ) );
		}
		$warning = [
			'code'     => $code,
			'sentence' => $sentence,
			'confirm'  => $confirm,
		];
		return new self( $this->field, $this->old, $this->new_value, $this->state, $this->reason, $this->code, $this->replaces, $this->note, $this->context, $this->notes, $warning );
	}

	/**
	 * The notes, in order (T106).
	 *
	 * @return list<string>
	 */
	public function notes(): array {
		return $this->notes;
	}

	/**
	 * The warning, or null (T106).
	 *
	 * @return array{code: string, sentence: string, confirm: bool}|null
	 */
	public function warning(): ?array {
		return $this->warning;
	}

	/**
	 * The outcome code this Result carries, or ''.
	 */
	public function note(): string {
		return $this->note;
	}

	/**
	 * What the note names, keyed by CONTEXT_KEYS.
	 *
	 * @return array<string, string|int|float|bool>
	 */
	public function context(): array {
		return $this->context;
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
	 * The `bulkcat_` (or, for an external result, `x_<provider id>_`) code behind a skipped or failed result, or ''.
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
	 * @return array{field: string, old: mixed, new: mixed, state: string, reason: string, code: string, replaces: bool, note: string, context: array<string, mixed>, notes?: list<string>, warning?: array<string, mixed>}
	 */
	public function to_array(): array {
		$extra = [];
		if ( [] !== $this->notes ) {
			$extra['notes'] = $this->notes;
		}
		if ( null !== $this->warning ) {
			$extra['warning'] = $this->warning;
		}
		return [
			'field'    => $this->field,
			'old'      => \BulkCatalogueManager\Publishing\PublicationPassword::FIELD === $this->field ? \BulkCatalogueManager\Publishing\PublicationPassword::display( $this->old ) : $this->old,
			'new'      => \BulkCatalogueManager\Publishing\PublicationPassword::FIELD === $this->field ? \BulkCatalogueManager\Publishing\PublicationPassword::display( $this->new_value ) : $this->new_value,
			'state'    => $this->state,
			'reason'   => $this->reason,
			'code'     => $this->code,
			'replaces' => $this->replaces,
			'note'     => $this->note,
			'context'  => $this->context,
		] + $extra;
	}
}
