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
 * and `wp_update_term()` (never SQL), with the sibling order in WooCommerce's
 * own `order` term meta (CategoryTree::ORDER_META), as its drag-reorder
 * writes it (wc_set_term_order()) and its shop sorts by, so a move here
 * changes the shop's order (ticket 23). Before anything is written it refuses, with a §4.8 sentence and a
 * `bulkcat_` code: an empty name, a name a sibling under the same parent already
 * has, a slug another category already has, a parent that does not exist,
 * and a parent that is the category itself or one of its descendants ("A
 * category cannot be moved inside itself.").
 *
 * Each write is a batch of one with source `tree` and one `term` item,
 * opened running through Engine\BatchFactory::open() and never queued, and
 * journalled through Engine\Journal::record() with object type `term`: one
 * row per changed property, `term_name`, `term_slug`, `term_description`,
 * `term_parent` and `term_menu_order`, each holding the old and new value, so the
 * History screen lists it and undo can put it back. The prefix keeps these
 * apart from the field registry's product fields of the same name. A move
 * writes WooCommerce's order only where it must (place()), and each sibling
 * whose order changed gets its own `term_menu_order` row in the same batch;
 * `term_order` rows of 1.3.2 and earlier recorded the retired tree-only
 * order. A create is written first (it has no id before), and removed again
 * if its batch cannot be recorded, so no category is ever created without
 * its journal. Choosing the shop's default category writes WooCommerce's
 * `default_product_cat` option and journals `term_default` on the new
 * default (old and new default ids).
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
	 * Journal property per native category detail kept in term meta, as
	 * WooCommerce's own category screen stores them (ticket 22): display type
	 * ('' Default, products, subcategories, both) and the image attachment id
	 * (0 when removed).
	 */
	public const META = [
		'display_type' => 'term_display_type',
		'thumbnail_id' => 'term_thumbnail_id',
	];

	/**
	 * Display types WooCommerce stores; '' is Default.
	 */
	public const DISPLAY_TYPES = [ '', 'products', 'subcategories', 'both' ];

	/**
	 * Refusal code for a display type WooCommerce does not offer.
	 */
	public const INVALID_DETAIL = 'bulkcat_category_detail_invalid';

	/**
	 * Refusal code for an image that is not a usable image attachment.
	 */
	public const INVALID_IMAGE = 'bulkcat_category_image_invalid';

	/**
	 * Journal property of WooCommerce's sibling order (`order` term meta).
	 */
	public const ORDER_PROP = 'term_menu_order';

	/**
	 * Journal property of the retired tree-only order (`bulkcat_order`),
	 * found in batches of 1.3.2 and earlier; never written now.
	 */
	public const LEGACY_ORDER_PROP = 'term_order';

	/**
	 * Journal property of the shop's default category: old and new default ids.
	 */
	public const DEFAULT_PROP = 'term_default';

	/**
	 * The step a default-category batch records.
	 */
	public const MAKE_DEFAULT = 'make_default_category';

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
	 * Refusal code for a category the user may not change.
	 */
	public const CANNOT_EDIT = 'bulkcat_cannot_edit';

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
	 * The plain label History shows for a category property, or null when the
	 * property is not a category's.
	 *
	 * @param string $prop Journal property.
	 */
	public static function label( string $prop ): ?string {
		return match ( $prop ) {
			'term_name'             => __( 'Name', 'bulk-catalogue-manager-for-woocommerce' ),
			'term_slug'             => __( 'Slug', 'bulk-catalogue-manager-for-woocommerce' ),
			'term_description'      => __( 'Description', 'bulk-catalogue-manager-for-woocommerce' ),
			'term_parent'           => __( 'Parent category', 'bulk-catalogue-manager-for-woocommerce' ),
			'term_display_type'     => __( 'Display type', 'bulk-catalogue-manager-for-woocommerce' ),
			'term_thumbnail_id'     => __( 'Image', 'bulk-catalogue-manager-for-woocommerce' ),
			self::ORDER_PROP        => __( 'Position', 'bulk-catalogue-manager-for-woocommerce' ),
			self::LEGACY_ORDER_PROP => __( 'Old position in this screen only', 'bulk-catalogue-manager-for-woocommerce' ),
			self::DEFAULT_PROP      => __( 'Default category', 'bulk-catalogue-manager-for-woocommerce' ),
			TermUndo::CREATED       => __( 'New category', 'bulk-catalogue-manager-for-woocommerce' ),
			Deleter::DELETED_PROP   => __( 'Deleted category', 'bulk-catalogue-manager-for-woocommerce' ),
			default                 => null,
		};
	}

	/**
	 * One category's details as the write routes answer them (the tree's node
	 * plus description, display type and image), or not found.
	 *
	 * @param int $id Term id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function details_of( int $id ): array|\WP_Error {
		$terms = CategoryTree::terms();
		if ( null === self::find( $terms, $id ) ) {
			return self::not_found();
		}
		return self::item( $id );
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
		$details = self::details( $input, $user_id );
		if ( null === $refused && $details instanceof \WP_Error ) {
			$refused = $details;
		}
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
		$batch_id = $this->factory->open( $user_id, [ self::step( self::CREATE, [ 'name' => $name ] + $args + $details ) ], [ [ $id, self::OBJECT_TYPE ] ], self::SOURCE );
		if ( $batch_id instanceof \WP_Error ) {
			// Never leave a category the journal does not know about.
			wp_delete_term( $id, Counts::TAXONOMY );
			return $batch_id;
		}
		if ( ! is_numeric( get_term_meta( $id, CategoryTree::ORDER_META, true ) ) ) {
			// As WooCommerce's own screen gives a new category (WC_Admin_Taxonomies::create_term()).
			update_term_meta( $id, CategoryTree::ORDER_META, 0 );
		}
		$before = array_fill_keys( array_values( self::PROPS ), null );
		foreach ( $details as $key => $value ) {
			$before[ self::META[ $key ] ] = null;
			update_term_meta( $id, $key, $value );
		}
		return $this->complete( $batch_id, $id, $before, [] );
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
		$details = self::details( $input, $user_id );
		if ( $details instanceof \WP_Error ) {
			return $details;
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

		$batch_id = $this->factory->open( $user_id, [ self::step( self::EDIT, $wanted + $details ) ], [ [ $id, self::OBJECT_TYPE ] ], self::SOURCE );
		if ( $batch_id instanceof \WP_Error ) {
			return $batch_id;
		}
		$before = [];
		foreach ( array_keys( $wanted ) as $key ) {
			$before[ self::PROPS[ $key ] ] = $current[ $key ];
		}
		foreach ( array_keys( $details ) as $key ) {
			$before[ self::META[ $key ] ] = $current[ $key ];
		}
		$updated = [] === $wanted ? true : wp_update_term( $id, Counts::TAXONOMY, wp_slash( $wanted ) );
		if ( is_wp_error( $updated ) ) {
			return $this->complete( $batch_id, $id, $before, [], $updated );
		}
		foreach ( $details as $key => $value ) {
			if ( $value !== $current[ $key ] ) {
				update_term_meta( $id, $key, $value );
			}
		}
		return $this->complete( $batch_id, $id, $before, $regroup ? self::place( $tree, $id, $parent_id, null ) : [] );
	}

	/**
	 * Move a category under a parent (0 for the top level) at a position
	 * among its new siblings, counted from 0; null or past the end places it
	 * last. The order written is WooCommerce's own (place()).
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
	 * Put a category at a position among its siblings under a parent by
	 * writing WooCommerce's own sibling order (`order` term meta), as its
	 * drag-reorder does (wc_set_term_order(), firing
	 * `woocommerce_after_set_term_order` for each value written). WooCommerce
	 * and its shop sort siblings by that value alone (Tree::comparator()), so
	 * only the siblings' relative order matters: when the category fits
	 * between its new neighbours' values it alone is written (its current
	 * value is kept when it already fits); otherwise the siblings are
	 * renumbered from 1 in their new order and only values that change are
	 * written. The siblings it leaves keep their order.
	 *
	 * @param Tree     $tree      The tree before the write.
	 * @param int      $id        Term id.
	 * @param int      $parent_id New parent id.
	 * @param int|null $position  Position, or null for last.
	 * @return array<int, array{0: int|null, 1: int}> Term id => [ stored order before, order now ], for each order written and the category's own.
	 */
	private static function place( Tree $tree, int $id, int $parent_id, ?int $position ): array {
		$joined = array_values( array_diff( $tree->children( $parent_id ), [ $id ] ) );
		$at     = null === $position ? count( $joined ) : max( 0, min( $position, count( $joined ) ) );
		array_splice( $joined, $at, 0, [ $id ] );

		$stored = [];
		foreach ( $joined as $term_id ) {
			$value              = get_term_meta( $term_id, CategoryTree::ORDER_META, true );
			$stored[ $term_id ] = is_numeric( $value ) ? (int) $value : null;
		}
		$wanted = self::fit( $tree, $joined, $stored, $id );
		if ( null === $wanted ) {
			$wanted = [];
			foreach ( $joined as $index => $term_id ) {
				$wanted[ $term_id ] = $index + 1;
			}
		}

		$orders = [];
		foreach ( $joined as $term_id ) {
			$now = $wanted[ $term_id ] ?? $stored[ $term_id ];
			if ( null !== $now && $now !== $stored[ $term_id ] ) {
				update_term_meta( $term_id, CategoryTree::ORDER_META, $now );
				$term = get_term( $term_id, Counts::TAXONOMY );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook, fired as its drag-reorder fires it for each order set.
				do_action( 'woocommerce_after_set_term_order', $term, $now, Counts::TAXONOMY );
				$orders[ $term_id ] = [ $stored[ $term_id ], $now ];
			} elseif ( $term_id === $id ) {
				$orders[ $term_id ] = [ $stored[ $term_id ], (int) $now ];
			}
		}
		return $orders;
	}

	/**
	 * The one order value that puts a category in its place among siblings
	 * keeping theirs (or its own value when it already fits), or null when
	 * none does: checked by sorting the siblings with WooCommerce's rule.
	 *
	 * @param Tree                 $tree   The tree before the write.
	 * @param list<int>            $joined Siblings in their new order.
	 * @param array<int, int|null> $stored Each one's stored order, null for none.
	 * @param int                  $id     The category placed.
	 * @return array<int, int>|null The category's id => its order, or null.
	 */
	private static function fit( Tree $tree, array $joined, array $stored, int $id ): ?array {
		$at         = (int) array_search( $id, $joined, true );
		$before     = $at > 0 ? $stored[ $joined[ $at - 1 ] ] : null;
		$after      = $at < count( $joined ) - 1 ? $stored[ $joined[ $at + 1 ] ] : null;
		$candidates = [ $stored[ $id ] ];
		if ( null !== $before ) {
			$candidates[] = $before + 1;
		} elseif ( null !== $after ) {
			$candidates[] = $after - 1;
		} else {
			$candidates[] = 0;
		}
		foreach ( $candidates as $candidate ) {
			if ( null === $candidate || $candidate < 0 ) {
				continue;
			}
			$nodes  = [];
			$ranked = [];
			foreach ( $joined as $term_id ) {
				$value             = $term_id === $id ? $candidate : $stored[ $term_id ];
				$nodes[ $term_id ] = [
					'name'  => (string) ( $tree->node( $term_id )['name'] ?? '' ),
					'order' => (int) $value,
				];
				if ( null !== $value ) {
					$ranked[ $term_id ] = true;
				}
			}
			$sorted = $joined;
			usort( $sorted, Tree::comparator( $nodes, $ranked ) );
			if ( $sorted === $joined ) {
				return [ $id => $candidate ];
			}
		}
		return null;
	}

	/**
	 * Make a category the shop's default product category, as WooCommerce's
	 * own Make default does: WooCommerce's `default_product_cat` option,
	 * with permission to edit that category. Journalled as `term_default`
	 * on the new default, holding the old and new default ids, so History
	 * names both and undo can put the old one back.
	 *
	 * @param int $id      Term id.
	 * @param int $user_id The user the batch is recorded for.
	 * @return array{item: array<string, mixed>, batch_id: int, status: string, results: list<array<string, mixed>>}|\WP_Error
	 */
	public function make_default( int $id, int $user_id ): array|\WP_Error {
		if ( null === self::find( CategoryTree::terms(), $id ) ) {
			return self::not_found();
		}
		if ( ! user_can( $user_id, 'edit_term', $id ) ) {
			return Refusal::create(
				self::CANNOT_EDIT,
				__( 'You cannot change this category, so it cannot be made the default. Ask an administrator for access to it.', 'bulk-catalogue-manager-for-woocommerce' ),
				403
			);
		}
		$old      = CategoryTree::default_id();
		$batch_id = $this->factory->open( $user_id, [ self::step( self::MAKE_DEFAULT, [ 'id' => $id ] ) ], [ [ $id, self::OBJECT_TYPE ] ], self::SOURCE );
		if ( $batch_id instanceof \WP_Error ) {
			return $batch_id;
		}
		if ( $old !== $id ) {
			update_option( CategoryTree::DEFAULT_OPTION, $id );
		}
		$now     = CategoryTree::default_id();
		$result  = $now === $old ? Result::already( self::DEFAULT_PROP, $old ) : Result::changed( self::DEFAULT_PROP, $old, $now );
		$journal = Journal::from_settings( Fields::instance(), $this->settings(), $this->changes );
		$applied = $journal->record( $batch_id, $id, self::OBJECT_TYPE, ChangeRepository::UNKNOWN_TIME, self::with_skip( [ $result ] ), [ self::DEFAULT_PROP => $old ], (object) [ 'term_id' => $id ] );
		$status  = $this->finish( $batch_id, 'done', '', $applied > 0 );
		Cache::invalidate();
		return [
			'item'     => self::item( $id ),
			'batch_id' => $batch_id,
			'status'   => $status,
			'results'  => [ $result->to_array() ],
		];
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
			$key       = (string) array_search( $prop, self::PROPS + self::META, true );
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
	public static function item( int $id ): array {
		$terms = CategoryTree::terms();
		$term  = self::find( $terms, $id );
		$meta  = null === $term ? [
			'display_type' => '',
			'thumbnail_id' => 0,
		] : self::values( $term );
		$url   = $meta['thumbnail_id'] > 0 ? wp_get_attachment_image_url( $meta['thumbnail_id'], 'thumbnail' ) : false;
		return ( CategoryTree::tree_of( $terms )->node( $id ) ?? [ 'id' => $id ] ) + [
			'description'   => null === $term ? '' : (string) ( $term->description ?? '' ),
			'display_type'  => $meta['display_type'],
			'thumbnail_id'  => $meta['thumbnail_id'],
			'thumbnail_url' => is_string( $url ) ? $url : null,
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
		$id = (int) ( $term->term_id ?? 0 );
		return [
			'name'         => (string) ( $term->name ?? '' ),
			'slug'         => (string) ( $term->slug ?? '' ),
			'description'  => (string) ( $term->description ?? '' ),
			'parent'       => (int) ( $term->parent ?? 0 ),
			'display_type' => $id > 0 ? (string) get_term_meta( $id, 'display_type', true ) : '',
			'thumbnail_id' => $id > 0 ? max( 0, (int) get_term_meta( $id, 'thumbnail_id', true ) ) : 0,
		];
	}

	/**
	 * The native details a request asks for, checked: a display type
	 * WooCommerce offers, and an image the user may choose (an existing image
	 * attachment they can read, with permission to use the media library) or
	 * 0 to remove it. Removing needs no media permission.
	 *
	 * @param array<string, mixed> $input   Request input.
	 * @param int                  $user_id The user the change is for.
	 * @return array<string, string|int>|\WP_Error
	 */
	private static function details( array $input, int $user_id ): array|\WP_Error {
		$details = [];
		if ( array_key_exists( 'display_type', $input ) && null !== $input['display_type'] ) {
			if ( ! is_string( $input['display_type'] ) || ! in_array( $input['display_type'], self::DISPLAY_TYPES, true ) ) {
				return Refusal::invalid( self::INVALID_DETAIL, __( 'Choose a display type from the list: Default, Products, Subcategories, or Both.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$details['display_type'] = $input['display_type'];
		}
		if ( array_key_exists( 'thumbnail_id', $input ) && null !== $input['thumbnail_id'] ) {
			$image = $input['thumbnail_id'];
			if ( ! is_int( $image ) && ! ( is_string( $image ) && ctype_digit( $image ) ) ) {
				return self::invalid_image();
			}
			$image = (int) $image;
			if ( $image < 0 ) {
				return self::invalid_image();
			}
			if ( $image > 0 ) {
				if ( ! user_can( $user_id, 'upload_files' ) || ! user_can( $user_id, 'read_post', $image ) ) {
					return Refusal::create( self::INVALID_IMAGE, __( 'Choosing a category image needs permission to use the media library. Ask an administrator, or leave the image as it is.', 'bulk-catalogue-manager-for-woocommerce' ), 403 );
				}
				$post = get_post( $image );
				if ( null === $post || 'attachment' !== $post->post_type || ! str_starts_with( (string) ( $post->post_mime_type ?? '' ), 'image/' ) ) {
					return self::invalid_image();
				}
			}
			$details['thumbnail_id'] = $image;
		}
		return $details;
	}

	/**
	 * The refusal of an image that cannot be used.
	 */
	private static function invalid_image(): \WP_Error {
		return Refusal::invalid( self::INVALID_IMAGE, __( 'That image could not be found in the media library, or it is not an image. Choose another image.', 'bulk-catalogue-manager-for-woocommerce' ) );
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
