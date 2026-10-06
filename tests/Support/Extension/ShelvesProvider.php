<?php
/**
 * A neutral contract version 2 test provider, "Sample shelves", acting on
 * product categories only: each category holds a sorted list of shelf
 * codes, provider-owned and meaningless to the core, chosen from a fixed
 * list (a `multichoice` argument, so it needs only the `category_target`
 * feature). It lives only in the test tree and never ships. Fault switches
 * in option `sample_shelves_faults`: `unavailable`, `version`,
 * `refuse_terms` (term ids authorise() refuses), `write_conflict` (term
 * ids), `throw_flush`, `state`.
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
final class ShelvesProvider extends AbstractProvider implements CategoryProvider {
	/**
	 * The option holding the fault switches.
	 */
	public const FAULTS = 'sample_shelves_faults';

	/**
	 * Recorded calls, for assertions: kind => list of arguments.
	 *
	 * @var array<string, list<mixed>>
	 */
	public static array $calls = [];

	/**
	 * Category storage.
	 *
	 * @var SampleStore
	 */
	private readonly SampleStore $store;

	/**
	 * Build it.
	 *
	 * @param SampleStore|null $store Category storage (term meta by default).
	 */
	public function __construct( ?SampleStore $store = null ) {
		$this->store = $store ?? new TermMetaStore();
	}

	/**
	 * Forget recorded calls.
	 */
	public static function reset(): void {
		self::$calls = [];
	}

	/**
	 * One fault switch.
	 *
	 * @param string $name     The switch.
	 * @param mixed  $fallback Its value when unset.
	 */
	public static function fault( string $name, mixed $fallback = null ): mixed {
		$faults = get_option( self::FAULTS, [] );
		return is_array( $faults ) && array_key_exists( $name, $faults ) ? $faults[ $name ] : $fallback;
	}

	/**
	 * Whether a list switch names an id.
	 *
	 * @param string $name The switch.
	 * @param int    $id   The id.
	 */
	public static function names( string $name, int $id ): bool {
		$ids = self::fault( $name, [] );
		return is_array( $ids ) && in_array( $id, array_map( 'intval', $ids ), true );
	}

	/**
	 * The shelves offered, code => label.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		return [
			'north' => 'North wall',
			'south' => 'South wall',
			'east'  => 'East wall',
			'west'  => 'West wall',
		];
	}

	/**
	 * The id.
	 */
	public function id(): string {
		return 'shelves';
	}

	/**
	 * The label.
	 */
	public function label(): string {
		return 'Sample shelves';
	}

	/**
	 * The version.
	 */
	public function version(): string {
		return (string) self::fault( 'version', '2.0.0' );
	}

	/**
	 * Contract 2.
	 */
	public function contract(): int {
		return 2;
	}

	/**
	 * Available unless switched off.
	 */
	public function availability(): bool|\WP_Error {
		return self::fault( 'unavailable', false ) ? new \WP_Error( 'x_shelves_off', 'Sample shelves needs its floor plan saved before it can be used.' ) : true;
	}

	/**
	 * The one field.
	 */
	public function fields(): array {
		return [ new ShelvesField( $this->store ) ];
	}

	/**
	 * Add and remove.
	 */
	public function actions(): array {
		return [ new ShelvesAction( ShelvesAction::ADD ), new ShelvesAction( ShelvesAction::REMOVE ) ];
	}

	/**
	 * One chooser group.
	 */
	public function groups(): array {
		return [ new ChooserGroup( 'x_shelves_items', 'Sample shelves' ) ];
	}

	/**
	 * Refuse chosen categories.
	 *
	 * @param int     $user_id The user.
	 * @param Subject $subject The object.
	 */
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error {
		self::$calls['authorise'][] = [ $user_id, $subject->type(), $subject->id(), $subject instanceof CategorySubject ? $subject->ref()->key() : '' ];
		return self::names( 'refuse_terms', $subject->id() ) ? new \WP_Error( 'x_shelves_refused', 'Sample shelves does not allow changes to this category.' ) : true;
	}

	/**
	 * The state switch, the scope's kind and its ids.
	 *
	 * @param Scope                                                   $scope   The scope.
	 * @param list<array{action: string, args: array<string, mixed>}> $steps   The steps.
	 * @param Context                                                 $context The run.
	 */
	public function state_token( Scope $scope, array $steps, Context $context ): string {
		unset( $steps );
		$ids = [];
		foreach ( $scope->chunks( 500 ) as $chunk ) {
			$ids = [ ...$ids, ...$chunk ];
		}
		$kind                         = $scope instanceof CategoryScope ? $scope->taxonomy() : 'product';
		self::$calls['state_token'][] = [ $kind, $ids, $context->mode() ];
		return hash( 'sha256', (string) wp_json_encode( [ (string) self::fault( 'state', '' ), $kind, $ids ] ) );
	}

	/**
	 * Never asked: this provider has no product actions.
	 *
	 * @param list<int> $object_ids Products.
	 */
	public function prime( array $object_ids ): void {
		self::$calls['prime'][] = $object_ids;
	}

	/**
	 * Never asked: this provider has no product actions.
	 *
	 * @param list<int> $object_ids Products.
	 * @param Context   $context    The run.
	 */
	public function flush( array $object_ids, Context $context ): void {
		self::$calls['flush'][] = [ $object_ids, $context->mode() ];
	}

	/**
	 * Load category values.
	 *
	 * @param string    $taxonomy The taxonomy.
	 * @param list<int> $term_ids Terms.
	 */
	public function prime_terms( string $taxonomy, array $term_ids ): void {
		self::$calls['prime_terms'][] = [ $taxonomy, $term_ids ];
		$this->store->prime( $term_ids );
	}

	/**
	 * Record a category flush.
	 *
	 * @param string    $taxonomy The taxonomy.
	 * @param list<int> $term_ids Written terms.
	 * @param Context   $context  The run.
	 * @throws \RuntimeException For the throw_flush fault.
	 */
	public function flush_terms( string $taxonomy, array $term_ids, Context $context ): void {
		self::$calls['flush_terms'][] = [ $taxonomy, $term_ids, $context->mode() ];
		if ( self::fault( 'throw_flush', false ) ) {
			throw new \RuntimeException( 'The sample shelves flush failed on purpose.' );
		}
	}
}
