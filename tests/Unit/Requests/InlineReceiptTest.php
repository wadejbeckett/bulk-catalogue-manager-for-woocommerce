<?php
/**
 * Inline acceptance exercises the real service, native preflight and Writer.
 * Storage faults are deterministic doubles, not native SQL/process proof.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Tests\Unit\Requests;

use BulkCatalogueManager\Requests\{RequestService, RequestGrant, CaptureIntent, InlineIntent, InlineReceipt, BatchRecovery};
use BulkCatalogueManager\Data\{BatchRepository, FoundationStorage};
use BulkCatalogueManager\Engine\{ChunkRunner, Progress, InlineWrite};
use BulkCatalogueManager\Tests\Unit\Engine\{InlineStore, StoredProduct};
use BulkCatalogueManager\Plugin;
use BulkCatalogueManager\Undo\Planner;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Engine/InlineStore.php';

final class InlineReceiptTest extends TestCase {
	private InlineStore $store;
	private RequestService $service;
	private int $now = 1790841600;

	protected function setUp(): void {
		bulkcat_test_reset();
		$GLOBALS['bulkcat_test']['logged_in'] = true;
		$GLOBALS['bulkcat_test']['user_id']   = 12;
		$GLOBALS['bulkcat_test']['scheduled'] = [];
		( new Plugin() )->boot();
		$this->store = new InlineStore();
		$this->store->enable_receipts();
		$GLOBALS['bulkcat_test']['caps']['publish_products'] = true;
		$GLOBALS['bulkcat_test']['products'][101]            = new StoredProduct(
			'simple',
			[
				'id'            => 101,
				'regular_price' => '20',
			]
		);
		$this->service                                       = new RequestService( fn(): int => $this->now );
	}

	private function grant( array $values = [ 'regular_price' => '21' ], string $source = 'inline' ): array {
		$grant = $this->service->issue(
			'product.inline',
			[
				'object_id' => 101,
				'source'    => $source,
				'values'    => $values,
			]
		);
		$this->assertTrue( is_array( $grant ), is_wp_error( $grant ) ? $grant->get_error_message() : '' );
		return $grant;
	}

	private function save( array $grant, array $values = [ 'regular_price' => '21' ], string $source = 'inline' ): mixed {
		return $this->service->inline( $grant, 101, $values, $source );
	}

	private function error( mixed $value, string $code ): void {
		$this->assertInstanceOf( \WP_Error::class, $value );
		$this->assertSame( $code, $value->get_error_code() );
	}

	public function test_replay_after_state_change_does_not_prepare_again(): void {
		$native = $this->hooked_product( static function (): void {} );
		$values = [ 'sale_price' => '15' ];
		$grant  = $this->grant( $values );
		$first  = $this->save( $grant, $values );
		$this->assertTrue( is_array( $first ) );
		$this->assertSame( 'closed', $first['receipt_state'] );
		$queries                                = count( $GLOBALS['wpdb']->queries );
		$native->stored->props['regular_price'] = '10';
		$this->now                             += 900000;
		$again                                  = $this->save( $grant, $values );
		$this->assertSame( $again, $this->service->resolve( $grant ) );
		$this->assertSame( $first['batch_id'], $again['batch_id'] );
		$this->assertSame( $first['steps'], $again['steps'] );
		$this->assertSame( 1, $native->stored->saves );
		$this->assertSame( [], array_values( array_filter( array_slice( $GLOBALS['wpdb']->queries, $queries ), static fn( string $q ): bool => str_contains( $q, '`wp_posts`' ) ) ), 'No materialisation or dry-run on replay.' );
		$this->error( $this->save( $grant, [ 'sale_price' => '14' ] ), 'bulkcat_request_conflict' );
		$this->assertSame( $again, $this->service->resolve( $grant ) );
		$this->assertSame( 1, $native->counts->prepares );
		$this->assertSame( 1, $native->counts->hooks );
		$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
		$this->assertSame( 0, did_action( 'bulkcat_batch_created' ) );
	}

	public function test_issuance_is_pure_and_a_committed_refusal_is_not_revalidated(): void {
		$grant = $this->grant( [ 'sale_price' => '30' ] );
		$this->assertSame( [], $this->store->receipts );
		$this->assertSame( [], $GLOBALS['wpdb']->writes );
		$bad = $this->save( $grant, [ 'sale_price' => '30' ] );
		$this->error( $bad, 'bulkcat_invalid_value' );
		$this->assertSame( 'refused', $bad->get_error_data()['receipt_state'] );
		$this->assertSame( 'sale_price', $bad->get_error_data()['field'] );
		$GLOBALS['bulkcat_test']['products'][101]->props['regular_price'] = '100';
		$this->assertSame(
			[
				'receipt_state' => 'refused',
				'replayed'      => true,
			],
			$this->save( $grant, [ 'sale_price' => '30' ] )
		);
		$row = $this->store->receipts[ $grant['uuid'] ];
		$this->assertSame( 'validation', $row['result_id'] );
		$this->assertSame( 'inline_refusal', $row['result_kind'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 8 * 86400 ), $row['retain_until'] );
		$this->assertSame( [], $this->store->batches );
	}

	public function test_versions_are_opaque_for_resolve_but_not_for_resend(): void {
		foreach ( [
			'payload_version'   => 99,
			'registry_revision' => str_repeat( 'a', 64 ),
		] as $key => $value ) {
			$grant          = $this->grant();
			$first          = $this->save( $grant );
			$intent         = $grant['intent'];
			$intent[ $key ] = $value;
			// Model a genuine grant issued under earlier supported semantics.
			$signer                                = new RequestGrant( wp_salt( 'auth' ), (string) get_current_blog_id(), FoundationStorage::epoch() );
			$old                                   = $signer->issue( 'product.inline', CaptureIntent::fingerprint( $intent ), 12, $this->now );
			$old                                  += [
				'intent'    => $intent,
				'operation' => 'product.inline',
			];
			$this->store->receipts[ $old['uuid'] ] = array_replace(
				$this->store->receipts[ $grant['uuid'] ],
				[
					'uuid'        => $old['uuid'],
					'fingerprint' => CaptureIntent::fingerprint( $intent ),
				]
			);
			$this->store->batches[ $first['batch_id'] ]['uuid'] = $old['uuid'];
			$this->error( $this->save( $old ), 'bulkcat_inline_version' );
			$this->assertSame( 'closed', $this->service->resolve( $old )['receipt_state'] );
		}
	}

	public function test_ambiguous_acceptance_never_executes_or_allows_takeover(): void {
		foreach ( [ 'inline', 'bulk' ] as $source ) {
			$this->setUp();
			$grant               = $this->grant( [ 'regular_price' => '21' ], $source );
			$this->store->faults = [ 'commit_after:1' ];
			$this->error( $this->save( $grant, [ 'regular_price' => '21' ], $source ), 'bulkcat_request_storage' );
			$receipt = $this->service->resolve( $grant );
			$this->assertSame( 'unclosed', $receipt['receipt_state'] );
			$id = $receipt['batch_id'];
			$this->assertSame( $receipt, $this->save( $grant, [ 'regular_price' => '21' ], $source ) );
			$this->assertFalse( ChunkRunner::ensure_queued( $id ) );
			$this->assertSame( 'stopped', ( new ChunkRunner() )->run( $id ) );
			BatchRecovery::finish(
				[
					'batch_id' => $id,
					'status'   => 'running',
				]
			);
			$this->assertSame( 'unclosed', ( new Progress() )->cancel( $id ) );
			$this->assertInstanceOf( \WP_Error::class, ( new Planner() )->plan( $id ) );
			$report = ( new Progress() )->report( $id );
			$this->assertFalse( $report['stalled'] );
			$this->assertFalse( $report['cancellable'] );
			$this->assertSame( 'unclosed', $report['inline_receipt']['receipt_state'] );
			$this->assertSame( 0, $GLOBALS['bulkcat_test']['products'][101]->saves );
			$this->assertSame( 0, array_values( $this->store->items )[0]['attempts'] );
			$this->assertSame( [], $GLOBALS['bulkcat_test']['scheduled'] );
		}
	}

	public function test_faults_before_confirmed_acceptance_have_zero_effects(): void {
		foreach ( [ 'receipt', 'header', 'header_zero', 'item', 'accept', 'commit_before:1' ] as $fault ) {
			$this->setUp();
			$grant               = $this->grant();
			$this->store->faults = [ $fault ];
			if ( 'header_zero' === $fault ) {
				$GLOBALS['wpdb']->write_results['insert:wp_bulkcat_batches'] = 0; }
			if ( 'header' === $fault ) {
				$GLOBALS['wpdb']->failing[] = 'insert:wp_bulkcat_batches'; }
			if ( 'item' === $fault ) {
				$GLOBALS['wpdb']->responses['/^INSERT INTO `wp_bulkcat_batch_items`/'] = false; }
			$this->error( $this->save( $grant ), 'bulkcat_request_storage' );
			$this->assertSame( 0, $GLOBALS['bulkcat_test']['products'][101]->saves, $fault );
			$this->assertSame( [], $this->store->receipts, $fault );
			$this->assertSame( [], $this->store->batches, $fault );
		}
	}

	public function test_closure_commit_loss_is_adopted_only_when_it_committed(): void {
		foreach ( [
			'before' => 'unclosed',
			'after'  => 'closed',
		] as $when => $state ) {
			$this->setUp();
			$grant               = $this->grant();
			$this->store->faults = [ 'commit_' . $when . ':2' ];
			$this->error( $this->save( $grant ), 'bulkcat_request_storage' );
			$receipt = $this->service->resolve( $grant );
			$this->assertSame( $state, $receipt['receipt_state'] );
			$this->assertSame( $receipt, $this->save( $grant ) );
			$this->assertSame( 1, $GLOBALS['bulkcat_test']['products'][101]->saves );
		}
	}

	public function test_receipt_is_immutable_after_later_undo_and_redacted(): void {
		$grant  = $this->grant( [ 'name' => 'PRIVATE CONTENT' ] );
		$first  = $this->save( $grant, [ 'name' => 'PRIVATE CONTENT' ] );
		$before = $this->service->resolve( $grant );
		( new BatchRepository() )->transition( $first['batch_id'], 'complete', 'undone' );
		$this->assertSame( $before, $this->service->resolve( $grant ) );
		$this->assertStringNotContainsString( 'PRIVATE CONTENT', wp_json_encode( [ $grant, $before ] ) );
		$this->assertSame( [ 'field', 'state', 'code' ], array_keys( $before['steps'][0] ) );
		$this->assertSame( 'complete', $before['write_status'] );
	}

	public function test_missing_receipt_with_surviving_header_cannot_recreate_or_fence(): void {
		$grant = $this->grant();
		$this->save( $grant );
		$this->store->receipts = [];
		$this->error( $this->save( $grant ), 'bulkcat_legacy_request' );
		$this->assertSame( [], $this->store->receipts );
		$this->assertCount( 1, $this->store->batches );
		$this->assertSame( 1, $GLOBALS['bulkcat_test']['products'][101]->saves );
	}

	public function test_invisible_acceptance_at_commit_barrier_is_not_a_no_effect_fence(): void {
		$grant                      = $this->grant();
		$this->store->before_commit = function ( int $commit ) use ( $grant ): void {
			if ( 1 !== $commit ) {
				return; }
			$this->store->committed_view(
				function () use ( $grant ): void {
					$this->assertSame(
						[
							'receipt_state' => 'not_accepted',
							'replayed'      => false,
						],
						$this->service->resolve( $grant )
					);
					$this->now = $grant['accept_until'] + 1;
					$this->error( $this->service->resolve( $grant ), 'bulkcat_request_expired' );
					$this->assertSame( [], $this->store->receipts );
					$this->assertSame( 0, $GLOBALS['bulkcat_test']['products'][101]->saves );
				}
			);
		};
		$first                      = $this->save( $grant );
		$this->assertSame( 'closed', $first['receipt_state'] );
		$this->assertSame( $first['batch_id'], $this->service->resolve( $grant )['batch_id'] );
		$this->assertSame( 1, $GLOBALS['bulkcat_test']['products'][101]->saves );
	}

	public function test_changed_payload_and_disclosure_denial_never_fence(): void {
		$grant = $this->grant();
		$this->error( $this->save( $grant, [ 'regular_price' => 21 ] ), 'bulkcat_request_conflict' );
		$this->error( $this->save( $grant, [] ), 'bulkcat_request_conflict' );
		$GLOBALS['bulkcat_test']['caps']['edit_post:101'] = false;
		$this->error( $this->service->resolve( $grant ), 'bulkcat_cannot_edit' );
		$this->assertSame( [], $this->store->receipts );
		$GLOBALS['bulkcat_test']['caps']['edit_post:101'] = true;
		$this->assertSame( 'closed', $this->save( $grant )['receipt_state'] );
	}

	public function test_registry_revision_and_pure_encoding_are_pinned(): void {
		$this->assertTrue( class_exists( InlineIntent::class ) );
		$this->assertSame( hash( 'sha256', "bulkcat/native-inline-write/1\npayload=1;actions=native-single-object-v1;status=set_status;variations=off;keep=false\n" ), InlineIntent::registry_revision() );
		$a = InlineIntent::normalize_payload(
			[
				'object_id' => 101,
				'source'    => 'inline',
				'values'    => [
					'sku'           => 's',
					'regular_price' => 21.0,
				],
			],
			1
		);
		$b = InlineIntent::normalize_payload(
			[
				'values'              => [
					'regular_price' => 21.0,
					'sku'           => 's',
				],
				'source'              => 'inline',
				'object_id'           => 101,
				'keep_changed_status' => false,
			],
			1
		);
		$this->assertSame( $a, $b );
		$this->assertFalse( $a['keep_changed_status'] );
		$this->assertSame( 21.0, $a['values']['regular_price'] );
	}
	public function test_zero_row_journal_acknowledgement_never_closes(): void {
		foreach ( [ 'insert', 'update' ] as $method ) {
			$this->setUp();
			$grant = $this->grant();
			$GLOBALS['wpdb']->write_results[ $method . ':wp_bulkcat_changes' ] = 0;
			$this->error( $this->save( $grant ), 'bulkcat_request_storage' );
			$this->assertSame( 'unclosed', $this->service->resolve( $grant )['receipt_state'] );
			$this->assertSame( 'insert' === $method ? 0 : 1, $GLOBALS['bulkcat_test']['products'][101]->saves );
		}
	}

	public function test_empty_or_mismatched_claim_has_no_effect_and_cannot_be_replayed(): void {
		foreach ( [
			[],
			[
				[
					'item_id'     => '1',
					'object_id'   => '999',
					'object_type' => 'product',
					'attempts'    => '1',
				],
			],
		] as $items ) {
			$this->setUp();
			$grant = $this->grant();
			$GLOBALS['wpdb']->responses['/^SELECT item_id, object_id, object_type, attempts FROM `wp_bulkcat_batch_items`/'] = $items;
			$this->error( $this->save( $grant ), 'bulkcat_request_storage' );
			$this->assertSame( 'unclosed', $this->save( $grant )['receipt_state'] );
			$this->assertSame( 0, $GLOBALS['bulkcat_test']['products'][101]->saves );
		}
	}

	public function test_refusal_commit_failure_cannot_report_a_terminal_refusal(): void {
		foreach ( [
			'before' => 'not_accepted',
			'after'  => 'refused',
		] as $when => $state ) {
			$this->setUp();
			$values              = [ 'sale_price' => '30' ];
			$grant               = $this->grant( $values );
			$this->store->faults = [ 'commit_' . $when . ':1' ];
			$this->error( $this->save( $grant, $values ), 'bulkcat_request_storage' );
			$this->assertSame( $state, $this->service->resolve( $grant )['receipt_state'] );
			$this->assertSame( 0, $GLOBALS['bulkcat_test']['products'][101]->saves );
		}
	}

	public function test_claim_rechecks_the_committed_winner_before_prepare(): void {
		$grant = $this->grant( [ 'sale_price' => '15' ] );
		$this->save( $grant, [ 'sale_price' => '15' ] );
		$closed                                 = $this->service->resolve( $grant );
		$pattern                                = '/^SELECT \\* FROM `wp_bulkcat_requests`/';
		$read                                   = $GLOBALS['wpdb']->responses[ $pattern ];
		$reads                                  = 0;
		$GLOBALS['wpdb']->responses[ $pattern ] = static function ( string $sql ) use ( &$reads, $read ): ?array {
			return 1 === ++$reads ? null : $read( $sql );
		};
		$GLOBALS['bulkcat_test']['products'][101]->props['regular_price'] = '10';
		$this->assertSame( $closed, $this->save( $grant, [ 'sale_price' => '15' ] ) );
		$this->assertSame( 1, $GLOBALS['bulkcat_test']['products'][101]->saves );
	}

	public function test_execution_permission_refusal_is_fenced_but_old_action_permission_is_not_disclosure(): void {
		$grant = $this->grant( [ 'status' => 'publish' ] );
		$GLOBALS['bulkcat_test']['caps']['publish_products'] = false;
		// A draft requires publication permission; issuance never performed that dry-run.
		$GLOBALS['bulkcat_test']['products'][101]->props['status'] = 'draft';
		$refused = $this->save( $grant, [ 'status' => 'publish' ] );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'refused', $refused->get_error_data()['receipt_state'] );
		$this->assertSame( 'permission', $this->store->receipts[ $grant['uuid'] ]['result_id'] );
		$this->setUp();
		$grant = $this->grant();
		$this->save( $grant );
		$GLOBALS['bulkcat_test']['caps']['publish_products'] = false;
		$GLOBALS['bulkcat_test']['caps']['edit_products']    = false;
		$this->assertSame( 'closed', $this->save( $grant )['receipt_state'] );
		$this->assertSame( 'closed', $this->service->resolve( $grant )['receipt_state'] );
	}

	public function test_expired_missing_invalid_proof_owner_and_epoch_do_not_execute(): void {
		$grant                     = $this->grant();
		$invalid                   = $grant;
		$invalid['request_proof'] .= 'a';
		$this->error( $this->save( $invalid ), 'bulkcat_invalid_request' );
		$GLOBALS['bulkcat_test']['user_id'] = 13;
		$this->error( $this->service->resolve( $grant ), 'bulkcat_invalid_request' );
		$GLOBALS['bulkcat_test']['user_id'] = 12;
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'f', 64 ) );
		$this->error( $this->service->resolve( $grant ), 'bulkcat_invalid_request' );
		update_option( FoundationStorage::EPOCH_OPTION, str_repeat( 'e', 64 ) );
		$this->now = $grant['accept_until'];
		$this->error( $this->save( $grant ), 'bulkcat_request_expired' );
		$this->assertSame( [], $this->store->receipts );
		$this->assertSame( 0, $GLOBALS['bulkcat_test']['products'][101]->saves );
	}

	public function test_all_closure_bookkeeping_failures_remain_unclosed(): void {
		foreach ( [ 'mark', 'counters', 'header', 'journal' ] as $fault ) {
			$this->setUp();
			$grant                      = $this->grant();
			$this->store->before_commit = function ( int $n ) use ( $fault ): void {
				if ( 1 !== $n ) {
					return; }
				if ( 'mark' === $fault ) {
					$GLOBALS['wpdb']->responses["/^UPDATE `wp_bulkcat_batch_items` SET state = '\\w+', message = .* AND item_id IN /s"] = 0; }
				if ( 'counters' === $fault ) {
					$GLOBALS['wpdb']->responses['/^UPDATE `wp_bulkcat_batches` SET done_items = /'] = 0; }
				if ( 'header_zero' === $fault ) {
					$GLOBALS['wpdb']->write_results['insert:wp_bulkcat_batches'] = 0; }
				if ( 'header' === $fault ) {
					$GLOBALS['wpdb']->failing[] = 'update:wp_bulkcat_batches:selection'; }
				if ( 'journal' === $fault ) {
					$GLOBALS['wpdb']->failing[] = 'update:wp_bulkcat_changes:result'; }
			};
			$this->error( $this->save( $grant ), 'bulkcat_request_storage' );
			$this->assertSame( 'unclosed', $this->service->resolve( $grant )['receipt_state'] );
			$this->assertSame( 1, $GLOBALS['bulkcat_test']['products'][101]->saves, $fault );
		}
	}

	public function test_public_steps_remove_internal_fields_and_arbitrary_codes_values_and_context(): void {
		$results = [
			\BulkCatalogueManager\Operations\Result::failed( 'name', 'private', 'private reason', 'bulkcat_private_payload' ),
			\BulkCatalogueManager\Operations\Result::already( 'publication_password', 'SECRET' ),
		];
		$outcome = new \BulkCatalogueManager\Engine\WriteOutcome( 101, $results, false );
		$this->assertSame(
			[
				[
					'field' => 'name',
					'state' => 'failed',
					'code'  => '',
				],
			],
			InlineReceipt::safe_steps( $outcome )
		);
	}

	/** A native save boundary double, including detached dry-run clones. */
	private function hooked_product( \Closure $hook ): object {
		$stored                                   = $GLOBALS['bulkcat_test']['products'][101];
		$counts                                   = (object) [
			'prepares' => 0,
			'hooks'    => 0,
		];
		$GLOBALS['bulkcat_test']['products'][101] = new class( $stored, $hook, $counts ) {
			private StoredProduct $pristine;
			public function __construct( public StoredProduct $stored, private \Closure $hook, public object $counts ) {
				$this->pristine = clone $stored; }
			public function __clone() {
				$this->stored = clone $this->stored;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Test-only counter distinguishes the detached dry-run from Writer's pre-save clone.
				if ( \BulkCatalogueManager\Engine\Preview::class === ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1]['class'] ?? null ) ) {
					++$this->counts->prepares; } }
			public function save(): int {
				++$this->counts->hooks;
				try {
					( $this->hook )();
				} catch ( \Throwable $e ) {
					// The hook threw before anything was stored: a fresh load finds the product as it was.
					$GLOBALS['bulkcat_test']['products'][101] = $this->pristine;
					throw $e;
				}
				return $this->stored->save(); }
			public function __call( string $method, array $args ): mixed {
				return $this->stored->$method( ...$args ); }
		};
		return $GLOBALS['bulkcat_test']['products'][101];
	}

	public function test_paused_original_is_not_taken_over_and_can_complete_late(): void {
		$grant   = $this->grant();
		$product = $this->hooked_product(
			function () use ( $grant ): void {
				$this->now = $grant['accept_until'] + 90000;
				$receipt   = $this->service->resolve( $grant );
				$this->assertSame( 'unclosed', $receipt['receipt_state'] );
				$this->assertSame( $receipt, $this->save( $grant ) );
				$this->assertFalse( ChunkRunner::ensure_queued( $receipt['batch_id'] ) );
				$this->assertSame( 'unclosed', ( new Progress() )->cancel( $receipt['batch_id'] ) );
			}
		);
		$this->assertSame( 'closed', $this->save( $grant )['receipt_state'] );
		$this->assertSame( 'closed', $this->service->resolve( $grant )['receipt_state'] );
		$this->assertSame( 1, $product->counts->hooks );
		$this->assertSame( 1, $product->counts->prepares );
		$this->assertSame( 1, $product->stored->saves );
	}

	public function test_throwable_and_caught_save_exception_never_close_or_log_payloads(): void {
		foreach ( [ \Error::class, \RuntimeException::class ] as $type ) {
			$this->setUp();
			$grant   = $this->grant();
			$product = $this->hooked_product(
				static function () use ( $type ): void {
					throw new $type( 'SECRET VALUE IN SAVE HOOK' );
				}
			);
			$this->error( $this->save( $grant ), 'bulkcat_request_storage' );
			$this->assertSame( 'unclosed', $this->save( $grant )['receipt_state'] );
			$this->assertSame( 1, $product->counts->hooks );
			$this->assertSame( 1, $product->counts->prepares );
			$this->assertStringNotContainsString( 'SECRET VALUE', wp_json_encode( $GLOBALS['bulkcat_test']['logged'] ?? [] ) );
		}
	}

	public function test_variation_parent_disclosure_denial_does_not_issue_or_fence(): void {
		$GLOBALS['bulkcat_test']['caps']['edit_post:103'] = false;
		$result = $this->service->issue(
			'product.inline',
			[
				'object_id' => 201,
				'source'    => 'inline',
				'values'    => [ 'regular_price' => '5' ],
			]
		);
		$this->error( $result, 'bulkcat_cannot_edit' );
		$this->assertSame( [], $this->store->receipts );
	}

	public function test_invalid_issuance_is_pure_and_type_array_order_and_secret_binding_are_distinct(): void {
		$this->error(
			$this->service->issue(
				'product.inline',
				[
					'object_id' => 101,
					'source'    => 'inline',
					'values'    => [],
				]
			),
			'bulkcat_request_conflict'
		);
		$one = $this->grant( [ 'name' => 'SECRET ONE' ] );
		$two = $this->grant( [ 'name' => 'SECRET TWO' ] );
		$this->assertNotSame( $one['intent']['commitment'], $two['intent']['commitment'] );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( [ $one, $two ] ) );
		$values = [ 'category_ids' => [ 1, 2 ] ];
		$grant  = $this->grant( $values );
		$this->error( $this->save( $grant, [ 'category_ids' => [ 2, 1 ] ] ), 'bulkcat_request_conflict' );
		$this->assertSame( [], $this->store->receipts );
	}

	public function test_history_receipt_disclosure_requires_owner_and_current_object_rights(): void {
		$grant    = $this->grant();
		$first    = $this->save( $grant );
		$progress = new Progress();
		$this->assertSame( 'closed', $progress->report( $first['batch_id'] )['inline_receipt']['receipt_state'] );
		$GLOBALS['bulkcat_test']['user_id'] = 13;
		$this->assertArrayNotHasKey( 'inline_receipt', $progress->report( $first['batch_id'] ) );
		$GLOBALS['bulkcat_test']['user_id']               = 12;
		$GLOBALS['bulkcat_test']['caps']['edit_post:101'] = false;
		$this->assertArrayNotHasKey( 'inline_receipt', $progress->report( $first['batch_id'] ) );
	}
	public function test_execution_rights_removed_after_acceptance_prevent_writer_effects(): void {
		$grant                      = $this->grant();
		$this->store->before_commit = static function ( int $commit ): void {
			if ( 1 === $commit ) {
				$GLOBALS['bulkcat_test']['caps']['edit_products'] = false; }
		};
		$this->error( $this->save( $grant ), 'bulkcat_request_storage' );
		$this->assertSame( 'unclosed', $this->service->resolve( $grant )['receipt_state'] );
		$this->assertSame( 0, $GLOBALS['bulkcat_test']['products'][101]->saves );
	}

	public function test_explicit_null_keep_is_not_the_omitted_boolean_default(): void {
		$this->error(
			InlineIntent::normalize_payload(
				[
					'object_id'           => 101,
					'source'              => 'inline',
					'values'              => [ 'name' => 'value' ],
					'keep_changed_status' => null,
				],
				1
			),
			'bulkcat_request_conflict'
		);
	}
}
