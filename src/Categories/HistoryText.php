<?php
/**
 * Plain words for category changes in History (ticket 133 bug 3).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Categories;

use BulkCatalogueManager\Data\ChangeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Names and sentences History shows for categories, never ids or stored
 * forms: a category by its path ("Clothing › Shirts › Golf"), its parent by
 * path ("Top level" for none), the default category by path, an image by
 * its title, a display type by WooCommerce's label, a product's category
 * list by names, every name with its HTML entities decoded (`&amp;` reads
 * as `&`), and a headline per batch ("Renamed category Golf to Golf
 * shirts", "Moved 15 products from Saucepans to Kitchen › Pots").
 *
 * A category deleted since reads by the path its delete journalled
 * (`term_deleted`); one never seen reads "A deleted category".
 */
final class HistoryText {
	/**
	 * Paths by term id, for this request (the tree is read once).
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $paths = null;

	/**
	 * Paths of deleted categories by term id, from the journal.
	 *
	 * @var array<int, string>
	 */
	private static array $deleted = [];

	/**
	 * Forget what this request has read (after a write, and between tests).
	 */
	public static function reset(): void {
		self::$paths   = null;
		self::$deleted = [];
	}

	/**
	 * Read the paths of every category, and of deleted ones a page names,
	 * in one tree read and at most one journal read.
	 *
	 * @param list<int>             $ids     Term ids a page names.
	 * @param ChangeRepository|null $changes The journal, for deleted ones.
	 */
	public static function prime( array $ids, ?ChangeRepository $changes = null ): void {
		$paths   = self::paths();
		$missing = [];
		foreach ( $ids as $id ) {
			if ( $id > 0 && ! isset( $paths[ $id ] ) && ! isset( self::$deleted[ $id ] ) ) {
				$missing[] = (int) $id;
			}
		}
		if ( [] !== $missing ) {
			self::$deleted += ( $changes ?? new ChangeRepository() )->deleted_paths( array_values( array_unique( $missing ) ) );
		}
	}

	/**
	 * A category's path, or the path it had when it was deleted, or a stand-in.
	 *
	 * @param int $id Term id.
	 */
	public static function path( int $id ): string {
		return self::paths()[ $id ] ?? self::$deleted[ $id ] ?? __( 'A deleted category', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * A category's own name (the last part of its path).
	 *
	 * @param int $id Term id.
	 */
	public static function name( int $id ): string {
		$parts = explode( ' › ', self::path( $id ) );
		return (string) end( $parts );
	}

	/**
	 * Term ids a journal row names in its object or values, for prime().
	 *
	 * @param array<string, mixed> $row Typed journal row.
	 * @return list<int>
	 */
	public static function ids_of( array $row ): array {
		$ids = Writer::OBJECT_TYPE === $row['object_type'] ? [ (int) $row['object_id'] ] : [];
		if ( in_array( $row['prop'], [ 'term_parent', Writer::DEFAULT_PROP ], true ) ) {
			$ids[] = (int) $row['old_value'];
			$ids[] = (int) $row['new_value'];
		}
		if ( Deleter::PRODUCT_PROP === $row['prop'] ) {
			foreach ( [ $row['old_value'], $row['new_value'] ] as $list ) {
				foreach ( is_array( $list ) ? $list : [] as $id ) {
					$ids[] = (int) $id;
				}
			}
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * A journal row's before and after as words, or null where History
	 * formats the value itself.
	 *
	 * @param array<string, mixed> $row Typed journal row.
	 * @return array{0: string, 1: string}|null
	 */
	public static function values( array $row ): ?array {
		$text = match ( (string) $row['prop'] ) {
			'term_name', 'term_description' => static fn( mixed $value ): string => self::decode( (string) $value ),
			'term_parent'               => static fn( mixed $value ): string => null === $value ? '' : ( 0 === (int) $value ? __( 'Top level', 'bulk-catalogue-manager-for-woocommerce' ) : self::path( (int) $value ) ),
			Writer::DEFAULT_PROP        => static fn( mixed $value ): string => 0 < (int) $value ? self::path( (int) $value ) : __( 'None', 'bulk-catalogue-manager-for-woocommerce' ),
			'term_display_type'         => static fn( mixed $value ): string => null === $value ? '' : self::display_type( (string) $value ),
			'term_thumbnail_id'         => static fn( mixed $value ): string => null === $value ? '' : self::image( (int) $value ),
			Deleter::DELETED_PROP       => static fn( mixed $value ): string => '' === (string) $value ? __( 'Deleted', 'bulk-catalogue-manager-for-woocommerce' ) : self::decode( (string) $value ),
			Deleter::PRODUCT_PROP       => static fn( mixed $value ): string => is_array( $value ) ? implode( ', ', array_map( static fn( mixed $id ): string => self::name( (int) $id ), $value ) ) : '',
			NativeForm::OTHER_PROP      => null,
			default                     => null,
		};
		if ( NativeForm::OTHER_PROP === (string) $row['prop'] ) {
			// Other plugins' fields: how many changed, never their keys (1.3.4).
			$old     = is_array( $row['old_value'] ) ? $row['old_value'] : [];
			$new     = is_array( $row['new_value'] ) ? $row['new_value'] : [];
			$changed = 0;
			foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
				$changed += ( $old[ $key ] ?? null ) === ( $new[ $key ] ?? null ) ? 0 : 1;
			}
			return [
				'',
				sprintf(
					/* translators: %s: how many fields other plugins keep for a category changed, such as "3". */
					_n( '%s field changed', '%s fields changed', $changed, 'bulk-catalogue-manager-for-woocommerce' ),
					number_format_i18n( $changed )
				),
			];
		}
		return null === $text ? null : [ $text( $row['old_value'] ), $text( $row['new_value'] ) ];
	}

	/**
	 * What a batch did, in words, where it changed categories: every
	 * category batch, and a product batch whose steps all add, remove or
	 * replace categories. Null otherwise (History describes it itself).
	 *
	 * @param array<string, mixed> $batch Typed batch row.
	 */
	public static function headline( array $batch ): ?string {
		$steps = is_array( $batch['operations'] ?? null ) ? $batch['operations'] : [];
		if ( [] === $steps ) {
			return null;
		}
		if ( Writer::SOURCE === ( $batch['source'] ?? '' ) ) {
			$step = is_array( $steps[0] ) ? $steps[0] : [];
			$args = is_array( $step['args'] ?? null ) ? $step['args'] : [];
			$id   = (int) ( $args['id'] ?? $batch['selection']['ids'][0] ?? 0 );
			return self::tree_headline( (string) ( $step['action'] ?? '' ), $args, $id );
		}
		return self::product_headline( $steps, (int) ( $batch['total_items'] ?? 0 ) );
	}

	/**
	 * A category batch's headline.
	 *
	 * @param string               $action The step.
	 * @param array<string, mixed> $args   Its arguments.
	 * @param int                  $id     The category.
	 */
	private static function tree_headline( string $action, array $args, int $id ): string {
		$name = isset( $args['name'] ) && is_string( $args['name'] ) && '' !== $args['name'] ? self::decode( $args['name'] ) : self::name( $id );
		$from = is_array( $args['from'] ?? null ) ? $args['from'] : [];
		switch ( $action ) {
			case Writer::CREATE:
				return sprintf(
					/* translators: %s: a category path, such as "Clothing › Hats". */
					__( 'Created category %s', 'bulk-catalogue-manager-for-woocommerce' ),
					0 < (int) ( $args['parent'] ?? 0 ) ? self::path( (int) $args['parent'] ) . ' › ' . $name : $name
				);
			case Writer::MOVE:
				$parent = (int) ( $args['parent'] ?? 0 );
				$where  = 0 === $parent ? __( 'the top level', 'bulk-catalogue-manager-for-woocommerce' ) : self::path( $parent );
				if ( array_key_exists( 'parent', $from ) && (int) $from['parent'] === $parent ) {
					return sprintf(
						/* translators: 1: a category name, such as "Golf shirts"; 2: where it is, such as "Clothing › Golf" or "the top level". */
						__( 'Reordered category %1$s in %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
						$name,
						$where
					);
				}
				return sprintf(
					/* translators: 1: a category name, such as "Golf shirts"; 2: where it went, such as "Clothing › Golf" or "the top level". */
					__( 'Moved category %1$s to %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
					$name,
					$where
				);
			case Writer::EDIT:
				return self::edit_headline( $args, $from, $id );
			case Writer::MAKE_DEFAULT:
				return sprintf(
					/* translators: %s: a category path, such as "Clothing". */
					__( 'Made %s the default category', 'bulk-catalogue-manager-for-woocommerce' ),
					self::path( $id )
				);
			case Deleter::DELETE:
			case Deleter::MERGE:
				$names = is_array( $args['names'] ?? null ) ? array_map( static fn( mixed $path ): string => self::decode( (string) $path ), array_values( $args['names'] ) ) : [];
				$list  = implode( ', ', $names );
				if ( Deleter::MERGE === $action ) {
					return sprintf(
						/* translators: 1: one or more category paths, such as "Kitchen › Pans, Kitchen › Skillets"; 2: a category path, such as "Kitchen › Pots". */
						_n( 'Merged category %1$s into %2$s', 'Merged categories %1$s into %2$s', max( 1, count( $names ) ), 'bulk-catalogue-manager-for-woocommerce' ),
						$list,
						self::decode( (string) ( $args['path'] ?? self::path( (int) ( $args['into'] ?? 0 ) ) ) )
					);
				}
				return sprintf(
					/* translators: %s: one or more category paths, such as "Clothing › Shirts, Clothing › Hats". */
					_n( 'Deleted category %s', 'Deleted categories %s', max( 1, count( $names ) ), 'bulk-catalogue-manager-for-woocommerce' ),
					$list
				);
			default:
				return sprintf(
					/* translators: %s: a category name, such as "Golf shirts". */
					__( 'Changed category %s', 'bulk-catalogue-manager-for-woocommerce' ),
					$name
				);
		}
	}

	/**
	 * An edit's headline: a rename, a move, a description, the details, or several.
	 *
	 * @param array<string, mixed> $args The edit's arguments.
	 * @param array<string, mixed> $from Its values before, when recorded.
	 * @param int                  $id   The category.
	 */
	private static function edit_headline( array $args, array $from, int $id ): string {
		if ( NativeForm::FORM === ( $args['form'] ?? null ) ) {
			return sprintf(
				/* translators: %s: a category name, such as "Golf shirts". */
				__( 'Saved category %s with WordPress’s category form', 'bulk-catalogue-manager-for-woocommerce' ),
				self::name( $id )
			);
		}
		$changed = array_values( array_intersect( [ 'name', 'slug', 'description', 'parent', 'display_type', 'thumbnail_id' ], array_keys( $args ) ) );
		$name    = isset( $args['name'] ) ? self::decode( (string) $args['name'] ) : self::name( $id );
		if ( [ 'name' ] === $changed || [ 'name', 'slug' ] === $changed ) {
			return isset( $from['name'] )
				? sprintf(
					/* translators: 1: the old name, such as "Golf"; 2: the new name, such as "Golf shirts". */
					__( 'Renamed category %1$s to %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
					self::decode( (string) $from['name'] ),
					$name
				)
				: sprintf(
					/* translators: %s: the new name, such as "Golf shirts". */
					__( 'Renamed a category to %s', 'bulk-catalogue-manager-for-woocommerce' ),
					$name
				);
		}
		if ( [ 'parent' ] === $changed ) {
			$parent = (int) $args['parent'];
			return sprintf(
				/* translators: 1: a category name, such as "Golf shirts"; 2: where it went, such as "Clothing › Golf" or "the top level". */
				__( 'Moved category %1$s to %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				$name,
				0 === $parent ? __( 'the top level', 'bulk-catalogue-manager-for-woocommerce' ) : self::path( $parent )
			);
		}
		if ( [ 'description' ] === $changed ) {
			return sprintf(
				/* translators: %s: a category name, such as "Golf shirts". */
				__( 'Changed the description of category %s', 'bulk-catalogue-manager-for-woocommerce' ),
				$name
			);
		}
		if ( [] !== $changed && [] === array_diff( $changed, [ 'display_type', 'thumbnail_id' ] ) ) {
			return sprintf(
				/* translators: %s: a category name, such as "Golf shirts". */
				__( 'Changed the display type or image of category %s', 'bulk-catalogue-manager-for-woocommerce' ),
				$name
			);
		}
		return sprintf(
			/* translators: %s: a category name, such as "Golf shirts". */
			__( 'Changed category %s', 'bulk-catalogue-manager-for-woocommerce' ),
			$name
		);
	}

	/**
	 * A product batch's headline when every step adds, removes or replaces
	 * product categories: "Moved 15 products from Saucepans to Kitchen › Pots".
	 *
	 * @param list<mixed> $steps The batch's steps.
	 * @param int         $count How many products it was for.
	 */
	private static function product_headline( array $steps, int $count ): ?string {
		$by = [];
		foreach ( $steps as $step ) {
			$field = is_array( $step ) ? ( $step['args']['field'] ?? '' ) : '';
			$terms = is_array( $step ) && is_array( $step['args']['terms'] ?? null ) ? array_map( 'intval', $step['args']['terms'] ) : null;
			if ( Deleter::PRODUCT_PROP !== $field || null === $terms || ! in_array( $step['action'] ?? '', [ 'add_terms', 'remove_terms', 'replace_terms' ], true ) ) {
				return null;
			}
			$by[ $step['action'] ] = array_merge( $by[ $step['action'] ] ?? [], $terms );
		}
		self::prime( array_merge( ...array_values( $by ) ) );
		$paths = static fn( array $ids ): string => implode( ', ', array_map( [ self::class, 'path' ], array_values( array_unique( $ids ) ) ) );
		$names = static fn( array $ids ): string => implode( ', ', array_map( [ self::class, 'name' ], array_values( array_unique( $ids ) ) ) );
		$what  = sprintf(
			/* translators: %s: a number of products, such as "15". */
			_n( '%s product', '%s products', $count, 'bulk-catalogue-manager-for-woocommerce' ),
			number_format_i18n( $count )
		);
		if ( isset( $by['replace_terms'] ) ) {
			return sprintf(
				/* translators: 1: a number of products, such as "15 products"; 2: category paths, such as "Kitchen › Pots". */
				__( 'Set the categories of %1$s to %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				$what,
				$paths( $by['replace_terms'] )
			);
		}
		if ( isset( $by['add_terms'], $by['remove_terms'] ) ) {
			return sprintf(
				/* translators: 1: a number of products, such as "15 products"; 2: category names, such as "Saucepans"; 3: category paths, such as "Kitchen › Pots". */
				__( 'Moved %1$s from %2$s to %3$s', 'bulk-catalogue-manager-for-woocommerce' ),
				$what,
				$names( $by['remove_terms'] ),
				$paths( $by['add_terms'] )
			);
		}
		if ( isset( $by['add_terms'] ) ) {
			return sprintf(
				/* translators: 1: category paths, such as "Kitchen › Pots"; 2: a number of products, such as "15 products". */
				__( 'Added %1$s to %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				$paths( $by['add_terms'] ),
				$what
			);
		}
		return sprintf(
			/* translators: 1: category paths, such as "Kitchen › Saucepans"; 2: a number of products, such as "15 products". */
			__( 'Removed %1$s from %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
			$paths( $by['remove_terms'] ?? [] ),
			$what
		);
	}

	/**
	 * WooCommerce's label for a display type.
	 *
	 * @param string $type '', products, subcategories or both.
	 */
	private static function display_type( string $type ): string {
		return match ( $type ) {
			'products'      => __( 'Products', 'bulk-catalogue-manager-for-woocommerce' ),
			'subcategories' => __( 'Subcategories', 'bulk-catalogue-manager-for-woocommerce' ),
			'both'          => __( 'Both', 'bulk-catalogue-manager-for-woocommerce' ),
			default         => __( 'Default', 'bulk-catalogue-manager-for-woocommerce' ),
		};
	}

	/**
	 * An image by its title.
	 *
	 * @param int $id Attachment id, 0 for none.
	 */
	private static function image( int $id ): string {
		if ( $id < 1 ) {
			return __( 'No image', 'bulk-catalogue-manager-for-woocommerce' );
		}
		$post  = get_post( $id );
		$title = is_object( $post ) ? trim( (string) ( $post->post_title ?? '' ) ) : '';
		if ( '' === $title ) {
			return is_object( $post )
				? __( 'An image with no title', 'bulk-catalogue-manager-for-woocommerce' )
				: __( 'An image no longer in the media library', 'bulk-catalogue-manager-for-woocommerce' );
		}
		return self::decode( $title );
	}

	/**
	 * Text with its HTML entities decoded, as a person reads it.
	 *
	 * @param string $text Stored text.
	 */
	public static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Every category's path, read once per request.
	 *
	 * @return array<int, string>
	 */
	private static function paths(): array {
		if ( null === self::$paths ) {
			// Every category, whatever this viewer's term queries show: hidden is not deleted (1.3.4).
			$tree        = CategoryTree::complete();
			self::$paths = [];
			$stack       = $tree->children( 0 );
			while ( [] !== $stack ) {
				$id                 = (int) array_shift( $stack );
				self::$paths[ $id ] = Deleter::path( $tree, $id );
				$stack              = array_merge( $stack, $tree->children( $id ) );
			}
		}
		return self::$paths;
	}
}
