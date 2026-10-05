<?php
/**
 * One provider the core accepted (docs/EXTENSIONS.md §3): what it said
 * about itself when it registered, checked and trimmed once (Inspector), so
 * the rest of the request never describes it again. Its availability is
 * asked once per request, through the guard.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\ExternalAction;
use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Extension\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * An accepted provider.
 *
 * An action as it is kept: `id`, `field`, `group`, `label`, `description`,
 * `edit_kind`, `arguments` (name => checked spec), `capability`,
 * `always_confirm` and `confirm_reason`. A field as it is kept: `key`,
 * `label`, `rule` (its variation rule) and `format`.
 */
final class Registered {
	/**
	 * Availability once asked: true, or the reason it is unavailable.
	 *
	 * @var true|\WP_Error|null
	 */
	private bool|\WP_Error|null $availability = null;

	/**
	 * Keep what the inspector checked.
	 *
	 * @param Provider                                                            $provider The provider.
	 * @param string                                                              $id       Its id.
	 * @param string                                                              $label    Its label, plain.
	 * @param string                                                              $version  Its version, plain.
	 * @param int                                                                 $contract The contract version it implements.
	 * @param array<string, ExternalField>                                        $fields   Fields by key, in its order.
	 * @param array<string, array{key: string, label: string, rule: string, format: int}> $meta Each field as it is kept, by key.
	 * @param array<string, ExternalAction>                                       $actions  Actions by id, in its order.
	 * @param array<string, array<string, mixed>>                                 $described Each action as it is kept, by id.
	 * @param list<array{id: string, label: string}>                              $groups   Its chooser groups, in its order.
	 */
	public function __construct(
		public readonly Provider $provider,
		public readonly string $id,
		public readonly string $label,
		public readonly string $version,
		public readonly int $contract,
		private readonly array $fields,
		private readonly array $meta,
		private readonly array $actions,
		private readonly array $described,
		private readonly array $groups
	) {}

	/**
	 * Its fields, by key.
	 *
	 * @return array<string, ExternalField>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * One field, or null.
	 *
	 * @param string $key Field key.
	 */
	public function field( string $key ): ?ExternalField {
		return $this->fields[ $key ] ?? null;
	}

	/**
	 * One field as it is kept (`key`, `label`, `rule`, `format`), or null.
	 *
	 * @param string $key Field key.
	 * @return array{key: string, label: string, rule: string, format: int}|null
	 */
	public function field_meta( string $key ): ?array {
		return $this->meta[ $key ] ?? null;
	}

	/**
	 * Every field as it is kept, by key.
	 *
	 * @return array<string, array{key: string, label: string, rule: string, format: int}>
	 */
	public function fields_meta(): array {
		return $this->meta;
	}

	/**
	 * Its actions, by id.
	 *
	 * @return array<string, ExternalAction>
	 */
	public function actions(): array {
		return $this->actions;
	}

	/**
	 * One action, or null.
	 *
	 * @param string $id Action id.
	 */
	public function action( string $id ): ?ExternalAction {
		return $this->actions[ $id ] ?? null;
	}

	/**
	 * One action as it is kept, or null.
	 *
	 * @param string $id Action id.
	 * @return array<string, mixed>|null
	 */
	public function described( string $id ): ?array {
		return $this->described[ $id ] ?? null;
	}

	/**
	 * Every action as it is kept, by id, in its order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all_described(): array {
		return $this->described;
	}

	/**
	 * Its chooser groups.
	 *
	 * @return list<array{id: string, label: string}>
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * Whether it works now: true, or a refusal naming the plain reason.
	 * Asked once per request, through the guard; an exception, or false,
	 * reads as unavailable.
	 *
	 * @return true|\WP_Error
	 */
	public function availability(): bool|\WP_Error {
		if ( null === $this->availability ) {
			try {
				$answer = Guard::run( $this->id, 'availability', fn(): mixed => $this->provider->availability() );
			} catch ( ProviderFailure $e ) {
				$answer = false;
			}
			$this->availability = true === $answer ? true : Refusals::unavailable( $this->label, $answer instanceof \WP_Error ? $answer->get_error_message() : '' );
		}
		return $this->availability;
	}

	/**
	 * A value of one of its fields as it describes it, plain text; '' when the
	 * field is unknown or describe() fails (the failure is logged).
	 *
	 * @param \BulkCatalogueManager\Extension\Subject $subject The object.
	 * @param string                                  $key     Field key.
	 * @param mixed                                   $value   The value.
	 */
	public function describe( \BulkCatalogueManager\Extension\Subject $subject, string $key, mixed $value ): string {
		$field = $this->field( $key );
		try {
			return null === $field ? '' : Text::plain( Guard::run( $this->id, 'describe', static fn(): mixed => $field->describe( $value, $subject ) ), Text::VALUE );
		} catch ( ProviderFailure $e ) {
			return '';
		}
	}

	/**
	 * A value wrapped for the journal with its format and its description.
	 *
	 * @param \BulkCatalogueManager\Extension\Subject $subject The object.
	 * @param string                                  $key     Field key.
	 * @param mixed                                   $value   The value.
	 * @return array{external: int, format: int, value: mixed, text: string}
	 */
	public function envelope( \BulkCatalogueManager\Extension\Subject $subject, string $key, mixed $value ): array {
		return Envelope::wrap( $value, (int) ( $this->field_meta( $key )['format'] ?? 1 ), $this->describe( $subject, $key, $value ) );
	}

	/**
	 * Whether it works now.
	 */
	public function available(): bool {
		return true === $this->availability();
	}

	/**
	 * The plain reason it is unavailable, or ''.
	 */
	public function reason(): string {
		$availability = $this->availability();
		return true === $availability ? '' : $availability->get_error_message();
	}

	/**
	 * Whether a change's steps act on categories (docs/EXTENSIONS-v2.md
	 * §5.1): a step's field belongs to this provider's category actions. A
	 * restore step names its field only, so the field decides.
	 *
	 * @param array<mixed> $operations The normalised steps.
	 */
	public function category_change( array $operations ): bool {
		foreach ( $operations as $step ) {
			$meta = is_array( $step ) ? $this->field_meta( (string) ( $step['args']['field'] ?? '' ) ) : null;
			if ( \BulkCatalogueManager\Extension\ActionDescriptor::TARGET_CATEGORY === ( $meta['target'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}
