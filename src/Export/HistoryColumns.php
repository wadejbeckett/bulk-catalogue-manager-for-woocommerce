<?php
/**
 * The columns of the history exports (SPEC §4.6, §10; T58): a batch's
 * changes (`changes`), the audit log (`audit`) and the history list
 * (`batches`). Each source has its columns, key => plain-English header in
 * the default order, and turns one row of its route (the changes feed, the
 * audit log or the history list, exactly as they answer) into the cells the
 * CSV writes: results, outcomes, object kinds, reversibility and action ids
 * become their labels, and nested values a line of text. Keys are the
 * routes' own row properties, so the screen names the visible columns the
 * same way it reads them.
 *
 * Times are written as stored, in UTC, and the headers say so.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

use BulkCatalogueManager\Engine\Reversibility;
use BulkCatalogueManager\Operations\Registry as Actions;

defined( 'ABSPATH' ) || exit;

/**
 * History export columns.
 */
final class HistoryColumns {
	/**
	 * A batch's changes.
	 */
	public const CHANGES = 'changes';

	/**
	 * The audit log.
	 */
	public const AUDIT = 'audit';

	/**
	 * The history list.
	 */
	public const BATCHES = 'batches';

	/**
	 * Every history source.
	 */
	public const SOURCES = [ self::CHANGES, self::AUDIT, self::BATCHES ];

	/**
	 * The outcome label's key for a partial batch with nothing failed (1.3.4); never a stored status.
	 */
	private const PARTIAL_SKIPPED = 'partial_skipped';

	/**
	 * The outcome label of a change undone and then made again by a Redo
	 * (an undone row with `redone_by`), not a stored status.
	 */
	private const REDONE = 'redone';

	/**
	 * Column keys per source, in the default order.
	 */
	public const KEYS = [
		self::CHANGES => [ 'applied_gmt', 'name', 'object_id', 'object_type', 'field_label', 'old_value', 'new_value', 'result', 'message' ],
		self::AUDIT   => [ 'applied_gmt', 'batch_id', 'user_name', 'name', 'object_id', 'object_type', 'field_label', 'old_value', 'new_value', 'result', 'message' ],
		self::BATCHES => [ 'batch_id', 'created_gmt', 'finished_gmt', 'user_name', 'actions', 'status', 'total_items', 'changed_items', 'failed_items', 'variations_included', 'reversibility', 'undo', 'undone_by', 'redone_by' ],
	];

	/**
	 * Every column key any history source has, each once.
	 *
	 * @return list<string>
	 */
	public static function all_keys(): array {
		return array_values( array_unique( array_merge( ...array_values( self::KEYS ) ) ) );
	}

	/**
	 * A source's columns: key => header, in the default order.
	 *
	 * @param string $source One of SOURCES.
	 * @return array<string, string>
	 * @throws \InvalidArgumentException On an unknown source.
	 */
	public static function labels( string $source ): array {
		if ( ! isset( self::KEYS[ $source ] ) ) {
			throw new \InvalidArgumentException( 'Unknown history export source.' );
		}
		$headers = [
			'applied_gmt'         => __( 'When (UTC)', 'bulk-catalogue-manager-for-woocommerce' ),
			'created_gmt'         => __( 'When (UTC)', 'bulk-catalogue-manager-for-woocommerce' ),
			'finished_gmt'        => __( 'Finished (UTC)', 'bulk-catalogue-manager-for-woocommerce' ),
			'batch_id'            => __( 'Change number', 'bulk-catalogue-manager-for-woocommerce' ),
			'user_name'           => __( 'Who', 'bulk-catalogue-manager-for-woocommerce' ),
			'name'                => __( 'Product', 'bulk-catalogue-manager-for-woocommerce' ),
			'object_id'           => __( 'ID', 'bulk-catalogue-manager-for-woocommerce' ),
			'object_type'         => __( 'Kind', 'bulk-catalogue-manager-for-woocommerce' ),
			'field_label'         => __( 'Field', 'bulk-catalogue-manager-for-woocommerce' ),
			'old_value'           => __( 'Old value', 'bulk-catalogue-manager-for-woocommerce' ),
			'new_value'           => __( 'New value', 'bulk-catalogue-manager-for-woocommerce' ),
			'result'              => __( 'Result', 'bulk-catalogue-manager-for-woocommerce' ),
			'message'             => __( 'Reason', 'bulk-catalogue-manager-for-woocommerce' ),
			'actions'             => __( 'What', 'bulk-catalogue-manager-for-woocommerce' ),
			'status'              => __( 'Outcome', 'bulk-catalogue-manager-for-woocommerce' ),
			'total_items'         => __( 'Rows', 'bulk-catalogue-manager-for-woocommerce' ),
			'changed_items'       => __( 'Changed', 'bulk-catalogue-manager-for-woocommerce' ),
			'failed_items'        => __( 'Failed', 'bulk-catalogue-manager-for-woocommerce' ),
			'variations_included' => __( 'Variations included', 'bulk-catalogue-manager-for-woocommerce' ),
			'reversibility'       => __( 'Can be undone', 'bulk-catalogue-manager-for-woocommerce' ),
			'undo'                => __( 'Undo of', 'bulk-catalogue-manager-for-woocommerce' ),
			'undone_by'           => __( 'Put back by', 'bulk-catalogue-manager-for-woocommerce' ),
			'redone_by'           => __( 'Done again by', 'bulk-catalogue-manager-for-woocommerce' ),
		];
		$labels  = [];
		foreach ( self::KEYS[ $source ] as $key ) {
			$labels[ $key ] = $headers[ $key ];
		}
		return $labels;
	}

	/**
	 * One route row as the cells the CSV writes, keyed by column.
	 *
	 * @param string               $source One of SOURCES.
	 * @param array<string, mixed> $row    A row as its route answers it.
	 * @return array<string, mixed>
	 */
	public static function cells( string $source, array $row ): array {
		$cells = [];
		foreach ( self::KEYS[ $source ] ?? [] as $key ) {
			$cells[ $key ] = self::cell( $key, $row[ $key ] ?? null );
		}
		if ( isset( $cells['status'] ) && 'partial' === ( $row['status'] ?? null ) && ! Reversibility::with_errors( $row ) ) {
			// 1.3.4: rows skipped by design are not errors; History says the same.
			$cells['status'] = self::map( 'status' )[ self::PARTIAL_SKIPPED ];
		}
		if ( isset( $cells['status'] ) && 'undone' === ( $row['status'] ?? null ) && is_array( $row['redone_by'] ?? null ) ) {
			// 1.4.2: undone, then made again by a Redo; History says the same.
			$cells['status'] = self::map( 'status' )[ self::REDONE ];
		}
		if ( isset( $cells['actions'] ) && is_array( $row['external']['actions'] ?? null ) ) {
			// T108: an add-on's action by the label its manifest kept, even after the add-on has gone.
			$labels           = $row['external']['actions'];
			$cells['actions'] = array_map( static fn( mixed $label, mixed $id ): mixed => $labels[ $id ] ?? $label, $cells['actions'], (array) ( $row['actions'] ?? [] ) );
		}
		return $cells;
	}

	/**
	 * A dated download name, such as `change-418-2026-09-23.csv`.
	 *
	 * @param string             $source   One of SOURCES.
	 * @param \DateTimeInterface $now      The site's current time.
	 * @param int                $batch_id For a batch's changes, the batch.
	 */
	public static function filename( string $source, \DateTimeInterface $now, int $batch_id = 0 ): string {
		$stem = match ( $source ) {
			self::CHANGES => 'change-' . $batch_id,
			self::AUDIT   => 'audit-log',
			default       => 'change-history',
		};
		return $stem . '-' . $now->format( 'Y-m-d' ) . '.csv';
	}

	/**
	 * One cell's value.
	 *
	 * @param string $key   Column.
	 * @param mixed  $value The row's value.
	 */
	private static function cell( string $key, mixed $value ): mixed {
		switch ( $key ) {
			case 'result':
				return self::choice( $value, self::map( 'result' ) );
			case 'object_type':
				return self::choice( $value, self::map( 'object_type' ) );
			case 'status':
				return self::choice( $value, self::map( 'status' ) );
			case 'reversibility':
				return self::choice( is_array( $value ) ? ( $value['state'] ?? null ) : $value, self::map( 'reversibility' ) );
			case 'actions':
				return array_map( [ self::class, 'action' ], is_array( $value ) ? $value : [] );
			case 'undo':
				return is_array( $value ) ? (string) ( $value['label'] ?? '' ) : '';
			case 'undone_by':
			case 'redone_by':
				if ( ! is_array( $value ) ) {
					return '';
				}
				if ( empty( $value['finished_gmt'] ) ) {
					return sprintf(
						/* translators: 1: the number of the undo or redo, 2: the name of the user who ran it. */
						__( '#%1$d by %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
						(int) ( $value['batch_id'] ?? 0 ),
						(string) ( $value['user_name'] ?? '' )
					);
				}
				return sprintf(
					/* translators: 1: the number of the undo or redo, 2: the name of the user who ran it, 3: when it finished, in UTC, such as "2026-10-06 14:05:00". */
					__( '#%1$d by %2$s on %3$s UTC', 'bulk-catalogue-manager-for-woocommerce' ),
					(int) ( $value['batch_id'] ?? 0 ),
					(string) ( $value['user_name'] ?? '' ),
					(string) $value['finished_gmt']
				);
			default:
				return $value;
		}
	}

	/**
	 * The labels of one column's values, built once per request: a history
	 * export writes thousands of rows, so no label is translated per cell.
	 *
	 * @param string $column result, object_type, status or reversibility.
	 * @return array<string, string>
	 */
	private static function map( string $column ): array {
		static $maps = null;
		if ( null === $maps ) {
			$maps = [
				'result'        => [
					'applied' => __( 'Applied', 'bulk-catalogue-manager-for-woocommerce' ),
					'skipped' => __( 'Skipped', 'bulk-catalogue-manager-for-woocommerce' ),
					'failed'  => __( 'Failed', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				'object_type'   => [
					'product'     => __( 'Product', 'bulk-catalogue-manager-for-woocommerce' ),
					'variation'   => __( 'Variation', 'bulk-catalogue-manager-for-woocommerce' ),
					'term'        => __( 'Category', 'bulk-catalogue-manager-for-woocommerce' ),
					'product_cat' => __( 'Category', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				'status'        => [
					'queued'              => __( 'Waiting to start', 'bulk-catalogue-manager-for-woocommerce' ),
					'running'             => __( 'In progress', 'bulk-catalogue-manager-for-woocommerce' ),
					'complete'            => __( 'Complete', 'bulk-catalogue-manager-for-woocommerce' ),
					'partial'             => __( 'Completed with errors', 'bulk-catalogue-manager-for-woocommerce' ),
					self::PARTIAL_SKIPPED => __( 'Completed, some rows skipped', 'bulk-catalogue-manager-for-woocommerce' ),
					'failed'              => __( 'Failed', 'bulk-catalogue-manager-for-woocommerce' ),
					'cancelled'           => __( 'Cancelled', 'bulk-catalogue-manager-for-woocommerce' ),
					'undoing'             => __( 'Being undone', 'bulk-catalogue-manager-for-woocommerce' ),
					'undone'              => __( 'Undone', 'bulk-catalogue-manager-for-woocommerce' ),
					self::REDONE          => __( 'Redone', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				'reversibility' => [
					Reversibility::REVERSIBLE            => __( 'Yes', 'bulk-catalogue-manager-for-woocommerce' ),
					Reversibility::PARTIALLY_REVERSIBLE  => __( 'Partly', 'bulk-catalogue-manager-for-woocommerce' ),
					Reversibility::IN_PROGRESS           => __( 'Not yet: still running', 'bulk-catalogue-manager-for-woocommerce' ),
					Reversibility::COMPLETED_WITH_ERRORS => __( 'Yes, with errors', 'bulk-catalogue-manager-for-woocommerce' ),
					Reversibility::NOT_REVERSIBLE        => __( 'No', 'bulk-catalogue-manager-for-woocommerce' ),
				],
			];
		}
		return $maps[ $column ];
	}

	/**
	 * A value's label, or the value itself when it has none.
	 *
	 * @param mixed                 $value  Value.
	 * @param array<string, string> $labels Labels by value.
	 */
	private static function choice( mixed $value, array $labels ): mixed {
		return is_string( $value ) && isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
	}

	/**
	 * An action id's label from the operations registry, or the id.
	 *
	 * @param mixed $id Action id.
	 */
	private static function action( mixed $id ): string {
		static $labels = [];
		$id            = is_scalar( $id ) ? (string) $id : '';
		if ( ! isset( $labels[ $id ] ) ) {
			$operation     = Actions::instance()->get( $id );
			$labels[ $id ] = null === $operation ? $id : $operation->describe()->label();
		}
		return $labels[ $id ];
	}
}
