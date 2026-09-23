<?php
/**
 * `POST /bcm/v1/batches` (SPEC §9.2, §12.1, §12.6): queues a change. The
 * body carries the client's request id (`uuid`), the operation list
 * (`operations`, `[ { action, args } ]`), the selection — an `ids` list, or
 * the signed `token` from `POST /selection`, exactly one of them — and the
 * `source` (bulk by default; inline or tree). Undo batches are created by the
 * undo route through Engine\BatchFactory, not here.
 *
 * It writes products, so it needs the write capability (Access::can_write);
 * each object is checked again as it is written. Engine\BatchFactory does the
 * work: a new batch answers 201, a repeat of a request id within sixty
 * seconds answers 200 with the same batch, both as
 * `{ batch_id, status, total_items, replayed }`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;

defined( 'ABSPATH' ) || exit;

/**
 * The batches route.
 */
final class BatchesController implements Controller {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/batches';

	/**
	 * Sources a request may name; `undo` comes only from the undo route.
	 */
	public const SOURCES = [ 'bulk', 'inline', 'tree' ];

	/**
	 * Refusal code for a body with neither or both of `ids` and `token`.
	 */
	public const SELECTION_REQUIRED = 'bcm_selection_required';

	/**
	 * A request id: a UUID in its 8-4-4-4-12 hexadecimal form.
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The factory.
	 *
	 * @var BatchFactory|null
	 */
	private ?BatchFactory $factory;

	/**
	 * Build a controller.
	 *
	 * @param BatchFactory|null $factory Batch factory; built when first needed by default.
	 */
	public function __construct( ?BatchFactory $factory = null ) {
		$this->factory = $factory;
	}

	/**
	 * Add the route through the registrar.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ Access::class, 'can_write' ],
					'args'                => self::args(),
				],
			]
		);
	}

	/**
	 * The route's arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function args(): array {
		return [
			'uuid'       => [
				'description'       => __( 'A request id the screen makes once per change, so sending it twice creates one change.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'              => 'string',
				'format'            => 'uuid',
				'required'          => true,
				'validate_callback' => static function ( mixed $value ): bool|\WP_Error {
					if ( is_string( $value ) && 1 === preg_match( self::UUID, $value ) ) {
						return true;
					}
					return Refusal::invalid( 'bcm_invalid_value', __( 'This change could not be sent because its request id is missing or damaged. Reload the page and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
				},
				'sanitize_callback' => static fn( mixed $value ): string => strtolower( (string) $value ),
			],
			'operations' => [
				'description'       => __( 'The changes to make, in order: each an action and its arguments.', 'bulk-catalogue-manager-for-woocommerce' ),
				'type'              => 'array',
				'items'             => [ 'type' => 'object' ],
				'required'          => true,
				// Operations\Registry::validate_list() checks the list in full, with its own refusals.
				'validate_callback' => static fn(): bool => true,
				'sanitize_callback' => static fn( mixed $value ): mixed => $value,
			],
			'ids'        => self::arg(
				[
					'description' => __( 'The chosen products or variations, by id, instead of a selection token.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'minItems'    => 1,
				],
				[ self::class, 'ids' ]
			),
			'token'      => self::arg(
				[
					'description' => __( 'The selection token from the selection route, instead of ids.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
				],
				'strval'
			),
			'source'     => self::arg(
				[
					'description' => __( 'Where the change was made: the bulk panel, a grid cell or the category manager.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
					'enum'        => self::SOURCES,
					'default'     => 'bulk',
				],
				'strval'
			),
		];
	}

	/**
	 * Queue the posted change, or answer the one this request id already queued.
	 *
	 * @param \WP_REST_Request $request The request, its arguments validated and sanitised by core.
	 */
	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$factory = $this->factory();
		$uuid    = (string) $request->get_param( 'uuid' );
		$user_id = get_current_user_id();

		// A repeat answers before its selection is read again: the token may have expired meanwhile.
		$existing = $factory->replayed( $uuid, $user_id );
		if ( null === $existing ) {
			$selection = self::selection( $request->get_param( 'ids' ), $request->get_param( 'token' ), $user_id );
			if ( $selection instanceof \WP_Error ) {
				return $selection;
			}
			$result = $factory->create( $uuid, $user_id, $request->get_param( 'operations' ), $selection, (string) ( $request->get_param( 'source' ) ?? 'bulk' ) );
		} else {
			$result = $factory->replay( $existing );
		}
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		return new \WP_REST_Response(
			[
				'batch_id'    => $result['batch_id'],
				'status'      => $result['status'],
				'total_items' => $result['total_items'],
				'replayed'    => $result['replayed'],
			],
			$result['replayed'] ? 200 : 201
		);
	}

	/**
	 * The selection a body names, from exactly one of `ids` and `token`.
	 *
	 * @param mixed $ids     The `ids` argument, sanitised.
	 * @param mixed $token   The `token` argument.
	 * @param int   $user_id The user presenting it.
	 */
	private static function selection( mixed $ids, mixed $token, int $user_id ): Selection|\WP_Error {
		if ( ( null === $ids ) === ( null === $token ) ) {
			return Refusal::invalid(
				self::SELECTION_REQUIRED,
				__( 'This change does not say which products to change. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		if ( null !== $token ) {
			return SelectionToken::parse( $token, $user_id );
		}
		try {
			return Selection::of_ids( (array) $ids );
		} catch ( \InvalidArgumentException $e ) {
			return Refusal::invalid(
				'bcm_invalid_value',
				__( 'These products could not be selected. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'details' => $e->getMessage() ]
			);
		}
	}

	/**
	 * The `ids` argument as whole numbers: a list, or comma-separated text as core accepts it.
	 *
	 * @param mixed $value The argument.
	 * @return list<int>
	 */
	public static function ids( mixed $value ): array {
		$list = is_array( $value ) ? array_values( $value ) : preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		return array_map( 'intval', (array) $list );
	}

	/**
	 * The factory, built on first use.
	 */
	private function factory(): BatchFactory {
		$this->factory ??= new BatchFactory();
		return $this->factory;
	}

	/**
	 * An argument validated against its own schema, with a sanitiser.
	 *
	 * @param array<string, mixed> $schema   JSON Schema of the argument.
	 * @param callable             $sanitise Sanitiser.
	 * @return array<string, mixed>
	 */
	private static function arg( array $schema, callable $sanitise ): array {
		$schema['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $schema ): bool|\WP_Error {
			unset( $request );
			return rest_validate_value_from_schema( $value, $schema, $param );
		};
		$schema['sanitize_callback'] = static fn( mixed $value ): mixed => $sanitise( $value );
		return $schema;
	}
}
