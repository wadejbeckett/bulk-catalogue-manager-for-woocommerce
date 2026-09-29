<?php
/**
 * "Sample labels" (docs/EXTENSIONS.md §15.1): the neutral test provider the
 * core's acceptance tests, native probe and browser tests use. It lives only
 * in the test tree and is never shipped (.distignore, .gitattributes and the
 * archive manifest keep tests/ out of every archive).
 *
 * Provider `sample`, contract 1. Field `x_sample_labels` (parent only, a
 * sorted list of labels in `_sample_labels`) and `x_sample_note` (per
 * variation, text in `_sample_note`). Actions `x_sample_add`,
 * `x_sample_remove`, `x_sample_replace`, `x_sample_clear` (chooser group
 * `x_sample_labels`) and `x_sample_set_note` (the native Organisation
 * group). Faults: SampleFaults.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

use BulkCatalogueManager\Extension\AbstractProvider;
use BulkCatalogueManager\Extension\ChooserGroup;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\Scope;
use BulkCatalogueManager\Extension\Subject;

/**
 * The sample provider.
 */
final class SampleProvider extends AbstractProvider {
	/**
	 * The option holding the offered labels (value => label).
	 */
	public const CHOICES = 'sample_provider_choices';

	/**
	 * Every flush() call, as [ ids, mode ].
	 *
	 * @var list<array{0: list<int>, 1: string}>
	 */
	public static array $flushed = [];

	/**
	 * Every validate() call's action id and mode.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	public static array $validated = [];

	/**
	 * Every compute() call, as [ action id, object id, mode ].
	 *
	 * @var list<array{0: string, 1: int, 2: string}>
	 */
	public static array $computed = [];

	/**
	 * Every authorise() call's object id.
	 *
	 * @var list<int>
	 */
	public static array $authorised = [];

	/**
	 * Every prime() call's ids.
	 *
	 * @var list<list<int>>
	 */
	public static array $primed = [];

	/**
	 * Forget every recorded call.
	 */
	public static function reset(): void {
		self::$flushed    = [];
		self::$tokens     = [];
		self::$validated  = [];
		self::$computed   = [];
		self::$authorised = [];
		self::$primed     = [];
	}

	/**
	 * Every state_token() call's scope, as [ count, ids ].
	 *
	 * @var list<array{0: int, 1: list<int>}>
	 */
	public static array $tokens = [];

	/**
	 * Where its values live.
	 *
	 * @var SampleStore
	 */
	private readonly SampleStore $store;

	/**
	 * Over a store: post meta unless one is given.
	 *
	 * @param SampleStore|null $store The store.
	 */
	public function __construct( ?SampleStore $store = null ) {
		$this->store = $store ?? new MetaStore();
	}

	/**
	 * The labels offered, value => label.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$choices = get_option( self::CHOICES, null );
		return is_array( $choices ) && [] !== $choices ? $choices : [
			'blue'  => 'Blue',
			'green' => 'Green',
			'red'   => 'Red',
			'sale'  => 'Sale',
		];
	}

	/**
	 * Its store.
	 */
	public function store(): SampleStore {
		return $this->store;
	}

	public function id(): string {
		return 'sample';
	}

	public function label(): string {
		return 'Sample labels';
	}

	public function version(): string {
		return (string) SampleFaults::get( 'version', '1.0.0' );
	}

	public function contract(): int {
		return (int) SampleFaults::get( 'contract', 1 );
	}

	public function availability(): bool|\WP_Error {
		return SampleFaults::get( 'unavailable', false )
			? new \WP_Error( 'x_sample_off', 'Sample labels needs its settings saved before it can be used.' )
			: true;
	}

	public function fields(): array {
		return [ new LabelsField( $this->store ), new NoteField( $this->store ) ];
	}

	public function actions(): array {
		return [
			new LabelsAction( LabelsAction::ADD ),
			new LabelsAction( LabelsAction::REMOVE ),
			new LabelsAction( LabelsAction::REPLACE ),
			new LabelsAction( LabelsAction::CLEAR ),
			new NoteAction(),
		];
	}

	public function groups(): array {
		return [ new ChooserGroup( 'x_sample_labels', 'Sample labels' ) ];
	}

	/**
	 * Refuse the ids of the `refuse` fault.
	 *
	 * @param int     $user_id The user.
	 * @param Subject $subject The object.
	 */
	public function authorise( int $user_id, Subject $subject ): bool|\WP_Error {
		unset( $user_id );
		self::$authorised[] = $subject->id();
		return SampleFaults::has( 'refuse', $subject->id() )
			? new \WP_Error( 'x_sample_refused', 'The sample add-on does not allow changes to this product.' )
			: true;
	}

	/**
	 * The offered labels, the `state` fault and the scope's size.
	 *
	 * @param Scope   $scope   The members.
	 * @param array   $steps   The steps.
	 * @param Context $context The run.
	 */
	public function state_token( Scope $scope, array $steps, Context $context ): string {
		unset( $steps, $context );
		$ids = [];
		foreach ( $scope->chunks( 500 ) as $chunk ) {
			$ids = [ ...$ids, ...$chunk ];
		}
		self::$tokens[] = [ $scope->count(), $ids ];
		return hash( 'sha256', (string) wp_json_encode( [ self::choices(), (string) SampleFaults::get( 'state', '' ), $scope->count() ] ) );
	}

	/**
	 * Load the values of a page in one go.
	 *
	 * @param list<int> $object_ids Objects.
	 */
	public function prime( array $object_ids ): void {
		self::$primed[] = $object_ids;
		$this->store->prime( $object_ids );
	}

	/**
	 * Record the call.
	 *
	 * @param list<int> $object_ids Objects written.
	 * @param Context   $context    The run.
	 * @throws \RuntimeException For the throw_flush fault.
	 */
	public function flush( array $object_ids, Context $context ): void {
		self::$flushed[] = [ $object_ids, $context->mode() ];
		if ( SampleFaults::get( 'throw_flush', false ) ) {
			throw new \RuntimeException( 'The sample flush failed on purpose.' );
		}
	}
}
