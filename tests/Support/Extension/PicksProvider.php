<?php
/**
 * A neutral contract version 2 test provider, "Sample picks": a list of
 * recipient ids, provider-owned and meaningless to the core, kept on
 * products and on product categories, chosen through a searchable, paged
 * lookup of 5,000 recipients, with row entries. It lives only in the test
 * tree and never ships (docs/EXTENSIONS-v2.md §9).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

use BulkCatalogueManager\Extension\AbstractProvider;
use BulkCatalogueManager\Extension\CategoryProvider;
use BulkCatalogueManager\Extension\CategoryScope;
use BulkCatalogueManager\Extension\CategorySubject;
use BulkCatalogueManager\Extension\ChooserGroup;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\Scope;
use BulkCatalogueManager\Extension\Subject;

/**
 * The provider.
 */
final class PicksProvider extends AbstractProvider implements CategoryProvider {
	/**
	 * Recorded calls, for assertions: kind => list of arguments.
	 *
	 * @var array<string, list<mixed>>
	 */
	public static array $calls = [];

	/**
	 * Product storage.
	 *
	 * @var SampleStore
	 */
	private readonly SampleStore $products;

	/**
	 * Category storage.
	 *
	 * @var SampleStore
	 */
	private readonly SampleStore $terms;

	/**
	 * Build it.
	 *
	 * @param SampleStore|null $products Product storage (post meta by default).
	 * @param SampleStore|null $terms    Category storage (term meta by default).
	 * @param bool             $categories Whether to offer the category actions.
	 */
	public function __construct( ?SampleStore $products = null, ?SampleStore $terms = null, private readonly bool $categories = true ) {
		$this->products = $products ?? new MetaStore();
		$this->terms    = $terms ?? new TermMetaStore();
	}

	/**
	 * Forget recorded calls.
	 */
	public static function reset(): void {
		self::$calls           = [];
		PicksAction::$computed = [];
		PicksField::$written   = [];
		PicksDirectory::reset();
	}

	/**
	 * The id.
	 */
	public function id(): string {
		return 'picks';
	}

	/**
	 * The label.
	 */
	public function label(): string {
		return 'Sample picks';
	}

	/**
	 * The version.
	 */
	public function version(): string {
		return (string) PicksFaults::get( 'version', '2.0.0' );
	}

	/**
	 * Contract 2.
	 */
	public function contract(): int {
		return (int) PicksFaults::get( 'contract', 2 );
	}

	/**
	 * Available unless switched off.
	 */
	public function availability(): bool|\WP_Error {
		return PicksFaults::get( 'unavailable', false ) ? new \WP_Error( 'x_picks_off', 'Sample picks needs its directory connected before it can be used.' ) : true;
	}

	/**
	 * The fields.
	 */
	public function fields(): array {
		$fields = [ new PicksField( PicksField::PRODUCT, $this->products ) ];
		if ( $this->categories ) {
			$fields[] = new PicksField( PicksField::CATEGORY, $this->terms );
		}
		return $fields;
	}

	/**
	 * The actions.
	 */
	public function actions(): array {
		$ids = [ PicksAction::ADD, PicksAction::REMOVE, PicksAction::ONLY, PicksAction::CLEAR ];
		if ( $this->categories ) {
			$ids = [ ...$ids, PicksAction::CATEGORY_ADD, PicksAction::CATEGORY_REM ];
		}
		return array_map( static fn( string $id ): PicksAction => new PicksAction( $id ), $ids );
	}

	/**
	 * One chooser group.
	 */
	public function groups(): array {
		return [ new ChooserGroup( 'x_picks_items', 'Sample picks' ) ];
	}

	/**
	 * Refuse chosen products or categories.
	 *
	 * @param int     $user_id The user.
	 * @param Subject $subject The object.
	 */
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error {
		self::$calls['authorise'][] = [ $user_id, $subject->type(), $subject->id() ];
		if ( $subject instanceof CategorySubject ) {
			return PicksFaults::has( 'refuse_terms', $subject->id() ) ? new \WP_Error( 'x_picks_refused', 'Sample picks does not allow changes to this category.' ) : true;
		}
		return PicksFaults::has( 'refuse', $subject->id() ) ? new \WP_Error( 'x_picks_refused', 'Sample picks does not allow changes to this product.' ) : true;
	}

	/**
	 * The directory's fingerprint, the scope's kind and size.
	 *
	 * @param Scope                                                $scope   The scope.
	 * @param list<array{action: string, args: array<string, mixed>}> $steps   The steps.
	 * @param Context                                              $context The run.
	 */
	public function state_token( Scope $scope, array $steps, Context $context ): string {
		unset( $steps );
		self::$calls['state_token'][] = [ $scope instanceof CategoryScope ? 'product_cat' : 'product', $scope->count(), $context->mode() ];
		return hash( 'sha256', PicksDirectory::fingerprint() . '|' . ( $scope instanceof CategoryScope ? $scope->taxonomy() : 'product' ) . '|' . $scope->count() );
	}

	/**
	 * Prime product meta.
	 *
	 * @param list<int> $object_ids Products and variations.
	 */
	public function prime( array $object_ids ): void {
		self::$calls['prime'][] = $object_ids;
		$this->products->prime( $object_ids );
	}

	/**
	 * Record a product flush.
	 *
	 * @param list<int> $object_ids Written products.
	 * @param Context   $context    The run.
	 */
	public function flush( array $object_ids, Context $context ): void {
		self::$calls['flush'][] = [ $object_ids, $context->mode() ];
	}

	/**
	 * Prime term meta.
	 *
	 * @param string    $taxonomy The taxonomy.
	 * @param list<int> $term_ids Terms.
	 */
	public function prime_terms( string $taxonomy, array $term_ids ): void {
		self::$calls['prime_terms'][] = [ $taxonomy, $term_ids ];
		$this->terms->prime( $term_ids );
	}

	/**
	 * Record a category flush.
	 *
	 * @param string    $taxonomy The taxonomy.
	 * @param list<int> $term_ids Written terms.
	 * @param Context   $context  The run.
	 */
	public function flush_terms( string $taxonomy, array $term_ids, Context $context ): void {
		self::$calls['flush_terms'][] = [ $taxonomy, $term_ids, $context->mode() ];
	}
}
