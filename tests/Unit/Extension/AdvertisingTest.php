<?php
/**
 * Advertising (T105; docs/EXTENSIONS.md §5; acceptance tests E3 and E13):
 * `GET /fields` gains external fields, actions, chooser groups and
 * providers only when a provider is registered; external actions follow the
 * native ones in the provider's order with their argument schema (the
 * multichoice kind included), disabled with a reason when the provider is
 * unavailable or the user lacks the capability; native entries never gain a
 * key; and every provider string reaches the browser as plain text.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Extension;

use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\FieldsController;
use BulkCatalogueManager\Tests\Support\Extension\MemoryStore;
use BulkCatalogueManager\Tests\Support\Extension\SampleProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/Extension/load.php';

final class AdvertisingTest extends TestCase {
	/**
	 * The extended payload the Jest suite reads as its fixture.
	 * Refresh with BULKCAT_UPDATE_SNAPSHOT=1 composer test, then review the diff.
	 */
	public const SNAPSHOT = __DIR__ . '/fields-external.snapshot.json';

	private const USER = 7;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['user_id']                 = self::USER;
		$GLOBALS['bulkcat_test']['logged_in']               = true;
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [ 'edit_products' => true ];
	}

	/**
	 * The payload with the sample provider registered.
	 *
	 * @param SampleProvider|null $provider The provider; a fresh one by default.
	 * @return array<string, mixed>
	 */
	private static function payload( ?SampleProvider $provider = null ): array {
		$fields = new Fields();
		return ( new FieldsController( $fields, new Actions( $fields, null, Providers::of( [ $provider ?? new SampleProvider( new MemoryStore() ) ] ) ) ) )->payload();
	}

	/**
	 * One entry of a list by a key's value.
	 *
	 * @param list<array<string, mixed>> $list  Entries.
	 * @param string                     $key   Key.
	 * @param string                     $value Value.
	 * @return array<string, mixed>
	 */
	private function entry( array $list, string $key, string $value ): array {
		foreach ( $list as $entry ) {
			if ( ( $entry[ $key ] ?? null ) === $value ) {
				return $entry;
			}
		}
		$this->fail( "No entry with $key $value." );
	}

	public function test_the_extended_payload_matches_its_snapshot(): void {
		$json = (string) wp_json_encode( self::payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		if ( '1' === getenv( 'BULKCAT_UPDATE_SNAPSHOT' ) ) {
			file_put_contents( self::SNAPSHOT, $json );
		}
		$this->assertFileExists( self::SNAPSHOT );
		$this->assertSame( (string) file_get_contents( self::SNAPSHOT ), $json, 'The /fields payload with a provider changed. If that is deliberate, run BULKCAT_UPDATE_SNAPSHOT=1 composer test and commit the snapshot.' );
	}

	public function test_external_entries_follow_the_native_ones_which_gain_no_key(): void {
		$native  = ( new FieldsController( new Fields() ) )->payload();
		$payload = self::payload();
		$this->assertSame( [ 'fields', 'actions', 'groups', 'providers' ], array_keys( $payload ) );

		$count = count( $native['fields'] );
		$this->assertSame( $native['fields'], array_slice( $payload['fields'], 0, $count ), 'Native fields come first, unchanged.' );
		$this->assertSame( [ 'x_sample_labels', 'x_sample_note' ], array_column( array_slice( $payload['fields'], $count ), 'key' ) );

		$count = count( $native['actions'] );
		$this->assertSame( $native['actions'], array_slice( $payload['actions'], 0, $count ), 'Native actions come first, unchanged.' );
		$this->assertSame( [ 'x_sample_add', 'x_sample_remove', 'x_sample_replace', 'x_sample_clear', 'x_sample_set_note' ], array_column( array_slice( $payload['actions'], $count ), 'id' ), 'In the provider\'s order.' );

		$this->assertSame( [ ...Group::ALL, 'x_sample_labels' ], array_column( $payload['groups'], 'id' ), 'Provider groups follow the native groups.' );
		$this->assertSame( 'Sample labels', end( $payload['groups'] )['label'] );
		$this->assertSame(
			[
				[
					'id'        => 'sample',
					'label'     => 'Sample labels',
					'version'   => '1.0.0',
					'available' => true,
					'reason'    => '',
				],
			],
			$payload['providers']
		);
	}

	public function test_an_external_field_is_described_for_the_panel_and_ignored_by_the_grid(): void {
		$labels = $this->entry( self::payload()['fields'], 'key', 'x_sample_labels' );
		$this->assertSame( [ 'key', 'label', 'type', 'source', 'provider', 'readOnly', 'nullable', 'inline', 'sortable', 'editKinds', 'variations', 'variationNote', 'group', 'defaultColumn', 'defaultOrder', 'schema', 'dataviews', 'choices' ], array_keys( $labels ) );
		$this->assertSame( 'external', $labels['type'] );
		$this->assertSame( 'external', $labels['source'] );
		$this->assertSame( 'sample', $labels['provider'] );
		$this->assertFalse( $labels['inline'] );
		$this->assertFalse( $labels['sortable'] );
		$this->assertFalse( $labels['defaultColumn'] );
		$this->assertNull( $labels['defaultOrder'] );
		$this->assertNull( $labels['dataviews'], 'No DataViews definition: the grid, filters and drawer skip it.' );
		$this->assertSame( [ 'add', 'remove', 'set', 'clear' ], $labels['editKinds'] );
		$this->assertSame( 'parent_only', $labels['variations'] );
		$this->assertSame( '“Sample labels” is kept on the product, not on each variation.', $labels['variationNote'] );
		$this->assertSame( 'x_sample_labels', $labels['group'] );
		$this->assertSame( '{}', wp_json_encode( $labels['schema'] ) );
		$note = $this->entry( self::payload()['fields'], 'key', 'x_sample_note' );
		$this->assertSame( 'per_variation', $note['variations'] );
		$this->assertSame( '', $note['variationNote'] );
		$this->assertSame( 'organisation', $note['group'], 'A field takes the group of the first action that changes it.' );
	}

	public function test_the_parent_only_note_reads_right_for_a_plural_label(): void {
		$this->assertSame( '“Gift tags” is kept on the product, not on each variation.', \BulkCatalogueManager\External\Advertiser::parent_only( 'Gift tags' ) );
		$this->assertSame( '“Colour” is kept on the product, not on each variation.', \BulkCatalogueManager\External\Advertiser::parent_only( 'Colour' ) );
	}

	public function test_an_external_action_carries_its_descriptor_and_argument_schema(): void {
		$add = $this->entry( self::payload()['actions'], 'id', 'x_sample_add' );
		$this->assertSame( [ 'id', 'label', 'description', 'group', 'groups', 'editKinds', 'fields', 'args', 'provider', 'variations', 'available', 'unavailableReason', 'alwaysConfirm', 'confirmReason' ], array_keys( $add ) );
		$this->assertSame( 'Add labels', $add['label'] );
		$this->assertSame( 'Adds the chosen labels. Other labels are kept.', $add['description'] );
		$this->assertSame( 'x_sample_labels', $add['group'] );
		$this->assertSame( [ 'x_sample_labels' ], $add['groups'] );
		$this->assertSame( [ 'add' ], $add['editKinds'] );
		$this->assertSame( [ 'x_sample_labels' ], $add['fields'] );
		$this->assertSame( 'sample', $add['provider'] );
		$this->assertSame( 'parent_only', $add['variations'] );
		$this->assertTrue( $add['available'] );
		$this->assertSame( '', $add['unavailableReason'] );
		$this->assertFalse( $add['alwaysConfirm'] );

		$args = $add['args'];
		$this->assertSame( 'object', $args['type'] );
		$this->assertFalse( $args['additionalProperties'] );
		$this->assertSame( [ 'field', 'labels', 'skip_already' ], array_keys( $args['properties'] ), 'A parent-only field offers no Also apply to variations.' );
		$this->assertSame( [ 'x_sample_labels' ], $args['properties']['field']['enum'] );
		$this->assertTrue( $args['properties']['field']['required'] );
		$labels = $args['properties']['labels'];
		$this->assertSame( 'array', $labels['type'] );
		$this->assertSame(
			[
				'type' => 'string',
				'enum' => [ 'blue', 'green', 'red', 'sale' ],
			],
			$labels['items']
		);
		$this->assertTrue( $labels['uniqueItems'] );
		$this->assertSame( 1, $labels['minItems'] );
		$this->assertSame( 20, $labels['maxItems'] );
		$this->assertSame(
			[
				'value' => 'blue',
				'label' => 'Blue',
			],
			$labels['choices'][0]
		);
		$this->assertTrue( $labels['required'] );
		$this->assertTrue( $args['properties']['skip_already']['default'] );

		$replace = $this->entry( self::payload()['actions'], 'id', 'x_sample_replace' );
		$this->assertTrue( $replace['alwaysConfirm'] );
		$this->assertSame( 'This replaces every label on the chosen products.', $replace['confirmReason'] );
		$note = $this->entry( self::payload()['actions'], 'id', 'x_sample_set_note' );
		$this->assertSame( 'organisation', $note['group'], 'An external action may sit in a native group.' );
		$this->assertSame( [ 'field', 'note', 'apply_to_variations', 'skip_already' ], array_keys( $note['args']['properties'] ), 'A per-variation field offers Also apply to variations.' );
		$this->assertArrayNotHasKey( 'default', $note['args']['properties']['apply_to_variations'], 'Its default comes from the server, as for native actions.' );
	}

	public function test_an_unavailable_provider_lists_its_actions_disabled_with_its_reason(): void {
		update_option( 'sample_provider_faults', [ 'unavailable' => true ] );
		$payload = self::payload();
		foreach ( array_filter( $payload['actions'], static fn( array $action ): bool => isset( $action['provider'] ) ) as $action ) {
			$this->assertFalse( $action['available'] );
			$this->assertSame( 'Sample labels needs its settings saved before it can be used.', $action['unavailableReason'] );
		}
		$this->assertFalse( $payload['providers'][0]['available'] );
		$this->assertSame( 'Sample labels needs its settings saved before it can be used.', $payload['providers'][0]['reason'] );
	}

	public function test_a_user_without_the_capability_sees_the_actions_disabled(): void {
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [];
		$add = $this->entry( self::payload()['actions'], 'id', 'x_sample_add' );
		$this->assertFalse( $add['available'] );
		$this->assertSame( 'You do not have permission to use this action.', $add['unavailableReason'] );
		$this->assertTrue( self::payload()['providers'][0]['available'], 'The provider itself is available.' );
	}

	public function test_a_disabled_action_carries_only_its_label_and_reason(): void {
		// Review of T108: an action the user may not use, or whose provider is
		// unavailable, sends no choices, defaults, argument labels, rules,
		// description or confirmation text: nothing the provider supplied but
		// its label and the reason it is disabled.
		$withheld = static function ( array $action ): void {
			self::assertFalse( $action['available'] );
			self::assertSame( '', $action['description'] );
			self::assertSame( [], (array) $action['args']['properties'] );
			self::assertSame( '', $action['confirmReason'] );
			self::assertStringNotContainsString( 'Blue', (string) wp_json_encode( $action ) );
		};
		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [];
		$actions = array_values( array_filter( self::payload()['actions'], static fn( array $action ): bool => isset( $action['provider'] ) ) );
		$this->assertCount( 5, $actions );
		foreach ( $actions as $action ) {
			$withheld( $action );
			$this->assertSame( 'You do not have permission to use this action.', $action['unavailableReason'] );
		}
		$this->assertSame( 'Add labels', $this->entry( $actions, 'id', 'x_sample_add' )['label'] );

		$GLOBALS['bulkcat_test']['user_caps'][ self::USER ] = [ 'edit_products' => true ];
		update_option( 'sample_provider_faults', [ 'unavailable' => true ] );
		$actions = array_values( array_filter( self::payload()['actions'], static fn( array $action ): bool => isset( $action['provider'] ) ) );
		$this->assertCount( 5, $actions );
		foreach ( $actions as $action ) {
			$withheld( $action );
			$this->assertSame( 'Sample labels needs its settings saved before it can be used.', $action['unavailableReason'] );
		}
		$this->assertStringNotContainsString( '"Blue"', (string) wp_json_encode( self::payload() ), 'No choice label anywhere in the payload.' );
	}

	public function test_a_missing_or_refused_provider_adds_nothing(): void {
		update_option( 'sample_provider_faults', [ 'malformed' => true ] );
		$this->assertSame( ( new FieldsController( new Fields() ) )->payload(), self::payload(), 'Refused: absent, and the payload is the native one.' );
	}

	public function test_provider_strings_reach_the_browser_as_plain_text(): void {
		// E13: tags stripped, long strings trimmed, in labels, descriptions, choice labels, group labels and reasons.
		update_option(
			'sample_provider_choices',
			[
				'bold' => '<b>Bold</b><script>alert(1)</script>',
				'long' => str_repeat( 'Long ', 40 ),
			]
		);
		$json = (string) wp_json_encode( self::payload() );
		update_option( 'sample_provider_faults', [ 'unavailable' => true ] );
		$json .= (string) wp_json_encode( self::payload() );
		$this->assertStringNotContainsString( '<script>', $json );
		$this->assertStringNotContainsString( '<b>', $json );
		update_option( 'sample_provider_faults', [] );
		$add    = $this->entry( self::payload()['actions'], 'id', 'x_sample_add' );
		$labels = array_column( $add['args']['properties']['labels']['choices'], 'label', 'value' );
		$this->assertSame( 'Boldalert(1)', $labels['bold'] );
		$this->assertSame( 80, mb_strlen( $labels['long'] ) );
		$this->assertStringEndsWith( '…', $labels['long'] );
	}

	public function test_the_schema_describes_the_extended_payload(): void {
		$fields     = new Fields();
		$controller = new FieldsController( $fields, new Actions( $fields, null, Providers::of( [ new SampleProvider( new MemoryStore() ) ] ) ) );
		$schema     = $controller->get_item_schema();
		$payload    = $controller->payload();
		$this->assertSame( array_keys( $payload ), array_keys( $schema['properties'] ) );
		foreach ( [ 'fields', 'actions', 'groups', 'providers' ] as $list ) {
			$properties = $schema['properties'][ $list ]['items']['properties'];
			foreach ( $payload[ $list ] as $item ) {
				foreach ( array_keys( $item ) as $key ) {
					$this->assertArrayHasKey( $key, $properties, "$list item key $key is not in the schema." );
				}
			}
		}
		$this->assertTrue( rest_validate_value_from_schema( json_decode( (string) wp_json_encode( $payload ), true ), $schema, 'fields' ) );
		$this->assertSame( [ 'fields', 'actions', 'groups' ], array_keys( ( new FieldsController( new Fields() ) )->get_item_schema()['properties'] ), 'With no provider the schema is the native one.' );
	}
}
