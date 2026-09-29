<?php
/**
 * Why a batch's add-on cannot work on it now (docs/EXTENSIONS.md §11), and
 * the core's sentences for it: the job's note, each item left alone, and the
 * undo's refusal. There are two cases.
 *
 * - `provider_missing`: no provider of that id is registered, because the
 *   add-on is deactivated or was refused at registration. "… is not active …
 *   turn it on again".
 * - `provider_unavailable`: it is registered but cannot run. Its
 *   availability() refuses (its own sentence is carried), or it cannot read
 *   what the batch recorded (a newer format, or a contract version this core
 *   no longer supports).
 *
 * The add-on's sentence is plain text already (Registered::reason()). It
 * always comes after the core's words, and each whole sentence is capped at
 * 255 characters (the journal and note columns), so a long reason is
 * trimmed with an ellipsis and never cuts the core's framing.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

defined( 'ABSPATH' ) || exit;

/**
 * A provider that blocks a batch.
 */
final class Blocked {
	/**
	 * The add-on is not registered.
	 */
	public const MISSING = 'provider_missing';

	/**
	 * The add-on is registered but cannot run.
	 */
	public const UNAVAILABLE = 'provider_unavailable';

	/**
	 * One case.
	 *
	 * @param string $kind   MISSING or UNAVAILABLE.
	 * @param string $label  The add-on's label (from the manifest), plain.
	 * @param string $reason Its reason, plain; '' when missing.
	 */
	private function __construct(
		public readonly string $kind,
		public readonly string $label,
		public readonly string $reason
	) {}

	/**
	 * The add-on is not registered.
	 *
	 * @param string $label Its label.
	 */
	public static function missing( string $label ): self {
		return new self( self::MISSING, $label, '' );
	}

	/**
	 * The add-on is registered but cannot run, for this reason.
	 *
	 * @param string $label  Its label.
	 * @param string $reason Why, as a plain sentence.
	 */
	public static function unavailable( string $label, string $reason ): self {
		return new self( self::UNAVAILABLE, $label, Text::plain( $reason, Text::REASON ) );
	}

	/**
	 * Whether it is the missing case.
	 */
	public function is_missing(): bool {
		return self::MISSING === $this->kind;
	}

	/**
	 * The reason stored with each item a stopped job left alone.
	 */
	public function skipped_sentence(): string {
		if ( $this->is_missing() ) {
			return sprintf(
				/* translators: %s: the name of an add-on, such as "Example add-on". */
				__( '%s is not active now, so this was left alone. Turn it on again, then preview and apply the change again.', 'bulk-catalogue-manager-for-woocommerce' ),
				$this->label
			);
		}
		return Text::plain(
			sprintf(
				/* translators: 1: the name of an add-on, such as "Example add-on"; 2: the add-on's own sentence saying why it cannot run. */
				__( '%1$s cannot run now, so this was left alone. %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				$this->label,
				$this->reason
			),
			Text::REASON
		);
	}

	/**
	 * The note of a job stopped for it.
	 *
	 * @param int $changed Products and variations changed before it stopped.
	 */
	public function stopped_note( int $changed ): string {
		if ( $this->is_missing() ) {
			return sprintf(
				/* translators: 1: the name of an add-on, such as "Example add-on"; 2: how many products were changed, such as "312". */
				_n( 'Stopped because %1$s is not active. %2$s product was changed; the rest were left alone.', 'Stopped because %1$s is not active. %2$s products were changed; the rest were left alone.', $changed, 'bulk-catalogue-manager-for-woocommerce' ),
				$this->label,
				number_format_i18n( $changed )
			);
		}
		return Text::plain(
			sprintf(
				/* translators: 1: the name of an add-on, such as "Example add-on"; 2: how many products were changed, such as "312"; 3: the add-on's own sentence saying why it cannot run. */
				_n( 'Stopped because %1$s cannot run. %2$s product was changed; the rest were left alone. %3$s', 'Stopped because %1$s cannot run. %2$s products were changed; the rest were left alone. %3$s', $changed, 'bulk-catalogue-manager-for-woocommerce' ),
				$this->label,
				number_format_i18n( $changed ),
				$this->reason
			),
			Text::REASON
		);
	}

	/**
	 * The undo's refusal.
	 */
	public function undo_sentence(): string {
		if ( $this->is_missing() ) {
			return sprintf(
				/* translators: %s: the name of an add-on, such as "Example add-on". */
				__( 'This change was made with %s, which is not active now. Turn it on again to undo this change.', 'bulk-catalogue-manager-for-woocommerce' ),
				$this->label
			);
		}
		return sprintf(
			/* translators: 1: the name of an add-on, such as "Example add-on"; 2: the add-on's own sentence saying why it cannot run. */
			__( 'This change was made with %1$s, which cannot run now, so it cannot be undone yet. %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
			$this->label,
			$this->reason
		);
	}
}
