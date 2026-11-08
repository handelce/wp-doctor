<?php
/**
 * Tables and column-to-column equalities named in a SELECT.
 *
 * @since 0.3.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

/**
 * Reads the tables after FROM and JOIN, with their aliases, and the
 * conditions of the form `a.col = b.col`, with string literals masked so text
 * inside them is not taken for SQL. It is a reading of the statement's text,
 * not a parser: derived tables and other unusual forms are passed over.
 *
 * @since 0.3.0
 */
final class Join_Columns {

	private const NAME     = '[A-Za-z0-9_$]+';
	private const KEYWORDS = 'ON|USING|WHERE|JOIN|INNER|LEFT|RIGHT|OUTER|CROSS|STRAIGHT_JOIN|NATURAL|GROUP|ORDER|LIMIT|HAVING|UNION|FOR|LOCK|WINDOW|USE|FORCE|IGNORE|SET';

	/**
	 * Table name by alias, both lowercase. A table without an alias maps to itself.
	 *
	 * @since 0.3.0
	 *
	 * @var array<string, string>
	 */
	private $tables = array();

	/**
	 * Equalities between two qualified columns: alias, column, alias, column, all lowercase.
	 *
	 * @since 0.3.0
	 *
	 * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
	 */
	private $joins = array();

	/**
	 * Reads a statement.
	 *
	 * @since 0.3.0
	 *
	 * @param string $sql A SELECT statement.
	 */
	public function __construct( string $sql ) {
		$masked = (string) preg_replace( '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"/s', "''", $sql );
		$masked = str_replace( '`', '', $masked );
		$name   = self::NAME;
		$alias  = '(?:\s+(?:AS\s+)?(?!(?:' . self::KEYWORDS . ')\b)(' . $name . '))?';

		if ( false !== preg_match_all( "/\\bJOIN\\s+(?:$name\\.)?($name)$alias/i", $masked, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$this->add_table( $match[1], $match[2] ?? '' );
			}
		}
		if ( false !== preg_match_all( '/\bFROM\s+(.*?)(?=\b(?:WHERE|GROUP|ORDER|LIMIT|HAVING|JOIN|INNER|LEFT|RIGHT|CROSS|STRAIGHT_JOIN|NATURAL|UNION|WINDOW|FOR)\b|\)|$)/is', $masked, $clauses ) ) {
			foreach ( $clauses[1] as $clause ) {
				foreach ( explode( ',', $clause ) as $item ) {
					if ( 1 === preg_match( "/^\\s*(?:$name\\.)?($name)$alias\\s*$/i", $item, $match ) ) {
						$this->add_table( $match[1], $match[2] ?? '' );
					}
				}
			}
		}
		if ( false !== preg_match_all( "/\\b($name)\\.($name)\\s*=\\s*($name)\\.($name)\\b/", $masked, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$join = array( strtolower( $match[1] ), strtolower( $match[2] ), strtolower( $match[3] ), strtolower( $match[4] ) );
				if ( $join[0] !== $join[2] ) {
					$this->joins[] = $join;
				}
			}
		}
	}

	/**
	 * Table name by alias.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	public function tables(): array {
		return $this->tables;
	}

	/**
	 * Equalities whose two sides are both columns of tables in the statement.
	 *
	 * @since 0.3.0
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public function joins(): array {
		$known = array();
		foreach ( $this->joins as $join ) {
			if ( isset( $this->tables[ $join[0] ], $this->tables[ $join[2] ] ) ) {
				$known[] = $join;
			}
		}

		return $known;
	}

	/**
	 * Records a table and its alias.
	 *
	 * @since 0.3.0
	 *
	 * @param string $table Table name.
	 * @param string $alias Alias, or empty for none.
	 * @return void
	 */
	private function add_table( string $table, string $alias ): void {
		$table = strtolower( $table );
		$this->tables[ '' === $alias ? $table : strtolower( $alias ) ] = $table;
		$this->tables[ $table ]                                        = $this->tables[ $table ] ?? $table;
	}
}
