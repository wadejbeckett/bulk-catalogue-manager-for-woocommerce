<?php
/**
 * The action registry (SPEC §6, §4.3, §12.1): resolves an action id to its
 * operation, lists every action's descriptor for `GET /fields`, and
 * validates an ordered operation list centrally, before any object is read.
 *
 * An operation list is a list of `{ action, args }` entries, applied to each
 * object in list order. validate_list() refuses an empty list, more than
 * MAX_OPERATIONS entries, a malformed entry, an unknown action id, an action
 * applied to a field it does not accept, and arguments the action refuses —
 * each a Rest\Refusal with status 400, a `bulkcat_` code and a §4.8 sentence
 * naming the step — and otherwise returns the list with every entry's
 * arguments normalised, as preview, batches and the journal store it.
 *
 * Internal actions (T90): `restore_status`, undo's step for a status and its
 * Off record, is resolved by get() so the writer runs stored undo items, but
 * ids(), descriptors(), to_client() and actions_for() leave it out, and
 * validate_list() refuses it as an unknown action for every caller except
 * the undo planner, which asks with `$internal` true.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Operations;

use BulkCatalogueManager\External\ExternalOperation;
use BulkCatalogueManager\External\Providers;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\Registered;
use BulkCatalogueManager\Fields\Registry as Fields;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Every action.
 *
 * External providers (T104, docs/EXTENSIONS.md §3.1): the shared registry
 * fires `bulkcat_register_providers` once per request, the first time it is
 * built on or after `init`, and keeps the providers that registered
 * (External\Providers). Built before `init` it holds none and is rebuilt,
 * with the action fired, the first time it is asked for after `init`. With
 * no provider nothing else changes.
 *
 * External actions (T106): get() and has() also resolve each registered
 * provider's actions (External\ExternalOperation), validated here like any
 * step; ids(), descriptors(), to_client() and actions_for() list native
 * actions only, and external_ids() lists the others. validate_list() refuses,
 * before any step is validated, a change mixing native steps with a
 * provider's or two providers' steps (400 `bulkcat_mixed_change`), and an
 * action of a provider that is not registered (409 `bulkcat_provider_unavailable`).
 */
final class Registry {
	/**
	 * Most operations one change may hold (SPEC §6, §12.1).
	 */
	public const MAX_OPERATIONS = 20;

	/**
	 * Refusal code for a list with no operations.
	 */
	public const NO_OPERATIONS = 'bulkcat_no_operations';

	/**
	 * Refusal code for a list above MAX_OPERATIONS.
	 */
	public const TOO_MANY = 'bulkcat_too_many_operations';

	/**
	 * Refusal code for an action id no operation has.
	 */
	public const UNKNOWN_ACTION = 'bulkcat_unknown_action';

	/**
	 * Actions only the engine itself may list (T90).
	 */
	public const INTERNAL = [ RestoreStatus::ID ];

	/**
	 * The instance runtime code shares.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the shared instance was built after `init`, with the providers it fired.
	 *
	 * @var bool
	 */
	private static bool $fired = false;

	/**
	 * Operations by id, in panel order.
	 *
	 * @var array<string, Operation>
	 */
	private array $operations = [];

	/**
	 * The external providers.
	 *
	 * @var Providers
	 */
	private readonly Providers $providers;

	/**
	 * External actions by id, provider by provider (T106).
	 *
	 * @var array<string, ExternalOperation>
	 */
	private array $external = [];

	/**
	 * Build every action over a field registry. Code outside tests passes no
	 * list; a test double passes its own operations.
	 *
	 * @param Fields               $fields     Field registry the actions resolve their fields from.
	 * @param list<Operation>|null $operations The operations, or null for every version 1 action.
	 * @param Providers|null       $providers  The external providers; none by default.
	 * @throws \LogicException When two operations share an id.
	 */
	public function __construct( private readonly Fields $fields, ?array $operations = null, ?Providers $providers = null ) {
		$this->providers = $providers ?? Providers::none();
		foreach ( $operations ?? self::defaults( $fields ) as $operation ) {
			$id = $operation->describe()->id();
			if ( isset( $this->operations[ $id ] ) ) {
				throw new \LogicException( esc_html( sprintf( 'Action "%s" is registered twice.', $id ) ) );
			}
			$this->operations[ $id ] = $operation;
		}
		foreach ( $this->providers->all() as $provider ) {
			foreach ( array_keys( $provider->all_described() ) as $id ) {
				$this->external[ $id ] = new ExternalOperation( $provider, $id );
			}
		}
	}

	/**
	 * The shared registry, over the shared field registry.
	 */
	public static function instance(): self {
		if ( null === self::$instance || ( ! self::$fired && did_action( 'init' ) > 0 ) ) {
			self::$fired    = did_action( 'init' ) > 0;
			self::$instance = new self( Fields::instance(), null, self::$fired ? Providers::fire() : null );
		}
		return self::$instance;
	}

	/**
	 * The external providers this registry holds (T104).
	 */
	public function providers(): Providers {
		return $this->providers;
	}

	/**
	 * The field registry the actions resolve against.
	 */
	public function fields(): Fields {
		return $this->fields;
	}

	/**
	 * Every public action id, in panel order.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_values( array_diff( array_keys( $this->operations ), self::INTERNAL ) );
	}

	/**
	 * The internal action ids registered here.
	 *
	 * @return string[]
	 */
	public function internal_ids(): array {
		return array_values( array_intersect( array_keys( $this->operations ), self::INTERNAL ) );
	}

	/**
	 * Whether an id is an action.
	 *
	 * @param string $id Action id.
	 */
	public function has( string $id ): bool {
		return isset( $this->operations[ $id ] ) || isset( $this->external[ $id ] );
	}

	/**
	 * Every registered external action id, provider by provider (T106).
	 *
	 * @return list<string>
	 */
	public function external_ids(): array {
		return array_keys( $this->external );
	}

	/**
	 * The provider of a change with external steps, or null for a native one
	 * (a normalised list holds one provider's steps at most). An internal
	 * external step names its provider through its field.
	 *
	 * @param array<mixed> $operations A normalised operation list.
	 */
	public function external_provider( array $operations ): ?Registered {
		foreach ( $operations as $step ) {
			$kind = is_array( $step ) ? self::kind( $step ) : '';
			if ( '' !== $kind ) {
				return $this->providers->get( $kind );
			}
		}
		return null;
	}

	/**
	 * The operation for an action id, or null.
	 *
	 * @param string $id Action id.
	 */
	public function get( string $id ): ?Operation {
		return $this->operations[ $id ] ?? $this->external[ $id ] ?? null;
	}

	/**
	 * The class implementing an action id, or null.
	 *
	 * @param string $id Action id.
	 * @return class-string<Operation>|null
	 */
	public function class_of( string $id ): ?string {
		$operation = $this->get( $id );
		return null === $operation ? null : $operation::class;
	}

	/**
	 * Every public action's descriptor, by id, in panel order.
	 *
	 * @return array<string, Descriptor>
	 */
	public function descriptors(): array {
		return array_map( static fn( Operation $operation ): Descriptor => $operation->describe(), array_diff_key( $this->operations, array_flip( self::INTERNAL ) ) );
	}

	/**
	 * The ids of the actions performing an edit kind on a field. The field
	 * registry's edit kinds each resolve to at least one (RegistryTest).
	 *
	 * @param string $field Field key.
	 * @param string $kind  Edit kind.
	 * @return string[]
	 */
	public function actions_for( string $field, string $kind ): array {
		$ids = [];
		foreach ( $this->descriptors() as $id => $descriptor ) {
			if ( $descriptor->accepts( $field ) && in_array( $kind, $descriptor->edit_kinds(), true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Every descriptor as the client reads it, in panel order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function to_client(): array {
		return array_values( array_map( static fn( Descriptor $descriptor ): array => $descriptor->to_client(), $this->descriptors() ) );
	}

	/**
	 * Validate an ordered operation list before any object is read.
	 *
	 * @param mixed $operations The list as submitted: `[ { action, args }, ... ]`.
	 * @param bool  $internal   Whether the internal actions are accepted: the undo planner only (T90).
	 * @return list<array{action: string, args: array<string, mixed>}>|\WP_Error The normalised list, or the refusal.
	 */
	public function validate_list( mixed $operations, bool $internal = false ): array|\WP_Error {
		if ( ! is_array( $operations ) || ! array_is_list( $operations ) ) {
			return self::refusal( BaseOperation::INVALID, __( 'The list of changes could not be read. Choose the changes again and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( [] === $operations ) {
			return self::refusal( self::NO_OPERATIONS, __( 'Choose at least one change to make, then try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$count = count( $operations );
		if ( $count > self::MAX_OPERATIONS ) {
			return self::refusal(
				self::TOO_MANY,
				sprintf(
					/* translators: 1: the most changes one batch may hold, 2: how many were asked for. */
					_n( 'One change can hold at most %1$d step, and this one has %2$d. Remove some steps, or split them into two changes, and try again.', 'One change can hold at most %1$d steps, and this one has %2$d. Remove some steps, or split them into two changes, and try again.', self::MAX_OPERATIONS, 'bulk-catalogue-manager-for-woocommerce' ),
					self::MAX_OPERATIONS,
					$count
				),
				[
					'count' => $count,
					'limit' => self::MAX_OPERATIONS,
				]
			);
		}

		$kinds = [];
		foreach ( $operations as $entry ) {
			$kinds[ is_array( $entry ) ? self::kind( $entry ) : '' ] = true;
		}
		if ( count( $kinds ) > 1 ) {
			// T106: one change holds native steps or one provider's steps, never both.
			return Refusals::mixed();
		}
		$external = (string) array_key_first( $kinds );
		if ( '' !== $external && null === $this->providers->get( $external ) ) {
			return Refusals::missing();
		}

		$valid = [];
		foreach ( $operations as $index => $entry ) {
			$step = $index + 1;
			if ( ! is_array( $entry ) || ! is_string( $entry['action'] ?? null ) || ! is_array( $entry['args'] ?? [] ) || [] !== array_diff_key(
				$entry,
				[
					'action' => 0,
					'args'   => 0,
				]
			) ) {
				return self::step_refusal( $step, $count, BaseOperation::INVALID, __( 'This step could not be read. Choose the action again and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$operation = $internal || ! in_array( $entry['action'], self::INTERNAL, true ) ? $this->get( $entry['action'] ) : null;
			if ( null === $operation ) {
				return self::step_refusal( $step, $count, self::UNKNOWN_ACTION, __( 'This step asks for an action that is not available. Choose an action from the list and try again.', 'bulk-catalogue-manager-for-woocommerce' ), [ 'action' => $entry['action'] ] );
			}
			$args  = $entry['args'] ?? [];
			$field = $args['field'] ?? null;
			if ( $operation instanceof ExternalOperation ) {
				$validation = $operation->validate( $args );
				$error      = $validation->error();
				if ( null !== $error ) {
					$data = (array) $error->get_error_data();
					return in_array( (int) ( $data['status'] ?? 400 ), [ 400 ], true )
						? self::step_refusal( $step, $count, (string) $error->get_error_code(), $error->get_error_message(), $data )
						: $error;
				}
				$valid[] = [
					'action' => $entry['action'],
					'args'   => $validation->args(),
				];
				continue;
			}
			if ( is_string( $field ) && '' !== $field && ! $operation->describe()->accepts( $field ) ) {
				$refusal = $operation instanceof BaseOperation
					? $operation->field_refusal( $field )
					: Refusal::invalid( BaseOperation::FIELD_NOT_ACCEPTED, __( 'This action cannot change that field. Choose one of the fields it offers and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
				return self::step_refusal( $step, $count, BaseOperation::FIELD_NOT_ACCEPTED, $refusal->get_error_message(), [ 'action' => $entry['action'] ] + (array) $refusal->get_error_data() );
			}
			$validation = $operation->validate( $args );
			$error      = $validation->error();
			if ( null !== $error ) {
				return self::step_refusal( $step, $count, (string) $error->get_error_code(), $error->get_error_message(), (array) $error->get_error_data() );
			}
			$valid[] = [
				'action' => $entry['action'],
				'args'   => $validation->args(),
			];
		}
		return $valid;
	}

	/**
	 * The kind of change a step makes (T106): '' for a native action, else
	 * the provider id its action (or, for an internal external step, its
	 * field) names.
	 *
	 * @param array<mixed> $step A step as submitted.
	 */
	private static function kind( array $step ): string {
		$action = is_string( $step['action'] ?? null ) ? $step['action'] : '';
		if ( Providers::is_external( $action ) ) {
			return (string) Providers::owner( $action );
		}
		$field = is_array( $step['args'] ?? null ) && is_string( $step['args']['field'] ?? null ) ? $step['args']['field'] : '';
		return Providers::is_external( $field ) ? (string) Providers::owner( $field ) : '';
	}

	/**
	 * Every version 1 action, in the order the panel lists them.
	 *
	 * @param Fields $fields Field registry.
	 * @return list<Operation>
	 */
	private static function defaults( Fields $fields ): array {
		return [
			new SetValue( $fields ),
			new Clear( $fields ),
			new Adjust( $fields ),
			new Round( $fields ),
			new CopyPrice( $fields ),
			new FindReplace( $fields ),
			new AffixSku( $fields ),
			new Terms( $fields, Terms::ADD ),
			new Terms( $fields, Terms::REMOVE ),
			new Terms( $fields, Terms::REPLACE ),
			new RestoreStatus( $fields ),
		];
	}

	/**
	 * A refusal of the list as a whole.
	 *
	 * @param string               $code    `bulkcat_` code.
	 * @param string               $message Plain sentence.
	 * @param array<string, mixed> $data    Extra data.
	 */
	private static function refusal( string $code, string $message, array $data = [] ): \WP_Error {
		return Refusal::invalid( $code, $message, $data );
	}

	/**
	 * A refusal of one step, naming it when the list has more than one.
	 *
	 * @param int                  $step    Position in the list, from 1.
	 * @param int                  $count   Steps in the list.
	 * @param string               $code    `bulkcat_` code.
	 * @param string               $message Plain sentence about the step.
	 * @param array<string, mixed> $data    Extra data.
	 */
	private static function step_refusal( int $step, int $count, string $code, string $message, array $data = [] ): \WP_Error {
		if ( $count > 1 ) {
			$message = sprintf(
				/* translators: 1: the step's position, such as 2, 2: how many steps there are, 3: what is wrong with it and what to do. */
				__( 'Step %1$d of %2$d: %3$s', 'bulk-catalogue-manager-for-woocommerce' ),
				$step,
				$count,
				$message
			);
		}
		unset( $data['status'] );
		return Refusal::invalid( $code, $message, [ 'step' => $step ] + $data );
	}
}
