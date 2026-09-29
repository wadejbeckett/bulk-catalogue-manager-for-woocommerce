<?php
/**
 * An external action as the action registry resolves it (T106,
 * docs/EXTENSIONS.md §5.2, §7.1): Operations\Registry::get() answers one for
 * each registered provider's action, so a change holding it is validated,
 * normalised, stored and hashed exactly as a native one. It is never listed
 * among the native actions; `GET /fields` advertises it (Advertiser).
 *
 * validate() checks, in order, the provider's availability (409
 * `bulkcat_provider_unavailable`), the action's capability for the run's
 * user (403 `bulkcat_forbidden`), then every argument against its spec —
 * unknown names, the field, required, kinds and bounds — refusing with
 * `bulkcat_invalid_operation` and the spec's sentence before the provider
 * sees anything; only then the provider's own validate(), whose refusal
 * becomes `bulkcat_invalid_operation` with its sentence (and its own code in
 * `data.provider_code`). The arguments kept are the core's normalised ones.
 *
 * It computes and writes nothing through the Operation interface: the
 * preview and the writer run external steps through Computer.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Operations\BaseOperation;
use BulkCatalogueManager\Operations\Descriptor;
use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validation;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The adapter.
 */
final class ExternalOperation implements Operation {
	/**
	 * Native group => the Settings::apply_to_variations() switch a per-variation field starts from.
	 */
	private const VARIATION_DEFAULTS = [
		'publishing' => 'status',
		'pricing'    => 'price',
		'stock'      => 'stock',
	];

	/**
	 * The descriptor, built once.
	 *
	 * @var Descriptor|null
	 */
	private ?Descriptor $descriptor = null;

	/**
	 * One provider action.
	 *
	 * @param Registered $provider Its provider.
	 * @param string     $id       Its id.
	 */
	public function __construct( public readonly Registered $provider, private readonly string $id ) {}

	/**
	 * The action as it is kept.
	 *
	 * @return array<string, mixed>
	 */
	public function described(): array {
		return (array) $this->provider->described( $this->id );
	}

	/**
	 * Its field key.
	 */
	public function field_key(): string {
		return (string) $this->described()['field'];
	}

	/**
	 * The descriptor, as the registry resolves it.
	 */
	public function describe(): Descriptor {
		if ( null === $this->descriptor ) {
			$described        = $this->described();
			$this->descriptor = new Descriptor(
				$this->id,
				$described['group'],
				$described['label'],
				$described['description'],
				[ $described['edit_kind'] ],
				[ $described['field'] ],
				[ $described['group'] ],
				Arguments::schema( $described, (array) $this->provider->field_meta( $described['field'] ) )
			);
		}
		return $this->descriptor;
	}

	/**
	 * Check and normalise the arguments; see the file comment for the order.
	 *
	 * @param array<string, mixed> $args Arguments as submitted.
	 */
	public function validate( array $args ): Validation {
		$context   = RunContext::current();
		$described = $this->described();
		$available = $this->provider->availability();
		if ( true !== $available ) {
			return Validation::refused( $available );
		}
		if ( ! user_can( $context->user_id(), $described['capability'] ) ) {
			return Validation::refused( Refusals::forbidden() );
		}
		$meta  = (array) $this->provider->field_meta( $described['field'] );
		$specs = Arguments::specs( $described, $meta );
		$clean = $this->check( $args, $specs, $described, $meta );
		if ( $clean instanceof \WP_Error ) {
			return Validation::refused( $clean );
		}
		$action = $this->provider->action( $this->id );
		try {
			$answer = null === $action ? null : Guard::run( $this->provider->id, 'validate', static fn(): mixed => $action->validate( $clean, $context ) );
		} catch ( ProviderFailure $e ) {
			$answer = null;
		}
		if ( ! $answer instanceof Validation ) {
			return Validation::refused( Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 ) );
		}
		$error = $answer->error();
		if ( null !== $error ) {
			$code = (string) $error->get_error_code();
			$data = [ 'action' => $this->id ];
			if ( 1 === preg_match( '/^x_' . preg_quote( $this->provider->id, '/' ) . '_[a-z0-9_]+$/', $code ) ) {
				$data['provider_code'] = $code;
			}
			$sentence = Text::plain( $error->get_error_message(), Text::REASON );
			return Validation::refused( Refusal::invalid( BaseOperation::INVALID, '' === $sentence ? __( 'The add-on refused these settings. Check them and try again.', 'bulk-catalogue-manager-for-woocommerce' ) : $sentence, $data ) );
		}
		return Validation::passed( $clean );
	}

	/**
	 * The arguments checked against their specs: the normalised form, or the refusal.
	 *
	 * @param array<string, mixed>                $args      As submitted.
	 * @param array<string, array<string, mixed>> $specs     Every spec, `field` first.
	 * @param array<string, mixed>                $described The action as it is kept.
	 * @param array<string, mixed>                $meta      Its field as it is kept.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check( array $args, array $specs, array $described, array $meta ): array|\WP_Error {
		$unknown = array_diff_key( $args, $specs );
		if ( [] !== $unknown ) {
			$name = substr( sanitize_key( (string) array_key_first( $unknown ) ), 0, 40 );
			return $this->refuse(
				sprintf(
					/* translators: 1: an action's name, such as "Round prices", 2: the name of a setting it was sent. */
					__( '%1$s has no setting called "%2$s". Choose the action again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					$described['label'],
					$name
				),
				$name
			);
		}
		if ( ( $args['field'] ?? null ) !== $described['field'] ) {
			return Refusal::invalid(
				BaseOperation::FIELD_NOT_ACCEPTED,
				is_string( $args['field'] ?? null ) && '' !== $args['field']
					? sprintf(
						/* translators: %s: an action's name, such as "Round prices". */
						__( '%s was asked to change a field that does not exist. Choose one of the fields it offers and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
						$described['label']
					)
					: $specs['field']['rule'],
				[
					'action' => $this->id,
					'field'  => is_string( $args['field'] ?? null ) ? $args['field'] : '',
				]
			);
		}
		$clean = [ 'field' => $described['field'] ];
		foreach ( $specs as $name => $spec ) {
			if ( 'field' === $name || 'apply_to_variations' === $name ) {
				continue;
			}
			if ( ! array_key_exists( $name, $args ) ) {
				if ( ! empty( $spec['required'] ) ) {
					return $this->refuse( $spec['rule'], $name );
				}
				if ( array_key_exists( 'default', $spec ) ) {
					$clean[ $name ] = $spec['default'];
				}
				continue;
			}
			$value = Arguments::normalise( $args[ $name ], $spec );
			if ( null === $value ) {
				return $this->refuse( $spec['rule'], $name );
			}
			$clean[ $name ] = $value;
		}
		$per_variation = ExternalField::PER_VARIATION === ( $meta['rule'] ?? '' );
		if ( array_key_exists( 'apply_to_variations', $args ) ) {
			$on = \BulkCatalogueManager\Fields\Values::boolean( $args['apply_to_variations'] );
			if ( null === $on || ( $on && ! $per_variation ) ) {
				return $this->refuse(
					null === $on
						? __( 'Also apply to variations has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' )
						: sprintf(
							/* translators: %s: a field's name, such as "Categories". */
							__( '%s belongs to the product, not to each variation, so it cannot be applied to variations. Turn off Also apply to variations and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
							(string) ( $meta['label'] ?? '' )
						),
					'apply_to_variations'
				);
			}
			if ( $per_variation ) {
				$clean['apply_to_variations'] = $on;
			}
		} elseif ( $per_variation ) {
			$setting                      = self::VARIATION_DEFAULTS[ $described['group'] ] ?? null;
			$clean['apply_to_variations'] = null !== $setting && Settings::load()->apply_to_variations( $setting );
		}
		// The same key order a native step has: field, own arguments, apply_to_variations, skip_already.
		$ordered = [];
		foreach ( array_keys( $specs ) as $name ) {
			if ( array_key_exists( $name, $clean ) ) {
				$ordered[ $name ] = $clean[ $name ];
			}
		}
		return $ordered;
	}

	/**
	 * A refusal of these arguments.
	 *
	 * @param string $message  Plain sentence.
	 * @param string $argument The argument at fault.
	 */
	private function refuse( string $message, string $argument ): \WP_Error {
		return Refusal::invalid(
			BaseOperation::INVALID,
			$message,
			[
				'action'   => $this->id,
				'argument' => $argument,
			]
		);
	}

	/**
	 * Never used: external steps are computed by Computer.
	 *
	 * @param object               $subject The object.
	 * @param array<string, mixed> $args    Arguments.
	 * @throws \LogicException Always.
	 */
	public function compute( object $subject, array $args ): Result {
		throw new \LogicException( 'External steps are computed by External\\Computer.' );
	}

	/**
	 * Nothing follows an external step.
	 *
	 * @param object               $subject The object.
	 * @param Result               $checked The checked Result.
	 * @param array<string, mixed> $args    Arguments.
	 * @return list<Result>
	 */
	public function follow( object $subject, Result $checked, array $args ): array {
		unset( $subject, $checked, $args );
		return [];
	}

	/**
	 * Never used: external steps are written by External\Writer.
	 *
	 * @param Result $result  The result.
	 * @param object $subject The object.
	 * @throws \LogicException Always.
	 */
	public function apply( Result $result, object $subject ): void {
		throw new \LogicException( 'External steps are written by External\\Writer.' );
	}
}
