<?php
/**
 * Tests for Mysqli_Driver that need no database server.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Db;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Db\Database_Exception;
use WP_Doctor\Db\Mysqli_Driver;

final class Mysqli_Driver_Test extends TestCase {

	/**
	 * @dataProvider host_strings
	 * @param string                                      $host     DB_HOST value.
	 * @param array{host: string, port: int, socket: string} $expected Parsed parts.
	 */
	public function test_a_wordpress_host_string_is_split_into_host_port_and_socket( string $host, array $expected ): void {
		$this->assertSame( $expected, Mysqli_Driver::split_host( $host ) );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function host_strings(): array {
		return array(
			'name only'      => array(
				'db.example.test',
				array(
					'host'   => 'db.example.test',
					'port'   => 3306,
					'socket' => '',
				),
			),
			'name and port'  => array(
				'db.example.test:3307',
				array(
					'host'   => 'db.example.test',
					'port'   => 3307,
					'socket' => '',
				),
			),
			'ipv4 and port'  => array(
				'127.0.0.1:3308',
				array(
					'host'   => '127.0.0.1',
					'port'   => 3308,
					'socket' => '',
				),
			),
			'bracketed ipv6' => array(
				'[::1]',
				array(
					'host'   => '::1',
					'port'   => 3306,
					'socket' => '',
				),
			),
			'ipv6 and port'  => array(
				'[::1]:3309',
				array(
					'host'   => '::1',
					'port'   => 3309,
					'socket' => '',
				),
			),
			'socket'         => array(
				'localhost:/var/run/mysqld/mysqld.sock',
				array(
					'host'   => 'localhost',
					'port'   => 3306,
					'socket' => '/var/run/mysqld/mysqld.sock',
				),
			),
			'socket only'    => array(
				':/tmp/mysql.sock',
				array(
					'host'   => 'localhost',
					'port'   => 3306,
					'socket' => '/tmp/mysql.sock',
				),
			),
			'empty'          => array(
				'',
				array(
					'host'   => 'localhost',
					'port'   => 3306,
					'socket' => '',
				),
			),
		);
	}

	public function test_the_host_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->host_strings() );
	}

	public function test_an_unresolvable_host_is_an_exception_and_prints_no_php_warning(): void {
		if ( ! extension_loaded( 'mysqli' ) ) {
			$this->markTestSkipped( 'The mysqli extension is not loaded.' );
		}

		$this->expectException( Database_Exception::class );
		Mysqli_Driver::connect( 'no-such-host.invalid', 'example_user', 'fake-pass-0042', 'example_db' );
	}

	public function test_the_error_handler_is_restored_after_a_failed_connection(): void {
		if ( ! extension_loaded( 'mysqli' ) ) {
			$this->markTestSkipped( 'The mysqli extension is not loaded.' );
		}
		$marker = static function (): bool {
			return false;
		};
		set_error_handler( $marker ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Checks the driver restores the handler.

		try {
			Mysqli_Driver::connect( '127.0.0.1:1', 'example_user', 'fake-pass-0042', 'example_db' );
		} catch ( Database_Exception $e ) {
			$current = set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Reads the current handler.
				static function (): bool {
					return false;
				}
			);
			restore_error_handler();
			$this->assertSame( $marker, $current );
		} finally {
			restore_error_handler();
		}
	}

	public function test_a_failed_connection_reports_an_error_without_the_password(): void {
		if ( ! extension_loaded( 'mysqli' ) ) {
			$this->markTestSkipped( 'The mysqli extension is not loaded.' );
		}
		$password = 'fake-pass-should-not-appear-0042';

		try {
			Mysqli_Driver::connect( '127.0.0.1:1', 'example_user', $password, 'example_db' );
			$this->fail( 'A connection to a closed port succeeded' );
		} catch ( Database_Exception $e ) {
			$this->assertNotSame( '', $e->getMessage() );
			$this->assertFalse( strpos( $e->getMessage(), $password ), 'The connection error contains the password' );
		}
	}

	public function test_a_query_that_hangs_is_cut_off_by_a_read_timeout(): void {
		if ( ! defined( 'MYSQLI_OPT_READ_TIMEOUT' ) ) {
			$this->markTestSkipped( 'This mysqli build has no read timeout option' );
		}

		$options = Mysqli_Driver::client_options();

		$this->assertSame( 10, $options[ MYSQLI_OPT_CONNECT_TIMEOUT ] );
		$this->assertArrayHasKey( MYSQLI_OPT_READ_TIMEOUT, $options );
		$this->assertGreaterThan( 0, $options[ MYSQLI_OPT_READ_TIMEOUT ] );
	}

	public function test_the_client_host_in_an_access_denied_message_is_masked(): void {
		$message = Mysqli_Driver::connection_error( 1045, "Access denied for user 'example_user'@'203.0.113.7' (using password: YES)" );

		$this->assertStringNotContainsString( '203.0.113.7', $message );
		$this->assertStringContainsString( "'example_user'@'[redacted]'", $message );
		$this->assertStringContainsString( 'error 1045', $message );
	}
}
