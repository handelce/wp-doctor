<?php
/**
 * Tests for Read_Only_Connection.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Db;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Db\Database_Driver;
use WP_Doctor\Db\Database_Exception;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Db\Read_Only_Violation_Exception;
use WP_Doctor\Db\Versioned_Driver;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Read_Only_Connection_Test extends TestCase {

	private const STATISTICS_SETUP = 'SET SESSION information_schema_stats_expiry = 0';

	/**
	 * A driver that reports a server version, records statements, and fails the ones it is told to.
	 *
	 * @param string   $version Server version string the driver reports.
	 * @param string[] $failing Statements that throw a Database_Exception.
	 * @return Versioned_Driver&object{statements: string[]}
	 */
	private function versioned_driver( string $version, array $failing = array() ): Versioned_Driver {
		return new class( $version, $failing ) implements Versioned_Driver {
			/**
			 * Every statement received, in order.
			 *
			 * @var string[]
			 */
			public $statements = array();

			/**
			 * Reported server version.
			 *
			 * @var string
			 */
			private $version;

			/**
			 * Statements that fail.
			 *
			 * @var string[]
			 */
			private $failing;

			/**
			 * Stores the canned version and failures.
			 *
			 * @param string   $version Server version.
			 * @param string[] $failing Statements that fail.
			 */
			public function __construct( string $version, array $failing ) {
				$this->version = $version;
				$this->failing = $failing;
			}

			/**
			 * Returns the canned version.
			 *
			 * @return string
			 */
			public function server_version(): string {
				return $this->version;
			}

			/**
			 * Records the statement and fails it when told to.
			 *
			 * @param string $sql      Statement.
			 * @param int    $max_rows Ignored.
			 * @return array<int, array<string, string|null>>
			 * @throws Database_Exception When the statement is one of the failing ones.
			 */
			public function execute( string $sql, int $max_rows ): array {
				$this->statements[] = $sql;
				if ( in_array( $sql, $this->failing, true ) ) {
					throw new Database_Exception( 'Query failed (error 1193): Unknown system variable' );
				}
				return array();
			}
		};
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function servers_that_cache_table_statistics(): array {
		return array(
			'mysql 8.0'   => array( '8.0.36' ),
			'mysql 9.3'   => array( '9.3.0' ),
			'percona 8.0' => array( '8.0.36-28' ),
		);
	}

	/**
	 * @dataProvider servers_that_cache_table_statistics
	 * @param string $version Server version.
	 */
	public function test_table_statistics_are_read_uncached_on_mysql_8_and_later( string $version ): void {
		$driver = $this->versioned_driver( $version );

		new Read_Only_Connection( $driver );

		$this->assertSame( array( 'SET SESSION TRANSACTION READ ONLY', self::STATISTICS_SETUP ), $driver->statements );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function servers_without_cached_table_statistics(): array {
		return array(
			'mysql 5.7'            => array( '5.7.44' ),
			'mariadb'              => array( '10.11.6-MariaDB' ),
			'mariadb behind 5.5.5' => array( '5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu2204' ),
			'mariadb 11'           => array( '11.4.2-MariaDB' ),
			'unrecognised version' => array( 'unknown' ),
		);
	}

	/**
	 * @dataProvider servers_without_cached_table_statistics
	 * @param string $version Server version.
	 */
	public function test_only_the_read_only_setup_is_sent_to_other_servers( string $version ): void {
		$driver = $this->versioned_driver( $version );

		new Read_Only_Connection( $driver );

		$this->assertSame( array( 'SET SESSION TRANSACTION READ ONLY' ), $driver->statements );
	}

	public function test_a_failure_to_disable_the_statistics_cache_does_not_stop_the_connection(): void {
		$driver     = $this->versioned_driver( '8.0.36', array( self::STATISTICS_SETUP ) );
		$connection = new Read_Only_Connection( $driver );

		$connection->query( 'SELECT 1' );

		$this->assertSame( array( 'SET SESSION TRANSACTION READ ONLY', self::STATISTICS_SETUP, 'SELECT 1' ), $driver->statements );
	}

	public function test_a_failure_of_the_read_only_setup_is_not_hidden(): void {
		$driver = $this->versioned_driver( '8.0.36', array( 'SET SESSION TRANSACTION READ ONLY' ) );

		try {
			new Read_Only_Connection( $driver );
			$this->fail( 'The connection was created without a read-only session' );
		} catch ( Database_Exception $e ) {
			$this->assertSame( array( 'SET SESSION TRANSACTION READ ONLY' ), $driver->statements );
			$this->assertStringStartsWith( 'The database server refused SET SESSION TRANSACTION READ ONLY, which needs MySQL 5.6.5 or MariaDB 10.0 or later; no database check runs without it: ', $e->getMessage() );
			$this->assertStringEndsWith( 'Query failed (error 1193): Unknown system variable', $e->getMessage() );
		}
	}

	public function test_a_check_still_cannot_change_the_statistics_cache_setting(): void {
		$driver     = $this->versioned_driver( '8.0.36' );
		$connection = new Read_Only_Connection( $driver );

		$this->expectException( Read_Only_Violation_Exception::class );
		$connection->query( 'SET SESSION information_schema_stats_expiry = 86400' );
	}

	public function test_the_session_is_made_read_only_before_anything_else_is_sent(): void {
		$driver = new Recording_Driver();

		new Read_Only_Connection( $driver );

		$this->assertSame( array( 'SET SESSION TRANSACTION READ ONLY' ), $driver->statements );
	}

	public function test_an_allowed_statement_reaches_the_driver_and_returns_its_rows(): void {
		$driver       = new Recording_Driver();
		$driver->rows = array( array( 'total' => '42' ) );
		$connection   = new Read_Only_Connection( $driver );

		$rows = $connection->query( 'SELECT 42 AS total' );

		$this->assertSame( array( array( 'total' => '42' ) ), $rows );
		$this->assertSame( 'SELECT 42 AS total', end( $driver->statements ) );
		$this->assertCount( 2, $driver->statements );
	}

	public function test_a_rejected_statement_never_reaches_the_driver(): void {
		$driver     = new Recording_Driver();
		$connection = new Read_Only_Connection( $driver );
		$before     = count( $driver->statements );

		try {
			$connection->query( 'DELETE FROM wp_options' );
			$this->fail( 'The write was allowed' );
		} catch ( Read_Only_Violation_Exception $e ) {
			$this->assertSame( $before, count( $driver->statements ) );
		}

		$this->assertGreaterThan( 0, $before, 'The session statement was not recorded' );
	}

	public function test_every_query_carries_a_row_limit(): void {
		$driver     = new Recording_Driver();
		$connection = new Read_Only_Connection( $driver );

		$connection->query( 'SELECT 1' );

		$this->assertGreaterThan( 0, end( $driver->limits ) );
	}

	public function test_a_driver_that_returns_more_rows_than_the_limit_is_an_error(): void {
		$driver     = new class() implements Database_Driver {
			/**
			 * Returns more rows than allowed.
			 *
			 * @param string $sql      Ignored.
			 * @param int    $max_rows Limit to exceed by one row.
			 * @return array<int, array<string, string|null>>
			 */
			public function execute( string $sql, int $max_rows ): array {
				return 0 === $max_rows ? array() : array_fill( 0, $max_rows + 1, array( 'a' => '1' ) );
			}
		};
		$connection = new Read_Only_Connection( $driver );

		$this->expectException( \RuntimeException::class );
		$connection->query( 'SELECT 1' );
	}
}
