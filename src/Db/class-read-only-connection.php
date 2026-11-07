<?php
/**
 * The only way checks reach the database.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Db;

use function WP_Doctor\esc_cli;

/**
 * Puts the session in read-only mode when it is created, then passes on only
 * the statements that Statement_Gate accepts.
 *
 * On MySQL 8.0 and later, information_schema.TABLES serves cached sizes for up
 * to information_schema_stats_expiry seconds, so the session also turns that
 * cache off. This setup goes straight to the driver; checks cannot send SET.
 *
 * @since 0.1.0
 */
final class Read_Only_Connection {

	public const SESSION_SETUP    = 'SET SESSION TRANSACTION READ ONLY';
	public const STATISTICS_SETUP = 'SET SESSION information_schema_stats_expiry = 0';
	public const MAX_ROWS         = 100000;

	/**
	 * Driver that runs accepted statements.
	 *
	 * @since 0.1.0
	 *
	 * @var Database_Driver
	 */
	private $driver;

	/**
	 * Allow-list every statement passes before it reaches the driver.
	 *
	 * @since 0.1.0
	 *
	 * @var Statement_Gate
	 */
	private $gate;

	/**
	 * Puts the driver's session in read-only mode before any query runs.
	 *
	 * @since 0.1.0
	 *
	 * @param Database_Driver $driver Driver for an open connection.
	 * @throws Database_Exception When the session cannot be made read-only.
	 */
	public function __construct( Database_Driver $driver ) {
		$this->driver = $driver;
		$this->gate   = new Statement_Gate();

		try {
			$this->driver->execute( self::SESSION_SETUP, 0 );
		} catch ( Database_Exception $e ) {
			$refused = 'The database server refused ' . self::SESSION_SETUP . ', which needs MySQL 5.6.5 or MariaDB 10.0 or later; no database check runs without it: ' . $e->getMessage();
			throw new Database_Exception( $refused, 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The driver's message has already passed through esc_cli().
		}

		if ( $driver instanceof Versioned_Driver && self::caches_table_statistics( $driver->server_version() ) ) {
			try {
				$this->driver->execute( self::STATISTICS_SETUP, 0 );
			} catch ( Database_Exception $e ) {
				// Only table sizes are affected: they may then come from the cache.
				unset( $e );
			}
		}
	}

	/**
	 * Whether the server caches information_schema table statistics.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version Version string the server announced.
	 * @return bool True for MySQL 8.0 and later, false for MariaDB and older MySQL.
	 */
	private static function caches_table_statistics( string $version ): bool {
		if ( false !== stripos( $version, 'mariadb' ) ) {
			return false;
		}

		return 1 === preg_match( '/^(\d+)\./', $version, $m ) && (int) $m[1] >= 8;
	}

	/**
	 * Runs a read statement.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql SELECT, SHOW, DESCRIBE or EXPLAIN SELECT.
	 * @return array<int, array<string, string|null>> Rows keyed by column name. Values are strings or null.
	 * @throws Read_Only_Violation_Exception|Database_Exception When the statement is not an allowed read, fails, or returns more than MAX_ROWS rows.
	 */
	public function query( string $sql ): array {
		$this->gate->assert_allowed( $sql );

		$rows = $this->driver->execute( $sql, self::MAX_ROWS );
		if ( count( $rows ) > self::MAX_ROWS ) {
			throw new Database_Exception( esc_cli( sprintf( 'Result larger than %d rows', self::MAX_ROWS ) ) );
		}

		return $rows;
	}
}
