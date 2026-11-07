<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Tables_Bloat_Check;
use WP_Doctor\Check\Category\Performance\Table_Sizes;
use WP_Doctor\Check\Category\Performance\Tables_Largest_Check;
use WP_Doctor\Check\Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Tables_Checks_Test extends TestCase {

	private const MIB = 1048576;

	/**
	 * Runs a check against a database that reports the given tables.
	 *
	 * @param Check                     $check  Check to run.
	 * @param array<string, int|string> $tables Size in bytes by table name.
	 * @return array{0: Result, 1: Recording_Driver}
	 */
	private function run_with_tables( Check $check, array $tables ): array {
		$driver       = new Recording_Driver();
		$driver->rows = array();
		foreach ( $tables as $name => $bytes ) {
			$driver->rows[] = array(
				'name'       => (string) $name,
				'size_bytes' => (string) $bytes,
			);
		}

		return array( $check->run( Context_Factory::with_driver( $driver ) ), $driver );
	}

	public function test_the_checks_have_ids_and_categories(): void {
		$this->assertSame( 'perf.tables.largest', ( new Tables_Largest_Check() )->id() );
		$this->assertSame( 'perf.tables.bloat', ( new Tables_Bloat_Check() )->id() );
		$this->assertSame( 'performance', ( new Tables_Largest_Check() )->category() );
		$this->assertSame( 'performance', ( new Tables_Bloat_Check() )->category() );
	}

	public function test_the_query_is_limited_to_the_prefix_and_the_current_database(): void {
		list( , $driver ) = $this->run_with_tables( new Tables_Largest_Check(), array( 'wptest_posts' => 100 ) );

		$sql = $driver->statements[1];
		$this->assertStringContainsString( 'information_schema.tables', $sql );
		$this->assertStringContainsString( 'table_schema = DATABASE()', $sql );
		$this->assertStringContainsString( "LEFT(table_name, 7) = 'wptest_'", $sql );
		$this->assertStringContainsString( 'ORDER BY size_bytes DESC', $sql );
	}

	public function test_the_largest_tables_are_listed_in_the_order_the_database_returned_them(): void {
		list( $result ) = $this->run_with_tables(
			new Tables_Largest_Check(),
			array(
				'wptest_postmeta' => 900 * self::MIB,
				'wptest_posts'    => 40 * self::MIB,
				'wptest_options'  => 3 * self::MIB,
			)
		);

		$finding  = $result->finding_list()[0];
		$evidence = $finding->evidence();
		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertSame( 3, $evidence['tables_with_prefix'] );
		$this->assertSame( 'wptest_postmeta', $evidence['table_1'] );
		$this->assertSame( 900 * self::MIB, $evidence['table_1_bytes'] );
		$this->assertSame( 'wptest_options', $evidence['table_3'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_only_ten_tables_are_listed(): void {
		$tables = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$tables[ 'wptest_table_' . $i ] = 1000 - $i;
		}

		list( $result ) = $this->run_with_tables( new Tables_Largest_Check(), $tables );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 12, $evidence['tables_with_prefix'] );
		$this->assertSame( 'wptest_table_10', $evidence['table_10'] );
		$this->assertArrayNotHasKey( 'table_11', $evidence );
	}

	public function test_a_full_page_of_tables_is_reported_as_a_lower_bound_not_a_total(): void {
		$tables = array();
		for ( $i = 1; $i <= Table_Sizes::LIMIT; $i++ ) {
			$tables[ 'wptest_table_' . $i ] = 100000 - $i;
		}

		list( $result ) = $this->run_with_tables( new Tables_Largest_Check(), $tables );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 'at least 200; only the largest 200 were read', $evidence['tables_with_prefix'] );
		$this->assertSame( 'wptest_table_1', $evidence['table_1'] );
	}

	public function test_a_database_without_prefixed_tables_is_skipped_by_both_checks(): void {
		foreach ( array( new Tables_Largest_Check(), new Tables_Bloat_Check() ) as $check ) {
			list( $result ) = $this->run_with_tables( $check, array() );

			$this->assertSame( Result::SKIPPED, $result->status() );
			$this->assertStringContainsString( 'wptest_', (string) $result->message() );
		}
	}

	/**
	 * @dataProvider malformed_rows
	 * @param array<string, int|string> $tables Tables the database should not have returned.
	 */
	public function test_a_row_without_a_numeric_size_is_an_error( array $tables ): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->run_with_tables( new Tables_Largest_Check(), $tables );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function malformed_rows(): array {
		return array(
			'text size' => array( array( 'wptest_posts' => 'big' ) ),
			'empty'     => array( array( 'wptest_posts' => '' ) ),
		);
	}

	public function test_the_malformed_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->malformed_rows() );
	}

	public function test_a_decimal_size_from_the_server_is_accepted(): void {
		list( $result ) = $this->run_with_tables( new Tables_Largest_Check(), array( 'wptest_posts' => '16384.0' ) );

		$this->assertSame( 16384, $result->finding_list()[0]->evidence()['table_1_bytes'] );
	}

	public function test_log_like_tables_above_one_hundred_mebibytes_are_a_warning(): void {
		list( $result ) = $this->run_with_tables(
			new Tables_Bloat_Check(),
			array(
				'wptest_postmeta'                => 900 * self::MIB,
				'wptest_actionscheduler_actions' => 300 * self::MIB,
				'wptest_security_log'            => 200 * self::MIB,
				'wptest_sessions'                => 101 * self::MIB,
				'wptest_actionscheduler_logs'    => 100 * self::MIB,
				'wptest_wc_sessions'             => 10 * self::MIB,
			)
		);

		$finding  = $result->finding_list()[0];
		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 'wptest_actionscheduler_actions', $evidence['table_1'] );
		$this->assertSame( 'wptest_security_log', $evidence['table_2'] );
		$this->assertSame( 'wptest_sessions', $evidence['table_3'] );
		$this->assertArrayNotHasKey( 'table_4', $evidence );
		$this->assertStringContainsString( 'default', (string) $evidence['threshold_basis'] );
	}

	public function test_large_tables_with_ordinary_names_and_small_log_tables_pass(): void {
		list( $result ) = $this->run_with_tables(
			new Tables_Bloat_Check(),
			array(
				'wptest_postmeta'     => 900 * self::MIB,
				'wptest_catalog'      => 500 * self::MIB,
				'wptest_blogs'        => 400 * self::MIB,
				'wptest_security_log' => 100 * self::MIB,
			)
		);

		$this->assertTrue( $result->is_pass() );
	}

	public function test_the_prefix_is_not_part_of_the_name_test(): void {
		$driver       = new Recording_Driver();
		$driver->rows = array(
			array(
				'name'       => 'wptest_x',
				'size_bytes' => (string) ( 500 * self::MIB ),
			),
		);

		$this->assertTrue( ( new Tables_Bloat_Check() )->run( Context_Factory::with_driver( $driver ) )->is_pass() );
	}

	public function test_both_checks_are_skipped_without_a_database_or_a_table_prefix(): void {
		foreach ( array( new Tables_Largest_Check(), new Tables_Bloat_Check() ) as $check ) {
			$this->assertSame( Result::SKIPPED, $check->run( Context_Factory::without_database() )->status() );
			$this->assertSame( Result::SKIPPED, $check->run( Context_Factory::with_driver( new Recording_Driver(), 'computed' ) )->status() );
		}
	}
}
