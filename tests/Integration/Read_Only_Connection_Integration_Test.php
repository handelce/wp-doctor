<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Db\Database_Exception;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Db\Read_Only_Violation_Exception;
use WP_Doctor\Tests\Support\Test_Database;

/**
 * Session setup against a real database server. Needs WPD_TEST_DB_HOST.
 */
final class Read_Only_Connection_Integration_Test extends TestCase {

	/**
	 * Throwaway database for the test.
	 *
	 * @var Test_Database|null
	 */
	private $database;

	protected function tearDown(): void {
		if ( null !== $this->database ) {
			$this->database->drop();
			$this->database = null;
		}
	}

	/**
	 * Seeds a throwaway database, skipping on servers without the statistics cache.
	 *
	 * @return Test_Database
	 */
	private function mysql_database(): Test_Database {
		$this->database = Test_Database::seeded( 'tables-sizes.sql' );
		$version        = $this->database->driver()->server_version();
		$this->assertNotSame( '', $version, 'The driver reported no server version' );
		if ( false !== stripos( $version, 'mariadb' ) || 1 !== preg_match( '/^(\d+)\./', $version, $m ) || (int) $m[1] < 8 ) {
			$this->markTestSkipped( 'Only MySQL 8.0 and later cache information_schema table statistics' );
		}

		return $this->database;
	}

	public function test_the_session_is_read_only_and_reads_uncached_table_statistics(): void {
		$connection = new Read_Only_Connection( $this->mysql_database()->driver() );

		$rows = $connection->query( 'SELECT @@SESSION.transaction_read_only AS read_only, @@SESSION.information_schema_stats_expiry AS expiry' );

		$this->assertSame(
			array(
				array(
					'read_only' => '1',
					'expiry'    => '0',
				),
			),
			$rows
		);
	}

	public function test_a_table_that_grew_after_its_size_was_cached_is_reported_at_its_current_size(): void {
		$database = $this->mysql_database();
		$writer   = $database->driver();
		$size_sql = "SELECT data_length AS bytes FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wptest_growing'";

		$writer->execute( 'CREATE TABLE wptest_growing (id INT AUTO_INCREMENT PRIMARY KEY, v TEXT) ENGINE=InnoDB STATS_PERSISTENT=0', 0 );
		$cached_before = $writer->execute( $size_sql, 1 );
		$writer->execute( "INSERT INTO wptest_growing (v) SELECT REPEAT('x', 2000) FROM information_schema.columns LIMIT 300", 0 );
		$cached_after = $writer->execute( $size_sql, 1 );

		$this->assertCount( 1, $cached_before );
		$this->assertSame( $cached_before, $cached_after, 'The server did not cache the size, so staleness was not tested' );

		$current = ( new Read_Only_Connection( $database->driver() ) )->query( $size_sql );

		$this->assertCount( 1, $current );
		$this->assertGreaterThan( 10 * (int) $cached_before[0]['bytes'], (int) $current[0]['bytes'] );
	}

	public function test_the_server_refuses_a_write_in_the_read_only_session(): void {
		$this->database = Test_Database::seeded( 'tables-sizes.sql' );
		$driver         = $this->database->driver();
		new Read_Only_Connection( $driver );

		$this->expectException( Database_Exception::class );
		$driver->execute( 'DELETE FROM wptest_posts', 0 );
	}

	public function test_a_quoted_function_name_is_refused_before_it_reaches_the_server(): void {
		$this->database = Test_Database::seeded( 'tables-sizes.sql' );
		$connection     = new Read_Only_Connection( $this->database->driver() );

		$this->expectException( Read_Only_Violation_Exception::class );
		$connection->query( "SELECT `get_lock`('wpd_test', 0) AS l" );
	}
}
