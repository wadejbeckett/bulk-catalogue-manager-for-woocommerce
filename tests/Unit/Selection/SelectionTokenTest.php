<?php
/**
 * Selection tokens (T19; SPEC §9.1, §12.1, §13): a token round-trips to the
 * selection it was minted from — a filter to the same normalised
 * conditions, an id list in its order with each id once — and a token with
 * any one byte altered, an expired one, one minted for another user, one
 * signed under another salt or anything malformed is refused 403
 * `bcm_invalid_selection`.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Unit\Selection;

use BulkCatalogueManager\Query\Criteria;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;
use PHPUnit\Framework\TestCase;

final class SelectionTokenTest extends TestCase {
	private const NOW = 1790000000;

	protected function setUp(): void {
		bcm_test_reset();
		$GLOBALS['bcm_test']['logged_in'] = true;
		$GLOBALS['bcm_test']['user_id']   = 7;
	}

	/**
	 * A view using every kind of normalised filter value.
	 */
	private static function criteria(): Criteria {
		return Criteria::from_array(
			[
				'mode'     => Criteria::MODE_PRODUCTS_AND_VARIATIONS,
				'search'   => '  Blue "shirt" ünï  ',
				'orderby'  => 'name',
				'page'     => 3,
				'per_page' => 50,
				'filters'  => [
					[
						'field'    => 'category_ids',
						'operator' => 'isAny',
						'value'    => [ '20', 11, 11 ],
					],
					[
						'field'    => 'regular_price',
						'operator' => 'between',
						'value'    => [ '9.5', 20 ],
					],
					[
						'field'    => 'stock_quantity',
						'operator' => 'greaterThanOrEqual',
						'value'    => '-3',
					],
					[
						'field'    => 'featured',
						'operator' => 'is',
						'value'    => 'false',
					],
					[
						'field'    => 'tax_class',
						'operator' => 'isNone',
						'value'    => [ '' ],
					],
					[
						'field'    => 'date_created',
						'operator' => 'inThePast',
						'value'    => [
							'value' => '3',
							'unit'  => 'weeks',
						],
					],
					[
						'field'    => 'date_modified',
						'operator' => 'before',
						'value'    => '2026-09-01',
					],
				],
			]
		);
	}

	/**
	 * Assert a parse was refused 403 `bcm_invalid_selection`.
	 *
	 * @param mixed  $result  parse() result.
	 * @param string $message Failure message.
	 */
	private function check_refused( mixed $result, string $message = '' ): void {
		$this->assertInstanceOf( \WP_Error::class, $result, $message );
		$this->assertSame( 'bcm_invalid_selection', $result->get_error_code(), $message );
		$this->assertSame( 403, $result->get_error_data()['status'], $message );
		$this->assertStringNotContainsString( 'bcm_', $result->get_error_message() );
	}

	public function test_a_filter_token_rematerialises_to_the_same_normalised_conditions(): void {
		$criteria = self::criteria();
		$token    = SelectionToken::mint( Selection::of_filter( $criteria, 42 ), null, self::NOW );
		$this->assertSame( 1, preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token ) );

		$selection = SelectionToken::parse( $token, null, self::NOW + 1 );
		$this->assertInstanceOf( Selection::class, $selection );
		$this->assertTrue( $selection->is_filter() );
		$this->assertSame( 42, $selection->count );
		$this->assertSame( [], $selection->ids );
		$this->assertSame( $criteria->conditions(), $selection->criteria->conditions() );
		$this->assertSame( Criteria::MODE_PRODUCTS_AND_VARIATIONS, $selection->criteria->mode );
		$this->assertSame( 1, $selection->criteria->page, 'Paging is not part of a selection.' );
	}

	public function test_a_variations_read_of_one_product_round_trips_its_parent(): void {
		$criteria  = Criteria::from_array(
			[
				'mode'   => Criteria::MODE_VARIATIONS,
				'parent' => 103,
			]
		);
		$selection = SelectionToken::parse( SelectionToken::mint( Selection::of_filter( $criteria, 2 ), null, self::NOW ), null, self::NOW );
		$this->assertInstanceOf( Selection::class, $selection );
		$this->assertSame( 103, $selection->criteria->parent_id );
		$this->assertSame( $criteria->conditions(), $selection->criteria->conditions() );
	}

	public function test_an_id_token_keeps_the_order_given_with_each_id_once(): void {
		$selection = Selection::of_ids( [ 305, 12, '305', 99, 12, '7' ] );
		$this->assertSame( [ 305, 12, 99, 7 ], $selection->ids );
		$this->assertSame( 4, $selection->count );
		$this->assertFalse( $selection->is_filter() );

		$parsed = SelectionToken::parse( SelectionToken::mint( $selection, null, self::NOW ), null, self::NOW );
		$this->assertInstanceOf( Selection::class, $parsed );
		$this->assertSame( [ 305, 12, 99, 7 ], $parsed->ids );
		$this->assertSame( 4, $parsed->count );
		$this->assertNull( $parsed->criteria );
	}

	public function test_an_id_list_must_hold_positive_ids(): void {
		foreach ( [ [], [ 0 ], [ -4 ], [ 'abc' ], [ 1.5 ], [ '01' ], [ null ], [ 'a' => 1 ] ] as $ids ) {
			try {
				Selection::of_ids( $ids );
				$this->fail( 'Accepted ' . wp_json_encode( $ids ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_any_one_altered_byte_is_refused(): void {
		$tokens = [
			'filter' => SelectionToken::mint( Selection::of_filter( self::criteria(), 42 ), null, self::NOW ),
			'ids'    => SelectionToken::mint( Selection::of_ids( [ 3, 1, 2 ] ), null, self::NOW ),
		];
		foreach ( $tokens as $kind => $token ) {
			$this->assertInstanceOf( Selection::class, SelectionToken::parse( $token, null, self::NOW ) );
			for ( $position = 0, $length = strlen( $token ); $position < $length; $position++ ) {
				$altered              = $token;
				$altered[ $position ] = 'A' === $token[ $position ] ? 'B' : 'A';
				$this->check_refused( SelectionToken::parse( $altered, null, self::NOW ), "$kind token, byte $position." );
			}
			$this->check_refused( SelectionToken::parse( $token . 'A', null, self::NOW ), "$kind token, a byte added." );
			$this->check_refused( SelectionToken::parse( substr( $token, 0, -1 ), null, self::NOW ), "$kind token, a byte removed." );
		}
	}

	public function test_an_expired_token_is_refused(): void {
		$token = SelectionToken::mint( Selection::of_ids( [ 5 ] ), null, self::NOW );
		$this->assertSame( 900, SelectionToken::ttl(), 'Fifteen minutes by default.' );
		$this->assertInstanceOf( Selection::class, SelectionToken::parse( $token, null, self::NOW + 899 ) );
		$this->check_refused( SelectionToken::parse( $token, null, self::NOW + 900 ) );
		$this->check_refused( SelectionToken::parse( $token, null, self::NOW + 86400 ) );
	}

	public function test_the_lifetime_is_filterable_within_bounds(): void {
		add_filter( 'bcm_selection_ttl', static fn(): int => 120 );
		$token = SelectionToken::mint( Selection::of_ids( [ 5 ] ), null, self::NOW );
		$this->assertInstanceOf( Selection::class, SelectionToken::parse( $token, null, self::NOW + 119 ) );
		$this->check_refused( SelectionToken::parse( $token, null, self::NOW + 120 ) );

		bcm_test_reset();
		add_filter( 'bcm_selection_ttl', static fn(): int => 1 );
		$this->assertSame( SelectionToken::MIN_TTL, SelectionToken::ttl() );
		add_filter( 'bcm_selection_ttl', static fn(): int => 10 * 86400, 20 );
		$this->assertSame( SelectionToken::MAX_TTL, SelectionToken::ttl() );
		add_filter( 'bcm_selection_ttl', static fn(): string => 'forever', 30 );
		$this->assertSame( SelectionToken::TTL, SelectionToken::ttl() );
	}

	public function test_a_token_minted_for_another_user_is_refused(): void {
		$token = SelectionToken::mint( Selection::of_ids( [ 5 ] ), 7, self::NOW );
		$this->assertInstanceOf( Selection::class, SelectionToken::parse( $token, 7, self::NOW ) );
		$this->check_refused( SelectionToken::parse( $token, 8, self::NOW ) );
		$this->check_refused( SelectionToken::parse( $token, 0, self::NOW ), 'A visitor.' );

		$GLOBALS['bcm_test']['user_id'] = 8;
		$this->check_refused( SelectionToken::parse( $token, null, self::NOW ), 'The current user by default.' );
	}

	public function test_a_token_signed_under_another_salt_is_refused(): void {
		$token                       = SelectionToken::mint( Selection::of_ids( [ 5 ] ), null, self::NOW );
		$GLOBALS['bcm_test']['salt'] = 'rotated';
		$this->check_refused( SelectionToken::parse( $token, null, self::NOW ) );
	}

	public function test_malformed_tokens_are_refused(): void {
		$token                = SelectionToken::mint( Selection::of_ids( [ 5 ] ), null, self::NOW );
		[ $body, $signature ] = explode( '.', $token );
		foreach ( [ '', '.', 'abc', $body, $body . '.', '.' . $signature, $signature . '.' . $body, $token . '.' . $signature, $body . '.' . $signature . '=', 12, null, [ $token ] ] as $candidate ) {
			$this->check_refused( SelectionToken::parse( $candidate, null, self::NOW ), (string) wp_json_encode( $candidate ) );
		}
	}

	public function test_a_token_is_made_only_for_a_user(): void {
		$GLOBALS['bcm_test']['user_id'] = 0;
		try {
			SelectionToken::mint( Selection::of_ids( [ 5 ] ) );
			$this->fail( 'A token was minted for a visitor.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'logged-in user', $e->getMessage() );
		}
	}
}
