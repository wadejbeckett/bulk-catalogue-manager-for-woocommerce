<?php
/**
 * Lookup arguments and row entries on the server (ticket 25;
 * docs/EXTENSIONS-v2.md §4, §6, §7): the features are on; a lookup value is
 * normalised to its canonical form; every id is resolved through the
 * provider at validation, refusing an unavailable choice; the resolved
 * labels are bound into the preview binding and kept in the manifest for
 * History, disclosed only with the step's arguments; `/fields` advertises a
 * lookup without choices and a row entry with `row`; a version 1 provider's
 * binding, manifest and entries are unchanged.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\Extension\Contract;
use BulkCatalogueManager\External\Arguments;
use BulkCatalogueManager\External\Binding;
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\FieldsController;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\PicksDirectory;
use BulkCatalogueManager\Tests\Support\Extension\PicksFaults;
use BulkCatalogueManager\Tests\Support\Extension\PicksProvider;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';

final class LookupArgumentsTest extends TestCase {
	private const USER = 7;

	protected function setUp(): void {
		bulkcat_test_reset();
		PicksProvider::reset();
		SampleProvider::reset();
		$GLOBALS['bulkcat_test']['user_id']                 = self::USER;
		$GLOBALS['bulkcat_test']['logged_in']               = true;
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [ 'edit_products' => true ];
	}

	/**
	 * Registered providers: the version 2 test provider (product actions
	 * only, while categories are not implemented) and the version 1 one.
	 */
	private static function providers(): Providers {
		return Providers::of( [ new PicksProvider( new MemoryStore(), new MemoryStore(), false ), new SampleProvider( new MemoryStore() ) ] );
	}

	/**
	 * A registry over those providers.
	 */
	private static function actions(): Actions {
		return new Actions( new Fields(), null, self::providers() );
	}

	/**
	 * An Add recipients step.
	 *
	 * @param mixed $recipients The submitted value.
	 */
	private static function add( mixed $recipients ): array {
		return [
			'action' => 'x_picks_add',
			'args'   => [
				'field'      => 'x_picks_items',
				'recipients' => $recipients,
			],
		];
	}

	public function test_lookup_is_implemented(): void {
		$this->assertContains( Contract::FEATURE_LOOKUP, Contract::FEATURES );
		$this->assertSame( [], self::providers()->refusals() );
		$this->assertNotNull( self::providers()->get( 'picks' ) );
	}

	public function test_a_lookup_value_is_normalised_to_its_canonical_form(): void {
		$many = [
			'type'      => 'lookup',
			'multiple'  => true,
			'min_items' => 1,
			'max_items' => 3,
		];
		$one  = [
			'type'     => 'lookup',
			'multiple' => false,
		];
		$this->assertSame( [ 'r0002', 'r0010' ], Arguments::normalise( [ 'r0010', 'r0002' ], $many ), 'Sorted by bytes.' );
		$this->assertNull( Arguments::normalise( [ 'r0002', 'r0002' ], $many ), 'Repeats are refused.' );
		$this->assertNull( Arguments::normalise( [], $many ), 'Fewer than min_items.' );
		$this->assertNull( Arguments::normalise( [ 'a', 'b', 'c', 'd' ], $many ), 'More than max_items.' );
		$this->assertNull( Arguments::normalise( [ "bad\nid" ], $many ), 'A control character.' );
		$this->assertNull( Arguments::normalise( [ str_repeat( 'x', 65 ) ], $many ), 'Longer than 64 characters.' );
		$this->assertNull( Arguments::normalise( [ 'a' => 'r0001' ], $many ), 'Not a list.' );
		$this->assertNull( Arguments::normalise( [ 1 ], $many ), 'Not strings.' );
		$this->assertSame( 'r0004', Arguments::normalise( 'r0004', $one ) );
		$this->assertNull( Arguments::normalise( [ 'r0004' ], $one ) );
		$this->assertNull( Arguments::normalise( '', $one ) );
	}

	public function test_valid_choices_pass_and_are_resolved_once_per_argument(): void {
		$list = self::actions()->validate_list( [ self::add( [ 'r0042', 'r0001' ] ) ] );
		$this->assertTrue( is_array( $list ), $list instanceof \WP_Error ? $list->get_error_message() : '' );
		$this->assertSame( [ 'r0001', 'r0042' ], $list[0]['args']['recipients'] );
		$this->assertSame( [ [ 'recipients', [ 'r0001', 'r0042' ], 'apply' ] ], PicksDirectory::$resolved );
	}

	public function test_withdrawn_denied_and_unknown_choices_are_refused(): void {
		update_option(
			PicksFaults::OPTION,
			[
				'withdrawn'  => [ 'r0002' ],
				'restricted' => [ 'r0003' ],
			]
		);
		foreach ( [ 'r0002', 'r0003', 'r9999' ] as $bad ) {
			$refusal = self::actions()->validate_list( [ self::add( [ 'r0001', $bad ] ) ] );
			$this->assertInstanceOf( \WP_Error::class, $refusal, $bad );
			$this->assertSame( 'bulkcat_choice_unavailable', $refusal->get_error_code(), $bad );
			$this->assertSame( 400, $refusal->get_error_data()['status'] );
			$this->assertSame( 'recipients', $refusal->get_error_data()['argument'] );
			$this->assertSame( 'Choose at least one recipient from the list.', $refusal->get_error_message() );
		}
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ]['manage_options'] = true;
		$this->assertTrue( is_array( self::actions()->validate_list( [ self::add( [ 'r0003' ] ) ] ) ), 'A user allowed that choice may use it.' );
	}

	public function test_a_provider_that_throws_while_resolving_refuses_the_request(): void {
		$throws   = new class() implements \BulkCatalogueManager\Extension\ExternalAction, \BulkCatalogueManager\Extension\LookupSource {
			public function describe(): \BulkCatalogueManager\Extension\ActionDescriptor {
				return ( new \BulkCatalogueManager\Tests\Support\Extension\PicksAction( \BulkCatalogueManager\Tests\Support\Extension\PicksAction::ADD, false ) )->describe();
			}

			public function validate( array $args, \BulkCatalogueManager\Extension\Context $context ): \BulkCatalogueManager\Operations\Validation {
				return \BulkCatalogueManager\Operations\Validation::passed( $args );
			}

			public function compute( \BulkCatalogueManager\Extension\Subject $subject, array $args, \BulkCatalogueManager\Extension\Context $context ): \BulkCatalogueManager\Operations\Result {
				return \BulkCatalogueManager\Operations\Result::already( 'x_picks_items', [] );
			}

			public function lookup( string $argument, string $query, int $page, \BulkCatalogueManager\Extension\Context $context ): \BulkCatalogueManager\Extension\LookupPage {
				throw new \RuntimeException( 'No lookups.' );
			}

			public function resolve( string $argument, array $ids, \BulkCatalogueManager\Extension\Context $context ): array {
				throw new \RuntimeException( 'No resolving.' );
			}
		};
		$provider = new class( $throws ) extends PicksProvider {
			public function __construct( private readonly object $action ) {
				parent::__construct( new MemoryStore(), new MemoryStore(), false );
			}

			public function actions(): array {
				return [ $this->action ];
			}
		};
		$refusal  = ( new Actions( new Fields(), null, Providers::of( [ $provider ] ) ) )->validate_list( [ self::add( [ 'r0001' ] ) ] );
		$this->assertInstanceOf( \WP_Error::class, $refusal );
		$this->assertSame( 'bulkcat_external_error', $refusal->get_error_code() );
		$this->assertSame( 409, $refusal->get_error_data()['status'] );
	}

	public function test_the_binding_carries_resolved_labels_only_for_lookups(): void {
		$providers = self::providers();
		$picks     = $providers->get( 'picks' );
		$steps     = self::actions()->validate_list( [ self::add( [ 'r0001', 'r0042' ] ) ] );
		$binding   = Binding::of( $picks, $steps, [ 10, 11 ], RunContext::preview( self::USER ) );
		$this->assertSame(
			[
				'x_picks_add' => [
					'recipients' => [
						'r0001' => 'Recipient 0001',
						'r0042' => 'Recipient 0042',
					],
				],
			],
			$binding['choices']
		);
		update_option( PicksFaults::OPTION, [ 'renamed' => [ 'r0042' => 'Someone else' ] ] );
		$this->assertNotSame( $binding, Binding::of( $picks, $steps, [ 10, 11 ], RunContext::preview( self::USER ) ), 'A rename changes the binding, so Apply is stale.' );
		$sample = $providers->get( 'sample' );
		$labels = [
			[
				'action' => 'x_sample_add',
				'args'   => [
					'field'  => 'x_sample_labels',
					'labels' => [ 'red' ],
				],
			],
		];
		$this->assertSame( [ 'provider', 'version', 'contract', 'formats', 'state' ], array_keys( Binding::of( $sample, $labels, [ 10 ] ) ), 'A version 1 binding is unchanged.' );
	}

	public function test_the_manifest_keeps_labels_and_discloses_them_only_with_the_arguments(): void {
		$providers = self::providers();
		$picks     = $providers->get( 'picks' );
		$steps     = self::actions()->validate_list( [ self::add( [ 'r0001', 'r0042' ] ) ] );
		$binding   = Binding::of( $picks, $steps, [ 10 ], RunContext::preview( self::USER ) );
		$manifest  = Manifest::build( $picks, $steps, $binding['choices'] );
		$this->assertSame(
			[
				'r0001' => 'Recipient 0001',
				'r0042' => 'Recipient 0042',
			],
			$manifest['picks']['choices']['x_picks_add']['recipients']
		);
		$client = Manifest::client( $manifest, $steps );
		$this->assertSame(
			[
				'x_picks_add' => [
					'recipients' => [
						'r0001' => 'Recipient 0001',
						'r0042' => 'Recipient 0042',
					],
				],
			],
			$client['choices']
		);
		$withheld = [
			[
				'action' => 'x_picks_add',
				'args'   => [ 'field' => 'x_picks_items' ],
			],
		];
		$this->assertSame( [], Manifest::client( $manifest, $withheld )['choices'], 'A reader who cannot see the arguments sees no choice labels.' );
		$sample = Manifest::build( $providers->get( 'sample' ), [] );
		$this->assertArrayNotHasKey( 'choices', $sample['sample'], 'A version 1 manifest is unchanged.' );
	}

	public function test_fields_advertise_lookups_without_choices(): void {
		$fields  = new Fields();
		$payload = ( new FieldsController( $fields, new Actions( $fields, null, self::providers() ) ) )->payload();
		$by_id   = array_column( $payload['actions'], null, 'id' );
		$this->assertSame(
			[
				'title'       => 'Recipients',
				'description' => 'Choose at least one recipient from the list.',
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'uniqueItems' => true,
				'minItems'    => 1,
				'maxItems'    => 20,
				'lookup'      => [ 'multiple' => true ],
				'required'    => true,
			],
			$by_id['x_picks_add']['args']['properties']['recipients']
		);
		$this->assertSame(
			[
				'title'       => 'Recipient',
				'description' => 'Choose one recipient from the list.',
				'type'        => 'string',
				'lookup'      => [ 'multiple' => false ],
				'required'    => true,
			],
			$by_id['x_picks_only']['args']['properties']['recipient']
		);
		$this->assertArrayNotHasKey( 'target', $by_id['x_picks_add'], 'The default target carries no key.' );
		$this->assertArrayNotHasKey( 'row', $by_id['x_sample_add'], 'A version 1 entry gains no key.' );
		$this->assertArrayNotHasKey( 'target', $by_id['x_sample_add'] );
	}

	public function test_the_version_two_payload_matches_its_snapshot(): void {
		$snapshot = __DIR__ . '/fields-picks.snapshot.json';
		$fields   = new Fields();
		$payload  = ( new FieldsController( $fields, new Actions( $fields, null, Providers::of( [ new PicksProvider( new MemoryStore(), new MemoryStore(), false ) ] ) ) ) )->payload();
		$json     = (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		if ( '1' === getenv( 'BULKCAT_UPDATE_SNAPSHOT' ) ) {
			file_put_contents( $snapshot, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The test's own snapshot.
		}
		$this->assertFileExists( $snapshot );
		$this->assertSame( (string) file_get_contents( $snapshot ), $json, 'The /fields payload with the version 2 provider changed. If that is deliberate, run BULKCAT_UPDATE_SNAPSHOT=1 composer test and commit the snapshot.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The test's own snapshot.
	}
}
