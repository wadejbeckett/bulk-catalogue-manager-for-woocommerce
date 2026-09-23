<?php
/**
 * The preview (SPEC §4.4, §9.1, §12.1): what a change would do to a
 * selection, computed without writing or saving anything.
 *
 * For one request it:
 * 1. validates the operation list through Operations\Registry::validate_list()
 *    (the same refusals, with the same codes, as a batch);
 * 2. resolves the selection exactly as a batch would, through
 *    BatchFactory::materialise() (a filter is re-resolved now; an id list
 *    keeps its order and drops ids that are no longer a listable product or
 *    variation, which are listed as unreadable);
 * 3. for each object, up to CAP of them, loads it with wc_get_product() —
 *    the writer's own load — and works on a clone, never the loaded object:
 *    per step in list order it runs the operation's compute(), passes the
 *    Result through Operations\Validator::check() (one Validator for the
 *    whole preview, so a SKU given to two objects fails the second, as it
 *    does inside one writer run), and sets every change the check lets
 *    through on the clone with Field::write() — the setter half of apply(),
 *    without the term write apply() makes — so a later step computes from an
 *    earlier step's value, as it does in the writer. Nothing is saved, no
 *    term is written and no hook fires. An object that cannot be loaded is
 *    listed under `unreadable` and left out;
 * 4. answers totals, a sample of rows, a warning block and a hash.
 *
 * Totals count objects: `matched` is what the selection resolves to now;
 * `would_change` the objects with at least one change; `already` those
 * where every step finds the target value already there; `would_fail`
 * counts, per plain reason, the objects with a failed or skipped step (an
 * object whose other steps change still counts under would_change, because
 * the writer saves those). `rows` counts object-and-step rows by state.
 *
 * Bounded work: totals are computed over the first CAP (10,000) objects.
 * A larger selection (up to Selection::MAX_OBJECTS) has every total and
 * warning count extrapolated from those, in proportion, and `estimated` is
 * true; the sample always comes from the objects computed.
 *
 * The hash (hash()) binds the answer to the selection (the token as sent,
 * or the id list), the normalised operation list and the settings that
 * change what a batch does or who may run it; changing any of them gives a
 * different hash, and applying a filter selection requires it (T38,
 * BatchFactory::create()'s apply check).
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Fields\Values;
use BulkCatalogueManager\Operations\Operation;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Operations\Result;
use BulkCatalogueManager\Operations\Validator;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Rest\SelectionController;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Settings\Schema;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Computes a preview.
 */
final class Preview {
	/**
	 * Most objects computed; beyond this the totals are extrapolated.
	 */
	public const CAP = 10000;

	/**
	 * The sample sizes a request may ask for, besides every row.
	 */
	public const SAMPLE_SIZES = [ 25, 100 ];

	/**
	 * The sample size asked for when none is given.
	 */
	public const DEFAULT_SAMPLE = 25;

	/**
	 * Most rows "every row" returns.
	 */
	public const SAMPLE_ALL = 1000;

	/**
	 * The warning kinds, in the order the block lists them (SPEC §4.4).
	 */
	public const WARNINGS = [ 'sale_above_regular', 'price_not_positive', 'stock_negative', 'sku_duplicate' ];

	/**
	 * The settings a preview is bound to: who may run it, the confirmation
	 * threshold, the apply-to-variations defaults and what the journal records.
	 */
	public const SETTINGS = [
		Schema::ADMINISTRATORS_ONLY,
		Schema::CONFIRM_THRESHOLD,
		Schema::VARIATIONS_STATUS,
		Schema::VARIATIONS_VISIBILITY,
		Schema::VARIATIONS_PRICE,
		Schema::VARIATIONS_STOCK,
		Schema::RECORD_UNCHANGED,
	];

	/**
	 * Context mixed into the hash, so it cannot be mistaken for another signature.
	 */
	private const CONTEXT = 'bulk-catalogue-manager-for-woocommerce/preview/1/';

	/**
	 * The action registry.
	 *
	 * @var Actions
	 */
	private Actions $actions;

	/**
	 * Resolves selections.
	 *
	 * @var BatchFactory
	 */
	private BatchFactory $factory;

	/**
	 * Build a preview.
	 *
	 * @param Actions|null      $actions Action registry; the shared one by default.
	 * @param BatchFactory|null $factory Selection resolver; a factory over the same registry by default.
	 * @param int               $cap     Most objects computed (CAP; tests lower it).
	 */
	public function __construct( ?Actions $actions = null, ?BatchFactory $factory = null, private readonly int $cap = self::CAP ) {
		$this->actions = $actions ?? Actions::instance();
		$this->factory = $factory ?? new BatchFactory( $this->actions );
	}

	/**
	 * Preview an operation list over a selection.
	 *
	 * @param Selection     $selection   The selection, from an id list or a parsed token.
	 * @param mixed         $operations  The operation list as submitted.
	 * @param int           $sample_size Rows to return: 25, 100, or up to SAMPLE_ALL.
	 * @param string        $token       The selection token as sent, or '' for an id list.
	 * @param Settings|null $settings    Settings; the stored ones by default.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function run( Selection $selection, mixed $operations, int $sample_size = self::DEFAULT_SAMPLE, string $token = '', ?Settings $settings = null ): array|\WP_Error {
		$operations = $this->actions->validate_list( $operations );
		if ( $operations instanceof \WP_Error ) {
			return $operations;
		}
		if ( $selection->too_large() ) {
			return self::too_large( $selection->count );
		}
		$objects = $this->factory->materialise( $selection );
		if ( count( $objects ) > Selection::MAX_OBJECTS ) {
			return self::too_large( count( $objects ) );
		}

		$unreadable = [];
		if ( ! $selection->is_filter() ) {
			$found      = array_flip( array_column( $objects, 0 ) );
			$unreadable = array_values( array_filter( $selection->ids, static fn( int $id ): bool => ! isset( $found[ $id ] ) ) );
		}

		$matched  = count( $objects );
		$computed = array_slice( $objects, 0, max( 1, $this->cap ) );
		$tally    = $this->compute( $computed, $operations, max( 1, min( self::SAMPLE_ALL, $sample_size ) ) );
		$factor   = [] === $computed ? 1.0 : $matched / count( $computed );

		$settings ??= Settings::load();
		return [
			'totals'     => [
				'matched'      => $matched,
				'computed'     => count( $computed ) - count( $tally['unreadable'] ),
				'estimated'    => $matched > count( $computed ),
				'would_change' => self::scale( $tally['would_change'], $factor ),
				'already'      => self::scale( $tally['already'], $factor ),
				'would_fail'   => array_map( static fn( int $n ): int => self::scale( $n, $factor ), $tally['would_fail'] ),
				'rows'         => array_map( static fn( int $n ): int => self::scale( $n, $factor ), $tally['rows'] ),
			],
			'sample'     => $tally['sample'],
			'warnings'   => array_map( static fn( int $n ): int => self::scale( $n, $factor ), $tally['warnings'] ),
			'unreadable' => array_merge( $unreadable, $tally['unreadable'] ),
			'selection'  => $selection->is_filter()
				? [
					'kind'          => 'filter',
					'count'         => $selection->count,
					'current_count' => $matched,
				]
				: [
					'kind'  => 'ids',
					'count' => $selection->count,
				],
			'hash'       => self::hash( '' !== $token ? $token : $selection->ids, $operations, $settings ),
		];
	}

	/**
	 * The hash binding a preview to its selection, operation list and
	 * settings: an HMAC keyed on the site's auth salt, so only this site
	 * issues one. The operation list is the one validate_list() returned;
	 * its argument order does not matter.
	 *
	 * @param string|array<int> $selection  The selection token as sent, or the id list.
	 * @param array<mixed>      $operations The normalised operation list.
	 * @param Settings          $settings   The settings.
	 */
	public static function hash( string|array $selection, array $operations, Settings $settings ): string {
		$bound = array_intersect_key( $settings->values(), array_flip( self::SETTINGS ) );
		ksort( $bound );
		$payload = [
			'selection'  => is_string( $selection ) ? [ 'token' => $selection ] : [ 'ids' => array_values( array_map( 'intval', $selection ) ) ],
			'operations' => self::canonical( $operations ),
			'settings'   => $bound,
		];
		return hash_hmac( 'sha256', self::CONTEXT . (string) wp_json_encode( $payload ), wp_salt( 'auth' ) );
	}

	/**
	 * Compute the objects: totals before extrapolation, the sample, the
	 * warnings and the objects that could not be loaded.
	 *
	 * @param list<array{0: int, 1: string}>                          $objects    The objects to compute, in order.
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The normalised list.
	 * @param int                                                     $limit      Sample rows wanted.
	 * @return array{would_change: int, already: int, would_fail: array<string, int>, rows: array<string, int>, sample: list<array<string, mixed>>, warnings: array<string, int>, unreadable: list<int>}
	 */
	private function compute( array $objects, array $operations, int $limit ): array {
		$fields    = $this->actions->fields();
		$steps     = $this->steps( $operations );
		$validator = Validator::for_request( $fields );
		$name      = $fields->get( 'name' );
		$tally     = [
			'would_change' => 0,
			'already'      => 0,
			'would_fail'   => [],
			'rows'         => array_fill_keys( Result::STATES, 0 ),
			'sample'       => [],
			'warnings'     => array_fill_keys( self::WARNINGS, 0 ),
			'unreadable'   => [],
		];

		foreach ( $objects as [ $id, $type ] ) {
			$subject = self::load( $id );
			if ( null === $subject ) {
				$tally['unreadable'][] = $id;
				continue;
			}
			$label   = null === $name ? '' : (string) $name->read( $subject );
			$results = [];
			foreach ( $steps as [ $operation, $args ] ) {
				$computed = $operation->compute( $subject, $args );
				$this->warn( $tally['warnings'], $subject, $computed );
				$checked = $validator->check( $subject, $computed, $args );
				$field   = $fields->get( $computed->field() );
				if ( $computed->is_changed() && null !== $field && $field->unique() && Result::FAILED === $checked[0]->state() ) {
					++$tally['warnings']['sku_duplicate'];
				}
				foreach ( $checked as $result ) {
					$results[] = $this->set( $fields, $result, $subject );
				}
			}

			$states = [];
			$failed = [];
			foreach ( $results as $result ) {
				$state = $result->state();
				++$tally['rows'][ $state ];
				$states[ $state ] = true;
				if ( Result::FAILED === $state || Result::SKIPPED === $state ) {
					$failed[ $result->reason() ] = true;
				}
				if ( count( $tally['sample'] ) < $limit ) {
					$tally['sample'][] = [
						'object_id'   => $id,
						'object_type' => $type,
						'name'        => $label,
						'field'       => $result->field(),
						'current'     => $result->old(),
						'new'         => $result->new(),
						'state'       => $state,
						'reason'      => $result->reason(),
						'code'        => $result->code(),
						'replaces'    => $result->replaces(),
					];
				}
			}
			if ( isset( $states[ Result::CHANGED ] ) ) {
				++$tally['would_change'];
			} elseif ( [ Result::ALREADY ] === array_keys( $states ) ) {
				++$tally['already'];
			}
			foreach ( array_keys( $failed ) as $reason ) {
				$tally['would_fail'][ $reason ] = ( $tally['would_fail'][ $reason ] ?? 0 ) + 1;
			}
		}
		return $tally;
	}

	/**
	 * A detached copy of an object to compute on, or null when it cannot be loaded.
	 *
	 * @param int $id Product or variation id.
	 */
	private static function load( int $id ): ?object {
		try {
			$product = wc_get_product( $id );
		} catch ( \LogicException $e ) {
			throw $e;
		} catch ( \Exception $e ) {
			// WooCommerce's data store throws for a post it cannot read as a product.
			return null;
		}
		return is_object( $product ) ? clone $product : null;
	}

	/**
	 * Set a change the validator let through on the detached copy, through
	 * the field, so later steps see it; a refusal at that point becomes a
	 * failed row, as it does in the writer.
	 *
	 * @param Fields $fields  Field registry.
	 * @param Result $result  A checked Result.
	 * @param object $subject The detached copy.
	 * @throws \LogicException When the Result names a field the registry does not have.
	 */
	private function set( Fields $fields, Result $result, object $subject ): Result {
		if ( ! $result->is_changed() ) {
			return $result;
		}
		$field = $fields->get( $result->field() );
		if ( null === $field ) {
			throw new \LogicException( esc_html( sprintf( 'Field "%s" is not in the registry.', $result->field() ) ) );
		}
		try {
			$written = $field->write( $subject, $result->new() );
		} catch ( \WC_Data_Exception $refused ) {
			$reason = trim( $refused->getMessage() );
			return Result::failed(
				$result->field(),
				$result->old(),
				'' !== $reason ? $reason : __( 'WooCommerce did not accept this value. Check it and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				Writer::INVALID
			);
		}
		if ( $written instanceof \WP_Error ) {
			$code = (string) $written->get_error_code();
			return Result::failed( $result->field(), $result->old(), $written->get_error_message(), str_starts_with( $code, 'bcm_' ) ? $code : '' );
		}
		return $result;
	}

	/**
	 * Count the warnings a computed change raises, before the validator
	 * decides on it: a sale price that would not be below the regular price,
	 * a price falling to zero or below, and stock falling below zero.
	 *
	 * @param array<string, int> $warnings The counts.
	 * @param object             $subject  The detached copy, before this change.
	 * @param Result             $computed What the operation computed.
	 */
	private function warn( array &$warnings, object $subject, Result $computed ): void {
		if ( ! $computed->is_changed() ) {
			return;
		}
		$fields = $this->actions->fields();
		$field  = $fields->get( $computed->field() );
		if ( null === $field ) {
			return;
		}
		$new = $computed->new();
		if ( Field::MONEY === $field->type() ) {
			$number = Values::decimal( $new );
			if ( null !== $number && (float) $number <= 0.0 ) {
				++$warnings['price_not_positive'];
			}
			$sale    = self::price_field( $fields, Field::COPY_FROM_REGULAR );
			$regular = self::price_field( $fields, Field::COPY_FROM_SALE );
			if ( null !== $sale && null !== $regular && in_array( $field->key(), [ $sale->key(), $regular->key() ], true ) ) {
				$regular_after = $field->key() === $regular->key() ? (string) $new : (string) $regular->read( $subject );
				$sale_after    = $field->key() === $sale->key() ? (string) $new : (string) $sale->read( $subject );
				if ( true !== Fields::check_prices( $regular_after, $sale_after ) ) {
					++$warnings['sale_above_regular'];
				}
			}
		}
		if ( $field->requires_managed_stock() ) {
			$number = Values::decimal( $new );
			if ( null !== $number && (float) $number < 0.0 ) {
				++$warnings['stock_negative'];
			}
		}
	}

	/**
	 * The editable price field allowing a copy kind: copy from regular
	 * finds the sale price, copy from sale the regular price.
	 *
	 * @param Fields $fields Field registry.
	 * @param string $kind   Field::COPY_FROM_REGULAR or Field::COPY_FROM_SALE.
	 */
	private static function price_field( Fields $fields, string $kind ): ?Field {
		foreach ( $fields->editable() as $field ) {
			if ( Field::MONEY === $field->type() && $field->accepts( $kind ) ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Each step's operation and arguments.
	 *
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The normalised list.
	 * @return list<array{0: Operation, 1: array<string, mixed>}>
	 * @throws \LogicException When a step names an unknown action.
	 */
	private function steps( array $operations ): array {
		$steps = [];
		foreach ( $operations as $entry ) {
			$operation = $this->actions->get( $entry['action'] );
			if ( null === $operation ) {
				throw new \LogicException( esc_html( sprintf( 'Action "%s" is not in the registry.', $entry['action'] ) ) );
			}
			$steps[] = [ $operation, $entry['args'] ];
		}
		return $steps;
	}

	/**
	 * A count scaled to the whole selection.
	 *
	 * @param int   $count  Counted over the objects computed.
	 * @param float $factor Matched over computed.
	 */
	private static function scale( int $count, float $factor ): int {
		return (int) round( $count * $factor );
	}

	/**
	 * A value with every associative array's keys sorted, lists kept in order.
	 *
	 * @param mixed $value A normalised value.
	 */
	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( [ self::class, 'canonical' ], $value );
	}

	/**
	 * The refusal of a selection above Selection::MAX_OBJECTS.
	 *
	 * @param int $count Objects it holds.
	 */
	private static function too_large( int $count ): \WP_Error {
		return Refusal::invalid(
			SelectionController::TOO_LARGE,
			sprintf(
				/* translators: 1: how many products the selection holds, such as "100,001"; 2: the most one change may hold, "100,000". */
				__( 'This selection holds %1$s products, more than the %2$s one change can include. Add a filter to narrow it and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				number_format_i18n( $count ),
				number_format_i18n( Selection::MAX_OBJECTS )
			),
			[
				'count' => $count,
				'limit' => Selection::MAX_OBJECTS,
			]
		);
	}
}
