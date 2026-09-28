<?php
/**
 * Receipt-first acceptance and resolution for the T81 capture foundation.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\FoundationStorage;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SnapshotRepository;
use BulkCatalogueManager\Selection\SnapshotService;
defined( 'ABSPATH' ) || exit;

/**
 * Every durable request — capture, compose, Apply and undo — resolves its
 * receipt before anything else and is accepted at most once.
 *
 * Apply and undo (T83): the receipt is read first, so an accepted request
 * answers its batch (`replayed: true`) even after its selection, its preview
 * or its grant has expired. A new request claims the receipt, and
 * BatchAcceptance records the batch header and items, and the receipt its
 * batch, in one transaction; only after it commits does the
 * `bulkcat_batch_created` action fire — once, for the request that committed
 * it, never on a replay — and BatchRecovery make sure its chunk is queued,
 * which every replay repeats, so a lost response or a crash before
 * scheduling is repaired by resolving the same request.
 */
final class RequestService {
	/**
	 * Every operation kind a grant can be issued for.
	 */
	public const OPERATIONS = [ 'selection.capture', 'selection.compose', 'batch.apply', 'batch.undo' ];

	/** Clock injection keeps retention tests deterministic without test hooks. */
	private \Closure $clock;

	/**
	 * Construct without database writes or implicit migration.
	 *
	 * @param \Closure|null $clock Current Unix time.
	 */
	public function __construct( ?\Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Issue a signed intent, never capture membership or perform a product action.
	 *
	 * @param string $operation Operation kind; capture or composition.
	 * @param array<string,mixed> $input Raw IDs/query.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function issue( string $operation, array $input ): array|\WP_Error {
		return $this->guard(
			function () use ( $operation, $input ): array|\WP_Error {
				if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
						return Refusal::invalid( 'bulkcat_invalid_operation', __( 'This operation is not available. Choose a supported catalogue action and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
				}
				$intent = $this->normalize( $operation, $input );
				if ( is_wp_error( $intent ) ) {
					return $intent; }
				return $this->signer()->issue( $operation, CaptureIntent::fingerprint( $intent ), get_current_user_id(), ( $this->clock )() ) + [
					'operation' => $operation,
					'intent'    => $intent,
				];
			},
			str_starts_with( $operation, 'batch.' )
		);
	}

	/**
	 * Capture or return its prior committed outcome.
	 *
	 * @param array<string,mixed> $request Original grant and immutable intent.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function capture( array $request ): array|\WP_Error {
		return $this->run( $request, true, 'selection.capture' );
	}

	/**
	 * Compose or replay the original committed successor.
	 *
	 * @param array<string,mixed> $request Canonical intent and current credentials.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function compose( array $request ): array|\WP_Error {
		return $this->run( $request, true, 'selection.compose' );
	}

	/**
	 * Accept an Apply, or answer the batch its receipt names.
	 *
	 * @param array<string,mixed> $request `uuid`, `request_proof` and canonical `intent`, plus `preview_hash` and, for a snapshot, its current `token`.
	 * @return array<string,mixed>|\WP_Error `{ batch_id, status, total_items, replayed }`.
	 */
	public function apply( array $request ): array|\WP_Error {
		return $this->run( $request, true, 'batch.apply' );
	}

	/**
	 * Accept an undo, or answer the undo batch its receipt names.
	 *
	 * @param array<string,mixed> $request `uuid`, `request_proof` and canonical `intent`.
	 * @return array<string,mixed>|\WP_Error `{ batch_id, status, total_items, replayed }`.
	 */
	public function undo( array $request ): array|\WP_Error {
		return $this->run( $request, true, 'batch.undo' );
	}

	/**
	 * The exact preview of a change over a snapshot or explicit ids: the
	 * scope ExactScope builds, its `scope_hash`, and a preview hash bound to
	 * the credential as sent (for a snapshot, its current authorization
	 * epoch). A preview never writes anything.
	 *
	 * @param array<string,mixed> $input `operations`, exactly one of `token` and `ids`, and optionally `sample_size`.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function preview( array $input ): array|\WP_Error {
		return $this->guard(
			function () use ( $input ): array|\WP_Error {
				if ( array_key_exists( 'token', $input ) === array_key_exists( 'ids', $input ) ) {
					throw new \InvalidArgumentException( 'Exactly one selection is required.' );
				}
				$token     = isset( $input['token'] ) && is_string( $input['token'] ) ? $input['token'] : '';
				$selection = array_key_exists( 'token', $input )
					? FoundationStorage::atomic( fn(): Selection|\WP_Error => ( new SnapshotService( $this->clock ) )->selection( $token, get_current_user_id() ) )
					: Selection::of_ids( BatchIntent::ids( $input['ids'] ) );
				if ( is_wp_error( $selection ) ) {
					return $selection;
				}
				return ( new Preview() )->run( $selection, $input['operations'] ?? null, (int) ( $input['sample_size'] ?? Preview::DEFAULT_SAMPLE ), $token, null, true );
			}
		);
	}

	/**
	 * Normalize operation-specific semantics without resolving authorization leases.
	 *
	 * @param string $operation Kind.
	 * @param array $input Raw or canonical input.
	 * @return array|\WP_Error
	 */
	private function normalize( string $operation, array $input ): array|\WP_Error {
		return match ( $operation ) {
			'selection.capture' => CaptureIntent::normalize( $input ),
			'selection.compose' => ComposeIntent::normalize( $input, get_current_user_id() ),
			default => BatchIntent::normalize( $operation, $input, get_current_user_id() ),
		};
	}

	/**
	 * Inspect, reauthorize or release with the same current rights/storage guard.
	 *
	 * @param string $operation Handle operation.
	 * @param array<string,mixed> $input Bounded input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function handle( string $operation, array $input ): array|\WP_Error {
		return $this->guard( fn(): array|\WP_Error => ( new SnapshotService( $this->clock ) )->handle( $operation, $input, get_current_user_id() ) );
	}

	/**
	 * Resolve only. Absence never licenses a new UUID for an uncertain attempt.
	 *
	 * @param array<string,mixed> $request Original grant and intent.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resolve( array $request ): array|\WP_Error {
		return $this->run( $request, false, $request['operation'] ?? 'selection.capture' );
	}

	/**
	 * Resolve authenticated receipts before acceptance expiry; claim only if new.
	 *
	 * @param array<string,mixed> $request Transport envelope.
	 * @param bool $accept Permit fresh capture/composition.
	 * @param string $operation Expected operation kind.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run( array $request, bool $accept, string $operation ): array|\WP_Error {
		$result = $this->guard(
			function () use ( $request, $accept, $operation ): array|\WP_Error {
				if ( ! is_array( $request['intent'] ?? null ) || 1 !== ( $request['intent']['version'] ?? null ) || ! is_string( $request['uuid'] ?? null ) || ! is_string( $request['request_proof'] ?? null ) || ! in_array( $operation, self::OPERATIONS, true ) || ( $request['operation'] ?? $operation ) !== $operation ) {
						throw new \InvalidArgumentException( 'Missing or unsupported request envelope.' );
				}
				$intent = $this->normalize( $operation, $request['intent'] );
				if ( is_wp_error( $intent ) ) {
					return $intent; }
				$claims = $this->signer()->verify( $request['uuid'], $request['request_proof'], $operation, CaptureIntent::fingerprint( $intent ), get_current_user_id() );
				if ( is_wp_error( $claims ) ) {
					return $claims;
				}
				$receipts = new RequestRepository();
				$row      = $receipts->read( $claims['owner'], $claims['uuid'] );
				if ( null !== $row ) {
					return $this->outcome( $row, $claims );
				}
				if ( $claims['accept_until'] <= ( $this->clock )() ) {
					return self::expired();
				}
				if ( ! $accept ) {
					return [ 'status' => 'not_accepted' ];
				}
				return FoundationStorage::atomic(
					function () use ( $receipts, $claims, $intent, $request, $operation ): array|\WP_Error {
						$row = $receipts->claim( $claims );
						if ( '' !== $row['result_id'] ) {
								return $this->outcome( $row, $claims );
						}
						if ( $claims['accept_until'] <= ( $this->clock )() ) {
							return self::expired();
						}
						if ( str_starts_with( $operation, 'batch.' ) ) {
							$result = BatchAcceptance::accept( $claims, $intent, $request, $this->clock );
							if ( is_wp_error( $result ) ) {
								return $result;
							}
							$receipts->accepted_batch( $claims, $result['batch_id'] );
							// Checked again last, just before the commit: an expired grant records nothing.
							if ( $claims['accept_until'] <= ( $this->clock )() ) {
								return self::expired();
							}
							return $result;
						}
						$service  = new SnapshotService( $this->clock );
						$snapshot = 'selection.capture' === $operation ? $service->capture( $intent, $claims['owner'], ( $this->clock )() ) : $service->compose( $intent, $request, $claims['owner'] );
						if ( is_wp_error( $snapshot ) ) {
							return $snapshot; }
						$receipts->accepted_snapshot( $claims, $snapshot['id'] );
						if ( $claims['accept_until'] <= ( $this->clock )() ) {
							return self::expired();
						}
						return ( new SnapshotService() )->envelope( $snapshot, ( $this->clock )(), false );
					}
				);
			},
			str_starts_with( $operation, 'batch.' )
		);
		if ( is_wp_error( $result ) || ! isset( $result['batch_id'] ) ) {
			return $result;
		}
		return $this->guard(
			static function () use ( $result ): array {
				// Scheduled first, so a listener that fails cannot hold the batch back.
				$finished = BatchRecovery::finish( $result );
				if ( false === $result['replayed'] ) {
					/** This action is documented in Engine\BatchFactory::create(). */
					do_action( 'bulkcat_batch_created', (int) $result['batch_id'] );
				}
				return $finished;
			}
		);
	}

	/**
	 * Read a receipt's exact outcome without querying original filters.
	 *
	 * @param array<string,mixed> $row Receipt.
	 * @param array<string,mixed> $claims Authenticated intent.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function outcome( array $row, array $claims ): array|\WP_Error {
		if ( $row['operation'] !== $claims['operation'] || $row['fingerprint'] !== $claims['fingerprint'] ) {
			return Refusal::create( 'bulkcat_request_conflict', __( 'This request belongs to a different change. Resolve the original change before starting another.', 'bulk-catalogue-manager-for-woocommerce' ), 409 );
		}
		if ( 'gone' === $row['state'] ) {
			return 'batch' === $row['result_kind'] ? BatchRecovery::gone() : SnapshotService::gone();
		}
		if ( 'batch' === $row['result_kind'] ) {
			// A failed read throws (503, retry the same request); only a batch that is truly gone is tombstoned.
			$batch = ( new BatchRepository() )->find_strict( (int) $row['result_id'] );
			if ( null === $batch ) {
				( new RequestRepository() )->batch_gone( $claims );
				return BatchRecovery::gone();
			}
			if ( $batch['user_id'] !== $claims['owner'] || $batch['uuid'] !== $claims['uuid'] ) {
				throw new \RuntimeException( 'Receipt does not match its batch.' );
			}
			return [
				'batch_id'    => $batch['batch_id'],
				'status'      => $batch['status'],
				'total_items' => $batch['total_items'],
				'replayed'    => true,
			];
		}
		if ( 'snapshot' !== $row['result_kind'] || '' === $row['result_id'] ) {
			throw new \RuntimeException( 'Stored outcome is incomplete.' );
		}
		return ( new SnapshotService() )->envelope( ( new SnapshotRepository() )->read( $row['result_id'], $claims['owner'] ), ( $this->clock )(), true );
	}

	/** Installation-bound signer; storage readiness is required first. */
	private function signer(): RequestGrant {
		return new RequestGrant( wp_salt( 'auth' ), (string) get_current_blog_id(), FoundationStorage::epoch() );
	}

	/**
	 * Authentication and fail-closed storage errors, with no SQL exposed to users.
	 *
	 * @param callable $operation Service operation.
	 * @param bool     $change    Whether it concerns an Apply or undo, whose malformed input is refused in those words.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function guard( callable $operation, bool $change = false ): array|\WP_Error {
		$access = Access::can_write();
		if ( is_wp_error( $access ) ) {
			return $access;
		}
		try {
			if ( ! FoundationStorage::ready() ) {
				throw new \RuntimeException( 'Storage needs a complete migration and transactional tables.' );
			}
			return $operation();
		} catch ( \LengthException $error ) {
			return Refusal::invalid( 'bulkcat_selection_too_large', __( 'This selection is too large. Add a filter to narrow it and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		} catch ( \InvalidArgumentException $error ) {
			return $change
				? Refusal::invalid( 'bulkcat_invalid_value', __( 'This change could not be read. Reload the page, preview the change again, then apply it.', 'bulk-catalogue-manager-for-woocommerce' ) )
				: Refusal::invalid( 'bulkcat_invalid_value', __( 'These products or filters could not be selected. Check your selection and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		} catch ( \Throwable $error ) {
			return Refusal::unexpected( 'bulkcat_request_storage', __( 'The request could not be resolved safely. Retry the same request; if this continues, ask an administrator to check catalogue storage.', 'bulk-catalogue-manager-for-woocommerce' ), 503, $error );
		}
	}

	/** Signed deadline remains enforceable even when every receipt was pruned. */
	private static function expired(): \WP_Error {
		return Refusal::create( 'bulkcat_request_expired', __( 'This request is past its acceptance deadline. Resolve the original result before starting a new change.', 'bulk-catalogue-manager-for-woocommerce' ), 410 );
	}

	/** Prune one bounded payload transaction; no current user is needed by the scheduler. */
	public function prune_selections(): int {
		if ( ! FoundationStorage::ready() ) {
			return 0;
		}
		return FoundationStorage::atomic( fn(): int => ( new SnapshotRepository() )->prune( ( $this->clock )(), new RequestRepository() ) );
	}

	/** Prune only eligible selection receipts; never accepted batch receipts. */
	public function prune_requests(): int {
		if ( ! FoundationStorage::ready() ) {
			return 0;
		}
		return FoundationStorage::atomic( fn(): int => ( new RequestRepository() )->prune( ( $this->clock )() ) );
	}
}
