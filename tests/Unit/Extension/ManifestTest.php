<?php
/**
 * The provider manifest and schema 8 (T107; docs/EXTENSIONS.md §8.2, §11;
 * the unit half of acceptance test E16): migration 7 → 8 adds the nullable
 * `extensions` column once and is idempotent; a batch records its manifest
 * only when it has external steps; the manifest names what the steps use and
 * tells when its provider can no longer work on it.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\Migrator;
use BulkCatalogueManager\Data\Registry;
use BulkCatalogueManager\Data\Schema;
use BulkCatalogueManager\Data\ValueCodec;
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';

final class ManifestTest extends TestCase {
	private const UUID = '6f1c1a8e-8a3b-4c61-9f5e-2b7d1d2c3a40';

	protected function setUp(): void {
		bulkcat_test_reset();
	}

	private static function sample(): \BulkCatalogueManager\External\Registered {
		return Providers::of( [ new SampleProvider( new MemoryStore() ) ] )->get( 'sample' );
	}

	private static function steps(): array {
		return [
			[
				'action' => 'x_sample_add',
				'args'   => [
					'field'  => 'x_sample_labels',
					'labels' => [ 'red' ],
				],
			],
		];
	}

	public function test_migration_7_to_8_adds_the_column_once(): void {
		$GLOBALS['bulkcat_test']['options'][ Migrator::VERSION_OPTION ] = '7';
		$this->assertStringContainsString( "\nextensions longtext NULL,\n", Schema::BATCHES_BODY );
		$this->assertTrue( ( new Migrator( Registry::plugin() ) )->run() );
		$this->assertSame( '8', get_option( Migrator::VERSION_OPTION ) );
		$this->assertCount( 1, $GLOBALS['bulkcat_test']['dbdelta'] );
		$this->assertStringContainsString( 'extensions longtext NULL', $GLOBALS['bulkcat_test']['dbdelta'][0][0] );
		$this->assertTrue( ( new Migrator( Registry::plugin() ) )->run() );
		$this->assertCount( 1, $GLOBALS['bulkcat_test']['dbdelta'], 'Current: nothing runs again.' );
	}

	public function test_a_batch_records_its_manifest_only_with_external_steps(): void {
		global $wpdb;
		$batches = new BatchRepository();
		$batches->create(
			self::UUID,
			3,
			'bulk',
			[
				[
					'action' => 'clear',
					'args'   => [ 'field' => 'sale_price' ],
				],
			],
			[],
			1
		);
		$this->assertArrayNotHasKey( 'extensions', $wpdb->writes[0][2], 'A native batch writes exactly what it always wrote.' );
		$manifest = Manifest::build( self::sample(), self::steps() );
		$batches->create( self::UUID, 3, 'bulk', self::steps(), [], 1, null, null, $manifest );
		$this->assertSame( $manifest, ValueCodec::decode( $wpdb->writes[1][2]['extensions'] ) );
		$this->assertSame( ',x_sample_add,', $wpdb->writes[1][2]['actions'], 'The history\'s action filter finds external batches.' );
	}

	public function test_the_manifest_names_what_the_steps_use(): void {
		$manifest = Manifest::build( self::sample(), self::steps() );
		$this->assertSame(
			[
				'sample' => [
					'label'    => 'Sample labels',
					'version'  => '1.0.0',
					'contract' => 1,
					'fields'   => [
						'x_sample_labels' => [
							'label'  => 'Sample labels',
							'format' => 1,
						],
					],
					'actions'  => [ 'x_sample_add' => 'Add labels' ],
				],
			],
			$manifest
		);
		$this->assertSame( 'Sample labels', Manifest::field_label( $manifest, 'x_sample_labels' ) );
		$this->assertNull( Manifest::field_label( $manifest, 'x_sample_note' ) );
		$this->assertNull( Manifest::field_label( null, 'x_sample_labels' ) );
		$this->assertSame( 'Add labels', Manifest::action_label( $manifest, 'x_sample_add' ) );
		$this->assertSame( 1, Manifest::format( $manifest, 'x_sample_labels' ) );
		$this->assertSame( 'An add-on', Manifest::label( [], 'gone' ) );
	}

	public function test_blocked_names_the_provider_that_cannot_work_on_it_now(): void {
		$manifest  = Manifest::build( self::sample(), self::steps() );
		$providers = Providers::of( [ new SampleProvider( new MemoryStore() ) ] );
		$this->assertNull( Manifest::blocked( $manifest, $providers ) );
		$this->assertSame( 'Sample labels', Manifest::blocked( $manifest, Providers::none() ), 'Not registered.' );
		$newer = $manifest;
		$newer['sample']['fields']['x_sample_labels']['format'] = 2;
		$this->assertSame( 'Sample labels', Manifest::blocked( $newer, $providers ), 'A format newer than it reads.' );
		$older = $manifest;
		update_option( 'sample_provider_faults', [ 'format' => 2 ] );
		$this->assertNull( Manifest::blocked( $older, Providers::of( [ new SampleProvider( new MemoryStore() ) ] ) ), 'An older format it can upgrade.' );
		update_option( 'sample_provider_faults', [ 'unavailable' => true ] );
		$this->assertSame( 'Sample labels', Manifest::blocked( $manifest, Providers::of( [ new SampleProvider( new MemoryStore() ) ] ) ), 'Unavailable.' );
		delete_option( 'sample_provider_faults' );
		$other                       = $manifest;
		$other['sample']['contract'] = 2;
		$this->assertSame( 'Sample labels', Manifest::blocked( $other, $providers ), 'A contract this core does not support.' );
		update_option( 'sample_provider_faults', [ 'version' => '1.1.0' ] );
		$this->assertSame( '1.1.0', Manifest::refreshed( $manifest, Providers::of( [ new SampleProvider( new MemoryStore() ) ] ) )['sample']['version'], 'An undo records the version at undo time.' );
	}
}
