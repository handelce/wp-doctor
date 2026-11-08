<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Tables_Engine_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Tables_Engine_Check_Test extends TestCase {

	/**
	 * Runs the check with canned rows for the engine query.
	 *
	 * @param array<int, array<string, string|null>> $rows Table name and engine rows.
	 * @return array{0: Result, 1: Recording_Driver}
	 */
	private function run_with( array $rows ): array {
		$driver           = new Recording_Driver();
		$driver->rows_for = array( 'information_schema.tables' => $rows );

		return array( ( new Tables_Engine_Check() )->run( Context_Factory::with_driver( $driver ) ), $driver );
	}

	/**
	 * A table row.
	 *
	 * @param string      $name   Table name.
	 * @param string|null $engine Engine, or null as the server reports for some tables.
	 * @return array<string, string|null>
	 */
	private static function table( string $name, ?string $engine ): array {
		return array(
			'name'   => $name,
			'engine' => $engine,
		);
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Tables_Engine_Check();

		$this->assertSame( 'perf.tables.engine', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_innodb_tables_pass_and_only_prefixed_base_tables_are_asked_for(): void {
		list( $result, $driver ) = $this->run_with( array( self::table( 'wptest_options', 'InnoDB' ), self::table( 'wptest_posts', 'InnoDB' ) ) );

		$this->assertTrue( $result->is_pass() );
		$query = implode( "\n", preg_grep( '/information_schema/', $driver->statements ) );
		$this->assertStringContainsString( "table_type = 'BASE TABLE'", $query );
		$this->assertStringContainsString( "LEFT(table_name, 7) = 'wptest_'", $query );
	}

	public function test_myisam_tables_are_listed_as_an_info_notice(): void {
		list( $result ) = $this->run_with(
			array(
				self::table( 'wptest_options', 'InnoDB' ),
				self::table( 'wptest_redirection_logs', 'MyISAM' ),
				self::table( 'wptest_terms', 'myisam' ),
				self::table( 'wptest_broken', null ),
			)
		);

		$finding  = $result->finding_list()[0];
		$evidence = $finding->evidence();
		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertSame( 4, $evidence['tables_with_prefix'] );
		$this->assertSame( 2, $evidence['myisam_tables'] );
		$this->assertSame( 'wptest_redirection_logs', $evidence['table_1'] );
		$this->assertSame( 'wptest_terms', $evidence['table_2'] );
		$this->assertStringContainsString( 'ALTER TABLE wptest_redirection_logs ENGINE=InnoDB', $finding->fix() );
	}

	public function test_no_prefixed_tables_is_a_skip(): void {
		list( $result ) = $this->run_with( array() );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wptest_', (string) $result->message() );
	}

	public function test_a_row_without_an_engine_column_is_an_error(): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->run_with( array( array( 'name' => 'wptest_posts' ) ) );
	}
}
