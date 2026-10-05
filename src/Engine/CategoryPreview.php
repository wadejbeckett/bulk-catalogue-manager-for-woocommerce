<?php
/**
 * The preview of a category change (docs/EXTENSIONS-v2.md §5.3).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Categories\HistoryText;
use BulkCatalogueManager\External\Binding;
use BulkCatalogueManager\External\Computer;
use BulkCatalogueManager\External\ExternalWriter;
use BulkCatalogueManager\External\Guard;
use BulkCatalogueManager\External\ProviderFailure;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\Registered;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\External\TermSubject;
use BulkCatalogueManager\External\Text;
use BulkCatalogueManager\Extension\CategoryProvider;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Previews a category change: what one
 * provider's category action would do to an exact set of product
 * categories, computed category by category without writing or flushing
 * anything.
 *
 * The steps are validated in preview mode (lookup choices resolved there,
 * EXTENSIONS-v2 §4.4) and must be one provider's category steps (else 400
 * `bulkcat_mixed_change`). The scope is built once (Engine\CategorySelection:
 * Include subcategories expanded here, duplicates removed, sorted), and the
 * answer lists every category record by its path with its outcome and the
 * provider's own descriptions (`current`, `new`, `change`, `notes`): the
 * categories being edited. Any effect on products is only what the
 * provider says in its notes and warnings; the core computes none. Per
 * category, as the writer does: it exists (read unfiltered), the user's
 * edit_term on it, the action's capability, then authorise(). The hash binds
 * the captured set and its scope hash, the steps, the settings and the
 * provider's identity, state token over a CategoryScope, and resolved choices.
 */
final class CategoryPreview {
	/**
	 * Build over the action registry.
	 *
	 * @param Actions|null $actions The registry; the shared one by default.
	 */
	public function __construct( private ?Actions $actions = null ) {
		$this->actions ??= Actions::instance();
	}

	/**
	 * The steps validated as a category change of one provider, or the refusal.
	 *
	 * @param mixed   $operations The steps as submitted.
	 * @param Actions $actions    The registry.
	 * @return array{0: list<array{action: string, args: array<string, mixed>}>, 1: Registered}|\WP_Error
	 */
	public static function steps( mixed $operations, Actions $actions ): array|\WP_Error {
		$operations = $actions->validate_list( $operations );
		if ( $operations instanceof \WP_Error ) {
			return $operations;
		}
		$provider = $actions->external_provider( $operations );
		if ( null === $provider || ! $provider->category_change( $operations ) ) {
			return self::not_categories();
		}
		return [ $operations, $provider ];
	}

	/**
	 * The refusal of steps that do not act on categories.
	 */
	public static function not_categories(): \WP_Error {
		return Refusal::invalid( Refusals::MIXED, __( 'Only an add-on\'s actions on categories can change categories. Choose one of those actions and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
	}

	/**
	 * Preview a category change.
	 *
	 * @param mixed         $operations    The steps as submitted.
	 * @param mixed         $categories    The chosen term ids as submitted.
	 * @param bool          $subcategories Include subcategories.
	 * @param Settings|null $settings      Settings; the stored ones by default.
	 * @return array<string, mixed>|\WP_Error
	 * @throws \InvalidArgumentException When the chosen ids are malformed.
	 */
	public function run( mixed $operations, mixed $categories, bool $subcategories, ?Settings $settings = null ): array|\WP_Error {
		$context = RunContext::preview( get_current_user_id() );
		$checked = RunContext::within( $context, fn(): array|\WP_Error => self::steps( $operations, $this->actions ) );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}
		[ $operations, $provider ] = $checked;
		try {
			$scope = CategorySelection::build( CategorySelection::ids( $categories ), $subcategories );
		} catch ( \LengthException $e ) {
			return CategorySelection::too_large();
		}
		try {
			$binding = Binding::of( $provider, $operations, $scope['available'], $context );
		} catch ( ProviderFailure $e ) {
			return Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
		}
		$tally = $this->compute( $provider, $context, $scope, $operations );
		HistoryText::reset();
		$settings ??= Settings::load();
		return [
			'totals'     => [
				'matched'      => count( $scope['ids'] ),
				'would_change' => $tally['would_change'],
				'already'      => $tally['already'],
				'would_fail'   => $tally['would_fail'],
				'unavailable'  => count( $scope['unavailable'] ),
			],
			'summary'    => self::summary( $tally['fields'], $tally['would_change'] ),
			'records'    => $tally['records'],
			'categories' => [
				'chosen'        => $scope['chosen'],
				'subcategories' => $scope['subcategories'],
				'ids'           => $scope['ids'],
				'count'         => count( $scope['ids'] ),
				'added'         => count( $scope['ids'] ) - count( $scope['chosen'] ),
			],
			'hash'       => Preview::hash( $scope['ids'], $operations, $settings, $scope['scope_hash'], $binding ),
			'scope_hash' => $scope['scope_hash'],
			'operations' => $operations,
			'external'   => [
				'provider' => $provider->id,
				'label'    => $provider->label,
				'version'  => $provider->version,
				'target'   => TermSubject::TAXONOMY,
				'warnings' => array_values( $tally['warnings'] ),
				'confirm'  => $tally['confirm'],
				'reasons'  => $tally['reasons'],
			],
		];
	}

	/**
	 * Compute every category of the scope.
	 *
	 * @param Registered                                              $provider   The provider.
	 * @param RunContext                                              $context    The preview's run.
	 * @param array<string, mixed>                                    $scope      CategorySelection::build().
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The steps.
	 * @return array<string, mixed>
	 */
	private function compute( Registered $provider, RunContext $context, array $scope, array $operations ): array {
		$reasons = [];
		$confirm = false;
		foreach ( $operations as $step ) {
			$described = $provider->described( (string) $step['action'] );
			if ( null !== $described && $described['always_confirm'] ) {
				$confirm   = true;
				$reasons[] = '' !== $described['confirm_reason'] ? $described['confirm_reason'] : sprintf(
					/* translators: %s: an action's name, such as "Replace labels". */
					__( '%s always asks before it is applied.', 'bulk-catalogue-manager-for-woocommerce' ),
					$described['label']
				);
			}
		}
		$tally    = [
			'would_change' => 0,
			'already'      => 0,
			'would_fail'   => [],
			'records'      => [],
			'fields'       => [],
			'warnings'     => [],
			'confirm'      => $confirm,
			'reasons'      => array_values( array_unique( $reasons ) ),
		];
		$computer = new Computer( $provider, $context );
		$category = $provider->provider;
		HistoryText::prime( $scope['ids'] );
		foreach ( array_chunk( $scope['available'], Preview::PAGE ) as $page ) {
			if ( $category instanceof CategoryProvider ) {
				try {
					Guard::run( $provider->id, 'prime_terms', static fn(): mixed => $category->prime_terms( TermSubject::TAXONOMY, $page ) );
				} catch ( ProviderFailure $e ) {
					unset( $e ); // Logged by the guard; reads then load one by one.
				}
			}
		}
		$terms = TermSubject::load_many( $scope['available'] );
		foreach ( $scope['ids'] as $id ) {
			$term = $terms[ $id ] ?? null;
			if ( null === $term ) {
				$tally['records'][] = self::record( $id, HistoryText::path( $id ), 'unavailable', [ self::gone_row( $operations, $provider ) ] );
				continue;
			}
			$subject = new TermSubject( $term, $provider );
			if ( ! user_can( $context->user_id(), 'edit_term', $id ) ) {
				// Nothing of a category the user may not edit is computed or shown, and the provider is never asked.
				$results = array_map( static fn( array $step ): Result => Result::skipped( (string) $step['args']['field'], null, __( 'You do not have permission to change this category, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ), Access::CANNOT_EDIT ), $operations );
				$shown   = false;
			} else {
				$results = $computer->run( $subject, $operations )->results;
				$shown   = true;
			}
			$states = [];
			$rows   = [];
			foreach ( $results as $result ) {
				$state            = $result->state();
				$states[ $state ] = true;
				$meta             = $provider->field_meta( $result->field() );
				$label            = null === $meta ? '' : (string) $meta['label'];
				if ( Result::FAILED === $state || Result::SKIPPED === $state ) {
					$tally['would_fail'][ $result->reason() ] = ( $tally['would_fail'][ $result->reason() ] ?? 0 ) + 1;
				}
				if ( Result::CHANGED === $state ) {
					$tally['fields'][ $result->field() ] ??= $label;
				}
				$this->warn( $tally, $result );
				$visible = $shown && in_array( $state, [ Result::CHANGED, Result::ALREADY ], true );
				$rows[]  = [
					'field'      => $result->field(),
					'fieldLabel' => $label,
					'state'      => $state,
					'current'    => $visible ? $provider->describe( $subject, $result->field(), $result->old() ) : '',
					'new'        => $visible ? $provider->describe( $subject, $result->field(), $result->new() ) : '',
					'change'     => $shown && $result->is_changed() ? self::describe_change( $provider, $subject, $result ) : '',
					'reason'     => $result->reason(),
					'code'       => $result->code(),
					'notes'      => $result->notes(),
				];
			}
			if ( isset( $states[ Result::CHANGED ] ) ) {
				++$tally['would_change'];
				$outcome = 'change';
			} elseif ( [ Result::ALREADY ] === array_keys( $states ) ) {
				++$tally['already'];
				$outcome = 'already';
			} else {
				$outcome = isset( $states[ Result::FAILED ] ) ? 'fail' : 'skip';
			}
			$tally['records'][] = self::record( $id, HistoryText::path( $id ), $outcome, $rows );
		}
		return $tally;
	}

	/**
	 * One category record.
	 *
	 * @param int                        $id      Term id.
	 * @param string                     $path    Its path, such as "Kitchen › Pots".
	 * @param string                     $outcome change, already, skip, fail or unavailable.
	 * @param list<array<string, mixed>> $rows    One per step.
	 * @return array<string, mixed>
	 */
	private static function record( int $id, string $path, string $outcome, array $rows ): array {
		return [
			'id'      => $id,
			'ref'     => 'term:' . TermSubject::TAXONOMY . ':' . $id,
			'path'    => $path,
			'outcome' => $outcome,
			'rows'    => $rows,
		];
	}

	/**
	 * The row of a category that no longer exists.
	 *
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The steps.
	 * @param Registered                                              $provider   The provider.
	 * @return array<string, mixed>
	 */
	private static function gone_row( array $operations, Registered $provider ): array {
		$field = (string) ( $operations[0]['args']['field'] ?? '' );
		$meta  = $provider->field_meta( $field );
		return [
			'field'      => $field,
			'fieldLabel' => null === $meta ? '' : (string) $meta['label'],
			'state'      => Result::SKIPPED,
			'current'    => '',
			'new'        => '',
			'change'     => '',
			'reason'     => ExternalWriter::term_gone_sentence(),
			'code'       => Writer::UNAVAILABLE,
			'notes'      => [],
		];
	}

	/**
	 * Count a provider's warning, each category once per code.
	 *
	 * @param array<string, mixed> $tally  The tally.
	 * @param Result               $result A result.
	 */
	private function warn( array &$tally, Result $result ): void {
		$warning = $result->warning();
		if ( null === $warning ) {
			return;
		}
		$tally['warnings'][ $warning['code'] ] ??= [
			'code'     => $warning['code'],
			'sentence' => $warning['sentence'],
			'count'    => 0,
			'confirm'  => false,
		];
		++$tally['warnings'][ $warning['code'] ]['count'];
		if ( $warning['confirm'] ) {
			$tally['warnings'][ $warning['code'] ]['confirm'] = true;
			$tally['confirm']                                 = true;
			if ( ! in_array( $warning['sentence'], $tally['reasons'], true ) ) {
				$tally['reasons'][] = $warning['sentence'];
			}
		}
	}

	/**
	 * What a change does, as its provider says it, plain; '' when it says nothing or fails.
	 *
	 * @param Registered  $provider The provider.
	 * @param TermSubject $item     The category.
	 * @param Result      $result   The change.
	 */
	private static function describe_change( Registered $provider, TermSubject $item, Result $result ): string {
		$field = $provider->field( $result->field() );
		try {
			return null === $field ? '' : Text::plain( Guard::run( $provider->id, 'describe_change', static fn(): mixed => $field->describe_change( $result->old(), $result->new(), $item ) ), Text::VALUE );
		} catch ( ProviderFailure $e ) {
			return '';
		}
	}

	/**
	 * The summary, counting categories (never products): "Shelves will
	 * change on 12 categories."
	 *
	 * @param array<string, string> $fields  Labels of the fields that would change.
	 * @param int                   $changed Categories that would change.
	 */
	public static function summary( array $fields, int $changed ): string {
		if ( 0 === $changed || [] === $fields ) {
			return __( 'Nothing would change: every chosen category is already as asked, or cannot be changed.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		if ( 1 === count( $fields ) ) {
			return sprintf(
				/* translators: 1: a field's name, such as "Shelves"; 2: how many categories, such as "12". */
				_n( '%1$s will change on %2$s category.', '%1$s will change on %2$s categories.', $changed, 'bulk-catalogue-manager-for-woocommerce' ),
				(string) reset( $fields ),
				number_format_i18n( $changed )
			);
		}
		return sprintf(
			/* translators: 1: how many fields, such as "2"; 2: how many categories, such as "12". */
			_n( '%1$s fields will change on %2$s category.', '%1$s fields will change on %2$s categories.', $changed, 'bulk-catalogue-manager-for-woocommerce' ),
			number_format_i18n( count( $fields ) ),
			number_format_i18n( $changed )
		);
	}
}
