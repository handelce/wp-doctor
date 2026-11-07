<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Cron_Overdue_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Cron_Overdue_Check_Test extends TestCase {

	private const NOW = 1700100000;

	/**
	 * Serializes a cron option from events.
	 *
	 * @param array<int, array{0: int, 1: string}> $events Timestamp and hook.
	 * @return string
	 */
	private function cron_option( array $events ): string {
		$cron = array();
		foreach ( $events as $index => $event ) {
			$cron[ $event[0] ][ $event[1] ][ md5( (string) $index ) ] = array(
				'schedule' => false,
				'args'     => array(),
			);
		}
		ksort( $cron );

		return serialize( $cron + array( 'version' => 2 ) );
	}

	/**
	 * Runs the check at a fixed time.
	 *
	 * @param string $value  Serialized cron option.
	 * @param string $site   Fixture site name.
	 * @return Result
	 */
	private function run_with_option( string $value, string $site = 'plain' ): Result {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'LENGTH(option_value)' => array( array( 'size_bytes' => (string) strlen( $value ) ) ),
			'SELECT option_value'  => array( array( 'option_value' => $value ) ),
		);

		return ( new Cron_Overdue_Check( self::NOW ) )->run( Context_Factory::with_driver( $driver, $site ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Cron_Overdue_Check();

		$this->assertSame( 'perf.cron.overdue', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_events_that_are_not_yet_due_or_less_than_an_hour_late_pass(): void {
		$value = $this->cron_option(
			array(
				array( self::NOW + 600, 'example_future' ),
				array( self::NOW - 3600, 'example_exactly_an_hour_late' ),
				array( self::NOW - 60, 'example_just_due' ),
			)
		);

		$this->assertTrue( $this->run_with_option( $value )->is_pass() );
	}

	public function test_an_event_more_than_an_hour_late_is_a_warning_with_the_counts(): void {
		$value = $this->cron_option(
			array(
				array( self::NOW - 3601, 'example_late' ),
				array( self::NOW - 7200, 'example_late' ),
				array( self::NOW - 90000, 'example_other' ),
				array( self::NOW + 100, 'example_future' ),
			)
		);

		$finding  = $this->run_with_option( $value )->finding_list()[0];
		$evidence = $finding->evidence();

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 3, $evidence['overdue_events'] );
		$this->assertSame( 4, $evidence['scheduled_events'] );
		$this->assertSame( 90000, $evidence['oldest_overdue_seconds'] );
		$this->assertSame( 'example_late', $evidence['hook_1'] );
		$this->assertSame( 2, $evidence['hook_1_overdue'] );
		$this->assertSame( 'example_other', $evidence['hook_2'] );
		$this->assertArrayNotHasKey( 'hook_3', $evidence );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_an_overdue_event_whose_arguments_hold_an_object_is_counted_not_reported_unreadable(): void {
		$post     = new \stdClass();
		$post->id = 42;
		$value    = serialize(
			array(
				self::NOW - 7200 => array(
					'example_publish_post' => array(
						md5( serialize( array( $post ) ) ) => array(
							'schedule' => false,
							'args'     => array( $post ),
						),
					),
				),
				'version'        => 2,
			)
		);

		$finding = $this->run_with_option( $value )->finding_list()[0];

		$this->assertStringNotContainsString( 'damaged', $finding->title() );
		$this->assertSame( 1, $finding->evidence()['overdue_events'] );
		$this->assertSame( 'example_publish_post', $finding->evidence()['hook_1'] );
	}

	public function test_a_site_that_disables_wp_cron_says_so(): void {
		$value            = $this->cron_option( array( array( self::NOW - 7200, 'example_late' ) ) );
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'LENGTH(option_value)' => array( array( 'size_bytes' => (string) strlen( $value ) ) ),
			'SELECT option_value'  => array( array( 'option_value' => $value ) ),
		);
		$tree             = new Temp_Tree();
		try {
			$result = ( new Cron_Overdue_Check( self::NOW ) )->run( Context_Factory::for_tree_with_driver( $tree, $driver, "define( 'DISABLE_WP_CRON', true );" ) );
		} finally {
			$tree->remove();
		}

		$this->assertArrayHasKey( 'DISABLE_WP_CRON', $result->finding_list()[0]->evidence() );
	}

	public function test_a_site_that_leaves_wp_cron_enabled_does_not_mention_it(): void {
		$value            = $this->cron_option( array( array( self::NOW - 7200, 'example_late' ) ) );
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'LENGTH(option_value)' => array( array( 'size_bytes' => (string) strlen( $value ) ) ),
			'SELECT option_value'  => array( array( 'option_value' => $value ) ),
		);
		$tree             = new Temp_Tree();
		try {
			$result = ( new Cron_Overdue_Check( self::NOW ) )->run( Context_Factory::for_tree_with_driver( $tree, $driver, "define( 'DISABLE_WP_CRON', false );" ) );
		} finally {
			$tree->remove();
		}

		$this->assertArrayNotHasKey( 'DISABLE_WP_CRON', $result->finding_list()[0]->evidence() );
	}

	public function test_a_disable_wp_cron_value_that_could_not_be_read_is_named_as_unknown(): void {
		$value            = $this->cron_option( array( array( self::NOW - 7200, 'example_late' ) ) );
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'LENGTH(option_value)' => array( array( 'size_bytes' => (string) strlen( $value ) ) ),
			'SELECT option_value'  => array( array( 'option_value' => $value ) ),
		);
		$tree             = new Temp_Tree();
		try {
			$result = ( new Cron_Overdue_Check( self::NOW ) )->run( Context_Factory::for_tree_with_driver( $tree, $driver, "define( 'DISABLE_WP_CRON', getenv( 'EXAMPLE_CRON' ) === 'off' );" ) );
		} finally {
			$tree->remove();
		}

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertArrayHasKey( 'DISABLE_WP_CRON', $evidence );
		$this->assertStringStartsWith( 'not read', (string) $evidence['DISABLE_WP_CRON'] );
		$this->assertStringContainsString( 'system cron job', (string) $evidence['DISABLE_WP_CRON'] );
	}

	public function test_at_most_ten_hooks_are_listed(): void {
		$events = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$events[] = array( self::NOW - 7200, sprintf( 'example_hook_%02d', $i ) );
		}

		$evidence = $this->run_with_option( $this->cron_option( $events ) )->finding_list()[0]->evidence();

		$this->assertSame( 12, $evidence['overdue_events'] );
		$this->assertSame( 'example_hook_10', $evidence['hook_10'] );
		$this->assertArrayNotHasKey( 'hook_11', $evidence );
	}

	public function test_a_missing_cron_option_is_skipped(): void {
		$result = ( new Cron_Overdue_Check( self::NOW ) )->run( Context_Factory::with_driver( new Recording_Driver() ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_an_unreadable_cron_option_is_a_warning(): void {
		$result = $this->run_with_option( 'O:8:"stdClass":0:{}' );

		$finding = $result->finding_list()[0];
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 'perf.cron.overdue', $finding->id() );
		$this->assertSame( 19, $finding->evidence()['option_bytes'] );
	}

	public function test_it_is_skipped_without_a_database_or_a_table_prefix(): void {
		$this->assertSame( Result::SKIPPED, ( new Cron_Overdue_Check( self::NOW ) )->run( Context_Factory::without_database() )->status() );
		$this->assertSame( Result::SKIPPED, $this->run_with_option( 'a:0:{}', 'computed' )->status() );
	}
}
