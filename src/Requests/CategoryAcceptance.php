<?php
/**
 * The checks a new acceptance of a category change makes
 * (docs/EXTENSIONS-v2.md §5.3, §7), inside BatchAcceptance's transaction and
 * before anything is recorded.
 *
 * The steps must still be what the registry makes of them, and one
 * provider's category steps. The scope is built again from the intent's
 * choice, never widened from it: its captured set and scope hash must be the
 * intent's, so a category added under a chosen parent, moved, or deleted
 * since the preview refuses 409 `bulkcat_preview_stale` (`scope_changed`).
 * The preview hash must bind that set, the steps, the settings and the
 * provider's identity, state token over the categories and resolved
 * choices as they are now (`provider_changed`). What is recorded is
 * exactly the captured set, each item `product_cat`, its gone members
 * already failed.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Requests;

use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\CategoryPreview;
use BulkCatalogueManager\Engine\CategorySelection;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\External\Binding;
use BulkCatalogueManager\External\Manifest;
use BulkCatalogueManager\External\ProviderFailure;
use BulkCatalogueManager\External\Refusals;
use BulkCatalogueManager\External\RunContext;
use BulkCatalogueManager\External\TermSubject;
use BulkCatalogueManager\Operations\Registry as Actions;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a category Apply.
 */
final class CategoryAcceptance {
	/**
	 * What to record for a category Apply, or the refusal.
	 *
	 * @param array<string, mixed> $intent      The canonical intent, with `categories`.
	 * @param array<string, mixed> $credentials The request: `preview_hash`.
	 * @param int                  $owner       The requester.
	 * @param Actions|null         $actions     The registry; the shared one by default.
	 * @return array{operations: list<array<string, mixed>>, objects: list<array{0: int, 1: string}>, unavailable: list<array{0: int, 1: string}>, summary: array<string, mixed>, manifest: array<string, mixed>}|\WP_Error
	 */
	public static function check( array $intent, array $credentials, int $owner, ?Actions $actions = null ): array|\WP_Error {
		$actions ??= Actions::instance();
		$context   = RunContext::apply( $owner, 0 );
		$current   = RunContext::within( $context, static fn(): mixed => $actions->validate_list( $intent['operations'] ?? null ) );
		if ( $current instanceof \WP_Error ) {
			return $current;
		}
		if ( wp_json_encode( Preview::canonical( $current ) ) !== wp_json_encode( Preview::canonical( $intent['operations'] ) ) ) {
			return BatchAcceptance::stale();
		}
		$operations = $current;
		$provider   = $actions->external_provider( $operations );
		if ( null === $provider || ! $provider->category_change( $operations ) ) {
			return CategoryPreview::not_categories();
		}
		$chosen = $intent['categories'];
		try {
			$scope = CategorySelection::build( $chosen['chosen'], $chosen['subcategories'] );
		} catch ( \LengthException $e ) {
			return CategorySelection::too_large();
		}
		if ( $scope['ids'] !== $chosen['ids'] || ! hash_equals( $scope['scope_hash'], (string) $intent['scope_hash'] ) ) {
			return BatchAcceptance::stale( 'scope_changed' );
		}
		try {
			$binding = Binding::of( $provider, $operations, $scope['available'], $context );
		} catch ( ProviderFailure $e ) {
			return Refusal::create( Refusals::ERROR, Refusals::error_sentence(), 409 );
		}
		$hash = Preview::hash( $scope['ids'], $operations, Settings::load(), $scope['scope_hash'], $binding );
		if ( ! is_string( $credentials['preview_hash'] ?? null ) || ! hash_equals( $hash, $credentials['preview_hash'] ) ) {
			return BatchAcceptance::stale( 'provider_changed' );
		}
		if ( [] === $scope['available'] ) {
			return Refusal::invalid( BatchFactory::EMPTY, __( 'None of the chosen categories exists now. Choose categories before making a change.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$pair = static fn( int $id ): array => [ $id, TermSubject::TAXONOMY ];
		return [
			'operations'  => $operations,
			'objects'     => array_map( $pair, $scope['ids'] ),
			'unavailable' => array_map( $pair, $scope['unavailable'] ),
			'summary'     => [
				'kind'          => 'categories',
				'taxonomy'      => TermSubject::TAXONOMY,
				'chosen'        => $scope['chosen'],
				'subcategories' => $scope['subcategories'],
				'ids'           => $scope['ids'],
				'count'         => count( $scope['ids'] ),
				'selected'      => count( $scope['chosen'] ),
				'materialised'  => count( $scope['ids'] ),
				'scope_hash'    => $scope['scope_hash'],
				'unavailable'   => count( $scope['unavailable'] ),
			],
			'manifest'    => Manifest::build( $provider, $operations, $binding['choices'] ?? [] ),
		];
	}
}
