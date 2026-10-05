<?php
/**
 * GET /lookup (docs/EXTENSIONS-v2.md §4.3, §4.7): one bounded page of the
 * choices of an add-on action's `lookup` argument, matching a search. The
 * screen right, the add-on's availability and the action's capability are
 * checked first, and for a row (`object`) the user's right to edit that
 * object and the add-on's authorise(); only then is the add-on asked. The
 * answer carries id, label and detail only, and is never cached.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\Context;
use BulkCatalogueManager\Extension\LookupPage;
use BulkCatalogueManager\Extension\ObjectRef;
use BulkCatalogueManager\External\Choices;
use BulkCatalogueManager\External\Guard;
use BulkCatalogueManager\External\ProductSubject;
use BulkCatalogueManager\External\ProviderFailure;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\Registered;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\External\TermSubject;
use BulkCatalogueManager\Operations\Registry as Actions;

defined( 'ABSPATH' ) || exit;

/**
 * The lookup route.
 */
final class LookupController implements ReadController {
	/**
	 * The route.
	 */
	public const ROUTE = '/lookup';

	/**
	 * A malformed lookup request.
	 */
	public const INVALID = 'bulkcat_invalid_lookup';

	/**
	 * The action registry, or how to build it.
	 *
	 * @var Actions|\Closure|null
	 */
	private Actions|\Closure|null $actions;

	/**
	 * Build it.
	 *
	 * @param Actions|\Closure|null $actions The registry, a closure answering it, or null for the shared one.
	 */
	public function __construct( Actions|\Closure|null $actions = null ) {
		$this->actions = $actions;
	}

	/**
	 * The registry.
	 */
	private function actions(): Actions {
		if ( $this->actions instanceof \Closure ) {
			$this->actions = ( $this->actions )();
		}
		return $this->actions ?? Actions::instance();
	}

	/**
	 * Register the route.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ Access::class, 'can_write' ],
					'args'                => [],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * The schema of one page of choices.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$read_only = [
			'context'  => [ 'view', 'edit' ],
			'readonly' => true,
		];
		return [
			'$schema'    => RouteSchema::DIALECT,
			'title'      => 'bulkcat-lookup',
			'type'       => 'object',
			'properties' => [
				'items'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'     => [ 'type' => 'string' ],
							'label'  => [ 'type' => 'string' ],
							'detail' => [ 'type' => 'string' ],
						],
					],
				] + $read_only,
				'page'    => [
					'type'    => 'integer',
					'minimum' => 1,
				] + $read_only,
				'hasMore' => [ 'type' => 'boolean' ] + $read_only,
			],
		];
	}

	/**
	 * One page of choices, or the refusal.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$action   = $request->get_param( 'action' );
		$argument = $request->get_param( 'argument' );
		$query    = $request->get_param( 'query' ) ?? '';
		$page     = $request->get_param( 'page' ) ?? 1;
		$object   = $request->get_param( 'object' );
		$provider = is_string( $action ) ? $this->actions()->providers()->owning( $action ) : null;
		$found    = null === $provider ? null : $provider->described( (string) $action );
		if ( null === $found || ! is_string( $argument ) || ! in_array( $argument, Choices::lookups( $found ), true ) ) {
			return self::invalid( __( 'That list of choices does not exist. Reload the page and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$page = is_int( $page ) || ( is_string( $page ) && 1 === preg_match( '/^[0-9]{1,4}$/', $page ) ) ? (int) $page : 0;
		if ( $page < 1 || $page > LookupPage::MAX_PAGE ) {
			return self::invalid( __( 'That page of choices does not exist.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( ! is_string( $query ) || mb_strlen( trim( $query ) ) > LookupPage::MAX_QUERY ) {
			return self::invalid(
				sprintf(
					/* translators: %d: the longest search allowed, 100. */
					__( 'Search with at most %d characters.', 'bulk-catalogue-manager-for-woocommerce' ),
					LookupPage::MAX_QUERY
				)
			);
		}
		$ref = null;
		if ( null !== $object && '' !== $object ) {
			$ref = is_string( $object ) ? ObjectRef::from_key( $object ) : null;
			// A row of the action's own kind only: a category row for a category action, a product or variation row otherwise.
			$category = ActionDescriptor::TARGET_CATEGORY === ( $found['target'] ?? ActionDescriptor::TARGET_PRODUCT );
			$term     = null !== $ref && ObjectRef::TERM === $ref->kind();
			if ( null === $ref || $term !== $category ) {
				return self::invalid( __( 'That row cannot be used here. Reload the page and try again.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
		}
		$available = $provider->availability();
		if ( true !== $available ) {
			return $available;
		}
		$user = get_current_user_id();
		if ( ! user_can( $user, $found['capability'] ) ) {
			return Refusals::forbidden();
		}
		if ( null !== $ref ) {
			$allowed = self::row( $provider, $ref, $user );
			if ( true !== $allowed ) {
				return $allowed;
			}
		}
		$answer = Choices::page( $provider, (string) $action, $argument, trim( $query ), $page, new RunContext( Context::LOOKUP, $user ) );
		if ( $answer instanceof \WP_Error ) {
			return $answer;
		}
		$response = new \WP_REST_Response(
			[
				'items'   => $answer['items'],
				'page'    => $page,
				'hasMore' => $answer['hasMore'],
			],
			200
		);
		if ( method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store, private' );
		}
		return $response;
	}

	/**
	 * Whether the user may act on a row: the core's edit check, then the add-on's authorise().
	 *
	 * @param Registered $provider The provider.
	 * @param ObjectRef  $ref      The row.
	 * @param int        $user     The user.
	 */
	private static function row( Registered $provider, ObjectRef $ref, int $user ): bool|\WP_Error {
		if ( ObjectRef::TERM === $ref->kind() ) {
			return self::category_row( $provider, $ref, $user );
		}
		$product = wc_get_product( $ref->id() );
		if ( ! is_object( $product ) || ! is_callable( [ $product, 'get_id' ] ) ) {
			return Refusals::forbidden();
		}
		$subject = ProductSubject::of( $product, $provider );
		if ( $subject->type() !== $ref->kind() || true !== Access::user_can_edit_object( $user, $subject->id(), $subject->parent_id() ) ) {
			return Refusals::forbidden();
		}
		try {
			$answer = Guard::run( $provider->id, 'authorise', static fn(): mixed => $provider->provider->authorise( $user, $subject ) );
		} catch ( ProviderFailure $e ) {
			$answer = false;
		}
		return true === $answer ? true : Refusals::forbidden();
	}

	/**
	 * Whether the user may act on a category row (docs/EXTENSIONS-v2.md
	 * §5.4): it still exists, read unfiltered; the user's edit_term on it;
	 * then the add-on's authorise() with the typed category.
	 *
	 * @param Registered $provider The provider.
	 * @param ObjectRef  $ref      The category.
	 * @param int        $user     The user.
	 */
	private static function category_row( Registered $provider, ObjectRef $ref, int $user ): bool|\WP_Error {
		$term = TermSubject::load( $ref->id() );
		if ( null === $term || ! user_can( $user, 'edit_term', $ref->id() ) ) {
			return Refusals::forbidden();
		}
		$subject = new TermSubject( $term, $provider );
		try {
			$answer = Guard::run( $provider->id, 'authorise', static fn(): mixed => $provider->provider->authorise( $user, $subject ) );
		} catch ( ProviderFailure $e ) {
			$answer = false;
		}
		return true === $answer ? true : Refusals::forbidden();
	}

	/**
	 * A 400 refusal.
	 *
	 * @param string $message The sentence.
	 */
	private static function invalid( string $message ): \WP_Error {
		return Refusal::create( self::INVALID, $message, 400 );
	}
}
