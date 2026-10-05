<?php
/**
 * A single-object write as a batch of one (SPEC §12.1, §9.3, §8, §13; T37):
 * what `PATCH /bulkcat/v1/products/{id}` runs. An inline edit, a detail-panel
 * save or any other change to one product or variation goes through the
 * same operation classes, Validator, Writer and Journal as a bulk batch and
 * is recorded as its own batch, so it shows in history and undoes like one.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Availability\StatusSwitch;
use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Data\ChangeRepository;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Values;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\BaseOperation;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Rest\Access;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one object as a synchronous batch of one.
 *
 * For one request it:
 * 1. resolves the id through BatchFactory::materialise(), exactly as a
 *    batch's id list is resolved: anything that is not a listable product
 *    or variation is refused 404 `bulkcat_not_found`;
 * 2. refuses 403 `bulkcat_cannot_edit` an object the current user may not edit
 *    (`current_user_can( 'edit_post', $id )`, through Access::can_edit_object());
 * 3. turns the submitted values into an operation list, one step per field
 *    in the field registry's order (operations()): the action that sets the
 *    field (set_status, set_value, or replace_terms for a term list), or `clear` for an
 *    empty value on a nullable field that allows clearing. When two prices
 *    arrive together the regular price's step clears a sale price it would
 *    leave not below it, so the later sale price step decides the pair.
 *    Each step is validated on its own through Registry::validate_list(),
 *    so a refusal names its field (400, with the field's sentence and
 *    `data.field`);
 * 4. dry-runs the whole list through Engine\Preview on a detached copy —
 *    the same compute(), Validator and Field::write() the writer runs — and
 *    refuses 400, naming the first refused field with its sentence and
 *    listing every refused field in `data.fields`, when any step would fail
 *    or be skipped: a parent-only field on a variation, a sale price not
 *    below the regular price, a clashing SKU, stock on a product that does
 *    not manage it. Nothing is written and no batch is recorded;
 * 5. opens the batch through BatchFactory::open() — running, one pending
 *    item, never enqueued to Action Scheduler — claims the item, writes it
 *    through Writer::for_batch()->run() (which journals from inside), marks
 *    the item and the counters as the chunk runner does, and finalises the
 *    batch complete, partial or failed with finished_gmt, all before the
 *    request returns.
 *
 * A write that throws (the journal refusing a row) still finalises the batch
 * as failed and is answered 500 `bulkcat_save_failed`.
 *
 * Status emits set_status explicitly. Obsolete Keep current status intent is
 * refused with reload/review guidance; accepted legacy batches resolve through
 * their stored operation instead of this new-write entry point.
 */
final class InlineWrite {
	/**
	 * The sources a single-object write may be recorded under.
	 */
	public const SOURCES = [ 'inline', 'bulk' ];

	/**
	 * The code for a value the dry run refused, when the refusal carries none of its own.
	 */
	public const INVALID = 'bulkcat_invalid_value';

	/**
	 * The operation registry.
	 *
	 * @var Actions
	 */
	private Actions $actions;

	/**
	 * Batch creation and materialisation.
	 *
	 * @var BatchFactory
	 */
	private BatchFactory $factory;

	/**
	 * The dry run.
	 *
	 * @var Preview
	 */
	private Preview $preview;

	/**
	 * Batches and items.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $batches;

	/**
	 * The journal table.
	 *
	 * @var ChangeRepository
	 */
	private ChangeRepository $changes;

	/**
	 * Build over the shared registry and the plugin's repositories by default.
	 *
	 * @param Actions|null          $actions  Operation registry.
	 * @param BatchRepository|null  $batches  Batches and items.
	 * @param ChangeRepository|null $changes  The journal table.
	 * @param Settings|null         $settings Settings; loaded when first needed by default.
	 */
	public function __construct( ?Actions $actions = null, ?BatchRepository $batches = null, ?ChangeRepository $changes = null, private ?Settings $settings = null ) {
		$this->actions = $actions ?? Actions::instance();
		$this->batches = $batches ?? new BatchRepository();
		$this->changes = $changes ?? new ChangeRepository();
		$this->factory = new BatchFactory( $this->actions, $this->batches );
		$this->preview = new Preview( $this->actions, $this->factory );
	}

	/**
	 * Write submitted field values to one product or variation.
	 *
	 * @param int                  $id      Product or variation id.
	 * @param array<string, mixed> $values  Field key => value, for any of the registry's editable fields.
	 * @param string               $source  One of SOURCES.
	 * @param int                  $user_id The user the batch is recorded for (the current user).
	 * @param bool                 $keep_changed_status Obsolete explicit legacy intent; true is refused for new writes.
	 * @return array{batch_id: int, status: string, outcome: WriteOutcome}|\WP_Error
	 * @throws \InvalidArgumentException On a source outside SOURCES: a programming error.
	 */
	public function write( int $id, array $values, string $source, int $user_id, bool $keep_changed_status = false ): array|\WP_Error {
		$prepared = $this->preflight( $id, $values, $source, $user_id, $keep_changed_status );
		if ( is_wp_error( $prepared ) ) {
			return $prepared; }
		$batch_id = $this->factory->open( $user_id, $prepared['operations'], $prepared['objects'], $source );
		return is_wp_error( $batch_id ) ? $batch_id : $this->run( $batch_id, $id, $prepared['operations'] );
	}

	/**
	 * Live native preflight; receipt transport calls this only after the unique new claim.
	 *
	 * @param int $id Object id.
	 * @param array $values Submitted native values.
	 * @param string $source Display source.
	 * @param int $user_id Current owner.
	 * @param bool $keep_changed_status Obsolete explicit intent.
	 * @return array|\WP_Error
	 */
	public function prepare( int $id, array $values, string $source, int $user_id, bool $keep_changed_status = false ): array|\WP_Error {
		$editable = $this->actions->fields()->editable();
		foreach ( $values as $key => $value ) {
			if ( \BulkCatalogueManager\External\Providers::is_external( (string) $key ) ) {
				return \BulkCatalogueManager\External\Refusals::not_inline(); }
			if ( ! isset( $editable[ $key ] ) ) {
				return \BulkCatalogueManager\Rest\RequestsController::unknown(); }
			$valid = $editable[ $key ]->validate( $value );
			if ( is_wp_error( $valid ) ) {
				return Refusal::native_validation(
					$valid->get_error_message(),
					[ $key => $valid->get_error_message() ],
					$key
				);
			}
			$values[ $key ] = $editable[ $key ]->sanitise( $value );
		}
		return $this->preflight( $id, $values, $source, $user_id, $keep_changed_status );
	}

	/**
	 * Shared engine preflight, preserving the internal write API's validation contract.
	 *
	 * @param int $id Object id.
	 * @param array $values Native values.
	 * @param string $source Display source.
	 * @param int $user_id Current owner.
	 * @param bool $keep_changed_status Obsolete explicit intent.
	 * @return array|\WP_Error
	 */
	private function preflight( int $id, array $values, string $source, int $user_id, bool $keep_changed_status ): array|\WP_Error {
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			throw new \InvalidArgumentException( 'A single-object write is recorded as inline or bulk.' );
		}
		$objects = $id > 0 ? $this->factory->materialise( Selection::of_ids( [ $id ] ) ) : [];
		if ( [] === $objects ) {
			return self::not_found();
		}
		if ( true !== Access::can_edit_object( $id ) ) {
			return Refusal::create(
				Access::CANNOT_EDIT,
				__( 'You do not have permission to edit this product, so nothing was changed. Ask an administrator for access to it.', 'bulk-catalogue-manager-for-woocommerce' ),
				403
			);
		}

		$operations = $this->operations( $values, $keep_changed_status );
		if ( $operations instanceof \WP_Error ) {
			return $operations;
		}
		$refused = $this->dry_run( $id, $operations );
		if ( null !== $refused ) {
			return $refused;
		}

		return [
			'object_id'  => $id,
			'source'     => $source,
			'owner'      => $user_id,
			'objects'    => $objects,
			'operations' => $operations,
		];
	}

	/**
	 * Execute only in the original invocation after confirmed acceptance. No replay entry point.
	 *
	 * @param int $batch_id Committed batch.
	 * @param array $prepared Live preflight held only in this invocation.
	 * @return array|\WP_Error
	 */
	public function run_accepted( int $batch_id, array $prepared ): array|\WP_Error {
		if ( true !== Access::can_write() ) {
			throw new \RuntimeException( 'Current inline execution rights cannot be confirmed.' );
		}
		$batch = $this->batches->find_strict( $batch_id );
		if ( null === $batch || get_current_user_id() !== $batch['user_id'] || $batch['user_id'] !== $prepared['owner'] || 'running' !== $batch['status']
			|| $batch['source'] !== $prepared['source'] || $batch['operations'] !== $prepared['operations'] || [ $prepared['object_id'] ] !== $batch['selection']['ids'] || 1 !== $batch['total_items'] || ( $batch['selection']['inline_receipt']['object_id'] ?? null ) !== $prepared['object_id']
			|| ! \BulkCatalogueManager\Requests\InlineReceipt::unclosed( $batch ) ) {
			throw new \RuntimeException( 'Accepted inline identity cannot be confirmed.' );
		}
		$receipt = ( new \BulkCatalogueManager\Requests\RequestRepository() )->read( $batch['user_id'], $batch['uuid'] );
		if ( null === $receipt || 'product.inline' !== $receipt['operation'] || 'accepted' !== $receipt['state'] || 'batch' !== $receipt['result_kind'] || (int) $receipt['result_id'] !== $batch_id ) {
			throw new \RuntimeException( 'Accepted inline receipt cannot be confirmed.' );
		}
		$now   = time();
		$items = $this->batches->claim_chunk( $batch_id, 1, bin2hex( random_bytes( 16 ) ), gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now - BatchRepository::LEASE_SECONDS ) );
		global $wpdb;
		if ( '' !== $wpdb->last_error || 1 !== count( $items ) || $items[0]['object_id'] !== $prepared['object_id'] || $items[0]['object_type'] !== $prepared['objects'][0][1] || 1 !== $items[0]['attempts'] || $items[0]['item_id'] < 1 ) {
			throw new \RuntimeException( 'Exact inline item claim cannot be confirmed.' );
		}
		// Preflight may have primed this PHP process before acceptance waited.
		// Only the confirmed original executor may reload current native state.
		clean_post_cache( $prepared['object_id'] );
		$post = get_post( $prepared['object_id'] );
		if ( $post && 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			// Native variation reads also consume the current parent's post/meta/terms.
			clean_post_cache( (int) $post->post_parent );
		}
		$writer  = Writer::for_batch( $this->actions, Journal::from_settings( $this->actions->fields(), $this->settings(), new ChangeRepository( strict: true ) ), $batch_id );
		$outcome = $writer->run( static fn( Writer $w ): WriteOutcome => $w->write( $prepared['object_id'], $prepared['operations'], $items[0]['object_type'] ) );
		foreach ( $outcome->results() as $result ) {
			// Writer catches save Exceptions: they cannot prove that a save hook had no effects.
			if ( in_array( $result->code(), [ Writer::SAVE_FAILED, Writer::SAVE_UNCERTAIN ], true ) ) {
				throw new \RuntimeException( 'Inline save outcome is uncertain.' ); }
		}
		[ $state, $message ] = ChunkRunner::item_state( $outcome );
		$failed              = 'failed' === $state ? 1 : 0;
		$status              = ChunkRunner::final_status( 'done' !== $state || '' !== $message ? 1 : 0, $failed, $outcome->saved() ? 1 : 0 );
		$this->batches->close_inline(
			$batch_id,
			[
				'item_id'      => $items[0]['item_id'],
				'state'        => $state,
				'message'      => $message,
				'saved'        => $outcome->saved(),
				'write_status' => $status,
				'steps'        => \BulkCatalogueManager\Requests\InlineReceipt::safe_steps( $outcome ),
			]
		);
		return [
			'receipt_state' => 'closed',
			'batch_id'      => $batch_id,
			'write_status'  => $status,
			'steps'         => \BulkCatalogueManager\Requests\InlineReceipt::safe_steps( $outcome ),
			'replayed'      => false,
			'status'        => $status,
			'outcome'       => $outcome,
		];
	}

	/**
	 * The operation list for submitted values: one validated, normalised step
	 * per editable field present, in the registry's order, or the refusal
	 * of the first field that cannot be set, naming it.
	 *
	 * @param array<string, mixed> $values              Field key => value.
	 * @param bool                 $keep_changed_status Obsolete explicit legacy intent, refused when true.
	 * @return list<array{action: string, args: array<string, mixed>}>|\WP_Error
	 */
	public function operations( array $values, bool $keep_changed_status = false ): array|\WP_Error {
		$editable = array_intersect_key( $this->actions->fields()->editable(), $values );
		if ( [] === $editable ) {
			return $this->actions->validate_list( [] );
		}
		if ( $keep_changed_status ) {
			return Refusal::invalid(
				self::INVALID,
				__( 'Status controls have changed. Reload and review the change again.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'field' => 'keep_changed_status' ]
			);
		}
		$prices     = array_filter( $editable, static fn( Field $field ): bool => Field::MONEY === $field->type() );
		$operations = [];
		foreach ( $editable as $key => $field ) {
			$step = $this->step( $field, $values[ $key ], count( $prices ) > 1 );
			$list = null === $step ? null : $this->actions->validate_list( [ $step ] );
			if ( ! is_array( $list ) ) {
				$message = $list instanceof \WP_Error ? $list->get_error_message() : $field->rule_text();
				$code    = $list instanceof \WP_Error ? (string) $list->get_error_code() : self::INVALID;
				return Refusal::invalid( $code, $message, [ 'field' => $key ] );
			}
			$operations[] = self::this_object_only( $list[0] );
		}
		return $operations;
	}

	/**
	 * A step as the write runs it: on the one object only. BatchFactory::open()
	 * takes the object alone, so Also apply to variations is off whatever the
	 * settings' default, and the dry run (which previews through expand())
	 * checks what is written: an inline Off of a variable product is not
	 * refused for variations it never touches (T94).
	 *
	 * @param array{action: string, args: array<string, mixed>} $step A normalised step.
	 * @return array{action: string, args: array<string, mixed>}
	 */
	private static function this_object_only( array $step ): array {
		if ( array_key_exists( BaseOperation::APPLY_TO_VARIATIONS, $step['args'] ) ) {
			$step['args'][ BaseOperation::APPLY_TO_VARIATIONS ] = false;
		}
		return $step;
	}

	/**
	 * The step setting one field to one value, or null when no action sets it.
	 *
	 * @param Field $field      The field.
	 * @param mixed $value      The submitted value.
	 * @param bool  $price_pair Whether both prices arrive together.
	 * @return array{action: string, args: array<string, mixed>}|null
	 */
	private function step( Field $field, mixed $value, bool $price_pair ): ?array {
		$empty = $field->nullable() && Values::is_empty( $value );
		$kind  = $empty && $field->accepts( Field::CLEAR ) ? Field::CLEAR : Field::SET;
		$ids   = $this->actions->actions_for( $field->key(), $kind );
		if ( [] === $ids ) {
			return null;
		}
		$args = [ 'field' => $field->key() ];
		if ( Field::SET === $kind ) {
			$args += Field::TERMS === $field->type() ? [ 'terms' => $value ] : [ 'value' => $empty ? '' : $value ];
		}
		if ( Field::SET === $kind && Field::MONEY === $field->type() ) {
			$args['clear_invalid_sale'] = $price_pair;
		}
		return [
			'action' => StatusSwitch::governs( $field ) ? 'set_status' : $ids[0],
			'args'   => $args,
		];
	}

	/**
	 * Compute every step on a detached copy: null when every step would
	 * change the object or find it already right, else the 400 refusal
	 * naming the first refused field.
	 *
	 * @param int                                                     $id         The object.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations Normalised steps.
	 */
	private function dry_run( int $id, array $operations ): ?\WP_Error {
		$preview = $this->preview->run( Selection::of_ids( [ $id ] ), $operations, Preview::SAMPLE_ALL, '', $this->settings() );
		if ( $preview instanceof \WP_Error ) {
			return $preview;
		}
		if ( [] !== $preview['unreadable'] ) {
			return self::not_found();
		}
		$refused = [];
		$first   = null;
		foreach ( $preview['sample'] as $row ) {
			if ( ! in_array( $row['state'], [ Result::FAILED, Result::SKIPPED ], true ) || isset( $refused[ $row['field'] ] ) ) {
				continue;
			}
			$refused[ $row['field'] ] = $row['reason'];
			$first                  ??= $row;
		}
		if ( null === $first ) {
			return null;
		}
		$data = [
			'field'  => $first['field'],
			'fields' => $refused,
		];
		if ( '' !== (string) ( $first['note'] ?? '' ) ) {
			// The Status switch's outcome (a conflict), so the grid can offer Keep current status.
			$data['outcome'] = (string) $first['note'];
			$data['context'] = (array) ( $first['context'] ?? [] );
		}
		return Refusal::invalid(
			str_starts_with( (string) $first['code'], Refusal::PREFIX ) ? (string) $first['code'] : self::INVALID,
			(string) $first['reason'],
			$data
		);
	}

	/**
	 * Claim the batch's one item, write it, record the outcome and finalise.
	 *
	 * @param int                                                     $batch_id   The running batch.
	 * @param int                                                     $id         The object.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations Normalised steps.
	 * @return array{batch_id: int, status: string, outcome: WriteOutcome}|\WP_Error
	 */
	private function run( int $batch_id, int $id, array $operations ): array|\WP_Error {
		$now     = time();
		$claimed = $this->batches->claim_chunk( $batch_id, 1, bin2hex( random_bytes( 16 ) ), gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now - BatchRepository::LEASE_SECONDS ) );
		$item_id = (int) ( $claimed[0]['item_id'] ?? 0 );
		$writer  = Writer::for_batch( $this->actions, Journal::from_settings( $this->actions->fields(), $this->settings(), $this->changes ), $batch_id );
		try {
			$outcome = $writer->run( static fn( Writer $w ): WriteOutcome => $w->write( $id, $operations ) );
		} catch ( \Throwable $e ) {
			$reason = __( 'Something went wrong while changing this product, so it may not have been finished. Open the product to check it.', 'bulk-catalogue-manager-for-woocommerce' );
			$this->finish( $batch_id, $item_id, [ 'failed', $reason ], false );
			return Refusal::unexpected( Writer::SAVE_FAILED, $reason, 500, $e, [ 'batch_id' => $batch_id ] );
		}
		return [
			'batch_id' => $batch_id,
			'status'   => $this->finish( $batch_id, $item_id, ChunkRunner::item_state( $outcome ), $outcome->saved() ),
			'outcome'  => $outcome,
		];
	}

	/**
	 * Mark the item and the counters as the chunk runner does, then move the
	 * batch from running to its final status, stamping finished_gmt.
	 *
	 * @param int                         $batch_id The batch.
	 * @param int                         $item_id  Its claimed item, or 0 when none was claimed.
	 * @param array{0: string, 1: string} $mark     The item's state and plain message.
	 * @param bool                        $saved    Whether the object was saved.
	 * @return string The final status.
	 */
	private function finish( int $batch_id, int $item_id, array $mark, bool $saved ): string {
		[ $state, $message ] = $mark;
		if ( $item_id > 0 ) {
			$this->batches->mark_items( $batch_id, [ $item_id ], $state, $message );
		}
		$failed = 'failed' === $state ? 1 : 0;
		$this->batches->increment_counters( $batch_id, 1, $saved ? 1 : 0, $failed );
		$status = ChunkRunner::final_status( 'done' !== $state || '' !== $message ? 1 : 0, $failed, $saved ? 1 : 0 );
		$this->batches->transition( $batch_id, 'running', $status );
		return $status;
	}

	/**
	 * The settings, loaded once.
	 */
	private function settings(): Settings {
		$this->settings ??= Settings::load();
		return $this->settings;
	}

	/**
	 * The refusal of an id that is not a listable product or variation.
	 */
	private static function not_found(): \WP_Error {
		return Refusal::create(
			Writer::NOT_FOUND,
			__( 'That product could not be found. It may have been deleted; reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
			404
		);
	}
}
