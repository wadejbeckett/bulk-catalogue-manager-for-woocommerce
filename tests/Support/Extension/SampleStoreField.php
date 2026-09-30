<?php
/**
 * A plain text field under any key, for registration tests that need a
 * field the sample provider does not declare. Test code only; never shipped.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support\Extension;

use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Extension\Subject;

/**
 * A text value stored under its own key.
 */
abstract class SampleStoreField implements ExternalField {
	/**
	 * Over a store, under a key.
	 *
	 * @param SampleStore $store Where the value lives.
	 * @param string      $key   The field key.
	 */
	public function __construct( private readonly SampleStore $store, private readonly string $key ) {}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return 'Sample text';
	}

	public function variation_rule(): string {
		return self::PARENT_ONLY;
	}

	public function format(): int {
		return 1;
	}

	public function read( Subject $subject ): mixed {
		return $this->store->get( $subject->id(), $this->key ) ?? '';
	}

	public function same( mixed $a, mixed $b ): bool {
		return $a === $b;
	}

	public function describe( mixed $value, Subject $subject ): string {
		return (string) $value;
	}

	public function describe_change( mixed $old, mixed $new_value, Subject $subject ): string {
		return '';
	}

	public function write( Subject $subject, mixed $target, mixed $expected, Context $context ): bool|\WP_Error {
		$this->store->set( $subject->id(), $this->key, (string) $target );
		return true;
	}

	public function upgrade( mixed $value, int $from_format ): mixed {
		return $value;
	}
}
