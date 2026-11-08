<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Autoload_Top_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Autoload_Top_Check_Test extends TestCase {

	/**
	 * Runs the check against a database that returns the given rows.
	 *
	 * @param array<int, array<string, string|null>> $rows Rows for every query.
	 * @return array{0: Result, 1: Recording_Driver}
	 */
	private function run_with_rows( array $rows ): array {
		$driver       = new Recording_Driver();
		$driver->rows = $rows;

		return array( ( new Autoload_Top_Check() )->run( Context_Factory::with_driver( $driver ) ), $driver );
	}

	/**
	 * One option row as the query returns it.
	 *
	 * @param string $name  Option name.
	 * @param string $bytes Size in bytes.
	 * @return array<string, string>
	 */
	private function row( string $name, string $bytes ): array {
		return array(
			'option_name' => $name,
			'size_bytes'  => $bytes,
		);
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Autoload_Top_Check();

		$this->assertSame( 'perf.autoload.top', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_the_largest_options_are_listed_in_order_as_one_info_finding(): void {
		list( $result ) = $this->run_with_rows(
			array(
				$this->row( 'example_big', '900000' ),
				$this->row( 'example_medium', '40000' ),
				$this->row( 'siteurl', '20' ),
			)
		);

		$findings = $result->finding_list();
		$this->assertCount( 1, $findings );
		$this->assertSame( 'info', $findings[0]->severity()->name() );
		$this->assertSame( 'perf.autoload.top', $findings[0]->id() );
		$this->assertSame(
			array(
				'options' => array(
					array(
						'name'  => 'example_big',
						'bytes' => 900000,
					),
					array(
						'name'  => 'example_medium',
						'bytes' => 40000,
					),
					array(
						'name'  => 'siteurl',
						'bytes' => 20,
					),
				),
			),
			$findings[0]->evidence()
		);
		$this->assertNotSame( '', $findings[0]->fix() );
	}

	public function test_the_query_orders_by_size_and_limits_the_rows_over_autoloaded_options_only(): void {
		list( , $driver ) = $this->run_with_rows( array( $this->row( 'siteurl', '20' ) ) );

		$this->assertCount( 2, $driver->statements, 'The check did not query the database' );
		$sql = $driver->statements[1];
		$this->assertStringContainsString( '`wptest_options`', $sql );
		$this->assertStringContainsString( "autoload IN ('yes', 'on', 'auto', 'auto-on')", $sql );
		$this->assertStringContainsString( 'ORDER BY size_bytes DESC, option_name ASC', $sql );
		$this->assertStringContainsString( 'LIMIT 10', $sql );
	}

	public function test_an_option_named_like_a_number_is_listed_as_a_value(): void {
		list( $result ) = $this->run_with_rows( array( $this->row( '2026', '500' ) ) );

		$this->assertSame( '2026', $result->finding_list()[0]->evidence()['options'][0]['name'] );
	}

	public function test_no_autoloaded_rows_is_skipped_not_passed(): void {
		list( $result ) = $this->run_with_rows( array() );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wptest_options', (string) $result->message() );
	}

	/**
	 * @dataProvider malformed_rows
	 * @param array<int, array<string, mixed>> $rows Rows the database should not have returned.
	 */
	public function test_an_unexpected_result_is_an_error_not_a_pass( array $rows ): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->run_with_rows( $rows );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function malformed_rows(): array {
		return array(
			'size not numeric' => array( array( $this->row( 'example', 'big' ) ) ),
			'size missing'     => array( array( array( 'option_name' => 'example' ) ) ),
			'name missing'     => array( array( array( 'size_bytes' => '10' ) ) ),
			'name null'        => array(
				array(
					array(
						'option_name' => null,
						'size_bytes'  => '10',
					),
				),
			),
			'more than asked'  => array( array_fill( 0, 11, $this->row( 'example', '10' ) ) ),
		);
	}

	public function test_the_malformed_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->malformed_rows() );
	}

	public function test_it_is_skipped_without_a_database(): void {
		$result = ( new Autoload_Top_Check() )->run( Context_Factory::without_database( 'no database in this test' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertSame( 'no database in this test', $result->message() );
	}

	public function test_it_is_skipped_when_the_table_prefix_was_not_read(): void {
		$driver = new Recording_Driver();
		$result = ( new Autoload_Top_Check() )->run( Context_Factory::with_driver( $driver, 'computed' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertCount( 1, $driver->statements, 'Only the session setup should have been sent' );
	}
}
