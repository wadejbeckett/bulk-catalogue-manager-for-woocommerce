<?php
/**
 * Exact flat serializer2 Preview transport; legacy keys are not repurposed.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\External\V2\{TypedScope, IntentCodec, Rules};
use BulkCatalogueManager\Requests\RequestFamily;
use BulkCatalogueManager\Engine\Preview;
defined( 'ABSPATH' ) || exit;
/** Optional schemas never introduce absent selection keys. */
final class TypedPreviewInput {
	/** The optional typed request fields. */
	public static function args(): array {
		return [
			'serializer'  => [
				'type' => 'integer',
				'enum' => [ 2 ],
			],
			'family'      => [
				'type' => 'string',
				'enum' => [ 'product', 'category' ],
			],
			'external'    => [ 'type' => 'object' ],
			'entry'       => [
				'type' => 'string',
				'enum' => [ 'product.row', 'product.bulk', 'category.row', 'category.bulk' ],
			],
			'targets'     => [
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 1000,
				'items'    => [ 'type' => 'object' ],
			],
			'typed_token' => [
				'type'      => 'string',
				'minLength' => 1,
			],
		];
	}
	/**
	 * Recognize typed intent even when malformed, preventing legacy downgrade.
	 * @param array $params Submitted fields.
	 */
	public static function present( array $params ): bool {
		return [] !== array_intersect( array_keys( $params ), array_keys( self::args() ) );}
	/**
	 * Normalize exactly one selection and derive its family from immutable members.
	 * @param array $params Flat body.
	 * @param int $actor Actor.
	 * @return array|\WP_Error
	 */
	public static function normalize( array $params, int $actor ): array|\WP_Error {
		try {
			$params = array_diff_key( $params, array_flip( [ '_locale','_wpnonce','rest_route' ] ) );
			Rules::json( $params, 65536 );
			Rules::ensure( [] === array_diff( array_keys( $params ), [ ...array_keys( self::args() ),'token','operations','sample_size' ] ), 'Unknown preview option.' );
			Rules::ensure( 2 === ( $params['serializer'] ?? null ), 'Unsupported preview serializer.' );
			IntentCodec::external( $params['external'] ?? null );
			IntentCodec::steps( $params['operations'] ?? null );
			$selection = array_intersect_key( $params, array_flip( [ 'targets','typed_token','token' ] ) );
			$scope     = TypedScope::from_request( $selection, $actor );
			if ( is_wp_error( $scope ) ) {
				return $scope;}
			$family = RequestFamily::from_scope( $scope );
			if ( is_wp_error( $family ) ) {
				return $family;}
			Rules::ensure( ! array_key_exists( 'family', $params ) || $params['family'] === $family, 'False family assertion.' );
			Rules::ensure( in_array( $params['entry'] ?? null, [ $family . '.row',$family . '.bulk' ], true ), 'Entry family mismatch.' );
			$size = $params['sample_size'] ?? Preview::DEFAULT_SAMPLE;
			$size = 'all' === $size ? Preview::SAMPLE_ALL : $size;
			Rules::ensure( is_int( $size ) && in_array( $size, [ ...Preview::SAMPLE_SIZES,Preview::SAMPLE_ALL ], true ), 'Invalid sample size.' );
			return [
				'serializer'  => 2,
				'family'      => $family,
				'external'    => $params['external'],
				'entry'       => $params['entry'],
				'operations'  => $params['operations'],
				'sample_size' => $size,
			] + $selection;
		} catch ( \InvalidArgumentException $error ) {
			return RequestsController::unknown();}
	}
}
