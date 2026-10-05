<?php
/**
 * Undoing category writes (SPEC §7, §9.4; ticket 133 bug 1).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Categories;

use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Undo\Plan;
use BulkCatalogueManager\Undo\Planner;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and writes the undo of one category, exactly as products are
 * undone: Undo\Planner hands each `term` object of a batch to plan(), and
 * Engine\ChunkRunner hands each `term` item of an undo batch to write().
 *
 * For every property the batch applied — name, slug, description, parent,
 * display type, image, WooCommerce's sibling order (`term_menu_order`) and
 * the shop's default category (`term_default`) — the value to expect now is
 * the one the batch left and the value to put back the one before it. A
 * property changed since (its value now is neither) makes the category
 * "changed since", left alone unless Revert anyway is chosen, checked when
 * the undo is previewed, confirmed and again just before it is written. A
 * category the batch created is removed again, but only while it still has
 * no products and no subcategories (and, unless Revert anyway, still holds
 * what the batch gave it). Rows of the retired tree-only order
 * (`term_order`) are not put back: nothing reads that order any more.
 *
 * Each item carries one internal step per property, `restore_category`
 * (`{ field, value }`; `{ field: term_created }` to remove a new
 * category), which no client can send: tree items never reach the
 * operation registry. write() journals what it put back on the undo batch
 * under the same properties, so the undo can itself be undone.
 *
 * Whether a category exists and what it holds now are read from the term
 * tables (CategoryTree::complete(), stored_term()), never from a term query
 * filtered for whoever runs the undo (1.3.4); permission is still checked
 * for the person who asked for it.
 */
final class TermUndo {
	/**
	 * The internal step of a category undo.
	 */
	public const ACTION = 'restore_category';

	/**
	 * The step's field when the undo removes a category the batch created.
	 */
	public const CREATED = 'term_created';

	/**
	 * Properties put back, in the order they are written.
	 */
	public const PROPS = [ 'term_name', 'term_slug', 'term_description', 'term_parent', 'term_display_type', 'term_thumbnail_id', Writer::ORDER_PROP, Writer::DEFAULT_PROP ];

	/**
	 * Plan one category: an Undo\Planner item.
	 *
	 * @param int                                                       $id       Term id.
	 * @param array<string, array{expected: mixed, target: mixed}>      $fields   The batch's applied values by property.
	 * @param int                                                       $user_id  The person undoing.
	 * @return array{object_id: int, object_type: string, operations: list<array{action: string, args: array<string, mixed>}>, state: string, code: string, reason: string, conflicts: list<array{field: string, expected: mixed, current: mixed}>}
	 */
	public static function plan( int $id, array $fields, int $user_id ): array {
		if ( [] === $fields ) {
			return self::skipped( $id, Planner::NOT_APPLIED, __( 'This batch did not change this category, so there is nothing to undo.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$fields = array_intersect_key( $fields, array_flip( self::PROPS ) );
		if ( [] === $fields ) {
			// Only what nothing reads any more (the retired tree-only order) or what cannot come back.
			return self::skipped( $id, Planner::CANNOT_RESTORE, __( 'This change cannot be undone here, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		// Never what this viewer's term queries show: a background undo has no user (1.3.4).
		$tree = CategoryTree::complete();
		if ( null === $tree->node( $id ) ) {
			return self::skipped( $id, Planner::MISSING, __( 'This category no longer exists, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( ! user_can( $user_id, \BulkCatalogueManager\Rest\Access::TERMS_CAPABILITY ) || ! user_can( $user_id, 'edit_term', $id ) ) {
			return self::skipped( $id, Planner::CANNOT_EDIT, __( 'You can no longer change this category, so it was left alone. Ask an administrator for access to it.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$now       = self::current( $id );
		$conflicts = [];
		foreach ( $fields as $prop => $values ) {
			$current = $now[ $prop ] ?? null;
			if ( ! self::same( $prop, $current, $values['expected'] ) && ! self::same( $prop, $current, $values['target'] ) ) {
				$conflicts[] = [
					'field'    => $prop,
					'expected' => $values['expected'],
					'current'  => $current,
				];
			}
		}

		$created = array_key_exists( 'term_name', $fields ) && null === $fields['term_name']['target'];
		if ( $created ) {
			$products = get_objects_in_term( [ $id ], Counts::TAXONOMY );
			if ( [] !== $tree->children( $id ) || ( is_array( $products ) && [] !== $products ) ) {
				return self::skipped( $id, Planner::CANNOT_RESTORE, __( 'This new category now has products or subcategories, so undo leaves it in place. Delete it from the Categories tab if you no longer want it.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			if ( CategoryTree::default_id() === $id ) {
				return self::skipped( $id, Planner::CANNOT_RESTORE, __( 'This new category is now the shop\'s default category, so undo leaves it in place.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$operations = [ self::step( self::CREATED, null ) ];
		} else {
			$operations = [];
			foreach ( self::PROPS as $prop ) {
				if ( isset( $fields[ $prop ] ) ) {
					$operations[] = self::step( $prop, $fields[ $prop ]['target'] );
				}
			}
			$parent = $fields['term_parent']['target'] ?? null;
			if ( null !== $parent && 0 !== (int) $parent && ( null === $tree->node( (int) $parent ) || in_array( $id, $tree->lineage( (int) $parent ), true ) ) ) {
				return self::skipped( $id, Planner::CANNOT_RESTORE, __( 'The parent this category had before no longer exists, or is now inside it, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$old_default = $fields[ Writer::DEFAULT_PROP ]['target'] ?? null;
			if ( null !== $old_default && (int) $old_default > 0 && null === $tree->node( (int) $old_default ) ) {
				return self::skipped( $id, Planner::CANNOT_RESTORE, __( 'The category that was the default before no longer exists, so the default was left as it is.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
		}
		if ( [] !== $conflicts ) {
			return [
				'object_id'   => $id,
				'object_type' => Writer::OBJECT_TYPE,
				'operations'  => $operations,
				'state'       => Plan::CONFLICTED,
				'code'        => Planner::CHANGED_SINCE,
				'reason'      => __( 'This category was changed since this batch ran, so it is left alone unless you choose Revert anyway.', 'bulk-catalogue-manager-for-woocommerce' ),
				'conflicts'   => $conflicts,
			];
		}
		return [
			'object_id'   => $id,
			'object_type' => Writer::OBJECT_TYPE,
			'operations'  => $operations,
			'state'       => Plan::REVERTIBLE,
			'code'        => '',
			'reason'      => '',
			'conflicts'   => [],
		];
	}

	/**
	 * Write one category's undo item, checking it again first unless
	 * Revert anyway was chosen, and journal what was put back.
	 *
	 * @param ChangeRepository     $changes    The journal.
	 * @param int                  $batch_id   The undo batch.
	 * @param int                  $id         Term id.
	 * @param list<array<string, mixed>> $operations The item's steps.
	 * @param array<string, mixed>|null  $now_plan   The item planned again now, or null under Revert anyway.
	 * @return array{0: string, 1: string, 2: bool} [ item state, plain message, whether anything changed ].
	 */
	public static function write( ChangeRepository $changes, int $batch_id, int $id, array $operations, ?array $now_plan ): array {
		if ( $changes->has_object( $batch_id, $id ) ) {
			return [ 'done', '', $changes->has_applied( $batch_id, $id ) ];
		}
		if ( null !== $now_plan && Plan::REVERTIBLE !== $now_plan['state'] ) {
			return [ 'skipped', (string) $now_plan['reason'], false ];
		}
		$values = [];
		foreach ( $operations as $operation ) {
			$field = (string) ( $operation['args']['field'] ?? '' );
			if ( self::ACTION !== ( $operation['action'] ?? '' ) || ( self::CREATED !== $field && ! in_array( $field, self::PROPS, true ) ) ) {
				return [ 'failed', __( 'This category\'s undo could not be read, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ), false ];
			}
			$values[ $field ] = $operation['args']['value'] ?? null;
		}
		$before = self::current( $id );
		if ( [] === $before ) {
			return [ 'skipped', __( 'This category no longer exists, so it was left alone.', 'bulk-catalogue-manager-for-woocommerce' ), false ];
		}

		if ( array_key_exists( self::CREATED, $values ) ) {
			$deleted = wp_delete_term( $id, Counts::TAXONOMY );
			if ( true !== $deleted ) {
				return self::failed( $changes, $batch_id, $id, 'term_name', $before['term_name'] );
			}
			$changes->append( $batch_id, $id, Writer::OBJECT_TYPE, Deleter::DELETED_PROP, $before['term_name'], '', ChangeRepository::UNKNOWN_TIME, 'applied' );
			Cache::invalidate();
			return [ 'done', '', true ];
		}

		$term_args = [];
		foreach ( Writer::PROPS as $key => $prop ) {
			if ( array_key_exists( $prop, $values ) ) {
				$term_args[ $key ] = 'parent' === $key ? (int) $values[ $prop ] : (string) $values[ $prop ];
			}
		}
		if ( [] !== $term_args ) {
			$updated = wp_update_term( $id, Counts::TAXONOMY, wp_slash( $term_args ) );
			if ( is_wp_error( $updated ) ) {
				return self::failed( $changes, $batch_id, $id, (string) array_key_first( $values ), $before[ (string) array_key_first( $values ) ] ?? null, $updated->get_error_message() );
			}
		}
		foreach ( Writer::META as $key => $prop ) {
			if ( array_key_exists( $prop, $values ) ) {
				update_term_meta( $id, $key, 'thumbnail_id' === $key ? max( 0, (int) $values[ $prop ] ) : (string) $values[ $prop ] );
			}
		}
		if ( array_key_exists( Writer::ORDER_PROP, $values ) ) {
			if ( null === $values[ Writer::ORDER_PROP ] ) {
				delete_term_meta( $id, CategoryTree::ORDER_META );
			} else {
				update_term_meta( $id, CategoryTree::ORDER_META, (int) $values[ Writer::ORDER_PROP ] );
			}
		}
		if ( array_key_exists( Writer::DEFAULT_PROP, $values ) && (int) $values[ Writer::DEFAULT_PROP ] > 0 ) {
			update_option( CategoryTree::DEFAULT_OPTION, (int) $values[ Writer::DEFAULT_PROP ] );
		}
		Cache::invalidate();

		$after   = self::current( $id );
		$changed = false;
		foreach ( array_keys( $values ) as $prop ) {
			$now = $after[ $prop ] ?? null;
			if ( self::same( $prop, $now, $before[ $prop ] ?? null ) ) {
				continue;
			}
			$changes->append( $batch_id, $id, Writer::OBJECT_TYPE, $prop, $before[ $prop ] ?? null, $now, ChangeRepository::UNKNOWN_TIME, 'applied' );
			$changed = true;
		}
		return [ 'done', '', $changed ];
	}

	/**
	 * A category's values now, by journal property; empty when it is gone.
	 *
	 * @param int $id Term id.
	 * @return array<string, mixed>
	 */
	public static function current( int $id ): array {
		$term = CategoryTree::stored_term( $id );
		if ( null === $term ) {
			return [];
		}
		$order = get_term_meta( $id, CategoryTree::ORDER_META, true );
		return [
			'term_name'          => (string) ( $term->name ?? '' ),
			'term_slug'          => (string) ( $term->slug ?? '' ),
			'term_description'   => (string) ( $term->description ?? '' ),
			'term_parent'        => (int) ( $term->parent ?? 0 ),
			'term_display_type'  => (string) get_term_meta( $id, 'display_type', true ),
			'term_thumbnail_id'  => max( 0, (int) get_term_meta( $id, 'thumbnail_id', true ) ),
			Writer::ORDER_PROP   => is_numeric( $order ) ? (int) $order : null,
			Writer::DEFAULT_PROP => CategoryTree::default_id(),
		];
	}

	/**
	 * Whether two values of a property are the same: whole numbers by
	 * number (null apart), text exactly.
	 *
	 * @param string $prop Journal property.
	 * @param mixed  $a    One value.
	 * @param mixed  $b    The other.
	 */
	public static function same( string $prop, mixed $a, mixed $b ): bool {
		if ( in_array( $prop, [ 'term_parent', 'term_thumbnail_id', Writer::ORDER_PROP, Writer::DEFAULT_PROP ], true ) ) {
			$a = null === $a || '' === $a ? null : (int) $a;
			$b = null === $b || '' === $b ? null : (int) $b;
			if ( 'term_parent' === $prop || 'term_thumbnail_id' === $prop ) {
				$a = (int) $a;
				$b = (int) $b;
			}
			return $a === $b;
		}
		return (string) $a === (string) $b;
	}

	/**
	 * One step.
	 *
	 * @param string $field Property, or CREATED.
	 * @param mixed  $value The value to put back.
	 * @return array{action: string, args: array<string, mixed>}
	 */
	private static function step( string $field, mixed $value ): array {
		return [
			'action' => self::ACTION,
			'args'   => [
				'field' => $field,
				'value' => $value,
			],
		];
	}

	/**
	 * A failed write, journalled.
	 *
	 * @param ChangeRepository $changes  The journal.
	 * @param int              $batch_id The undo batch.
	 * @param int              $id       Term id.
	 * @param string           $prop     The first property.
	 * @param mixed            $value    Its value now.
	 * @param string           $detail   WordPress's reason, if any.
	 * @return array{0: string, 1: string, 2: bool}
	 */
	private static function failed( ChangeRepository $changes, int $batch_id, int $id, string $prop, mixed $value, string $detail = '' ): array {
		$reason = __( 'WordPress did not accept the old values for this category, so it was left as it is.', 'bulk-catalogue-manager-for-woocommerce' );
		$changes->append( $batch_id, $id, Writer::OBJECT_TYPE, $prop, $value, $value, ChangeRepository::UNKNOWN_TIME, 'failed', '' === $detail ? $reason : $reason . ' ' . wp_strip_all_tags( $detail ) );
		return [ 'failed', $reason, false ];
	}

	/**
	 * A skipped item.
	 *
	 * @param int    $id     Term id.
	 * @param string $code   Planner item code.
	 * @param string $reason Plain sentence.
	 * @return array{object_id: int, object_type: string, operations: list<array{action: string, args: array<string, mixed>}>, state: string, code: string, reason: string, conflicts: list<array{field: string, expected: mixed, current: mixed}>}
	 */
	private static function skipped( int $id, string $code, string $reason ): array {
		return [
			'object_id'   => $id,
			'object_type' => Writer::OBJECT_TYPE,
			'operations'  => [],
			'state'       => Plan::SKIPPED,
			'code'        => $code,
			'reason'      => $reason,
			'conflicts'   => [],
		];
	}
}
