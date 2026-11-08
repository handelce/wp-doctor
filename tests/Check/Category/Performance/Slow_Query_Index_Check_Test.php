<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Slow_Query_Index_Check;
use WP_Doctor\Check\Supplied_Queries;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Slow_Query_Index_Check_Test extends TestCase {

	private const JOIN = "SELECT o.id FROM wptest_orders o JOIN wptest_order_items i ON i.order_id = o.id WHERE o.note = 'private-value'";

	/**
	 * A driver answering EXPLAIN and the column lookup.
	 *
	 * @param string $items_type Data type of wptest_order_items.order_id.
	 * @param string $scan       Access type EXPLAIN reports for the items table.
	 * @return Recording_Driver
	 */
	private static function driver( string $items_type, string $scan ): Recording_Driver {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'EXPLAIN SELECT'             => array(
				array(
					'table' => 'o',
					'type'  => 'ALL',
					'key'   => null,
				),
				array(
					'table' => 'i',
					'type'  => $scan,
					'key'   => 'ALL' === $scan ? null : 'order_id',
				),
			),
			'information_schema.columns' => array(
				array(
					't'         => 'wptest_orders',
					'c'         => 'id',
					'type'      => 'bigint',
					'collation' => null,
				),
				array(
					't'         => 'wptest_order_items',
					'c'         => 'order_id',
					'type'      => $items_type,
					'collation' => 'varchar' === $items_type ? 'utf8mb4_unicode_ci' : null,
				),
			),
		);

		return $driver;
	}

	/**
	 * Runs the check with the given statements.
	 *
	 * @param Recording_Driver $driver Database stand-in.
	 * @param string           $text   Content of the queries file.
	 * @return Result
	 */
	private function run_with( Recording_Driver $driver, string $text ): Result {
		$context = Context_Factory::with_driver( $driver )->with_queries( Supplied_Queries::from_text( $text ) );

		return ( new Slow_Query_Index_Check() )->run( $context );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Slow_Query_Index_Check();

		$this->assertSame( 'perf.slowquery.indexcheck', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_without_statements_it_is_skipped_saying_how_to_give_them(): void {
		$result = ( new Slow_Query_Index_Check() )->run( Context_Factory::with_driver( new Recording_Driver() ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '--queries=FILE', (string) $result->message() );
	}

	public function test_a_number_joined_to_text_with_a_full_scan_is_a_likely_warning_without_the_statement(): void {
		$finding = $this->run_with( self::driver( 'varchar', 'ALL' ), self::JOIN )->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 1, $evidence['joins'][0]['line'] );
		$this->assertSame( 'wptest_order_items.order_id (varchar, utf8mb4_unicode_ci) = wptest_orders.id (bigint)', $evidence['joins'][0]['join'] );
		$this->assertStringContainsString( 'wptest_order_items (i), type ALL, no key', $evidence['joins'][0]['plan'] );
		$this->assertStringNotContainsString( 'private-value', (string) json_encode( $evidence ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_matching_types_pass(): void {
		$this->assertTrue( $this->run_with( self::driver( 'bigint', 'ALL' ), self::JOIN )->is_pass() );
	}

	public function test_a_mismatch_the_plan_reads_through_an_index_is_not_reported(): void {
		$driver                                = self::driver( 'varchar', 'ref' );
		$driver->rows_for['EXPLAIN SELECT'][0] = array(
			'table' => 'o',
			'type'  => 'eq_ref',
			'key'   => 'PRIMARY',
		);

		$this->assertTrue( $this->run_with( $driver, self::JOIN )->is_pass() );
	}

	public function test_a_statement_the_gate_refuses_is_listed_by_line_without_its_text_and_makes_a_clean_result_a_skip(): void {
		$result = $this->run_with( self::driver( 'bigint', 'ALL' ), self::JOIN . "\nDELETE FROM wptest_orders WHERE note = 'private-value'" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'line 2: refused by the read-only gate', (string) $result->message() );
		$this->assertStringNotContainsString( 'private-value', (string) $result->message() );
	}

	public function test_without_a_database_it_is_skipped(): void {
		$context = Context_Factory::without_database()->with_queries( Supplied_Queries::from_text( self::JOIN ) );

		$this->assertSame( Result::SKIPPED, ( new Slow_Query_Index_Check() )->run( $context )->status() );
	}
}
