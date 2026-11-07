<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Transients_Expired_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Transients_Expired_Check_Test extends TestCase {

	private const NOW = 1700100000;

	/**
	 * Runs the check with canned counts for ordinary and site transients.
	 *
	 * @param array{0: string, 1: string} $ordinary Rows and bytes for ordinary transients.
	 * @param array{0: string, 1: string} $site     Rows and bytes for site transients.
	 * @return array{0: Result, 1: Recording_Driver}
	 */
	private function run_with_counts( array $ordinary, array $site ): array {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			"LIKE '!_transient!_timeout!_%'"       => array(
				array(
					'expired_rows'  => $ordinary[0],
					'expired_bytes' => $ordinary[1],
				),
			),
			"LIKE '!_site!_transient!_timeout!_%'" => array(
				array(
					'expired_rows'  => $site[0],
					'expired_bytes' => $site[1],
				),
			),
		);

		return array( ( new Transients_Expired_Check( self::NOW ) )->run( Context_Factory::with_driver( $driver ) ), $driver );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Transients_Expired_Check();

		$this->assertSame( 'perf.transients.expired', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_five_thousand_expired_transients_pass_after_both_kinds_were_counted(): void {
		list( $result, $driver ) = $this->run_with_counts( array( '4000', '800' ), array( '1000', '10' ) );

		$this->assertTrue( $result->is_pass() );
		$this->assertCount( 3, $driver->statements, 'Both kinds of transient should be queried' );
	}

	public function test_more_than_five_thousand_is_a_warning_with_the_sums(): void {
		list( $result ) = $this->run_with_counts( array( '4000', '1500' ), array( '1001', '500' ) );

		$finding  = $result->finding_list()[0];
		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 5001, $evidence['expired_transients'] );
		$this->assertSame( 2000, $evidence['expired_value_bytes'] );
		$this->assertSame( 5000, $evidence['warning_above_rows'] );
		$this->assertStringContainsString( 'default', (string) $evidence['threshold_basis'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_the_queries_compare_against_the_clock_and_the_options_table(): void {
		list( , $driver ) = $this->run_with_counts( array( '0', '0' ), array( '0', '0' ) );

		foreach ( array_slice( $driver->statements, 1 ) as $sql ) {
			$this->assertStringContainsString( '`wptest_options`', $sql );
			$this->assertStringContainsString( '< ' . self::NOW, $sql );
			$this->assertStringContainsString( 'CAST(t.option_value AS UNSIGNED)', $sql );
		}
	}

	public function test_the_timeout_rows_are_found_with_a_prefix_match_the_name_index_can_serve(): void {
		list( , $driver ) = $this->run_with_counts( array( '0', '0' ), array( '0', '0' ) );

		$queries = array_slice( $driver->statements, 1 );
		$this->assertCount( 2, $queries );
		$this->assertStringContainsString( "t.option_name LIKE '!_transient!_timeout!_%' ESCAPE '!'", $queries[0] );
		$this->assertStringContainsString( "t.option_name LIKE '!_site!_transient!_timeout!_%' ESCAPE '!'", $queries[1] );
		foreach ( $queries as $sql ) {
			$this->assertStringNotContainsString( 'LEFT(', $sql );
		}
	}

	/**
	 * @dataProvider malformed_counts
	 * @param array{0: string, 1: string} $counts Counts the database should not have returned.
	 */
	public function test_an_unexpected_result_is_an_error_not_a_pass( array $counts ): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->run_with_counts( $counts, array( '0', '0' ) );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function malformed_counts(): array {
		return array(
			'rows not numeric'  => array( array( 'many', '0' ) ),
			'bytes not numeric' => array( array( '0', 'lots' ) ),
		);
	}

	public function test_the_malformed_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->malformed_counts() );
	}

	public function test_a_missing_result_row_is_an_error(): void {
		$this->expectException( \UnexpectedValueException::class );
		( new Transients_Expired_Check( self::NOW ) )->run( Context_Factory::with_driver( new Recording_Driver() ) );
	}

	public function test_it_is_skipped_without_a_database_or_a_table_prefix(): void {
		$this->assertSame( Result::SKIPPED, ( new Transients_Expired_Check( self::NOW ) )->run( Context_Factory::without_database() )->status() );
		$this->assertSame( Result::SKIPPED, ( new Transients_Expired_Check( self::NOW ) )->run( Context_Factory::with_driver( new Recording_Driver(), 'computed' ) )->status() );
	}
}
