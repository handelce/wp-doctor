<?php
/**
 * Joins between columns that cannot share an index.
 *
 * @since 0.3.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Db\Database_Exception;
use WP_Doctor\Db\Read_Only_Violation_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * A join on `a.x = b.y` can use an index on one side only when the values
 * compare without conversion. When one column holds numbers and the other
 * text, or both hold text in different character sets, the server converts
 * every value of one side and reads that table in full; two collations of the
 * same character set make it refuse the statement instead (error 1267), which
 * is reported too. For each statement given
 * with --queries this check reads the column-to-column equalities, looks up
 * both columns' types and collations, and runs EXPLAIN. A mismatch is
 * reported when EXPLAIN shows a full scan of either table (type ALL or
 * index, or no key). The statements pass through the read-only gate, and
 * EXPLAIN does not run them. Only the joins are shown, never the statement.
 *
 * @since 0.3.0
 */
final class Slow_Query_Index_Check implements Check {

	private const NUMERIC    = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'bit', 'year' );
	private const TEXT       = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set' );
	private const FULL_SCANS = array( 'all', 'index' );

	private const ILLEGAL_COLLATION_MIX = 1267;

	/**
	 * Id of the index mismatch finding.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.slowquery.indexcheck';
	}

	/**
	 * Grouped under performance.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Examines each supplied statement.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the statements from --queries and the database.
	 * @return Result
	 * @throws \UnexpectedValueException When the database returns an unexpected row.
	 */
	public function run( Context $context ): Result {
		$queries = $context->queries();
		if ( null === $queries ) {
			return Result::skipped( 'No statements were given; pass --queries=FILE with one SELECT per line to check them' );
		}
		$unavailable = $context->database_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$examined = 0;
		$flagged  = array();
		$failed   = array();
		foreach ( $queries->all() as $line => $sql ) {
			$refused = false;
			try {
				$plan = $this->explain( $context, $sql );
			} catch ( Read_Only_Violation_Exception $e ) {
				$failed[ $line ] = 'refused by the read-only gate' . ( 1 === preg_match( '/^Statement refused \(([^)]*)\)/', $e->getMessage(), $m ) ? " ($m[1])" : '' );
				continue;
			} catch ( Database_Exception $e ) {
				$error = 1 === preg_match( '/\(error (\d+)\)/', $e->getMessage(), $m ) ? (int) $m[1] : 0;
				if ( self::ILLEGAL_COLLATION_MIX !== $error ) {
					$failed[ $line ] = 'EXPLAIN failed' . ( 0 === $error ? '' : " with error $error" );
					continue;
				}
				$plan    = array();
				$refused = true;
			}
			++$examined;
			$joins = new Join_Columns( $sql );
			if ( array() === $joins->joins() ) {
				if ( $refused ) {
					$failed[ $line ] = 'the server refuses it for an illegal mix of collations, in a form whose joins were not read';
				}
				continue;
			}
			$columns = $this->columns( $context, array_values( array_unique( $joins->tables() ) ) );
			foreach ( $joins->joins() as $join ) {
				$problem = $this->problem( $join, $joins->tables(), $columns, $plan, $refused );
				if ( null !== $problem ) {
					$flagged[] = array( $line, $problem[0], $problem[1] );
				}
			}
		}

		if ( array() === $flagged ) {
			if ( array() === $failed ) {
				return Result::pass();
			}

			return Result::skipped( "No join in the $examined statement(s) examined compares columns that cannot share an index, but " . count( $failed ) . ' could not be examined: ' . self::listed( $failed ) );
		}

		$evidence = array(
			'statements_given'    => count( $queries->all() ),
			'statements_examined' => $examined,
			'joins_flagged'       => count( $flagged ),
			'scan_incomplete'     => array() !== $failed,
		);
		if ( array() !== $failed ) {
			$evidence['not_examined'] = self::listed( $failed );
		}
		foreach ( array_slice( $flagged, 0, Evidence::MAX_LISTED ) as $index => $row ) {
			$rank                            = $index + 1;
			$evidence[ "join_{$rank}_line" ] = $row[0];
			$evidence[ "join_$rank" ]        = $row[1];
			$evidence[ "join_{$rank}_plan" ] = $row[2];
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Queries likely join columns that cannot share an index',
				$evidence,
				'Give both columns of each join the same type, and for text the same collation, so the server can use an index. '
				. 'After a backup, change the column that differs, for example `ALTER TABLE <table> MODIFY <column> BIGINT UNSIGNED NOT NULL` '
				. 'or `ALTER TABLE <table> CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci`. If the table belongs to a '
				. 'plugin, report it to the plugin\'s author instead.'
			)
		);
	}

	/**
	 * How the server would read each table of a statement, by the name EXPLAIN shows.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the database.
	 * @param string  $sql     A SELECT statement.
	 * @return array<string, array{type: string, key: string}> Access type and key by lowercase table name or alias.
	 * @throws Read_Only_Violation_Exception When the gate refuses the statement.
	 * @throws Database_Exception            When the server cannot explain it.
	 */
	private function explain( Context $context, string $sql ): array {
		$plan = array();
		foreach ( $context->database()->query( 'EXPLAIN ' . $sql ) as $row ) {
			if ( ! isset( $row['table'] ) ) {
				continue;
			}
			$plan[ strtolower( $row['table'] ) ] = array(
				'type' => strtolower( (string) ( $row['type'] ?? '' ) ),
				'key'  => (string) ( $row['key'] ?? '' ),
			);
		}

		return $plan;
	}

	/**
	 * Type and collation of every column of the given tables.
	 *
	 * @since 0.3.0
	 *
	 * @param Context  $context Gives the database.
	 * @param string[] $tables  Lowercase table names.
	 * @return array<string, array{type: string, collation: string}> By "table.column", lowercase.
	 * @throws \UnexpectedValueException When the database returns an unexpected row.
	 */
	private function columns( Context $context, array $tables ): array {
		$names = array();
		foreach ( $tables as $table ) {
			if ( 1 === preg_match( '/^[a-z0-9_$]{1,64}$/', $table ) ) {
				$names[] = "'$table'";
			}
		}
		if ( array() === $names ) {
			return array();
		}
		$rows    = $context->database()->query(
			'SELECT table_name AS t, column_name AS c, data_type AS type, collation_name AS collation FROM information_schema.columns'
			. ' WHERE table_schema = DATABASE() AND LOWER(table_name) IN (' . implode( ', ', $names ) . ')'
		);
		$columns = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['t'], $row['c'], $row['type'] ) ) {
				throw new \UnexpectedValueException( 'The column query returned an unexpected row' );
			}
			$columns[ strtolower( $row['t'] . '.' . $row['c'] ) ] = array(
				'type'      => strtolower( $row['type'] ),
				'collation' => (string) ( $row['collation'] ?? '' ),
			);
		}

		return $columns;
	}

	/**
	 * The join and the scan it causes, when the two columns cannot share an index and EXPLAIN shows a full scan. Null otherwise.
	 *
	 * @since 0.3.0
	 *
	 * @param array{0: string, 1: string, 2: string, 3: string}     $join    Alias, column, alias, column.
	 * @param array<string, string>                                 $tables  Table name by alias.
	 * @param array<string, array{type: string, collation: string}> $columns By "table.column".
	 * @param array<string, array{type: string, key: string}>       $plan    EXPLAIN rows by table name or alias.
	 * @param bool                                                  $refused Whether the server refused the statement for an illegal mix of collations.
	 * @return array{0: string, 1: string}|null
	 */
	private function problem( array $join, array $tables, array $columns, array $plan, bool $refused ): ?array {
		$left  = $columns[ $tables[ $join[0] ] . '.' . $join[1] ] ?? null;
		$right = $columns[ $tables[ $join[2] ] . '.' . $join[3] ] ?? null;
		if ( null === $left || null === $right ) {
			return null;
		}
		$left_text  = in_array( $left['type'], self::TEXT, true );
		$right_text = in_array( $right['type'], self::TEXT, true );
		$mixed      = ( $left_text && in_array( $right['type'], self::NUMERIC, true ) ) || ( $right_text && in_array( $left['type'], self::NUMERIC, true ) );
		$collations = $left_text && $right_text && '' !== $left['collation'] && '' !== $right['collation'] && $left['collation'] !== $right['collation'];
		if ( ! $mixed && ! $collations ) {
			return null;
		}
		$described = $tables[ $join[0] ] . '.' . $join[1] . ' (' . self::describe( $left ) . ') = ' . $tables[ $join[2] ] . '.' . $join[3] . ' (' . self::describe( $right ) . ')';
		if ( $refused ) {
			return $collations ? array( $described, 'the server refuses the statement: illegal mix of collations (error 1267)' ) : null;
		}
		$scans = array();
		foreach ( array( $join[0], $join[2] ) as $alias ) {
			$row = $plan[ $alias ] ?? null;
			if ( null !== $row && ( in_array( $row['type'], self::FULL_SCANS, true ) || '' === $row['key'] ) ) {
				$scans[] = $tables[ $alias ] . ( $alias === $tables[ $alias ] ? '' : " ($alias)" ) . ', type ' . strtoupper( $row['type'] ) . ( '' === $row['key'] ? ', no key' : '' );
			}
		}
		if ( array() === $scans ) {
			return null;
		}

		return array( $described, 'EXPLAIN reads in full: ' . implode( '; ', $scans ) );
	}

	/**
	 * A column's type, with its collation when it holds text.
	 *
	 * @since 0.3.0
	 *
	 * @param array{type: string, collation: string} $column Column details.
	 * @return string
	 */
	private static function describe( array $column ): string {
		return $column['type'] . ( '' === $column['collation'] ? '' : ', ' . $column['collation'] );
	}

	/**
	 * Line numbers with their reasons, for a message.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, string> $failed Reason by line.
	 * @return string
	 */
	private static function listed( array $failed ): string {
		$parts = array();
		foreach ( array_slice( $failed, 0, 5, true ) as $line => $reason ) {
			$parts[] = "line $line: $reason";
		}

		return implode( '; ', $parts ) . ( count( $failed ) > 5 ? '; ...' : '' );
	}
}
