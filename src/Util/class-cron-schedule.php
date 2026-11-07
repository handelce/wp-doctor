<?php
/**
 * Events in the WordPress cron option.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Util;

use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Severity;

use function WP_Doctor\esc_cli;

/**
 * Walks the serialized cron option: timestamp, then hook, then a key derived
 * from the event arguments, then the event body. Nothing is unserialized. PHP
 * stores a numeric-looking array key as an integer, so hook and argument keys
 * are read as either and returned as strings. Event arguments may hold any
 * serializable value, so objects are accepted inside an event body and read
 * as property lists; every level above it must be a plain array.
 *
 * @since 0.1.0
 */
final class Cron_Schedule {

	/**
	 * Lists scheduled events.
	 *
	 * @since 0.1.0
	 *
	 * @param string $serialized Value of the cron option.
	 * @return \Generator Yields arrays of timestamp (int), hook (string) and argument key (string).
	 * @throws Malformed_Serialization_Exception While iterating, when the value is not a cron schedule.
	 */
	public static function events( string $serialized ): \Generator {
		$reader  = new Serialized_Reader( $serialized, true );
		$entries = $reader->open_plain_array();
		for ( $entry = 0; $entry < $entries; $entry++ ) {
			$time = $reader->read_key();
			if ( 'version' === $time ) {
				$reader->skip_value();
				continue;
			}
			if ( ! is_int( $time ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The second argument is a byte offset, not text.
				throw new Malformed_Serialization_Exception( esc_cli( 'Unreadable cron schedule: a key is neither a timestamp nor version' ), $reader->position() );
			}

			$hooks = $reader->open_plain_array();
			for ( $hook_index = 0; $hook_index < $hooks; $hook_index++ ) {
				$hook = (string) $reader->read_key();

				$events = $reader->open_plain_array();
				for ( $event = 0; $event < $events; $event++ ) {
					$args_key = (string) $reader->read_key();
					$fields   = $reader->open_plain_array();
					for ( $field = 0; $field < $fields; $field++ ) {
						$reader->read_key();
						$reader->skip_value();
					}
					$reader->close_array();

					yield array( $time, $hook, $args_key );
				}
				$reader->close_array();
			}
			$reader->close_array();
		}
		$reader->close_array();
		$reader->assert_end();
	}

	/**
	 * The finding for a cron option that is not in the shape WordPress writes.
	 *
	 * @since 0.1.0
	 *
	 * @param string                            $check_id Id of the check that hit the unreadable option.
	 * @param Malformed_Serialization_Exception $error    Why reading stopped, and at which byte.
	 * @param int                               $bytes    Size of the option value in bytes.
	 * @return Finding
	 */
	public static function unreadable( string $check_id, Malformed_Serialization_Exception $error, int $bytes ): Finding {
		$evidence = array(
			'reason'          => $error->getMessage(),
			'option_bytes'    => $bytes,
			'expected_layout' => 'a serialized array of timestamps, each holding hooks, each holding one event per argument set, plus a version key',
		);
		if ( $error->position() >= 0 ) {
			$evidence['stopped_at_byte'] = $error->position();
		}

		return new Finding(
			$check_id,
			Severity::warning(),
			'The cron option is likely damaged or not in the shape WordPress writes',
			$evidence,
			'WordPress runs scheduled tasks from this option, so a value it cannot read stops them. Inspect it with `wp cron event list`. '
			. 'If WP-CLI cannot read it either, export the option as a backup, remove it with `wp option delete cron`, and load the site '
			. 'once so WordPress recreates its own events; plugins reschedule theirs as they run.'
		);
	}
}
