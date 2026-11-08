<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Cron_Duplicates_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Cron_Duplicates_Check_Test extends TestCase {

	/**
	 * Serializes a cron option with the given events.
	 *
	 * @param array<int, array{0: int, 1: string, 2: string}> $events Timestamp, hook and argument key.
	 * @return string
	 */
	private function cron_option( array $events ): string {
		$cron = array();
		foreach ( $events as $event ) {
			$cron[ $event[0] ][ $event[1] ][ $event[2] ] = array(
				'schedule' => 'hourly',
				'args'     => array(),
				'interval' => 3600,
			);
		}
		ksort( $cron );

		return serialize( $cron + array( 'version' => 2 ) );
	}

	/**
	 * The same hook and arguments at many different times.
	 *
	 * @param string $hook  Hook name.
	 * @param int    $count Number of copies.
	 * @return array<int, array{0: int, 1: string, 2: string}>
	 */
	private function copies( string $hook, int $count ): array {
		$events = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$events[] = array( 1700000000 + $i * 3600, $hook, md5( 'same' ) );
		}

		return $events;
	}

	/**
	 * Runs the check against a database whose cron option is the given value.
	 *
	 * @param string $value Serialized cron option.
	 * @return array{0: Result, 1: Recording_Driver}
	 */
	private function run_with_option( string $value ): array {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'LENGTH(option_value)' => array( array( 'size_bytes' => (string) strlen( $value ) ) ),
			'SELECT option_value'  => array( array( 'option_value' => $value ) ),
		);

		return array( ( new Cron_Duplicates_Check() )->run( Context_Factory::with_driver( $driver ) ), $driver );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Cron_Duplicates_Check();

		$this->assertSame( 'perf.cron.duplicates', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_a_schedule_without_duplicates_passes_after_the_cron_option_was_read(): void {
		list( $result, $driver ) = $this->run_with_option( $this->cron_option( $this->copies( 'example_hook', 3 ) ) );

		$this->assertTrue( $result->is_pass() );
		$this->assertCount( 3, $driver->statements, 'The check did not read the cron option' );
		foreach ( array_slice( $driver->statements, 1 ) as $sql ) {
			$this->assertStringContainsString( '`wptest_options`', $sql );
			$this->assertStringContainsString( "option_name = 'cron'", $sql );
		}
	}

	public function test_exactly_fifty_copies_pass(): void {
		list( $result ) = $this->run_with_option( $this->cron_option( $this->copies( 'example_hook', 50 ) ) );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_a_critical_finding_is_raised_above_fifty_copies(): void {
		list( $result ) = $this->run_with_option( $this->cron_option( $this->copies( 'example_hook', 51 ) ) );

		$findings = $result->finding_list();
		$this->assertCount( 1, $findings );
		$this->assertSame( 'critical', $findings[0]->severity()->name() );
		$this->assertSame( 'perf.cron.duplicates', $findings[0]->id() );
		$this->assertStringContainsString( 'likely', $findings[0]->title() );
	}

	public function test_one_hook_scheduled_with_many_different_arguments_is_not_a_duplicate(): void {
		$events = array();
		for ( $i = 0; $i < 80; $i++ ) {
			$events[] = array( 1700000000 + $i, 'example_per_post_hook', md5( (string) $i ) );
		}

		list( $result ) = $this->run_with_option( $this->cron_option( $events ) );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_the_finding_lists_each_offending_hook_largest_first_with_the_totals(): void {
		$events = array_merge(
			$this->copies( 'example_small', 60 ),
			$this->copies( 'example_large', 90 ),
			$this->copies( 'example_fine', 5 )
		);
		$value  = $this->cron_option( $events );

		list( $result ) = $this->run_with_option( $value );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 'example_large', $evidence['hooks'][0]['hook'] );
		$this->assertSame( 90, $evidence['hooks'][0]['copies'] );
		$this->assertSame( 'example_small', $evidence['hooks'][1]['hook'] );
		$this->assertSame( 60, $evidence['hooks'][1]['copies'] );
		$this->assertArrayNotHasKey( 'hook_3', $evidence );
		$this->assertSame( 155, $evidence['scheduled_events'] );
		$this->assertSame( strlen( $value ), $evidence['cron_option_bytes'] );
		$this->assertSame( 50, $evidence['critical_above_copies'] );
		$this->assertStringContainsString( 'default', (string) $evidence['threshold_basis'] );
	}

	public function test_at_most_ten_hooks_are_listed(): void {
		$events = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$events = array_merge( $events, $this->copies( sprintf( 'example_hook_%02d', $i ), 60 ) );
		}

		list( $result ) = $this->run_with_option( $this->cron_option( $events ) );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 'example_hook_10', $evidence['hooks'][9]['hook'] );
		$this->assertArrayNotHasKey( 'hook_11', $evidence );
		$this->assertSame( 720, $evidence['scheduled_events'] );
	}

	public function test_the_fix_names_no_site_data(): void {
		list( $result ) = $this->run_with_option( $this->cron_option( $this->copies( 'example_hook; rm -rf x', 60 ) ) );

		$finding = $result->finding_list()[0];
		$this->assertNotSame( '', $finding->fix() );
		$this->assertStringNotContainsString( 'example_hook', $finding->fix() );
		$this->assertStringContainsString( '<hook>', $finding->fix() );
	}

	public function test_a_hook_named_like_a_number_is_reported_as_a_string(): void {
		list( $result ) = $this->run_with_option( $this->cron_option( $this->copies( '12345', 60 ) ) );

		$this->assertSame( '12345', $result->finding_list()[0]->evidence()['hooks'][0]['hook'] );
	}

	public function test_a_missing_cron_option_is_skipped_not_passed(): void {
		$driver = new Recording_Driver();
		$result = ( new Cron_Duplicates_Check() )->run( Context_Factory::with_driver( $driver ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wptest_options', (string) $result->message() );
	}

	public function test_a_cron_option_too_large_to_read_is_skipped_without_fetching_it(): void {
		$driver           = new Recording_Driver();
		$driver->rows_for = array( 'LENGTH(option_value)' => array( array( 'size_bytes' => '16777217' ) ) );

		$result = ( new Cron_Duplicates_Check() )->run( Context_Factory::with_driver( $driver ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '16777217', (string) $result->message() );
		$this->assertCount( 2, $driver->statements, 'The oversized value was fetched' );
	}

	public function test_a_schedule_with_too_many_distinct_events_is_skipped(): void {
		$events = array();
		for ( $i = 0; $i <= 50000; $i++ ) {
			$events[] = array( 1700000000, 'example_hook_' . $i, 'k' );
		}

		list( $result ) = $this->run_with_option( $this->cron_option( $events ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '50000', (string) $result->message() );
	}

	public function test_many_copies_past_the_event_limit_are_counted_in_linear_time(): void {
		$events = $this->copies( 'example_looping_hook', 20000 );
		for ( $i = 0; $i < 49000; $i++ ) {
			$events[] = array( 1700000000, 'example_hook_' . $i, 'k' );
		}
		$option = $this->cron_option( $events );

		$started        = microtime( true );
		list( $result ) = $this->run_with_option( $option );
		$elapsed        = microtime( true ) - $started;

		$this->assertSame( Result::FINDINGS, $result->status() );
		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 'example_looping_hook', $evidence['hooks'][0]['hook'] );
		$this->assertSame( 69000, $evidence['scheduled_events'] );
		$this->assertLessThan( 3.0, $elapsed, sprintf( 'Took %.1f seconds', $elapsed ) );
	}

	public function test_an_unreadable_cron_option_is_a_warning_with_the_reason_and_position(): void {
		list( $result ) = $this->run_with_option( 'O:8:"stdClass":0:{}' );

		$finding = $result->finding_list()[0];
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$evidence = $finding->evidence();
		$this->assertSame( 'perf.cron.duplicates', $finding->id() );
		$this->assertNotSame( '', $evidence['reason'] );
		$this->assertStringNotContainsString( 'stdClass', (string) $evidence['reason'] );
		$this->assertSame( 19, $evidence['option_bytes'] );
		$this->assertGreaterThanOrEqual( 0, $evidence['stopped_at_byte'] );
		$this->assertStringContainsString( 'wp cron event list', $finding->fix() );
	}

	public function test_a_schedule_with_a_text_key_at_the_top_is_a_warning_naming_the_position(): void {
		list( $result ) = $this->run_with_option( 'a:1:{s:4:"meta";a:0:{}}' );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertStringContainsString( 'neither a timestamp nor version', (string) $evidence['reason'] );
		$this->assertSame( 16, $evidence['stopped_at_byte'] );
	}

	public function test_a_size_that_is_not_numeric_is_an_error(): void {
		$driver           = new Recording_Driver();
		$driver->rows_for = array( 'LENGTH(option_value)' => array( array( 'size_bytes' => 'big' ) ) );

		$this->expectException( \UnexpectedValueException::class );
		( new Cron_Duplicates_Check() )->run( Context_Factory::with_driver( $driver ) );
	}

	public function test_a_missing_value_is_an_error(): void {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'LENGTH(option_value)' => array( array( 'size_bytes' => '10' ) ),
			'SELECT option_value'  => array( array( 'option_value' => null ) ),
		);

		$this->expectException( \UnexpectedValueException::class );
		( new Cron_Duplicates_Check() )->run( Context_Factory::with_driver( $driver ) );
	}

	public function test_it_is_skipped_without_a_database(): void {
		$result = ( new Cron_Duplicates_Check() )->run( Context_Factory::without_database( 'no database in this test' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertSame( 'no database in this test', $result->message() );
	}

	public function test_it_is_skipped_when_the_table_prefix_was_not_read(): void {
		$driver = new Recording_Driver();
		$result = ( new Cron_Duplicates_Check() )->run( Context_Factory::with_driver( $driver, 'computed' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertCount( 1, $driver->statements, 'Only the session setup should have been sent' );
	}
}
