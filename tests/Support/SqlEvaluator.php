<?php
/**
 * A small in-memory evaluator for the SELECT statements the read path
 * prepares (ProductQuery's page and count statements with their term
 * sub-queries, the Hydrator's grouped variation count and lookup read), so
 * the seeded-catalogue tests run the real prepared SQL against rows rather
 * than matching its text.
 *
 * It reads exactly the dialect those statements use: SELECT with aliases,
 * COUNT(*) and COUNT(DISTINCT x); FROM one table with INNER and LEFT JOINs;
 * WHERE with AND, OR, NOT, =, <>, !=, <, <=, >, >=, [NOT] IN, [NOT] LIKE
 * (backslash escapes), IS [NOT] NULL, EXISTS and scalar sub-queries
 * (correlated), CASE WHEN, CONCAT (NULL when any part is); GROUP BY; ORDER BY with ASC/DESC; LIMIT and
 * OFFSET; an optimiser hint comment (opening slash, star, plus) is read
 * past. It follows MySQL where the answer depends on it: three-valued logic
 * with NULL, NULL sorting first ascending, numbers compared as numbers, text
 * compared without regard to case, LIKE without regard to case. Anything
 * else throws, so a statement outside the dialect fails a test loudly
 * instead of answering wrongly.
 *
 * Tables are full names (`wp_posts`) => list of rows (column => value, with
 * null for SQL NULL). Result values are strings, as `$wpdb` returns them.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Tests\Support;

if ( ! class_exists( SqlEvaluator::class ) ) {
	final class SqlEvaluator {
		/**
		 * Matches a catalogue count statement, with or without ProductQuery's
		 * optimiser hint, for tests that route canned responses by pattern.
		 */
		public const COUNT = '/^SELECT (\/\*\+ [^*]*\*\/ )?COUNT\(\*\) /';

		/**
		 * Matches a catalogue page statement, with or without the hint.
		 */
		public const PAGE = '/^SELECT (\/\*\+ [^*]*\*\/ )?p\.ID AS id, p\.post_parent AS parent_id/';

		private const KEYWORDS = [ 'SELECT', 'FROM', 'AS', 'LEFT', 'INNER', 'JOIN', 'ON', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'LIKE', 'EXISTS', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'ORDER', 'BY', 'ASC', 'DESC', 'LIMIT', 'OFFSET', 'GROUP', 'COUNT', 'DISTINCT', 'CONCAT' ];

		/**
		 * Tokens of the statement being parsed: [ kind, value ].
		 *
		 * @var array<int, array{0: string, 1: mixed}>
		 */
		private array $tokens = [];

		private int $at = 0;

		/**
		 * @param array<string, list<array<string, mixed>>> $tables Full table name => rows.
		 */
		public function __construct( public array $tables ) {}

		/**
		 * The rows a statement returns, as `$wpdb->get_results( $sql, ARRAY_A )` would.
		 *
		 * @return list<array<string, string|null>>
		 */
		public function rows( string $sql ): array {
			$this->tokens = self::tokenise( $sql );
			$this->at     = 0;
			$select       = $this->select();
			if ( $this->at < count( $this->tokens ) ) {
				throw new \LogicException( 'Unread SQL after position ' . $this->at . ': ' . $sql );
			}
			return array_map(
				static fn( array $row ): array => array_map( static fn( mixed $value ): ?string => null === $value ? null : (string) $value, $row ),
				$this->run( $select, [] )
			);
		}

		/**
		 * The first column of the first row, as `$wpdb->get_var()` would.
		 */
		public function value( string $sql ): ?string {
			$rows = $this->rows( $sql );
			return [] === $rows ? null : reset( $rows[0] );
		}

		/*
		 * Tokeniser.
		 */

		/**
		 * @return array<int, array{0: string, 1: mixed}>
		 */
		private static function tokenise( string $sql ): array {
			$tokens = [];
			$length = strlen( $sql );
			$i      = 0;
			while ( $i < $length ) {
				$char = $sql[ $i ];
				if ( ctype_space( $char ) ) {
					++$i;
					continue;
				}
				if ( str_starts_with( substr( $sql, $i, 3 ), '/*+' ) ) {
					// An optimiser hint (ProductQuery's JOIN_PREFIX) changes a plan, never a result.
					$end = strpos( $sql, '*/', $i + 3 );
					if ( false === $end ) {
						throw new \LogicException( 'Unclosed optimiser hint in: ' . $sql );
					}
					$i = $end + 2;
					continue;
				}
				if ( '`' === $char ) {
					$end      = strpos( $sql, '`', $i + 1 );
					$tokens[] = [ 'table', substr( $sql, $i + 1, (int) $end - $i - 1 ) ];
					$i        = (int) $end + 1;
					continue;
				}
				if ( "'" === $char ) {
					$text = '';
					++$i;
					while ( $i < $length && "'" !== $sql[ $i ] ) {
						if ( '\\' === $sql[ $i ] ) {
							++$i;
							$text .= '0' === $sql[ $i ] ? "\0" : $sql[ $i ];
						} else {
							$text .= $sql[ $i ];
						}
						++$i;
					}
					$tokens[] = [ 'value', $text ];
					++$i;
					continue;
				}
				if ( 1 === preg_match( '/\G-?\d+(\.\d+)?/', $sql, $m, 0, $i ) ) {
					$tokens[] = [ 'value', isset( $m[1] ) ? (float) $m[0] : (int) $m[0] ];
					$i       += strlen( $m[0] );
					continue;
				}
				if ( 1 === preg_match( '/\G(>=|<=|<>|!=|=|<|>|\(|\)|,|\*)/', $sql, $m, 0, $i ) ) {
					$tokens[] = [ 'op', $m[0] ];
					$i       += strlen( $m[0] );
					continue;
				}
				if ( 1 === preg_match( '/\G[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?/', $sql, $m, 0, $i ) ) {
					$upper    = strtoupper( $m[0] );
					$tokens[] = in_array( $upper, self::KEYWORDS, true ) ? [ 'keyword', $upper ] : [ 'column', $m[0] ];
					$i       += strlen( $m[0] );
					continue;
				}
				throw new \LogicException( 'Cannot read SQL at: ' . substr( $sql, $i, 20 ) );
			}
			return $tokens;
		}

		/*
		 * Parser.
		 */

		private function peek( string $kind, mixed $value = null, int $ahead = 0 ): bool {
			$token = $this->tokens[ $this->at + $ahead ] ?? null;
			return null !== $token && $kind === $token[0] && ( null === $value || $value === $token[1] );
		}

		private function accept( string $kind, mixed $value = null ): bool {
			if ( $this->peek( $kind, $value ) ) {
				++$this->at;
				return true;
			}
			return false;
		}

		private function expect( string $kind, mixed $value = null ): mixed {
			if ( ! $this->peek( $kind, $value ) ) {
				throw new \LogicException( sprintf( 'Expected %s %s at token %d, found %s.', $kind, (string) $value, $this->at, wp_json_encode( $this->tokens[ $this->at ] ?? null ) ) );
			}
			return $this->tokens[ $this->at++ ][1];
		}

		/**
		 * @return array<string, mixed>
		 */
		private function select(): array {
			$this->expect( 'keyword', 'SELECT' );
			$columns = [];
			do {
				$expr      = $this->operand();
				$alias     = $this->accept( 'keyword', 'AS' ) ? (string) $this->expect( 'column' ) : self::name( $expr );
				$columns[] = [ $expr, $alias ];
			} while ( $this->accept( 'op', ',' ) );

			$this->expect( 'keyword', 'FROM' );
			$from  = [ (string) $this->expect( 'table' ), $this->accept( 'keyword', 'AS' ) ? (string) $this->expect( 'column' ) : '' ];
			$joins = [];
			while ( $this->peek( 'keyword', 'LEFT' ) || $this->peek( 'keyword', 'INNER' ) ) {
				$type = $this->expect( 'keyword' );
				$this->expect( 'keyword', 'JOIN' );
				$table = (string) $this->expect( 'table' );
				$this->expect( 'keyword', 'AS' );
				$alias = (string) $this->expect( 'column' );
				$this->expect( 'keyword', 'ON' );
				$joins[] = [ $type, $table, $alias, $this->expr() ];
			}
			$where = $this->accept( 'keyword', 'WHERE' ) ? $this->expr() : null;
			$group = null;
			if ( $this->accept( 'keyword', 'GROUP' ) ) {
				$this->expect( 'keyword', 'BY' );
				$group = $this->operand();
			}
			$order = [];
			if ( $this->accept( 'keyword', 'ORDER' ) ) {
				$this->expect( 'keyword', 'BY' );
				do {
					$expr    = $this->operand();
					$order[] = [ $expr, $this->accept( 'keyword', 'DESC' ) ? -1 : 1 ];
					$this->accept( 'keyword', 'ASC' );
				} while ( $this->accept( 'op', ',' ) );
			}
			$limit  = null;
			$offset = 0;
			if ( $this->accept( 'keyword', 'LIMIT' ) ) {
				$limit = (int) $this->expect( 'value' );
				if ( $this->accept( 'keyword', 'OFFSET' ) ) {
					$offset = (int) $this->expect( 'value' );
				}
			}
			return compact( 'columns', 'from', 'joins', 'where', 'group', 'order', 'limit', 'offset' );
		}

		/**
		 * A result column's name without an alias: the column after its table alias.
		 */
		private static function name( array $expr ): string {
			return 'column' === $expr[0] ? (string) preg_replace( '/^.*\./', '', $expr[1] ) : 'expr';
		}

		private function expr(): array {
			$parts = [ $this->conjunction() ];
			while ( $this->accept( 'keyword', 'OR' ) ) {
				$parts[] = $this->conjunction();
			}
			return 1 === count( $parts ) ? $parts[0] : [ 'or', $parts ];
		}

		private function conjunction(): array {
			$parts = [ $this->negation() ];
			while ( $this->accept( 'keyword', 'AND' ) ) {
				$parts[] = $this->negation();
			}
			return 1 === count( $parts ) ? $parts[0] : [ 'and', $parts ];
		}

		private function negation(): array {
			return $this->accept( 'keyword', 'NOT' ) ? [ 'not', $this->negation() ] : $this->predicate();
		}

		private function predicate(): array {
			if ( $this->accept( 'keyword', 'EXISTS' ) ) {
				$this->expect( 'op', '(' );
				$select = $this->select();
				$this->expect( 'op', ')' );
				return [ 'exists', $select ];
			}
			$left = $this->operand();
			foreach ( [ '=', '<>', '!=', '<', '<=', '>', '>=' ] as $op ) {
				if ( $this->accept( 'op', $op ) ) {
					return [ 'compare', $op, $left, $this->operand() ];
				}
			}
			if ( $this->accept( 'keyword', 'IS' ) ) {
				$negate = $this->accept( 'keyword', 'NOT' );
				$this->expect( 'keyword', 'NULL' );
				return [ 'null', $left, $negate ];
			}
			$negate = $this->accept( 'keyword', 'NOT' );
			if ( $this->accept( 'keyword', 'IN' ) ) {
				$this->expect( 'op', '(' );
				$list = [];
				do {
					$list[] = $this->operand();
				} while ( $this->accept( 'op', ',' ) );
				$this->expect( 'op', ')' );
				return [ 'in', $left, $list, $negate ];
			}
			if ( $this->accept( 'keyword', 'LIKE' ) ) {
				return [ 'like', $left, $this->operand(), $negate ];
			}
			if ( $negate ) {
				throw new \LogicException( 'NOT must be followed by IN or LIKE here.' );
			}
			return $left;
		}

		private function operand(): array {
			if ( $this->peek( 'value' ) ) {
				return [ 'value', $this->expect( 'value' ) ];
			}
			if ( $this->peek( 'column' ) ) {
				return [ 'column', $this->expect( 'column' ) ];
			}
			if ( $this->accept( 'keyword', 'NULL' ) ) {
				return [ 'value', null ];
			}
			if ( $this->accept( 'keyword', 'COUNT' ) ) {
				$this->expect( 'op', '(' );
				if ( $this->accept( 'op', '*' ) ) {
					$this->expect( 'op', ')' );
					return [ 'count', null ];
				}
				$this->expect( 'keyword', 'DISTINCT' );
				$expr = $this->operand();
				$this->expect( 'op', ')' );
				return [ 'count', $expr ];
			}
			if ( $this->accept( 'keyword', 'CONCAT' ) ) {
				// T92: the Off filter's own-record prefix, CONCAT( text, p.ID, text ).
				$this->expect( 'op', '(' );
				$parts = [];
				do {
					$parts[] = $this->operand();
				} while ( $this->accept( 'op', ',' ) );
				$this->expect( 'op', ')' );
				return [ 'concat', $parts ];
			}
			if ( $this->accept( 'keyword', 'CASE' ) ) {
				$cases = [];
				while ( $this->accept( 'keyword', 'WHEN' ) ) {
					$when = $this->expr();
					$this->expect( 'keyword', 'THEN' );
					$cases[] = [ $when, $this->operand() ];
				}
				$else = $this->accept( 'keyword', 'ELSE' ) ? $this->operand() : [ 'value', null ];
				$this->expect( 'keyword', 'END' );
				return [ 'case', $cases, $else ];
			}
			$this->expect( 'op', '(' );
			if ( $this->peek( 'keyword', 'SELECT' ) ) {
				$select = $this->select();
				$this->expect( 'op', ')' );
				return [ 'scalar', $select ];
			}
			$expr = $this->expr();
			$this->expect( 'op', ')' );
			return $expr;
		}

		/*
		 * Evaluation. A context is a list of scopes, innermost first; a scope
		 * is alias => row (null for a LEFT JOIN that found nothing).
		 */

		/**
		 * @param array<string, mixed>                          $select Parsed statement.
		 * @param list<array<string, array<string, mixed>|null>> $outer Enclosing scopes.
		 * @return list<array<string, mixed>>
		 */
		private function run( array $select, array $outer ): array {
			[ $table, $alias ] = $select['from'];
			$scopes            = array_map( static fn( array $row ): array => [ ( '' === $alias ? $table : $alias ) => $row ], $this->table( $table ) );
			foreach ( $select['joins'] as [ $type, $join_table, $join_alias, $on ] ) {
				$joined = [];
				foreach ( $scopes as $scope ) {
					$matched = false;
					foreach ( $this->table( $join_table ) as $row ) {
						$candidate = $scope + [ $join_alias => $row ];
						if ( true === $this->evaluate( $on, [ $candidate, ...$outer ] ) ) {
							$joined[] = $candidate;
							$matched  = true;
						}
					}
					if ( ! $matched && 'LEFT' === $type ) {
						$joined[] = $scope + [ $join_alias => null ];
					}
				}
				$scopes = $joined;
			}
			if ( null !== $select['where'] ) {
				$scopes = array_values( array_filter( $scopes, fn( array $scope ): bool => true === $this->evaluate( $select['where'], [ $scope, ...$outer ] ) ) );
			}

			$aggregate = null !== $select['group'] || [] !== array_filter( $select['columns'], static fn( array $column ): bool => 'count' === $column[0][0] );
			if ( $aggregate ) {
				$groups = [];
				foreach ( $scopes as $scope ) {
					$key              = null === $select['group'] ? '' : (string) wp_json_encode( $this->evaluate( $select['group'], [ $scope, ...$outer ] ) );
					$groups[ $key ][] = $scope;
				}
				if ( null === $select['group'] && [] === $groups ) {
					$groups[''] = [];
				}
				$rows = [];
				foreach ( $groups as $members ) {
					$row = [];
					foreach ( $select['columns'] as [ $expr, $name ] ) {
						$row[ $name ] = $this->aggregate( $expr, $members, $outer );
					}
					$rows[] = $row;
				}
				return array_slice( $rows, $select['offset'], $select['limit'] );
			}

			if ( [] !== $select['order'] ) {
				$keyed = [];
				foreach ( $scopes as $index => $scope ) {
					$keys = [];
					foreach ( $select['order'] as [ $expr ] ) {
						$keys[] = $this->evaluate( $expr, [ $scope, ...$outer ] );
					}
					$keyed[] = [ $keys, $index, $scope ];
				}
				usort(
					$keyed,
					function ( array $a, array $b ) use ( $select ): int {
						foreach ( $select['order'] as $position => [ , $direction ] ) {
							$result = self::order( $a[0][ $position ], $b[0][ $position ] ) * $direction;
							if ( 0 !== $result ) {
								return $result;
							}
						}
						return $a[1] <=> $b[1];
					}
				);
				$scopes = array_column( $keyed, 2 );
			}
			$scopes = array_slice( $scopes, $select['offset'], $select['limit'] );

			$rows = [];
			foreach ( $scopes as $scope ) {
				$row = [];
				foreach ( $select['columns'] as [ $expr, $name ] ) {
					$row[ $name ] = $this->evaluate( $expr, [ $scope, ...$outer ] );
				}
				$rows[] = $row;
			}
			return $rows;
		}

		/**
		 * @return list<array<string, mixed>>
		 */
		private function table( string $name ): array {
			if ( ! array_key_exists( $name, $this->tables ) ) {
				throw new \LogicException( "No table $name in the fixture." );
			}
			return $this->tables[ $name ];
		}

		private function aggregate( array $expr, array $members, array $outer ): mixed {
			if ( 'count' !== $expr[0] ) {
				return [] === $members ? null : $this->evaluate( $expr, [ $members[0], ...$outer ] );
			}
			if ( null === $expr[1] ) {
				return count( $members );
			}
			$seen = [];
			foreach ( $members as $scope ) {
				$value = $this->evaluate( $expr[1], [ $scope, ...$outer ] );
				if ( null !== $value ) {
					$seen[ strtolower( (string) $value ) ] = true;
				}
			}
			return count( $seen );
		}

		/**
		 * Evaluate an expression: a value, or for a condition true, false or null (unknown).
		 *
		 * @param array                                         $expr    Parsed expression.
		 * @param list<array<string, array<string, mixed>|null>> $context Scopes, innermost first.
		 */
		private function evaluate( array $expr, array $context ): mixed {
			switch ( $expr[0] ) {
				case 'value':
					return $expr[1];
				case 'column':
					return self::column( $expr[1], $context );
				case 'and':
					$result = true;
					foreach ( $expr[1] as $part ) {
						$value = $this->evaluate( $part, $context );
						if ( false === $value ) {
							return false;
						}
						$result = null === $value ? null : $result;
					}
					return $result;
				case 'or':
					$result = false;
					foreach ( $expr[1] as $part ) {
						$value = $this->evaluate( $part, $context );
						if ( true === $value ) {
							return true;
						}
						$result = null === $value ? null : $result;
					}
					return $result;
				case 'not':
					$value = $this->evaluate( $expr[1], $context );
					return null === $value ? null : ! $value;
				case 'compare':
					$left  = $this->evaluate( $expr[2], $context );
					$right = $this->evaluate( $expr[3], $context );
					if ( null === $left || null === $right ) {
						return null;
					}
					$result = self::order( $left, $right );
					return match ( $expr[1] ) {
						'='        => 0 === $result,
						'<>', '!=' => 0 !== $result,
						'<'        => $result < 0,
						'<='       => $result <= 0,
						'>'        => $result > 0,
						default    => $result >= 0,
					};
				case 'null':
					$value = $this->evaluate( $expr[1], $context );
					return $expr[2] ? null !== $value : null === $value;
				case 'in':
					$value = $this->evaluate( $expr[1], $context );
					if ( null === $value ) {
						return null;
					}
					$result = false;
					foreach ( $expr[2] as $item ) {
						$item = $this->evaluate( $item, $context );
						if ( null === $item ) {
							$result = null;
						} elseif ( 0 === self::order( $value, $item ) ) {
							$result = true;
							break;
						}
					}
					return null === $result ? null : ( $expr[3] ? ! $result : $result );
				case 'like':
					$value   = $this->evaluate( $expr[1], $context );
					$pattern = $this->evaluate( $expr[2], $context );
					if ( null === $value || null === $pattern ) {
						return null;
					}
					$matches = 1 === preg_match( self::like( (string) $pattern ), (string) $value );
					return $expr[3] ? ! $matches : $matches;
				case 'concat':
					$text = '';
					foreach ( $expr[1] as $part ) {
						$value = $this->evaluate( $part, $context );
						if ( null === $value ) {
							return null;
						}
						$text .= (string) $value;
					}
					return $text;
				case 'exists':
					return [] !== $this->run( $expr[1], $context );
				case 'scalar':
					$rows = $this->run( $expr[1], $context );
					return [] === $rows ? null : reset( $rows[0] );
				case 'case':
					foreach ( $expr[1] as [ $when, $then ] ) {
						if ( true === $this->evaluate( $when, $context ) ) {
							return $this->evaluate( $then, $context );
						}
					}
					return $this->evaluate( $expr[2], $context );
			}
			throw new \LogicException( 'Cannot evaluate ' . $expr[0] . ' outside an aggregate.' );
		}

		/**
		 * A column's value from the innermost scope that has its table alias
		 * (or, unqualified, the column).
		 */
		private static function column( string $name, array $context ): mixed {
			$alias  = str_contains( $name, '.' ) ? strstr( $name, '.', true ) : null;
			$column = str_contains( $name, '.' ) ? substr( (string) strstr( $name, '.' ), 1 ) : $name;
			foreach ( $context as $scope ) {
				foreach ( $scope as $scope_alias => $row ) {
					if ( null !== $alias && $alias !== $scope_alias ) {
						continue;
					}
					if ( null === $row ) {
						if ( null !== $alias ) {
							return null;
						}
						continue;
					}
					if ( array_key_exists( $column, $row ) ) {
						return $row[ $column ];
					}
					if ( null !== $alias ) {
						throw new \LogicException( "No column $name in the fixture." );
					}
				}
			}
			throw new \LogicException( "Unknown column $name." );
		}

		/**
		 * MySQL's comparison of two non-null values: as numbers when either
		 * is a number and the other numeric, otherwise as text without regard
		 * to case. NULL sorts first.
		 */
		private static function order( mixed $a, mixed $b ): int {
			if ( null === $a || null === $b ) {
				return ( null === $a ? 0 : 1 ) <=> ( null === $b ? 0 : 1 );
			}
			if ( ( is_int( $a ) || is_float( $a ) || is_int( $b ) || is_float( $b ) ) && is_numeric( $a ) && is_numeric( $b ) ) {
				return (float) $a <=> (float) $b;
			}
			return strcasecmp( (string) $a, (string) $b ) <=> 0;
		}

		/**
		 * A LIKE pattern as a regular expression: % any run, _ one character, a backslash escapes.
		 */
		private static function like( string $pattern ): string {
			$regex  = '';
			$length = strlen( $pattern );
			for ( $i = 0; $i < $length; $i++ ) {
				$char = $pattern[ $i ];
				if ( '\\' === $char && $i + 1 < $length ) {
					$regex .= preg_quote( $pattern[ ++$i ], '/' );
				} elseif ( '%' === $char ) {
					$regex .= '.*';
				} elseif ( '_' === $char ) {
					$regex .= '.';
				} else {
					$regex .= preg_quote( $char, '/' );
				}
			}
			return '/^' . $regex . '$/is';
		}
	}
}
