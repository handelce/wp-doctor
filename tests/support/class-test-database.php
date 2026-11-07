<?php
/**
 * A throwaway database seeded from a SQL file, for integration tests.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedTestError;
use WP_Doctor\Db\Mysqli_Driver;

final class Test_Database {

	/**
	 * Server connection used to create and drop the database.
	 *
	 * @var \mysqli
	 */
	private $admin;

	/**
	 * Name of the throwaway database.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Environment settings for the connection.
	 *
	 * @var array{host: string, user: string, password: string}
	 */
	private $settings;

	/**
	 * Creates the database and loads the seed.
	 *
	 * @param \mysqli                                        $admin    Connection with rights to create a database.
	 * @param string                                         $name     Database name.
	 * @param array{host: string, user: string, password: string} $settings Connection settings.
	 */
	private function __construct( \mysqli $admin, string $name, array $settings ) {
		$this->admin    = $admin;
		$this->name     = $name;
		$this->settings = $settings;
	}

	/**
	 * Creates a database from a seed file under tests/fixtures/sql.
	 *
	 * Skips the test when WPD_TEST_DB_HOST is not set, unless WPD_REQUIRE_DB is
	 * set, in which case a missing database fails the test.
	 *
	 * @param string $seed File name under tests/fixtures/sql.
	 * @return self
	 * @throws SkippedTestError When no test database is configured.
	 */
	public static function seeded( string $seed ): self {
		$host = getenv( 'WPD_TEST_DB_HOST' );
		if ( false === $host || '' === $host ) {
			$required = getenv( 'WPD_REQUIRE_DB' );
			if ( false !== $required && '' !== $required ) {
				Assert::fail( 'WPD_REQUIRE_DB is set but WPD_TEST_DB_HOST is not' );
			}
			throw new SkippedTestError( 'WPD_TEST_DB_HOST is not set; no integration database.' );
		}
		$user     = getenv( 'WPD_TEST_DB_USER' );
		$password = getenv( 'WPD_TEST_DB_PASSWORD' );
		$settings = array(
			'host'     => $host,
			'user'     => false === $user ? 'root' : $user,
			'password' => false === $password ? '' : $password,
		);

		$parts = Mysqli_Driver::split_host( $host );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Test setup creates its own throwaway database.
		$admin = new \mysqli( $parts['host'], $settings['user'], $settings['password'], '', $parts['port'], $parts['socket'] );
		$name  = 'wpd_test_' . bin2hex( random_bytes( 4 ) );
		$admin->query( "CREATE DATABASE `$name`" );
		$admin->select_db( $name );

		$sql = file_get_contents( dirname( __DIR__ ) . '/fixtures/sql/' . $seed );
		Assert::assertIsString( $sql );
		Assert::assertTrue( $admin->multi_query( $sql ), 'The seed failed to run' );
		do {
			$admin->store_result();
		} while ( $admin->more_results() && $admin->next_result() );
		Assert::assertSame( 0, $admin->errno, 'The seed failed: ' . $admin->error );

		return new self( $admin, $name, $settings );
	}

	/**
	 * A real driver connected to the seeded database with the site's credentials.
	 *
	 * @return Mysqli_Driver
	 */
	public function driver(): Mysqli_Driver {
		return Mysqli_Driver::connect( $this->settings['host'], $this->settings['user'], $this->settings['password'], $this->name );
	}

	/**
	 * Drops the database.
	 */
	public function drop(): void {
		$this->admin->query( "DROP DATABASE `{$this->name}`" );
		$this->admin->close();
	}
}
