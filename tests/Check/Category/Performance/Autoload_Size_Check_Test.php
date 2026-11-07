<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Autoload_Size_Check;
use WP_Doctor\Check\Thresholds;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Autoload_Size_Check_Test extends TestCase {

	private const MIB = 1048576;

	/**
	 * Runs the check against a database that reports one aggregate row.
	 *
	 * @param string $rows  Autoloaded row count.
	 * @param string $bytes Autoloaded bytes.
	 * @return array{0: Result, 1: Recording_Driver}
	 */
	private function run_with_totals( string $rows, string $bytes ): array {
		$driver       = new Recording_Driver();
		$driver->rows = array(
			array(
				'option_count' => $rows,
				'total_bytes'  => $bytes,
			),
		);

		return array( ( new Autoload_Size_Check() )->run( Context_Factory::with_driver( $driver ) ), $driver );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Autoload_Size_Check();

		$this->assertSame( 'perf.autoload.size', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_a_site_under_the_warning_threshold_passes_after_the_options_table_was_queried(): void {
		list( $result, $driver ) = $this->run_with_totals( '210', (string) ( 600 * 1024 ) );

		$this->assertTrue( $result->is_pass() );
		$this->assertCount( 2, $driver->statements, 'The check did not query the database' );
		$this->assertStringContainsString( '`wptest_options`', $driver->statements[1] );
	}

	public function test_exactly_the_warning_threshold_passes(): void {
		list( $result ) = $this->run_with_totals( '300', (string) self::MIB );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_a_warning_is_raised_above_the_warning_threshold(): void {
		list( $result ) = $this->run_with_totals( '900', (string) ( self::MIB + 1 ) );

		$findings = $result->finding_list();
		$this->assertCount( 1, $findings );
		$this->assertSame( 'warning', $findings[0]->severity()->name() );
		$this->assertSame( 'perf.autoload.size', $findings[0]->id() );
	}

	public function test_exactly_the_critical_threshold_is_still_a_warning(): void {
		list( $result ) = $this->run_with_totals( '900', (string) ( 3 * self::MIB ) );

		$this->assertSame( 'warning', $result->finding_list()[0]->severity()->name() );
	}

	public function test_a_critical_finding_is_raised_above_the_critical_threshold(): void {
		list( $result ) = $this->run_with_totals( '4000', (string) ( 3 * self::MIB + 1 ) );

		$this->assertSame( 'critical', $result->finding_list()[0]->severity()->name() );
	}

	public function test_the_finding_carries_the_measured_size_and_a_fix_and_says_the_thresholds_are_defaults(): void {
		list( $result ) = $this->run_with_totals( '900', (string) ( 2 * self::MIB ) );

		$finding  = $result->finding_list()[0];
		$evidence = $finding->evidence();
		$this->assertSame( 2 * self::MIB, $evidence['autoloaded_bytes'] );
		$this->assertSame( 900, $evidence['autoloaded_options'] );
		$this->assertArrayHasKey( 'warning_above_bytes', $evidence );
		$this->assertArrayHasKey( 'critical_above_bytes', $evidence );
		$this->assertStringContainsString( 'default', (string) $evidence['threshold_basis'] );
		$this->assertStringContainsString( 'perf.autoload.top', $finding->fix() );
	}

	public function test_the_query_counts_every_autoload_value_wordpress_loads_on_every_request(): void {
		list( , $driver ) = $this->run_with_totals( '10', '1000' );

		foreach ( array( "'yes'", "'on'", "'auto'", "'auto-on'" ) as $value ) {
			$this->assertStringContainsString( $value, $driver->statements[1] );
		}
		$this->assertStringNotContainsString( "'no'", $driver->statements[1] );
	}

	public function test_an_options_table_with_no_autoloaded_rows_is_skipped_not_passed(): void {
		list( $result ) = $this->run_with_totals( '0', '0' );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wptest_options', (string) $result->message() );
	}

	public function test_a_result_that_is_not_numeric_is_an_error_not_a_pass(): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->run_with_totals( '10', 'lots' );
	}

	public function test_a_missing_result_row_is_an_error_not_a_pass(): void {
		$driver = new Recording_Driver();

		$this->expectException( \UnexpectedValueException::class );
		( new Autoload_Size_Check() )->run( Context_Factory::with_driver( $driver ) );
	}

	public function test_it_is_skipped_without_a_database(): void {
		$result = ( new Autoload_Size_Check() )->run( Context_Factory::without_database( 'no database in this test' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertSame( 'no database in this test', $result->message() );
	}

	public function test_it_is_skipped_when_the_table_prefix_was_not_read(): void {
		$driver = new Recording_Driver();
		$result = ( new Autoload_Size_Check() )->run( Context_Factory::with_driver( $driver, 'computed' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '$table_prefix', (string) $result->message() );
		$this->assertCount( 1, $driver->statements, 'Only the session setup should have been sent' );
	}

	public function test_configured_thresholds_replace_the_defaults_and_the_evidence_says_so(): void {
		$driver       = new Recording_Driver();
		$driver->rows = array(
			array(
				'option_count' => '10',
				'total_bytes'  => '150',
			),
		);
		$context      = Context_Factory::with_driver( $driver )->with_thresholds(
			Thresholds::from_config(
				array(
					'perf.autoload.size' => array(
						'warning'  => 100,
						'critical' => 200,
					),
				),
				'custom.json'
			)
		);

		$finding = ( new Autoload_Size_Check() )->run( $context )->finding_list()[0];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 100, $finding->evidence()['warning_above_bytes'] );
		$this->assertSame( 200, $finding->evidence()['critical_above_bytes'] );
		$this->assertSame( 'set in custom.json', $finding->evidence()['threshold_basis'] );
	}
}
