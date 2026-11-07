<?php
/**
 * Allow-list for SQL statements.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Db;

use function WP_Doctor\esc_cli;

/**
 * Accepts SELECT, SHOW, DESCRIBE of a table, and EXPLAIN or DESCRIBE of a
 * SELECT, and nothing else.
 *
 * Starting with a read verb is not enough. Comments, backslashes and stacked
 * statements are refused because their meaning depends on server SQL mode,
 * and a parser that disagrees with the server lets injection through.
 * Clauses that write files or take locks on a read-only verb are refused too,
 * and so is any call of a function whose name is quoted, because the
 * denied-function list can only be matched against unquoted names.
 *
 * @since 0.1.0
 */
final class Statement_Gate {

	private const MAX_LENGTH = 65536;

	/**
	 * A string literal, or a quoted identifier (group 1) with any parenthesis that follows it (group 2).
	 *
	 * @since 0.1.0
	 */
	private const LITERAL_OR_IDENTIFIER = '/\'(?:[^\']|\'\')*\'|("(?:[^"]|"")*"|`(?:[^`]|``)*`)(\s*\()?/';

	private const DENIED_FUNCTIONS = 'SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|RELEASE_ALL_LOCKS|IS_USED_LOCK|IS_FREE_LOCK|LOAD_FILE|SYS_EXEC|SYS_EVAL'
		. '|MASTER_POS_WAIT|SOURCE_POS_WAIT|MASTER_GTID_WAIT|WAIT_FOR_EXECUTED_GTID_SET|WAIT_UNTIL_SQL_THREAD_AFTER_GTIDS'
		. '|NEXTVAL|SETVAL|LASTVAL'
		. '|SERVICE_\w*LOCKS|VERSION_TOKENS_\w+|GROUP_REPLICATION_\w+|ASYNCHRONOUS_CONNECTION_FAILOVER_\w+|KEYRING_\w+|AUDIT_LOG_\w+';

	/**
	 * Masked, upper-cased forms of the allowed statements. `?` stands for a literal or quoted identifier.
	 *
	 * @since 0.1.0
	 */
	private const ALLOWED_FORMS = array(
		'/^SELECT\b/',
		'/^SHOW\b/',
		'/^(EXPLAIN|DESCRIBE|DESC)\s+(FORMAT\s*=\s*[A-Z_]+\s+)?SELECT\b/',
		'/^(DESCRIBE|DESC)\s+(?!(SELECT|DELETE|INSERT|REPLACE|UPDATE|TABLE|WITH|VALUES|ANALYZE|FORMAT|EXTENDED|PARTITIONS|FOR)\b)(\?|[A-Z0-9_$\x80-\xFF]+)(\s*\.\s*(\?|[A-Z0-9_$\x80-\xFF]+))?(\s+(\?|[A-Z0-9_$\x80-\xFF]+))?$/',
	);

	/**
	 * Refuses a statement that is not an allowed read.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql Statement a check wants to run.
	 * @throws Read_Only_Violation_Exception When the statement is not allowed.
	 */
	public function assert_allowed( string $sql ): void {
		if ( '' === trim( $sql ) ) {
			$this->reject( $sql, 'empty statement' );
		}
		if ( strlen( $sql ) > self::MAX_LENGTH ) {
			$this->reject( $sql, 'statement too long' );
		}
		if ( 1 === preg_match( '/[^\x09\x0A\x0D\x20-\x7E\x80-\xFF]/', $sql ) ) {
			$this->reject( $sql, 'control character' );
		}
		if ( false !== strpos( $sql, '\\' ) ) {
			$this->reject( $sql, 'backslash' );
		}

		$quoted_call = false;
		$masked      = preg_replace_callback(
			self::LITERAL_OR_IDENTIFIER,
			static function ( array $token ) use ( &$quoted_call ): string {
				if ( isset( $token[2] ) && '' !== $token[2] ) {
					$quoted_call = true;
				}
				return '?' . ( $token[2] ?? '' );
			},
			$sql
		);
		if ( null === $masked ) {
			$this->reject( $sql, 'statement could not be analysed' );
		}
		if ( false !== strpbrk( $masked, '\'"`' ) ) {
			$this->reject( $sql, 'unterminated quote' );
		}
		if ( false !== strpos( $masked, ';' ) ) {
			$this->reject( $sql, 'more than one statement' );
		}
		if ( 1 === preg_match( '/--|#|\/\*|\*\//', $masked ) ) {
			$this->reject( $sql, 'comment' );
		}

		if ( $quoted_call ) {
			$this->reject( $sql, 'call of a function with a quoted name' );
		}

		$upper   = strtoupper( trim( $masked ) );
		$allowed = false;
		foreach ( self::ALLOWED_FORMS as $form ) {
			$allowed = $allowed || 1 === preg_match( $form, $upper );
		}
		if ( ! $allowed ) {
			$this->reject( $sql, 'only SELECT, SHOW, DESCRIBE of a table and EXPLAIN SELECT are allowed' );
		}

		$denied = array(
			'/\bINTO\b/'                                => 'INTO',
			'/\bFOR\s+(UPDATE|SHARE)\b/'                => 'locking read',
			'/\bLOCK\s+IN\s+SHARE\s+MODE\b/'            => 'locking read',
			'/:=/'                                      => 'variable assignment',
			'/\b(NEXT|PREVIOUS)\s+VALUE\s+FOR\b/'       => 'sequence access',
			'/\b(' . self::DENIED_FUNCTIONS . ')\s*\(/' => 'function with side effects',
		);
		foreach ( $denied as $pattern => $reason ) {
			if ( 1 === preg_match( $pattern, $upper ) ) {
				$this->reject( $sql, $reason );
			}
		}
	}

	/**
	 * Throws with the reason and the start of the statement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql    Refused statement. Only its first 60 bytes are quoted.
	 * @param string $reason Why it was refused.
	 * @throws Read_Only_Violation_Exception Always.
	 */
	private function reject( string $sql, string $reason ): void {
		$excerpt = strlen( $sql ) > 60 ? substr( $sql, 0, 60 ) . '...' : $sql;

		throw new Read_Only_Violation_Exception( esc_cli( "Statement refused ($reason): $excerpt" ) );
	}
}
