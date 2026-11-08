<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Db_Version_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Db_Version_Check_Test extends TestCase {

	private const NOW = 1794096000;

	/**
	 * Runs the check against a server reporting the given version, on 2026-11-08.
	 *
	 * @param string $version Value of VERSION().
	 * @return Result
	 */
	private function run_for( string $version ): Result {
		$driver           = new Recording_Driver();
		$driver->rows_for = array( 'SELECT VERSION()' => array( array( 'version' => $version ) ) );

		return ( new Db_Version_Check( self::NOW ) )->run( Context_Factory::with_driver( $driver ) );
	}

	public function test_the_fixed_time_is_the_date_the_tables_were_checked(): void {
		$this->assertSame( '2026-11-08', gmdate( 'Y-m-d', self::NOW ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Db_Version_Check();

		$this->assertSame( 'config.db.version', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function ended_servers(): array {
		return array(
			'MySQL 5.7'                   => array( '5.7.44-log', 'MySQL 5.7', '2023-10-25' ),
			'MySQL 8.0 after April 2026'  => array( '8.0.36', 'MySQL 8.0', '2026-04-21' ),
			'MySQL Innovation 9.1'        => array( '9.1.0', 'MySQL 9.1', 'yes, on a date the vendor does not give' ),
			'MySQL older than the table'  => array( '3.23.58', 'MySQL 3.23', 'yes, on a date the vendor does not give' ),
			'MariaDB 10.6 after July'     => array( '10.6.18-MariaDB-0ubuntu0.22.04.1', 'MariaDB 10.6', '2026-07-06' ),
			'MariaDB short-term 11.2'     => array( '11.2.4-MariaDB', 'MariaDB 11.2', '2024-11-30' ),
			'MariaDB with the old prefix' => array( '5.5.5-10.4.34-MariaDB-log', 'MariaDB 10.4', '2024-06-18' ),
			'MariaDB 5.5'                 => array( '5.5.68-MariaDB', 'MariaDB 5.5', 'yes, on a date the vendor does not give' ),
		);
	}

	/**
	 * @dataProvider ended_servers
	 * @param string $version Value of VERSION().
	 * @param string $series  Series named in the evidence.
	 * @param string $ended   End of support given in the evidence.
	 */
	public function test_a_series_past_its_end_of_support_is_a_likely_warning( string $version, string $series, string $ended ): void {
		$finding = $this->run_for( $version )->finding_list()[0];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( $series, $finding->evidence()['series'] );
		$this->assertSame( $ended, $finding->evidence()['support_ended'] );
		$this->assertSame( $version, $finding->evidence()['server_version'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function supported_servers(): array {
		return array(
			'MySQL 8.4'            => array( '8.4.3' ),
			'MySQL 9.7'            => array( '9.7.4' ),
			'Percona Server 8.4'   => array( '8.4.2-2' ),
			'MariaDB 10.11'        => array( '10.11.6-MariaDB-1:10.11.6+maria~ubu2204' ),
			'MariaDB 13.0 for now' => array( '13.0.1-MariaDB' ),
		);
	}

	/**
	 * @dataProvider supported_servers
	 * @param string $version Value of VERSION().
	 */
	public function test_a_supported_series_passes( string $version ): void {
		$this->assertTrue( $this->run_for( $version )->is_pass() );
	}

	public function test_the_providers_have_samples(): void {
		$this->assertGreaterThan( 5, count( $this->ended_servers() ) );
		$this->assertGreaterThan( 3, count( $this->supported_servers() ) );
	}

	public function test_a_series_the_table_does_not_cover_is_a_skip(): void {
		foreach ( array( '12.1.2-MariaDB', '11.3.2-MariaDB', '10.0.0' ) as $version ) {
			$result = $this->run_for( $version );
			$this->assertSame( Result::SKIPPED, $result->status(), $version );
			$this->assertStringContainsString( 'not in the table', (string) $result->message() );
		}
	}

	public function test_an_unreadable_version_is_a_skip(): void {
		$result = $this->run_for( 'unknown' );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_without_a_database_it_is_skipped(): void {
		$this->assertSame( Result::SKIPPED, ( new Db_Version_Check() )->run( Context_Factory::without_database() )->status() );
	}
}
