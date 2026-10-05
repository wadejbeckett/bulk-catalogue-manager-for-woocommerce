<?php
/**
 * WP_Term as core builds it, copying a term row's properties, for contract
 * version 2 category subjects (External\TermSubject).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_Term' ) ) {
	final class WP_Term {
		public int $term_id = 0;

		public string $name = '';

		public string $slug = '';

		public int $term_group = 0;

		public int $term_taxonomy_id = 0;

		public string $taxonomy = '';

		public string $description = '';

		public int $parent = 0;

		public int $count = 0;

		public string $filter = 'raw';

		public function __construct( object $term ) {
			foreach ( get_object_vars( $term ) as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = is_int( $this->$key ) ? (int) $value : (string) $value;
				}
			}
		}
	}
}
