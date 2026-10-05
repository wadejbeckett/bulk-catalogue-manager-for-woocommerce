<?php
/**
 * Deleting and merging product categories (ticket 24; SPEC §4.7, §7):
 * WooCommerce's native consequences, a confirmation that states them, and
 * a journalled batch History shows.
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
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes or merges chosen categories.
 *
 * **Delete** removes each chosen category with WordPress's own
 * `wp_delete_term()`, so the consequences are WooCommerce's: its
 * subcategories move up to its parent, it is taken off every product, and a
 * product left with no category is given the shop's default category.
 * WooCommerce's own screens do that last part afterwards, in a scheduled
 * action (AssignDefaultCategory); here it is done in the same call through
 * `wp_delete_term()`'s own `default` argument, so the batch journals it and
 * the confirmation's count is what happened. The default
 * category itself is refused, as WooCommerce refuses it, and so is a
 * category the user may not delete (`delete_term`).
 *
 * **Merge into…** first moves every product of the chosen categories into
 * the target, through WooCommerce's CRUD as a bulk change does (add the
 * target, remove the chosen ones; Engine\Writer), then deletes the chosen
 * categories as above. A merge of more than MERGE_LIMIT products is refused;
 * a bulk change moves that many in the background.
 *
 * preview() states the consequences and a token binding them; apply() needs
 * that token and refuses 409 `bulkcat_preview_stale` when anything it
 * counted has changed since, so nothing outside what was confirmed is
 * touched. Every run is one `tree` batch (step `delete_categories` or
 * `merge_categories`, with the names, for History's headline) whose items
 * are the chosen categories, journalling: `term_deleted` on each deleted
 * category (its path then, as text, since it can no longer be looked up),
 * `term_parent` on each subcategory moved up, and `category_ids` on each
 * product whose categories changed, before and after as read from
 * WordPress.
 *
 * A deleted category cannot be brought back exactly: recreating it gives a
 * new id, which menus, links, other plugins' settings and earlier History
 * rows would not follow. So these batches are never offered for undo
 * (Engine\Reversibility reason `permanent`), and the confirmation says so.
 */
final class Deleter {
	/**
	 * The step a delete batch records.
	 */
	public const DELETE = 'delete_categories';

	/**
	 * The step a merge batch records.
	 */
	public const MERGE = 'merge_categories';

	/**
	 * Steps whose batch cannot be undone.
	 */
	public const PERMANENT = [ self::DELETE, self::MERGE ];

	/**
	 * Journal property of a deleted category: its path as text, then ''.
	 */
	public const DELETED_PROP = 'term_deleted';

	/**
	 * The journal property of a product's categories (the field registry's key).
	 */
	public const PRODUCT_PROP = 'category_ids';

	/**
	 * Most categories one request may delete or merge.
	 */
	public const MAX_IDS = 100;

	/**
	 * Most products one merge may move.
	 */
	public const MERGE_LIMIT = 500;

	/**
	 * Refusal code for a list with no category.
	 */
	public const NONE_CHOSEN = 'bulkcat_no_categories_chosen';

	/**
	 * Refusal code for the default category.
	 */
	public const IS_DEFAULT = 'bulkcat_default_category';

	/**
	 * Refusal code for a merge target that is missing or one of those merged.
	 */
	public const BAD_TARGET = 'bulkcat_merge_target_invalid';

	/**
	 * Refusal code for a merge of too many products.
	 */
	public const TOO_MANY = 'bulkcat_merge_too_large';

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
	 * Moves one product into the target: fn( int $product_id, list<int> $remove, int $add, int $batch_id, int $user_id ): void.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $mover;

	/**
	 * Build over the plugin's repositories by default.
	 *
	 * @param BatchRepository|null  $batches Batches and items.
	 * @param ChangeRepository|null $changes The journal table.
	 * @param \Closure|null         $mover   Moves one product (tests); WooCommerce CRUD through Engine\Writer by default.
	 */
	public function __construct( ?BatchRepository $batches = null, ?ChangeRepository $changes = null, ?\Closure $mover = null ) {
		$this->batches = $batches ?? new BatchRepository();
		$this->changes = $changes ?? new ChangeRepository();
		$this->mover   = $mover;
	}

	/**
	 * What deleting (or, with a target, merging) the chosen categories would
	 * do, or why it is refused.
	 *
	 * @param list<int> $ids     Category ids.
	 * @param int       $into    Merge target, or 0 to delete.
	 * @param int       $user_id The user asking.
	 * @return array<string, mixed>|\WP_Error `{ categories, subcategories, products, fallback, into, default, token }`.
	 */
	public function preview( array $ids, int $into, int $user_id ): array|\WP_Error {
		$plan = $this->plan( $ids, $into, $user_id );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}
		$tree = $plan['tree'];
		return [
			'categories'    => array_map(
				static fn( int $id ): array => [
					'id'   => $id,
					'name' => (string) $tree->node( $id )['name'],
					'path' => self::path( $tree, $id ),
				],
				$plan['ids']
			),
			'subcategories' => count( $plan['moved_up'] ),
			'products'      => count( $plan['products'] ),
			'fallback'      => $into > 0 ? 0 : count( $plan['fallback'] ),
			'into'          => $into > 0 ? [
				'id'   => $into,
				'path' => self::path( $tree, $into ),
			] : null,
			'default'       => $plan['default'] > 0 && null !== $tree->node( $plan['default'] ) ? [
				'id'   => $plan['default'],
				'path' => self::path( $tree, $plan['default'] ),
			] : null,
			'token'         => $plan['token'],
		];
	}

	/**
	 * Delete (or merge into a target) the chosen categories, as previewed.
	 *
	 * @param list<int> $ids     Category ids.
	 * @param int       $into    Merge target, or 0 to delete.
	 * @param string    $token   The preview's token.
	 * @param int       $user_id The user the batch is recorded for.
	 * @return array{batch_id: int, status: string, deleted: list<int>, failed: list<array{id: int, reason: string}>}|\WP_Error
	 */
	public function apply( array $ids, int $into, string $token, int $user_id ): array|\WP_Error {
		$plan = $this->plan( $ids, $into, $user_id );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}
		if ( ! hash_equals( $plan['token'], $token ) ) {
			return Refusal::create(
				BatchFactory::STALE_PREVIEW,
				__( 'The categories or their products changed after you checked them. Check again, then confirm.', 'bulk-catalogue-manager-for-woocommerce' ),
				409
			);
		}
		$tree  = $plan['tree'];
		$names = [];
		foreach ( $plan['ids'] as $id ) {
			$names[ $id ] = self::path( $tree, $id );
		}
		$step     = $into > 0
			? [
				'action' => self::MERGE,
				'args'   => [
					'ids'   => $plan['ids'],
					'names' => $names,
					'into'  => $into,
					'path'  => self::path( $tree, $into ),
				],
			]
			: [
				'action' => self::DELETE,
				'args'   => [
					'ids'   => $plan['ids'],
					'names' => $names,
				],
			];
		$objects  = array_map( static fn( int $id ): array => [ $id, Writer::OBJECT_TYPE ], $plan['ids'] );
		$batch_id = ( new BatchFactory( null, $this->batches ) )->open( $user_id, [ $step ], $objects, Writer::SOURCE );
		if ( $batch_id instanceof \WP_Error ) {
			return $batch_id;
		}

		if ( $into > 0 ) {
			foreach ( array_keys( $plan['products'] ) as $product_id ) {
				$this->move( $product_id, $plan['ids'], $into, $batch_id, $user_id );
			}
		}
		$before   = self::categories_of( array_keys( $plan['products'] ) );
		$parents  = self::parents( $tree );
		$deleted  = [];
		$failed   = [];
		$recorded = 0;
		foreach ( $plan['ids'] as $id ) {
			// WordPress's own default argument: a product whose only category this was gets the shop's
			// default category now, as WooCommerce's scheduled AssignDefaultCategory would later, so the
			// journal records it (wp_delete_term() otherwise just removes the relationship).
			$done = wp_delete_term( $id, Counts::TAXONOMY, $plan['default'] > 0 && null !== $tree->node( $plan['default'] ) ? [ 'default' => $plan['default'] ] : [] );
			if ( true === $done ) {
				$deleted[] = $id;
				$this->changes->append( $batch_id, $id, Writer::OBJECT_TYPE, self::DELETED_PROP, $names[ $id ], '', ChangeRepository::UNKNOWN_TIME, 'applied' );
				++$recorded;
				continue;
			}
			$reason   = __( 'WordPress did not delete this category, so it was left as it was.', 'bulk-catalogue-manager-for-woocommerce' );
			$failed[] = [
				'id'     => $id,
				'reason' => $reason,
			];
			$this->changes->append( $batch_id, $id, Writer::OBJECT_TYPE, self::DELETED_PROP, $names[ $id ], $names[ $id ], ChangeRepository::UNKNOWN_TIME, 'failed', $reason );
		}
		$after = CategoryTree::complete();
		foreach ( $parents as $id => $parent ) {
			$now = $after->node( $id );
			if ( null !== $now && $now['parent'] !== $parent ) {
				$this->changes->append( $batch_id, $id, Writer::OBJECT_TYPE, Writer::PROPS['parent'], $parent, $now['parent'], ChangeRepository::UNKNOWN_TIME, 'applied' );
			}
		}
		foreach ( self::categories_of( array_keys( $before ) ) as $product_id => $now ) {
			if ( self::sorted( $now ) !== self::sorted( $before[ $product_id ] ) ) {
				$this->changes->append( $batch_id, $product_id, 'product', self::PRODUCT_PROP, $before[ $product_id ], $now, ChangeRepository::UNKNOWN_TIME, 'applied' );
			}
		}
		Cache::invalidate();
		$status = $this->finish( $batch_id, $deleted, $failed );
		return [
			'batch_id' => $batch_id,
			'status'   => $status,
			'deleted'  => $deleted,
			'failed'   => $failed,
		];
	}

	/**
	 * Everything apply() and preview() need, checked, or the refusal.
	 *
	 * @param list<int> $ids     Category ids.
	 * @param int       $into    Merge target, or 0.
	 * @param int       $user_id The user asking.
	 * @return array{tree: Tree, ids: list<int>, moved_up: list<int>, products: array<int, list<int>>, fallback: list<int>, default: int, token: string}|\WP_Error
	 */
	private function plan( array $ids, int $into, int $user_id ): array|\WP_Error {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( [] === $ids || count( $ids ) > self::MAX_IDS ) {
			return Refusal::invalid(
				self::NONE_CHOSEN,
				sprintf(
					/* translators: %d: the most categories one change may take. */
					__( 'Choose between 1 and %d categories, then try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					self::MAX_IDS
				)
			);
		}
		// Every category, whatever this viewer's term queries show (1.3.4).
		$tree    = CategoryTree::complete();
		$default = CategoryTree::default_id();
		foreach ( $ids as $id ) {
			if ( null === $tree->node( $id ) ) {
				return Refusal::create(
					ProductWriter::NOT_FOUND,
					__( 'One of these categories could not be found. It may have been deleted already; reload the categories and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					404,
					[ 'id' => $id ]
				);
			}
			if ( $id === $default ) {
				return Refusal::invalid(
					self::IS_DEFAULT,
					sprintf(
						/* translators: %s: a category name, such as "Uncategorized". */
						__( '"%s" is the shop\'s default category, so it cannot be deleted. Make another category the default first.', 'bulk-catalogue-manager-for-woocommerce' ),
						(string) $tree->node( $id )['name']
					),
					[ 'id' => $id ]
				);
			}
			if ( ! user_can( $user_id, 'delete_term', $id ) ) {
				return Refusal::create(
					Writer::CANNOT_EDIT,
					sprintf(
						/* translators: %s: a category name. */
						__( 'You do not have permission to delete "%s". Ask an administrator for access.', 'bulk-catalogue-manager-for-woocommerce' ),
						(string) $tree->node( $id )['name']
					),
					403,
					[ 'id' => $id ]
				);
			}
		}
		if ( 0 !== $into ) {
			if ( null === $tree->node( $into ) || in_array( $into, $ids, true ) ) {
				return Refusal::invalid(
					self::BAD_TARGET,
					__( 'Choose a category to merge into that is not one of those being merged.', 'bulk-catalogue-manager-for-woocommerce' )
				);
			}
			if ( ! user_can( $user_id, 'assign_term', $into ) ) {
				return Refusal::create(
					Writer::CANNOT_EDIT,
					__( 'You do not have permission to put products in that category. Ask an administrator for access.', 'bulk-catalogue-manager-for-woocommerce' ),
					403
				);
			}
		}

		$chosen   = array_fill_keys( $ids, true );
		$moved_up = [];
		foreach ( self::parents( $tree ) as $id => $parent ) {
			if ( ! isset( $chosen[ $id ] ) && isset( $chosen[ $parent ] ) ) {
				$moved_up[] = $id;
			}
		}
		$products = self::categories_of( self::products_in( $ids ) );
		if ( $into > 0 && count( $products ) > self::MERGE_LIMIT ) {
			return Refusal::invalid(
				self::TOO_MANY,
				sprintf(
					/* translators: 1: how many products, such as "1,204"; 2: the most one merge moves, such as "500". */
					__( 'These categories hold %1$s products, more than the %2$s one merge moves. Move the products with a bulk change first, then delete the categories.', 'bulk-catalogue-manager-for-woocommerce' ),
					number_format_i18n( count( $products ) ),
					number_format_i18n( self::MERGE_LIMIT )
				)
			);
		}
		$fallback = [];
		foreach ( $products as $product_id => $terms ) {
			if ( [] === array_diff( $terms, $ids ) ) {
				$fallback[] = $product_id;
			}
		}
		$bound = [
			'ids'      => $ids,
			'into'     => $into,
			'default'  => $default,
			'parents'  => self::parents( $tree ),
			'products' => array_map( [ self::class, 'sorted' ], $products ),
		];
		ksort( $bound['parents'] );
		ksort( $bound['products'] );
		return [
			'tree'     => $tree,
			'ids'      => $ids,
			'moved_up' => $moved_up,
			'products' => $products,
			'fallback' => $fallback,
			'default'  => $default,
			'token'    => hash_hmac( 'sha256', 'bulk-catalogue-manager-for-woocommerce/categories-delete/1/' . wp_json_encode( $bound ), wp_salt( 'auth' ) ),
		];
	}

	/**
	 * Move one product out of the chosen categories into the target, through
	 * WooCommerce's CRUD as a bulk change does, journalled in the batch.
	 *
	 * @param int       $product_id Product.
	 * @param list<int> $remove     The chosen categories.
	 * @param int       $add        The target.
	 * @param int       $batch_id   The batch.
	 * @param int       $user_id    The user the batch is recorded for.
	 */
	private function move( int $product_id, array $remove, int $add, int $batch_id, int $user_id ): void {
		if ( null !== $this->mover ) {
			( $this->mover )( $product_id, $remove, $add, $batch_id, $user_id );
			return;
		}
		$actions    = Actions::instance();
		$operations = $actions->validate_list(
			[
				[
					'action' => 'add_terms',
					'args'   => [
						'field' => self::PRODUCT_PROP,
						'terms' => [ $add ],
					],
				],
				[
					'action' => 'remove_terms',
					'args'   => [
						'field' => self::PRODUCT_PROP,
						'terms' => $remove,
					],
				],
			]
		);
		if ( $operations instanceof \WP_Error ) {
			return;
		}
		$writer = ProductWriter::for_batch( $actions, Journal::from_settings( $actions->fields(), Settings::load(), $this->changes ), $batch_id, $user_id );
		$writer->run( static fn( ProductWriter $writer ) => $writer->write( $product_id, $operations, 'product' ) );
	}

	/**
	 * Mark the batch's items (the chosen categories) and move it from running
	 * to its final status.
	 *
	 * @param int                                       $batch_id The running batch.
	 * @param list<int>                                 $deleted  Categories deleted.
	 * @param list<array{id: int, reason: string}>      $failed   Categories WordPress would not delete.
	 * @return string The final status.
	 */
	private function finish( int $batch_id, array $deleted, array $failed ): string {
		$total   = count( $deleted ) + count( $failed );
		$now     = time();
		$claimed = $this->batches->claim_chunk( $batch_id, $total, bin2hex( random_bytes( 16 ) ), gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now - BatchRepository::LEASE_SECONDS ) );
		$reasons = array_column( $failed, 'reason', 'id' );
		$done    = [];
		$bad     = [];
		foreach ( $claimed as $item ) {
			if ( isset( $reasons[ (int) $item['object_id'] ] ) ) {
				$bad[] = (int) $item['item_id'];
			} else {
				$done[] = (int) $item['item_id'];
			}
		}
		$this->batches->mark_items( $batch_id, $done, 'done', '' );
		if ( [] !== $bad ) {
			$this->batches->mark_items( $batch_id, $bad, 'failed', (string) reset( $reasons ) );
		}
		$this->batches->increment_counters( $batch_id, $total, count( $deleted ), count( $failed ) );
		$status = ChunkRunner::final_status( count( $failed ), count( $failed ), count( $deleted ) );
		$this->batches->transition( $batch_id, 'running', $status );
		return $status;
	}

	/**
	 * Every category's parent in a tree.
	 *
	 * @param Tree $tree The tree.
	 * @return array<int, int> Id => parent id.
	 */
	private static function parents( Tree $tree ): array {
		$parents = [];
		$stack   = $tree->children( 0 );
		while ( [] !== $stack ) {
			$id             = (int) array_shift( $stack );
			$parents[ $id ] = (int) $tree->node( $id )['parent'];
			$stack          = array_merge( $stack, $tree->children( $id ) );
		}
		return $parents;
	}

	/**
	 * A category's path as History and the confirmation show it: "Clothing › Shirts".
	 *
	 * @param Tree $tree The tree.
	 * @param int  $id   Term id.
	 */
	public static function path( Tree $tree, int $id ): string {
		$names = array_map( static fn( int $each ): string => (string) $tree->node( $each )['name'], array_reverse( $tree->lineage( $id ) ) );
		return implode( ' › ', $names );
	}

	/**
	 * The products (any status) carrying any of some categories.
	 *
	 * @param list<int> $ids Category ids.
	 * @return list<int>
	 */
	private static function products_in( array $ids ): array {
		$objects = get_objects_in_term( $ids, Counts::TAXONOMY );
		if ( ! is_array( $objects ) ) {
			return [];
		}
		$objects = array_values( array_unique( array_map( 'intval', $objects ) ) );
		sort( $objects );
		return $objects;
	}

	/**
	 * Each product's categories as WordPress holds them now.
	 *
	 * @param list<int> $product_ids Products.
	 * @return array<int, list<int>> Product id => category ids.
	 */
	private static function categories_of( array $product_ids ): array {
		$out = array_fill_keys( $product_ids, [] );
		foreach ( array_chunk( $product_ids, 500 ) as $chunk ) {
			$terms = wp_get_object_terms( $chunk, Counts::TAXONOMY, [ 'fields' => 'all_with_object_id' ] );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$object = (int) ( $term->object_id ?? 0 );
				if ( isset( $out[ $object ] ) ) {
					$out[ $object ][] = (int) $term->term_id;
				}
			}
		}
		return $out;
	}

	/**
	 * Ids sorted, for comparison.
	 *
	 * @param list<int> $ids Ids.
	 * @return list<int>
	 */
	public static function sorted( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return $ids;
	}
}
