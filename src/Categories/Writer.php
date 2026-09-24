<?php
/**
 * Category writes (SPEC §4.7, §7, §9.3, §13; T63, T64): create a product
 * category, edit its name, slug, description and parent, and move it to a
 * parent and a position among its siblings.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Categories;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ChunkRunner;
use BulkCatalogueManager\Engine\Journal;
use BulkCatalogueManager\Engine\Writer as ProductWriter;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Writes product categories as journalled batches of one.
 *
 * Every write goes through WordPress's own term functions, `wp_insert_term()`
 * and `wp_update_term()` (never SQL), with the tree order in the plugin's own
 * `bulkcat_order` term meta (CategoryTree::TREE_ORDER_META), which only this tree
 * reads. Before anything is written it refuses, with a §4.8 sentence and a
 * `bulkcat_` code: an empty name, a name a sibling under the same parent already
 * has, a slug another category already has, a parent that does not exist,
 * and a parent that is the category itself or one of its descendants ("A
 * category cannot be moved inside itself.").
 *
 * Each write is a batch of one with source `tree` and one `term` item,
 * opened running through Engine\BatchFactory::open() and never queued, and
 * journalled through Engine\Journal::record() with object type `term`: one
 * row per changed property, `term_name`, `term_slug`, `term_description`,
 * `term_parent` and `term_order`, each holding the old and new value, so the
 * History screen lists it and undo can put it back. The prefix keeps these
 * apart from the field registry's product fields of the same name. A move
 * renumbers the siblings it leaves and joins compactly from 0 in display
 * order, and each sibling whose position changed gets its own `term_order`
 * row in the same batch. A create is written first (it has no id before),
 * and removed again if its batch cannot be recorded, so no category is ever
 * created without its journal.
 *
 * After a write the tree cache is renewed (Cache::invalidate(); the term
 * hooks do so too, but a term meta change fires none), and the answer holds
 * the category in the tree's node shape, which carries its description.
 */
final class Writer {
	/**
	 * The batch source of every category write.
	 */
	public const SOURCE = 'tree';

	/**
	 * The item and journal object type.
	 */
	public const OBJECT_TYPE = 'term';

	/**
	 * Journal property per term argument.
	 */
	public const PROPS = [
		'name'        => 'term_name',
		'slug'        => 'term_slug',
		'description' => 'term_description',
		'parent'      => 'term_parent',
	];

	/**
	 * Journal property of the tree order.
	 */
	public const ORDER_PROP = 'term_order';

	/**
	 * The step a create batch records.
	 */
	public const CREATE = 'create_category';

	/**
	 * The step an edit batch records.
	 */
	public const EDIT = 'edit_category';

	/**
	 * The step a move batch records.
	 */
	public const MOVE = 'move_category';

	/**
	 * Refusal code for an id that is not a product category.
	 */
	public const NOT_FOUND = ProductWriter::NOT_FOUND;

	/**
	 * Refusal code for an empty name.
	 */
	public const NAME_REQUIRED = 'bulkcat_name_required';

	/**
	 * Refusal code for a name a sibling already has.
	 */
	public const NAME_TAKEN = 'bulkcat_category_exists';

	/**
	 * Refusal code for a slug another category already has.
	 */
	public const SLUG_TAKEN = 'bulkcat_slug_taken';

	/**
	 * Refusal code for a slug with no letter or number.
	 */
	public const SLUG_INVALID = 'bulkcat_slug_invalid';

	/**
	 * Refusal code for a parent that does not exist.
	 */
	public const PARENT_MISSING = 'bulkcat_parent_missing';

	/**
	 * Refusal code for a parent that is the category or one of its descendants.
	 */
	public const CYCLE = 'bulkcat_category_cycle';

	/**
	 * Refusal code for a write WordPress refused.
	 */
	public const NOT_SAVED = 'bulkcat_category_not_saved';

	/**
	 * Batches and items.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $batches;

	/**
	 * The journal table.
	 *
	 * @var ChangeRepository
	 */
	private ChangeRepository $changes;

	/**
	 * Opens the batches.
	 *
	 * @var BatchFactory
	 */
	private BatchFactory $factory;

	/**
	 * Build over the plugin's repositories by default.
	 *
	 * @param BatchRepository|null  $batches  Batches and items.
	 * @param ChangeRepository|null $changes  The journal table.
	 * @param Settings|null         $settings Settings; loaded when first needed by default.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, private ?Settings $settings = null ) {
		$this->batches = $batches ?? new BatchRepository();
		$this->changes = $changes ?? new ChangeRepository();
		$this->factory = new BatchFactory( null, $this->batches );
	}

	/**
	 * Create a category.
	 *
	 * @param array<string, mixed> $input   name, and optionally slug, description and parent.
	 * @param int                  $user_id The user the batch is recorded for.
	 * @return array{item: array<string, mixed>, batch_id: int, status: string, results: list<array<string, mixed>>}|\WP_Error
	 */
	public function create( array $input, int $user_id ): array|\WP_Error {
		$tree      = CategoryTree::tree_of( CategoryTree::terms() );
		$name      = self::text( $input['name'] ?? '', $user_id );
		$parent_id = (int) ( $input['parent'] ?? 0 );
		$slug      = self::slug( $input['slug'] ?? null );
		if ( '' === $name ) {
			return self::name_required();
		}
		$refused = $slug instanceof \WP_Error ? $slug : self::parent_refusal( $tree, 0, $parent_id );
		$refused = $refused ?? self::name_refusal( $tree, 0, $parent_id, $name ) ?? self::slug_refusal( $tree, 0, $slug );
		if ( null !== $refused ) {
			return $refused;
		}

		$args = [
			'description' => self::html( (string) ( $input['description'] ?? '' ), $user_id ),
			'parent'      => $parent_id,
		];
		if ( is_string( $slug ) ) {
			$args['slug'] = $slug;
		}
		$inserted = wp_insert_term( wp_slash( $name ), Counts::TAXONOMY, wp_slash( $args ) );
		if ( is_wp_error( $inserted ) ) {
			return self::not_saved( $inserted );
		}
		$id       = (int) $inserted['term_id'];
		$batch_id = $this->factory->open( $user_id, [ self::step( self::CREATE, [ 'name' => $name ] + $args ) ], [ [ $id, self::OBJECT_TYPE ] ], self::SOURCE );
		if ( $batch_id instanceof \WP_Error ) {
			// Never leave a category the journal does not know about.
			wp_delete_term( $id, Counts::TAXONOMY );
			return $batch_id;
		}
		return $this->complete( $batch_id, $id, array_fill_keys( array_values( self::PROPS ), null ), [] );
	}

	/**
	 * Edit a category's name, slug, description or parent; a new parent
	 * places it last among its new siblings.
	 *
	 * @param int                  $id      Term id.
	 * @param array<string, mixed> $input   Any of name, slug, description and parent.
	 * @param int                  $user_id The user the batch is recorded for.
	 * @return array{item: array<string, mixed>, batch_id: int, status: string, results: list<array<string, mixed>>}|\WP_Error
	 */
	public function update( int $id, array $input, int $user_id ): array|\WP_Error {
		$terms = CategoryTree::terms();
		$tree  = CategoryTree::tree_of( $terms );
		$term  = self::find( $terms, $id );
		if ( null === $term || null === $tree->node( $id ) ) {
			return self::not_found();
		}
		$current = self::values( $term );
		$wanted  = [];
		if ( array_key_exists( 'name', $input ) && null !== $input['name'] ) {
			$wanted['name'] = self::text( $input['name'], $user_id );
			if ( '' === $wanted['name'] ) {
				return self::name_required();
			}
		}
		$slug = self::slug( $input['slug'] ?? null );
		if ( $slug instanceof \WP_Error ) {
			return $slug;
		}
		if ( is_string( $slug ) ) {
			$wanted['slug'] = $slug;
		}
		if ( array_key_exists( 'description', $input ) && null !== $input['description'] ) {
			$wanted['description'] = self::html( (string) $input['description'], $user_id );
		}
		if ( array_key_exists( 'parent', $input ) && null !== $input['parent'] ) {
			$wanted['parent'] = (int) $input['parent'];
		}

		$parent_id = $wanted['parent'] ?? $current['parent'];
		$regroup   = array_key_exists( 'parent', $wanted ) && $parent_id !== $tree->node( $id )['parent'];
		$refused   = array_key_exists( 'parent', $wanted ) && $parent_id !== $current['parent'] ? self::parent_refusal( $tree, $id, $parent_id ) : null;
		if ( null === $refused && ( $regroup || ( isset( $wanted['name'] ) && self::fold( $wanted['name'] ) !== self::fold( $current['name'] ) ) ) ) {
			$refused = self::name_refusal( $tree, $id, $parent_id, $wanted['name'] ?? $current['name'] );
		}
		if ( null === $refused && isset( $wanted['slug'] ) && $wanted['slug'] !== $current['slug'] ) {
			$refused = self::slug_refusal( $tree, $id, $wanted['slug'] );
		}
		if ( null !== $refused ) {
			return $refused;
		}

		$batch_id = $this->factory->open( $user_id, [ self::step( self::EDIT, $wanted ) ], [ [ $id, self::OBJECT_TYPE ] ], self::SOURCE );
		if ( $batch_id instanceof \WP_Error ) {
			return $batch_id;
		}
		$before = [];
		foreach ( array_keys( $wanted ) as $key ) {
			$before[ self::PROPS[ $key ] ] = $current[ $key ];
		}
		$updated = [] === $wanted ? true : wp_update_term( $id, Counts::TAXONOMY, wp_slash( $wanted ) );
		if ( is_wp_error( $updated ) ) {
			return $this->complete( $batch_id, $id, $before, [], $updated );
		}
		return $this->complete( $batch_id, $id, $before, $regroup ? self::place( $tree, $id, $parent_id, null ) : [] );
	}

	/**
	 * Move a category under a parent (0 for the top level) at a position
	 * among its new siblings, counted from 0; null or past the end places it last.
	 *
	 * @param int      $id       Term id.
	 * @param int      $parent_id   New parent id.
	 * @param int|null $position Position among the siblings.
	 * @param int      $user_id  The user the batch is recorded for.
	 * @return array{item: array<string, mixed>, batch_id: int, status: string, results: list<array<string, mixed>>}|\WP_Error
	 */
	public function move( int $id, int $parent_id, ?int $position, int $user_id ): array|\WP_Error {
		$terms = CategoryTree::terms();
		$tree  = CategoryTree::tree_of( $terms );
		$term  = self::find( $terms, $id );
		if ( null === $term || null === $tree->node( $id ) ) {
			return self::not_found();
		}
		$current = self::values( $term );
		$refused = self::parent_refusal( $tree, $id, $parent_id );
		if ( null === $refused && $parent_id !== $tree->node( $id )['parent'] ) {
			$refused = self::name_refusal( $tree, $id, $parent_id, $current['name'] );
		}
		if ( null !== $refused ) {
			return $refused;
		}

		$step     = self::step(
			self::MOVE,
			[
				'parent'   => $parent_id,
				'position' => $position,
			]
		);
		$batch_id = $this->factory->open( $user_id, [ $step ], [ [ $id, self::OBJECT_TYPE ] ], self::SOURCE );
		if ( $batch_id instanceof \WP_Error ) {
			return $batch_id;
		}
		$before = [ self::PROPS['parent'] => $current['parent'] ];
		if ( $parent_id !== $current['parent'] ) {
			$updated = wp_update_term( $id, Counts::TAXONOMY, [ 'parent' => $parent_id ] );
			if ( is_wp_error( $updated ) ) {
				return $this->complete( $batch_id, $id, $before, [], $updated );
			}
		}
		return $this->complete( $batch_id, $id, $before, self::place( $tree, $id, $parent_id, $position ) );
	}

	/**
	 * Put a category at a position under a parent and renumber, from 0 in
	 * display order, the siblings it joins and, when it changes parent, the
	 * ones it leaves; only positions that change are written.
	 *
	 * @param Tree     $tree     The tree before the write.
	 * @param int      $id       Term id.
	 * @param int      $parent_id   New parent id.
	 * @param int|null $position Position, or null for last.
	 * @return array<int, array{0: int|null, 1: int}> Term id => [ stored order before, order now ].
	 */
	private static function place( Tree $tree, int $id, int $parent_id, ?int $position ): array {
		$from   = $tree->node( $id )['parent'] ?? 0;
		$joined = array_values( array_diff( $tree->children( $parent_id ), [ $id ] ) );
		$at     = null === $position ? count( $joined ) : max( 0, min( $position, count( $joined ) ) );
		array_splice( $joined, $at, 0, [ $id ] );
		$groups = [ $joined ];
		if ( $from !== $parent_id ) {
			$groups[] = array_values( array_diff( $tree->children( $from ), [ $id ] ) );
		}
		$orders = [];
		foreach ( $groups as $group ) {
			foreach ( $group as $index => $term_id ) {
				$stored = get_term_meta( $term_id, CategoryTree::TREE_ORDER_META, true );
				$stored = is_numeric( $stored ) ? (int) $stored : null;
				if ( $stored !== $index ) {
					update_term_meta( $term_id, CategoryTree::TREE_ORDER_META, $index );
				}
				$orders[ $term_id ] = [ $stored, $index ];
			}
		}
		return $orders;
	}

	/**
	 * Journal the write, finish the batch and answer the category.
	 *
	 * @param int                                    $batch_id The running batch.
	 * @param int                                    $id       The category written.
	 * @param array<string, mixed>                   $before   Journal property => value before, for each property the write named.
	 * @param array<int, array{0: int|null, 1: int}> $orders   Tree orders from place().
	 * @param \WP_Error|null                         $failure  WordPress's refusal of the write, when it refused.
	 * @return array{item: array<string, mixed>, batch_id: int, status: string, results: list<array<string, mixed>>}|\WP_Error
	 */
	private function complete( int $batch_id, int $id, array $before, array $orders, ?\WP_Error $failure = null ): array|\WP_Error {
		$journal = Journal::from_settings( Fields::instance(), $this->settings(), $this->changes );
		$term    = get_term( $id, Counts::TAXONOMY );
		$after   = is_object( $term ) ? self::values( $term ) : [];
		$entries = [];
		foreach ( $before as $prop => $old ) {
			$key       = (string) array_search( $prop, self::PROPS, true );
			$entries[] = match ( true ) {
				null !== $failure                     => Result::failed( $prop, $old, self::not_saved( $failure )->get_error_message(), self::NOT_SAVED ),
				( $after[ $key ] ?? null ) === $old => Result::already( $prop, $old ),
				default                               => Result::changed( $prop, $old, $after[ $key ] ?? null ),
			};
		}
		if ( isset( $orders[ $id ] ) ) {
			$entries[] = self::order_result( $orders[ $id ] );
		}

		try {
			$applied  = $journal->record( $batch_id, $id, self::OBJECT_TYPE, ChangeRepository::UNKNOWN_TIME, self::with_skip( $entries ), $before, is_object( $term ) ? $term : null );
			$siblings = array_diff_key( $orders, [ $id => true ] );
			foreach ( $siblings as $sibling => $order ) {
				$applied += $journal->record( $batch_id, $sibling, self::OBJECT_TYPE, ChangeRepository::UNKNOWN_TIME, self::with_skip( [ self::order_result( $order ) ] ), [ self::ORDER_PROP => $order[0] ], (object) [ 'term_id' => $sibling ] );
			}
		} catch ( \Throwable $e ) {
			$reason = __( 'Something went wrong while changing this category, so it may not have been finished. Reload the categories to check it.', 'bulk-catalogue-manager-for-woocommerce' );
			$this->finish( $batch_id, 'failed', $reason, false );
			Cache::invalidate();
			return Refusal::unexpected( ProductWriter::SAVE_FAILED, $reason, 500, $e, [ 'batch_id' => $batch_id ] );
		}

		if ( null !== $failure ) {
			$refusal = self::not_saved( $failure, [ 'batch_id' => $batch_id ] );
			$this->finish( $batch_id, 'failed', $refusal->get_error_message(), false );
			return $refusal;
		}

		$status = $this->finish( $batch_id, 'done', '', $applied > 0 );
		Cache::invalidate();
		return [
			'item'     => self::item( $id ),
			'batch_id' => $batch_id,
			'status'   => $status,
			'results'  => array_map( static fn( Result $result ): array => $result->to_array(), $entries ),
		];
	}

	/**
	 * Claim the batch's one item, mark it and the counters as the chunk runner
	 * does, and move the batch from running to its final status.
	 *
	 * @param int    $batch_id The running batch.
	 * @param string $state    The item's state: done or failed.
	 * @param string $message  Its plain message.
	 * @param bool   $applied  Whether any journal row was applied.
	 * @return string The final status.
	 */
	private function finish( int $batch_id, string $state, string $message, bool $applied ): string {
		$now     = time();
		$claimed = $this->batches->claim_chunk( $batch_id, 1, bin2hex( random_bytes( 16 ) ), gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now - BatchRepository::LEASE_SECONDS ) );
		$item_id = (int) ( $claimed[0]['item_id'] ?? 0 );
		if ( $item_id > 0 ) {
			$this->batches->mark_items( $batch_id, [ $item_id ], $state, $message );
		}
		$failed = 'failed' === $state ? 1 : 0;
		$this->batches->increment_counters( $batch_id, 1, $applied ? 1 : 0, $failed );
		$status = ChunkRunner::final_status( 'done' !== $state || '' !== $message ? 1 : 0, $failed, $applied ? 1 : 0 );
		$this->batches->transition( $batch_id, 'running', $status );
		return $status;
	}

	/**
	 * The category as the answer carries it: its tree node (id, name, slug,
	 * parent, depth, order) read afresh, plus its description.
	 *
	 * @param int $id Term id.
	 * @return array<string, mixed>
	 */
	private static function item( int $id ): array {
		$terms = CategoryTree::terms();
		$term  = self::find( $terms, $id );
		return ( CategoryTree::tree_of( $terms )->node( $id ) ?? [ 'id' => $id ] ) + [
			'description' => null === $term ? '' : (string) ( $term->description ?? '' ),
		];
	}

	/**
	 * The refusal of a parent that does not exist, or that is the category
	 * itself or one of its descendants; null when the parent is fine.
	 *
	 * @param Tree $tree   The tree.
	 * @param int  $id     The category, 0 for a new one.
	 * @param int  $parent_id The parent asked for, 0 for the top level.
	 */
	private static function parent_refusal( Tree $tree, int $id, int $parent_id ): ?\WP_Error {
		if ( 0 !== $parent_id && null === $tree->node( $parent_id ) ) {
			return Refusal::invalid(
				self::PARENT_MISSING,
				__( 'The chosen parent category could not be found. It may have been deleted; reload the categories and choose another.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'parent' => $parent_id ]
			);
		}
		if ( 0 !== $id && in_array( $id, $tree->lineage( $parent_id ), true ) ) {
			return Refusal::invalid(
				self::CYCLE,
				__( 'A category cannot be moved inside itself.', 'bulk-catalogue-manager-for-woocommerce' ),
				[
					'id'     => $id,
					'parent' => $parent_id,
				]
			);
		}
		return null;
	}

	/**
	 * The refusal of a name another category under the same parent has,
	 * ignoring case and surrounding space; null when it is free.
	 *
	 * @param Tree   $tree   The tree.
	 * @param int    $id     The category, 0 for a new one.
	 * @param int    $parent_id The parent it will be under.
	 * @param string $name   The name it will have.
	 */
	private static function name_refusal( Tree $tree, int $id, int $parent_id, string $name ): ?\WP_Error {
		foreach ( $tree->children( $parent_id ) as $sibling ) {
			if ( $sibling === $id || self::fold( (string) $tree->node( $sibling )['name'] ) !== self::fold( $name ) ) {
				continue;
			}
			$shown   = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
			$message = 0 === $parent_id
				? sprintf(
					/* translators: %s: category name, such as "Shirts". */
					__( 'A category called "%s" already exists at the top level. Choose a different name, or put it somewhere else.', 'bulk-catalogue-manager-for-woocommerce' ),
					$shown
				)
				: sprintf(
					/* translators: 1: category name, such as "Shirts"; 2: the parent category's name, such as "Clothing". */
					__( 'A category called "%1$s" already exists in "%2$s". Choose a different name, or put it somewhere else.', 'bulk-catalogue-manager-for-woocommerce' ),
					$shown,
					(string) $tree->node( $parent_id )['name']
				);
			return Refusal::invalid(
				self::NAME_TAKEN,
				$message,
				[ 'existing' => $sibling ]
			);
		}
		return null;
	}

	/**
	 * The refusal of a slug another category already has; null when it is free or none was given.
	 *
	 * @param Tree        $tree The tree.
	 * @param int         $id   The category, 0 for a new one.
	 * @param string|null $slug The slug asked for, sanitised.
	 */
	private static function slug_refusal( Tree $tree, int $id, ?string $slug ): ?\WP_Error {
		if ( null === $slug ) {
			return null;
		}
		foreach ( self::ids( $tree ) as $other ) {
			if ( $other !== $id && $tree->node( $other )['slug'] === $slug ) {
				return Refusal::invalid(
					self::SLUG_TAKEN,
					sprintf(
						/* translators: 1: slug, such as "shirts"; 2: the category using it, such as "Shirts". */
						__( 'The slug "%1$s" is already used by the category "%2$s". Choose a different slug.', 'bulk-catalogue-manager-for-woocommerce' ),
						$slug,
						(string) $tree->node( $other )['name']
					),
					[ 'existing' => $other ]
				);
			}
		}
		return null;
	}

	/**
	 * Every id in a tree, top down.
	 *
	 * @param Tree $tree The tree.
	 * @return list<int>
	 */
	private static function ids( Tree $tree ): array {
		$ids   = [];
		$stack = $tree->children( 0 );
		while ( [] !== $stack ) {
			$id    = (int) array_shift( $stack );
			$ids[] = $id;
			$stack = array_merge( $tree->children( $id ), $stack );
		}
		return $ids;
	}

	/**
	 * A submitted slug sanitised as WordPress stores one; null when none was
	 * given, or the refusal of one with no letter or number.
	 *
	 * @param mixed $slug The submitted slug.
	 */
	private static function slug( mixed $slug ): string|\WP_Error|null {
		if ( null === $slug || '' === trim( (string) $slug ) ) {
			return null;
		}
		$clean = sanitize_title( (string) $slug );
		if ( '' === $clean ) {
			return Refusal::invalid(
				self::SLUG_INVALID,
				__( 'A slug needs at least one letter or number. Enter a slug like summer-sale.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		return $clean;
	}

	/**
	 * A name: trimmed, and through the post HTML filter for a user without
	 * unfiltered_html (SPEC §13).
	 *
	 * @param mixed $text    Submitted text.
	 * @param int   $user_id The user the batch is recorded for.
	 */
	private static function text( mixed $text, int $user_id ): string {
		return trim( self::html( is_scalar( $text ) ? (string) $text : '', $user_id ) );
	}

	/**
	 * Text through the post HTML filter unless the user the batch is
	 * recorded for has unfiltered_html.
	 *
	 * @param string $text    Submitted text.
	 * @param int    $user_id The user the batch is recorded for.
	 */
	private static function html( string $text, int $user_id ): string {
		return $user_id > 0 && user_can( $user_id, 'unfiltered_html' ) ? $text : wp_kses_post( $text );
	}

	/**
	 * A name folded for comparison: entities decoded, trimmed, lower case.
	 *
	 * @param string $name Name.
	 */
	private static function fold( string $name ): string {
		return mb_strtolower( trim( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * One term from a list, or null.
	 *
	 * @param list<object> $terms Terms.
	 * @param int          $id    Term id.
	 */
	private static function find( array $terms, int $id ): ?object {
		foreach ( $terms as $term ) {
			if ( (int) ( $term->term_id ?? 0 ) === $id ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * A term's journalled values, as stored.
	 *
	 * @param object $term The term.
	 * @return array{name: string, slug: string, description: string, parent: int}
	 */
	private static function values( object $term ): array {
		return [
			'name'        => (string) ( $term->name ?? '' ),
			'slug'        => (string) ( $term->slug ?? '' ),
			'description' => (string) ( $term->description ?? '' ),
			'parent'      => (int) ( $term->parent ?? 0 ),
		];
	}

	/**
	 * The Result of a tree order change.
	 *
	 * @param array{0: int|null, 1: int} $order Before and after.
	 */
	private static function order_result( array $order ): Result {
		return $order[0] === $order[1] ? Result::already( self::ORDER_PROP, $order[0] ) : Result::changed( self::ORDER_PROP, $order[0], $order[1] );
	}

	/**
	 * Results as the journal takes them, each with the default skip switch:
	 * an unchanged property writes no row.
	 *
	 * @param list<Result> $results Results.
	 * @return list<array{0: Result, 1: bool}>
	 */
	private static function with_skip( array $results ): array {
		return array_map( static fn( Result $result ): array => [ $result, true ], $results );
	}

	/**
	 * A step as the batch row stores it.
	 *
	 * @param string               $action CREATE, EDIT or MOVE.
	 * @param array<string, mixed> $args   Its arguments.
	 * @return array{action: string, args: array<string, mixed>}
	 */
	private static function step( string $action, array $args ): array {
		return [
			'action' => $action,
			'args'   => $args,
		];
	}

	/**
	 * The settings, loaded once.
	 */
	private function settings(): Settings {
		$this->settings ??= Settings::load();
		return $this->settings;
	}

	/**
	 * The refusal of an empty name.
	 */
	private static function name_required(): \WP_Error {
		return Refusal::invalid(
			self::NAME_REQUIRED,
			__( 'A category needs a name. Enter a name and try again.', 'bulk-catalogue-manager-for-woocommerce' )
		);
	}

	/**
	 * The refusal of an id that is not a product category.
	 */
	private static function not_found(): \WP_Error {
		return Refusal::create(
			self::NOT_FOUND,
			__( 'That category could not be found. It may have been deleted; reload the categories and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			404
		);
	}

	/**
	 * The refusal of a write WordPress would not make, its own reason in the details.
	 *
	 * @param \WP_Error            $error WordPress's error.
	 * @param array<string, mixed> $data  Extra data.
	 */
	private static function not_saved( \WP_Error $error, array $data = [] ): \WP_Error {
		return Refusal::invalid(
			self::NOT_SAVED,
			__( 'The category could not be saved, so nothing was changed. Check the name and slug, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			[ 'details' => $error->get_error_code() . ': ' . $error->get_error_message() ] + $data
		);
	}
}
