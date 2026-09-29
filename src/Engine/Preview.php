<?php
/**
 * The preview (SPEC §4.4, §9.1, §12.1): what a change would do to a
 * selection, computed without writing or saving anything.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Engine;

use BulkCatalogueManager\Availability\Outcome;
use BulkCatalogueManager\Availability\Stamp;
use BulkCatalogueManager\Availability\StatusSwitch;
use BulkCatalogueManager\External\Binding;
use BulkCatalogueManager\External\Computer;
use BulkCatalogueManager\External\Guard;
use BulkCatalogueManager\External\ProductSubject;
use BulkCatalogueManager\External\ProviderFailure;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\Registered;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\External\Text;
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
 *
 * For one request it:
 * 1. validates the operation list through Operations\Registry::validate_list()
 *    (the same refusals, with the same codes, as a batch);
 * 2. resolves the selection exactly as a batch would, through
 *    BatchFactory::materialise() (a filter is re-resolved now; an id list
 *    keeps its order and drops ids that are no longer a listable product or
 *    variation, which are listed as unreadable), then adds the variations
 *    "Also apply to variations" reaches through BatchFactory::expand() (T69),
 *    each computed with only the steps that reach it, as the batch will;
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
 * Totals count objects: `matched` is what the selection resolves to now,
 * the variations added included, and `by_type` splits `matched` and
 * `would_change` into products and variations; `summary` says it in one
 * plain sentence ("Regular price will change on 1,284 products and 3,915
 * variations."). The selection's `current_count` is the selection's own
 * objects, before any variations are added, as the T38 count check compares;
 * `variations_added` is how many the switch adds, and `parents_left_out` how
 * many variable products it leaves out because no step is theirs (a price
 * reaching their variations);
 * `would_change` the objects with at least one change; `already` those
 * where every step finds the target value already there; `would_fail`
 * counts, per plain reason, the objects with a failed or skipped step (an
 * object whose other steps change still counts under would_change, because
 * the writer saves those). `rows` counts object-and-step rows by state.
 *
 * Bounded memory (T76): objects are loaded PAGE at a time, each page's
 * posts, meta and terms primed in one go, and the request's runtime object
 * cache released after each page, as WP-CLI and Action Scheduler do between
 * batches; without it the 10,000 loaded products and everything cached for
 * them outgrew a 128 MB memory limit at 20,000 products.
 *
 * Bounded work: totals are computed over the first CAP (10,000) objects.
 * A larger selection (up to Selection::MAX_OBJECTS) has every total and
 * warning count extrapolated from those, in proportion, and `estimated` is
 * true; the sample always comes from the objects computed.
 *
 * After the 1.0 keys (totals, summary, sample, warnings, unreadable,
 * selection, hash) the answer carries, in this order, `unavailable_count`
 * (every chosen object that cannot be changed; `unreadable` lists at most
 * SAMPLE_ALL of them), `scope_hash` (ExactScope's signature on the exact
 * path, T83; null otherwise) and `operations` (the normalised list, which a
 * durable Apply request's intent carries).
 *
 * Then (T91, SPEC §9.1) `availability`: null when no step sets Status, and
 * otherwise the Off/On outcome of each product's Status step, counted per
 * Availability\Outcome code (scaled like every total when estimated), the
 * restores to Private and Pending review split out, `variations_kept` (the
 * variations a Published step is kept away from, BatchFactory::expand()),
 * and `conflicts`: up to the sample size of the products changed elsewhere
 * while Off, in object order, with their current and saved status. The Off
 * record a Status step writes with it is set on the copy but never a sample
 * row or a counted row; a product whose only change is its record counts once
 * under would change, named as the Status field in the summary. Status sample
 * rows carry the step's `note` and `context`.
 *
 * The hash (hash()) binds the answer to the selection (the token as sent,
 * or the id list), the normalised operation list and the settings that
 * change what a batch does or who may run it; changing any of them gives a
 * different hash, and applying a filter selection requires it (T38,
 * BatchFactory::create()'s apply check).
 *
 * External changes (T106, docs/EXTENSIONS.md §7.2, §10). A change whose steps
 * are one provider's is validated in preview mode, then computed object by
 * object through External\Computer on the same loaded copies, after the
 * same edit check: nothing is written or flushed, the provider primes each
 * page first, and each sample row carries text (`current`, `new`, `change`,
 * `fieldLabel`, `notes`, `external: true`), never a raw value. The answer
 * then ends with `external`: the provider, its label and version, its
 * warnings aggregated by code with a count (scaled when estimated), whether
 * Apply always confirms, and the sentences it restates. The hash also binds
 * the provider's identity and state token (External\Binding) over the
 * change's available members.
 */
final class Preview {
	/**
	 * Most objects computed; beyond this the totals are extrapolated.
	 */
	public const CAP = 10000;

	/**
	 * Objects loaded between two releases of the runtime object cache.
	 */
	public const PAGE = 100;

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
	public function run( Selection $selection, mixed $operations, int $sample_size = self::DEFAULT_SAMPLE, string $token = '', ?Settings $settings = null, bool $exact = false ): array|\WP_Error {
		$context    = RunContext::preview( get_current_user_id() );
		$operations = RunContext::within( $context, fn(): mixed => $this->actions->validate_list( $operations ) );
		if ( $operations instanceof \WP_Error ) {
			return $operations;
		}
		$provider = $this->actions->external_provider( $operations );
		if ( $selection->too_large() ) {
			return self::too_large( $selection->count );
		}
		$scope    = $exact ? ExactScope::build( $selection, $operations, $this->factory ) : null;
		$selected = $scope['selected'] ?? $this->factory->materialise( $selection );
		if ( count( $selected ) > Selection::MAX_OBJECTS ) {
			return self::too_large( count( $selected ) );
		}
		$expanded = $scope ?? $this->factory->expand( $selected, $operations );
		$objects  = $expanded['objects'];
		if ( count( $objects ) > Selection::MAX_OBJECTS ) {
			return self::too_large( count( $objects ) );
		}

		$unreadable = $scope['unavailable'] ?? [];
		if ( ! $exact && ! $selection->is_filter() ) {
			$found      = array_flip( array_column( $selected, 0 ) );
			$unreadable = array_values( array_filter( $selection->ids, static fn( int $id ): bool => ! isset( $found[ $id ] ) ) );
		}

		$matched  = count( $objects );
		$computed = array_slice( $objects, 0, max( 1, $this->cap ) );
		$binding  = [];
		if ( null !== $provider ) {
			$gone = array_flip( [ ...( $scope['unavailable'] ?? [] ), ...$unreadable ] );
			try {
				$binding = Binding::of( $provider, $operations, array_values( array_filter( array_column( $objects, 0 ), static fn( int $id ): bool => ! isset( $gone[ $id ] ) ) ), $context );
			} catch ( ProviderFailure $e ) {
				return \BulkCatalogueManager\Rest\Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
			}
		}
		// A preview's Off records are stamped batch 0 and never stored (T89).
		$tally   = null === $provider
			? Stamp::within( 0, null, fn(): array => $this->compute( $computed, $operations, max( 1, min( self::SAMPLE_ALL, $sample_size ) ), $scope['unavailable'] ?? [] ) )
			: $this->compute_external( $provider, $context, $computed, $operations, max( 1, min( self::SAMPLE_ALL, $sample_size ) ), $scope['unavailable'] ?? [] );
		$factor  = [] === $computed ? 1.0 : $matched / count( $computed );
		$by_type = [];
		foreach ( [ 'product', 'variation' ] as $type ) {
			$by_type[ $type ] = [
				'matched'      => count( array_filter( $objects, static fn( array $entry ): bool => $type === $entry[1] ) ),
				'would_change' => self::scale( $tally['by_type'][ $type ], $factor ),
			];
		}

		$settings ??= Settings::load();
		$missing    = array_values( array_unique( array_merge( $unreadable, $tally['unreadable'] ) ) );
		$off_on     = $this->sets_status( $operations )
			? [
				'counts'          => array_map( static fn( int $n ): int => self::scale( $n, $factor ), $tally['availability']['counts'] ),
				'restores'        => array_map( static fn( int $n ): int => self::scale( $n, $factor ), $tally['availability']['restores'] ),
				'variations_kept' => (int) ( $expanded['kept'] ?? 0 ),
				'conflicts'       => $tally['availability']['conflicts'],
			]
			: null;
		$answer     = [
			'totals'            => [
				'matched'      => $matched,
				'computed'     => count( $computed ) - count( $tally['unreadable'] ),
				'estimated'    => $matched > count( $computed ),
				'would_change' => self::scale( $tally['would_change'], $factor ),
				'already'      => self::scale( $tally['already'], $factor ),
				'would_fail'   => array_map( static fn( int $n ): int => self::scale( $n, $factor ), $tally['would_fail'] ),
				'rows'         => array_map( static fn( int $n ): int => self::scale( $n, $factor ), $tally['rows'] ),
				'by_type'      => $by_type,
			],
			'summary'           => self::summary( $tally['fields'], $by_type['product']['would_change'], $by_type['variation']['would_change'] ),
			'sample'            => $tally['sample'],
			'warnings'          => array_map( static fn( int $n ): int => self::scale( $n, $factor ), $tally['warnings'] ),
			'unreadable'        => array_slice( $missing, 0, self::SAMPLE_ALL ),
			'selection'         => ( $selection->is_filter()
				? [
					'kind'          => 'filter',
					'count'         => $selection->count,
					'current_count' => count( $selected ),
				]
				: [
					'kind'  => 'ids',
					'count' => $selection->count,
				] ) + [
					'variations_added' => $expanded['added'],
					'parents_left_out' => $expanded['left_out'],
					'variations_kept'  => (int) ( $expanded['kept'] ?? 0 ),
				],
			'hash'              => self::hash( '' !== $token ? $token : $selection->ids, $operations, $settings, $scope['scope_hash'] ?? '', $binding ),
			'unavailable_count' => count( $missing ),
			'scope_hash'        => $scope['scope_hash'] ?? null,
			'operations'        => $operations,
			'availability'      => $off_on,
		];
		if ( null !== $provider ) {
			$answer['external'] = [
				'provider' => $provider->id,
				'label'    => $provider->label,
				'version'  => $provider->version,
				'warnings' => array_map(
					static fn( array $warning ): array => [
						'code'     => $warning['code'],
						'sentence' => $warning['sentence'],
						'count'    => self::scale( $warning['count'], $factor ),
						'confirm'  => $warning['confirm'],
					],
					array_values( $tally['external']['warnings'] )
				),
				'confirm'  => $tally['external']['confirm'],
				'reasons'  => $tally['external']['reasons'],
			];
		}
		return $answer;
	}

	/**
	 * Whether any step sets the Status field (T91).
	 *
	 * @param list<array{action: string, args: array<string, mixed>}> $operations The normalised list.
	 */
	private function sets_status( array $operations ): bool {
		foreach ( $operations as $step ) {
			$field = $this->actions->fields()->get( (string) ( $step['args']['field'] ?? '' ) );
			if ( null !== $field && StatusSwitch::governs( $field ) ) {
				return true;
			}
		}
		return false;
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
	public static function hash( string|array $selection, array $operations, Settings $settings, string $scope_hash = '', array $external = [] ): string {
		$bound = array_intersect_key( $settings->values(), array_flip( self::SETTINGS ) );
		ksort( $bound );
		$payload = [
			'selection'  => is_string( $selection ) ? [ 'token' => $selection ] : [ 'ids' => array_values( array_map( 'intval', $selection ) ) ],
			'operations' => self::canonical( $operations ),
			'settings'   => $bound,
		];
		if ( '' !== $scope_hash ) {
			$payload['scope_hash'] = $scope_hash;
		}
		if ( [] !== $external ) {
			// T106: the provider's id, version, contract, field formats and state token (External\Binding).
			$payload['external'] = self::canonical( $external );
		}
		return hash_hmac( 'sha256', self::CONTEXT . (string) wp_json_encode( $payload ), wp_salt( 'auth' ) );
	}

	/**
	 * Compute the objects: totals before extrapolation, the sample, the
	 * warnings and the objects that could not be loaded.
	 *
	 * @param list<array{0: int, 1: string, 2?: list<array{action: string, args: array<string, mixed>}>}> $objects    The objects to compute, in order, each with its own step list when it has one.
	 * @param list<array{action: string, args: array<string, mixed>}>                                      $operations The normalised list.
	 * @param int                                                                                          $limit      Sample rows wanted.
	 * @param list<int>                                                                                    $unavailable Chosen objects the exact scope found missing or changed in kind (T83): listed as unreadable, never loaded.
	 * @return array{would_change: int, already: int, would_fail: array<string, int>, rows: array<string, int>, sample: list<array<string, mixed>>, warnings: array<string, int>, unreadable: list<int>, by_type: array<string, int>, fields: array<string, string>}
	 */
	private function compute( array $objects, array $operations, int $limit, array $unavailable = [] ): array {
		$fields    = $this->actions->fields();
		$all       = $this->steps( $operations );
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
			'by_type'      => [
				'product'   => 0,
				'variation' => 0,
			],
			'fields'       => [],
			'availability' => [
				'counts'    => array_fill_keys( Outcome::CODES, 0 ),
				'restores'  => [
					'private' => 0,
					'pending' => 0,
				],
				'conflicts' => [],
			],
		];

		$skip = array_flip( $unavailable );
		foreach ( $objects as $index => $entry ) {
			if ( 0 === $index % self::PAGE ) {
				Memory::next_page( array_column( array_slice( $objects, $index, self::PAGE ), 0 ), 0 < $index );
			}
			[ $id, $type ] = $entry;
			$steps         = isset( $entry[2] ) ? $this->steps( $entry[2] ) : $all;
			$subject       = isset( $skip[ $id ] ) ? null : self::load( $id );
			if ( null === $subject ) {
				$tally['unreadable'][] = $id;
				continue;
			}
			$denied = $validator->authorise( $subject );
			if ( null !== $denied ) {
				// Nothing of an object the user may not edit is computed or shown: no name, no values, the reason only.
				$results = array_map( static fn( array $step ): Result => Result::skipped( (string) $step[1]['field'], null, $denied->get_error_message(), (string) $denied->get_error_code() ), $steps );
				$label   = '';
				$steps   = [];
			} else {
				$label   = null === $name ? '' : (string) $name->read( $subject );
				$results = [];
			}
			// The Off record a Status step writes with it (T89) is set on the copy but never shown
			// as a row: an object whose only change is its record still counts, under its step's field.
			$hidden = [];
			foreach ( $steps as [ $operation, $args ] ) {
				$computed = $operation->compute( $subject, $args );
				$this->warn( $tally['warnings'], $subject, $computed );
				$checked = $validator->check( $subject, $computed, $args );
				// What follows the step (the Off record, T89), decided before anything is set, as the writer does.
				$follow = $validator->follow( $operation, $subject, $checked[0], $args );
				$field  = $fields->get( $computed->field() );
				if ( $computed->is_changed() && null !== $field && $field->unique() && Result::FAILED === $checked[0]->state() ) {
					++$tally['warnings']['sku_duplicate'];
				}
				foreach ( $checked as $index => $result ) {
					$set       = $this->set( $fields, $result, $subject );
					$results[] = $set;
					if ( 0 === $index && $result->is_changed() && ! $set->is_changed() ) {
						$follow = [];
					}
					if ( 0 === $index && null !== $field && StatusSwitch::governs( $field ) ) {
						self::count_outcome( $tally['availability'], $set, $id, $label, $limit );
					}
				}
				foreach ( $follow as $result ) {
					$set = $this->set( $fields, $result, $subject );
					if ( $set->is_changed() ) {
						$hidden[ $computed->field() ] = true;
					}
				}
			}

			$states = [];
			$failed = [];
			if ( [] !== $hidden ) {
				$states[ Result::CHANGED ] = true;
				foreach ( array_keys( $hidden ) as $key ) {
					if ( ! isset( $tally['fields'][ $key ] ) ) {
						$field                   = $fields->get( $key );
						$tally['fields'][ $key ] = null === $field ? $key : $field->label();
					}
				}
			}
			foreach ( $results as $result ) {
				$state = $result->state();
				++$tally['rows'][ $state ];
				$states[ $state ] = true;
				if ( Result::FAILED === $state || Result::SKIPPED === $state ) {
					$failed[ $result->reason() ] = true;
				}
				if ( Result::CHANGED === $state && ! isset( $tally['fields'][ $result->field() ] ) ) {
					$field                               = $fields->get( $result->field() );
					$tally['fields'][ $result->field() ] = null === $field ? $result->field() : $field->label();
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
						'note'        => $result->note(),
						'context'     => $result->context(),
					];
				}
			}
			if ( isset( $states[ Result::CHANGED ] ) ) {
				++$tally['would_change'];
				++$tally['by_type'][ 'variation' === $type ? 'variation' : 'product' ];
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
	 * Compute an external change's objects (T106): the same totals, sample
	 * and unreadable list as compute(), from External\Computer, plus the
	 * provider's warnings by code and its confirmation sentences.
	 *
	 * @param Registered                                                                                   $provider    The change's provider.
	 * @param RunContext                                                                                   $context     The preview's run.
	 * @param list<array{0: int, 1: string, 2?: list<array{action: string, args: array<string, mixed>}>}> $objects     The objects to compute.
	 * @param list<array{action: string, args: array<string, mixed>}>                                      $operations  The normalised list.
	 * @param int                                                                                          $limit       Sample rows wanted.
	 * @param list<int>                                                                                    $unavailable Chosen objects the exact scope found missing: never loaded.
	 * @return array<string, mixed>
	 */
	private function compute_external( Registered $provider, RunContext $context, array $objects, array $operations, int $limit, array $unavailable ): array {
		$fields    = $this->actions->fields();
		$validator = Validator::for_request( $fields );
		$name      = $fields->get( 'name' );
		$computer  = new Computer( $provider, $context );
		$reasons   = [];
		$confirm   = false;
		foreach ( $operations as $step ) {
			$described = $provider->described( (string) $step['action'] );
			if ( null !== $described && $described['always_confirm'] ) {
				$confirm   = true;
				$reasons[] = '' !== $described['confirm_reason'] ? $described['confirm_reason'] : sprintf(
					/* translators: %s: an action's name, such as "Replace labels". */
					__( '%s always asks before it is applied.', 'bulk-catalogue-manager-for-woocommerce' ),
					$described['label']
				);
			}
		}
		$tally = [
			'would_change' => 0,
			'already'      => 0,
			'would_fail'   => [],
			'rows'         => array_fill_keys( Result::STATES, 0 ),
			'sample'       => [],
			'warnings'     => array_fill_keys( self::WARNINGS, 0 ),
			'unreadable'   => [],
			'by_type'      => [
				'product'   => 0,
				'variation' => 0,
			],
			'fields'       => [],
			'availability' => [
				'counts'    => array_fill_keys( Outcome::CODES, 0 ),
				'restores'  => [
					'private' => 0,
					'pending' => 0,
				],
				'conflicts' => [],
			],
			'external'     => [
				'warnings' => [],
				'confirm'  => $confirm,
				'reasons'  => array_values( array_unique( $reasons ) ),
			],
		];
		$skip  = array_flip( $unavailable );
		foreach ( $objects as $index => $entry ) {
			if ( 0 === $index % self::PAGE ) {
				$page = array_values( array_filter( array_column( array_slice( $objects, $index, self::PAGE ), 0 ), static fn( int $id ): bool => ! isset( $skip[ $id ] ) ) );
				Memory::next_page( $page, 0 < $index );
				try {
					Guard::run( $provider->id, 'prime', static fn(): mixed => $provider->provider->prime( $page ) );
				} catch ( ProviderFailure $e ) {
					unset( $e ); // Logged by the guard; reads then load one by one.
				}
			}
			[ $id, $type ] = $entry;
			$steps         = $entry[2] ?? $operations;
			$subject       = isset( $skip[ $id ] ) ? null : self::load( $id );
			if ( null === $subject ) {
				$tally['unreadable'][] = $id;
				continue;
			}
			$denied = $validator->authorise( $subject );
			$label  = '';
			if ( null !== $denied ) {
				// Nothing of an object the user may not edit is computed or shown, and the provider is never asked.
				$results = array_map( static fn( array $step ): Result => Result::skipped( (string) $step['args']['field'], null, $denied->get_error_message(), (string) $denied->get_error_code() ), $steps );
				$object  = null;
			} else {
				$label   = null === $name ? '' : (string) $name->read( $subject );
				$object  = ProductSubject::of( $subject, $provider );
				$results = $computer->run( $object, $steps )->results;
			}
			$states = [];
			$failed = [];
			foreach ( $results as $result ) {
				$state = $result->state();
				++$tally['rows'][ $state ];
				$states[ $state ] = true;
				$meta             = $provider->field_meta( $result->field() );
				$field_label      = null === $meta ? '' : $meta['label'];
				if ( Result::FAILED === $state || Result::SKIPPED === $state ) {
					$failed[ $result->reason() ] = true;
				}
				if ( Result::CHANGED === $state && ! isset( $tally['fields'][ $result->field() ] ) ) {
					$tally['fields'][ $result->field() ] = $field_label;
				}
				$warning = $result->warning();
				if ( null !== $warning ) {
					$tally['external']['warnings'][ $warning['code'] ] ??= [
						'code'     => $warning['code'],
						'sentence' => $warning['sentence'],
						'count'    => 0,
						'confirm'  => false,
					];
					++$tally['external']['warnings'][ $warning['code'] ]['count'];
					if ( $warning['confirm'] ) {
						$tally['external']['warnings'][ $warning['code'] ]['confirm'] = true;
						$tally['external']['confirm']                                 = true;
						if ( ! in_array( $warning['sentence'], $tally['external']['reasons'], true ) ) {
							$tally['external']['reasons'][] = $warning['sentence'];
						}
					}
				}
				if ( count( $tally['sample'] ) < $limit ) {
					$shown             = null !== $object && in_array( $state, [ Result::CHANGED, Result::ALREADY ], true );
					$tally['sample'][] = [
						'object_id'   => $id,
						'object_type' => $type,
						'name'        => $label,
						'field'       => $result->field(),
						'current'     => $shown ? self::describe( $provider, $object, $result->field(), $result->old() ) : '',
						'new'         => $shown ? self::describe( $provider, $object, $result->field(), $result->new() ) : '',
						'state'       => $state,
						'reason'      => $result->reason(),
						'code'        => $result->code(),
						'replaces'    => $result->replaces(),
						'note'        => '',
						'context'     => [],
						'fieldLabel'  => $field_label,
						'change'      => null !== $object && $result->is_changed() ? self::describe_change( $provider, $object, $result ) : '',
						'notes'       => $result->notes(),
						'external'    => true,
					];
				}
			}
			if ( isset( $states[ Result::CHANGED ] ) ) {
				++$tally['would_change'];
				++$tally['by_type'][ 'variation' === $type ? 'variation' : 'product' ];
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
	 * A value as its provider describes it, plain; '' when the provider fails.
	 *
	 * @param Registered     $provider The provider.
	 * @param ProductSubject $item     The object.
	 * @param string         $key      Field key.
	 * @param mixed          $value    The value.
	 */
	public static function describe( Registered $provider, ProductSubject $item, string $key, mixed $value ): string {
		$field = $provider->field( $key );
		try {
			return null === $field ? '' : Text::plain( Guard::run( $provider->id, 'describe', static fn(): mixed => $field->describe( $value, $item ) ), Text::VALUE );
		} catch ( ProviderFailure $e ) {
			return '';
		}
	}

	/**
	 * What a change does, as its provider says it, plain; '' when it says nothing or fails.
	 *
	 * @param Registered     $provider The provider.
	 * @param ProductSubject $item     The object.
	 * @param Result         $result   The change.
	 */
	private static function describe_change( Registered $provider, ProductSubject $item, Result $result ): string {
		$field = $provider->field( $result->field() );
		try {
			return null === $field ? '' : Text::plain( Guard::run( $provider->id, 'describe_change', static fn(): mixed => $field->describe_change( $result->old(), $result->new(), $item ) ), Text::VALUE );
		} catch ( ProviderFailure $e ) {
			return '';
		}
	}

	/**
	 * Count one Status step's Off/On outcome (T91): its code, a restore to
	 * another status, and a conflict listed up to the sample size.
	 *
	 * @param array<string, mixed> $block  The availability tally.
	 * @param Result               $result The Status step's Result, as set on the copy.
	 * @param int                  $id     The object.
	 * @param string               $name   Its name.
	 * @param int                  $limit  Most conflicts listed.
	 */
	private static function count_outcome( array &$block, Result $result, int $id, string $name, int $limit ): void {
		$code = $result->note();
		if ( ! isset( $block['counts'][ $code ] ) ) {
			return;
		}
		++$block['counts'][ $code ];
		$context = $result->context();
		if ( Outcome::ON_RESTORES === $code && isset( $block['restores'][ $context['restores'] ?? '' ] ) ) {
			++$block['restores'][ $context['restores'] ];
		}
		if ( Outcome::CONFLICT === $code && count( $block['conflicts'] ) < $limit ) {
			$block['conflicts'][] = [
				'object_id' => $id,
				'name'      => $name,
				'current'   => (string) ( $context['current'] ?? '' ),
				'saved'     => (string) ( $context['saved'] ?? '' ),
			];
		}
	}

	/**
	 * The preview's plain summary (SPEC §4.4 item 1): what will change, on
	 * how many products and how many variations, such as "Regular price
	 * will change on 1,284 products and 3,915 variations." One field is
	 * named; several are counted.
	 *
	 * @param array<string, string> $fields     Labels of the fields that would change, by key.
	 * @param int                   $products   Products that would change.
	 * @param int                   $variations Variations that would change.
	 */
	public static function summary( array $fields, int $products, int $variations ): string {
		if ( [] === $fields || 0 === $products + $variations ) {
			return __( 'Nothing will change: every row already holds the value or cannot be changed.', 'bulk-catalogue-manager-for-woocommerce' );
		}
		$product_text = sprintf(
			/* translators: %s: a number of products, such as "1,284". */
			_n( '%s product', '%s products', $products, 'bulk-catalogue-manager-for-woocommerce' ),
			number_format_i18n( $products )
		);
		$variation_text = sprintf(
			/* translators: %s: a number of variations, such as "3,915". */
			_n( '%s variation', '%s variations', $variations, 'bulk-catalogue-manager-for-woocommerce' ),
			number_format_i18n( $variations )
		);
		if ( 0 === $variations ) {
			$where = $product_text;
		} elseif ( 0 === $products ) {
			$where = $variation_text;
		} else {
			$where = sprintf(
				/* translators: 1: a number of products, such as "1,284 products"; 2: a number of variations, such as "3,915 variations". */
				__( '%1$s and %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				$product_text,
				$variation_text
			);
		}
		if ( 1 === count( $fields ) ) {
			return sprintf(
				/* translators: 1: a field's name, such as "Regular price"; 2: where, such as "1,284 products and 3,915 variations". */
				__( '%1$s will change on %2$s.', 'bulk-catalogue-manager-for-woocommerce' ),
				(string) reset( $fields ),
				$where
			);
		}
		return sprintf(
			/* translators: 1: how many fields, such as 3; 2: where, such as "1,284 products and 3,915 variations". */
			_n( '%1$d field will change on %2$s.', '%1$d fields will change on %2$s.', count( $fields ), 'bulk-catalogue-manager-for-woocommerce' ),
			count( $fields ),
			$where
		);
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
			return Result::failed( $result->field(), $result->old(), $written->get_error_message(), str_starts_with( $code, 'bulkcat_' ) ? $code : '' );
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
	 * A value with every associative array's keys sorted, lists kept in
	 * order: the form operation lists are hashed and compared in.
	 *
	 * @param mixed $value A normalised value.
	 */
	public static function canonical( mixed $value ): mixed {
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
				_n( 'This selection holds %1$s product, more than the %2$s one change can include. Add a filter to narrow it and try again.', 'This selection holds %1$s products, more than the %2$s one change can include. Add a filter to narrow it and try again.', $count, 'bulk-catalogue-manager-for-woocommerce' ),
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
