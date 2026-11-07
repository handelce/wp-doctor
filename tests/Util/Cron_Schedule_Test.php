<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Util;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Util\Cron_Schedule;
use WP_Doctor\Util\Malformed_Serialization_Exception;

final class Cron_Schedule_Test extends TestCase {

	/**
	 * Builds the value WordPress stores in the cron option.
	 *
	 * @param array<int, array<string, array<string, array<string, mixed>>>> $by_time Events by timestamp, hook and args key.
	 * @return string
	 */
	private function cron_option( array $by_time ): string {
		return serialize( $by_time + array( 'version' => 2 ) );
	}

	/**
	 * One scheduled event body, as WordPress stores it.
	 *
	 * @param array<int, mixed> $args Event arguments.
	 * @return array<string, mixed>
	 */
	private function event( array $args = array() ): array {
		return array(
			'schedule' => 'hourly',
			'args'     => $args,
			'interval' => 3600,
		);
	}

	public function test_every_event_is_listed_with_its_time_hook_and_argument_key(): void {
		$data = $this->cron_option(
			array(
				1700000000 => array(
					'example_hook' => array( 'aaa' => $this->event() ),
					'other_hook'   => array(
						'bbb' => $this->event( array( 7 ) ),
						'ccc' => $this->event( array( 8 ) ),
					),
				),
				1700003600 => array( 'example_hook' => array( 'aaa' => $this->event() ) ),
			)
		);

		$events = iterator_to_array( Cron_Schedule::events( $data ), false );

		$this->assertSame(
			array(
				array( 1700000000, 'example_hook', 'aaa' ),
				array( 1700000000, 'other_hook', 'bbb' ),
				array( 1700000000, 'other_hook', 'ccc' ),
				array( 1700003600, 'example_hook', 'aaa' ),
			),
			$events
		);
	}

	public function test_numeric_looking_hook_and_argument_keys_are_returned_as_strings(): void {
		$data = serialize( array( 1700000000 => array( '12345' => array( '678' => $this->event() ) ) ) );

		$events = iterator_to_array( Cron_Schedule::events( $data ), false );

		$this->assertSame( array( array( 1700000000, '12345', '678' ) ), $events );
	}

	public function test_a_schedule_with_no_events_lists_nothing(): void {
		$this->assertSame( array(), iterator_to_array( Cron_Schedule::events( 'a:0:{}' ), false ) );
		$this->assertSame( array(), iterator_to_array( Cron_Schedule::events( $this->cron_option( array() ) ), false ) );
	}

	/**
	 * @dataProvider unreadable_schedules
	 * @param string $data Value that is not a cron schedule.
	 */
	public function test_a_value_that_is_not_a_cron_schedule_is_refused( string $data ): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		iterator_to_array( Cron_Schedule::events( $data ), false );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unreadable_schedules(): array {
		return array(
			'empty'                        => array( '' ),
			'a string'                     => array( 's:3:"abc";' ),
			'an object'                    => array( 'O:8:"stdClass":0:{}' ),
			'an unexpected top-level key'  => array( 'a:1:{s:5:"other";a:0:{}}' ),
			'a hook list that is a scalar' => array( 'a:1:{i:1700000000;i:5;}' ),
			'an event list that is scalar' => array( 'a:1:{i:1700000000;a:1:{s:1:"h";i:5;}}' ),
			'an object as an event'        => array( 'a:1:{i:1700000000;a:1:{s:1:"h";a:1:{s:1:"k";O:8:"stdClass":0:{}}}}' ),
			'an object as the hook list'   => array( 'a:1:{i:1700000000;O:8:"stdClass":0:{}}' ),
			'an object as the event list'  => array( 'a:1:{i:1700000000;a:1:{s:1:"h";O:8:"stdClass":0:{}}}' ),
			'an object holding the events' => array( serialize( (object) array( 1700000000 => array() ) ) ),
			'a hook name that is null'     => array( 'a:1:{i:1700000000;a:1:{N;a:0:{}}}' ),
			'trailing data'                => array( 'a:0:{}i:1;' ),
			'truncated'                    => array( 'a:1:{i:1700000000;a:1:{s:1:"h";a:1:{s:1:"k";a:0:{}' ),
		);
	}

	public function test_the_unreadable_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unreadable_schedules() );
	}

	public function test_an_event_whose_arguments_hold_an_object_is_listed(): void {
		$post       = new \stdClass();
		$post->id   = 42;
		$author     = new \stdClass();
		$author->id = 7;
		$args       = array( $post, array( 'nested' => $author ) );
		$args_key   = md5( serialize( $args ) );
		$data       = $this->cron_option(
			array(
				1700000000 => array(
					'example_hook' => array(
						$args_key => array(
							'schedule' => false,
							'args'     => $args,
						),
					),
				),
			)
		);

		$events = iterator_to_array( Cron_Schedule::events( $data ), false );

		$this->assertSame( array( array( 1700000000, 'example_hook', $args_key ) ), $events );
	}

	public function test_a_largest_integer_hook_name_is_read(): void {
		$data = serialize( array( 1700000000 => array( PHP_INT_MAX => array( 'k' => $this->event() ) ) ) );

		$events = iterator_to_array( Cron_Schedule::events( $data ), false );

		$this->assertSame( array( array( 1700000000, (string) PHP_INT_MAX, 'k' ) ), $events );
	}

	public function test_an_event_whose_arguments_repeat_an_object_is_listed(): void {
		$post     = new \stdClass();
		$post->id = 42;
		$args     = array( $post, array( 'again' => $post ) );
		$args_key = md5( serialize( $args ) );
		$data     = $this->cron_option(
			array(
				1700000000 => array(
					'example_hook' => array(
						$args_key => array(
							'schedule' => false,
							'args'     => $args,
						),
					),
				),
			)
		);
		$this->assertStringContainsString( 'r:', $data, 'serialize() wrote no back-reference, so this test proves nothing' );

		$events = iterator_to_array( Cron_Schedule::events( $data ), false );

		$this->assertSame( array( array( 1700000000, 'example_hook', $args_key ) ), $events );
	}

	/**
	 * @dataProvider references_outside_event_values
	 * @param string $data Schedule with a reference where a structural level is expected.
	 */
	public function test_a_reference_in_place_of_a_schedule_level_is_refused( string $data ): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		iterator_to_array( Cron_Schedule::events( $data ), false );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function references_outside_event_values(): array {
		return array(
			'as the hook list'  => array( 'a:1:{i:1700000000;r:1;}' ),
			'as the event list' => array( 'a:1:{i:1700000000;a:1:{s:1:"h";R:2;}}' ),
			'as an event'       => array( 'a:1:{i:1700000000;a:1:{s:1:"h";a:1:{s:1:"k";r:1;}}}' ),
		);
	}

	public function test_the_reference_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->references_outside_event_values() );
	}
}
