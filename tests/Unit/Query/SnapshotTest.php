<?php
/**
 * The Snapshot answers every getter the field registry names, and reads each
 * meta-stored field from the key the registry declares (T16; SPEC §5.1).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Query;

use BulkCatalogueManager\Fields\Registry;
use BulkCatalogueManager\Fields\Storage;
use BulkCatalogueManager\Query\Snapshot;
use PHPUnit\Framework\TestCase;

final class SnapshotTest extends TestCase {
	public function test_every_registry_getter_is_answered(): void {
		foreach ( ( new Registry() )->all() as $key => $field ) {
			$this->assertTrue( Snapshot::answers( $field->getter() ), "Snapshot has no {$field->getter()}() for $key; add it so the grid can read the field without a product object." );
		}
		$this->assertFalse( Snapshot::answers( 'get_nothing' ) );
	}

	public function test_meta_keys_match_the_registry(): void {
		$registry = [];
		foreach ( ( new Registry() )->all() as $field ) {
			if ( Storage::META === $field->storage()->kind() ) {
				$registry[ $field->getter() ] = $field->storage()->key();
			}
		}
		ksort( $registry );
		$snapshot = Snapshot::META;
		ksort( $snapshot );
		$this->assertSame( $registry, $snapshot );
	}
}
