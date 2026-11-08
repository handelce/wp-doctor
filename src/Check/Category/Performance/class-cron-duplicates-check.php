<?php
/**
 * The same cron event scheduled many times.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Option_Value;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Util\Cron_Schedule;
use WP_Doctor\Util\Malformed_Serialization_Exception;

/**
 * Counts, per hook, how many times the same hook with the same arguments is
 * scheduled. A hook scheduled with different arguments, such as one event per
 * post, is not counted as a duplicate.
 *
 * @since 0.1.0
 */
final class Cron_Duplicates_Check implements Check {

	private const MAX_OPTION_BYTES    = 16777216;
	private const MAX_DISTINCT_EVENTS = 50000;
	private const MAX_HOOKS_LISTED    = 10;

	/**
	 * Id under which the copy threshold is configured.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.cron.duplicates';
	}

	/**
	 * Performance, with the other cron check.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Reads the cron option and counts identical events. A cron option that is
	 * not a plain serialized schedule is a warning finding, not an error.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the cron option from the options table.
	 * @return Result
	 * @throws \UnexpectedValueException When the database returns an unexpected result.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		list( $value, $reason ) = Option_Value::read( $context, 'cron', self::MAX_OPTION_BYTES );
		if ( null === $value ) {
			return Result::skipped( (string) $reason );
		}
		$bytes = strlen( $value );

		$copies   = array();
		$total    = 0;
		$distinct = 0;
		try {
			foreach ( Cron_Schedule::events( $value ) as $event ) {
				list( , $hook, $args_key ) = $event;
				if ( ! isset( $copies[ $hook ][ $args_key ] ) ) {
					if ( $distinct >= self::MAX_DISTINCT_EVENTS ) {
						return Result::skipped( 'The cron option has more than ' . self::MAX_DISTINCT_EVENTS . ' distinct scheduled events, more than this check analyses' );
					}
					$copies[ $hook ][ $args_key ] = 0;
					++$distinct;
				}
				++$copies[ $hook ][ $args_key ];
				++$total;
			}
		} catch ( Malformed_Serialization_Exception $e ) {
			return Result::findings( Cron_Schedule::unreadable( $this->id(), $e, $bytes ) );
		}

		return $this->result_for( $context, $copies, $total, $bytes );
	}

	/**
	 * Turns the per-hook counts into a pass or a critical finding.
	 *
	 * @since 0.1.0
	 *
	 * @param Context                               $context Gives the copy threshold and its basis.
	 * @param array<string|int, array<string, int>> $copies  Copies by hook and argument key.
	 * @param int                                   $total   Events in the schedule.
	 * @param int                                   $bytes   Size of the serialized cron option in bytes.
	 * @return Result
	 */
	private function result_for( Context $context, array $copies, int $total, int $bytes ): Result {
		$critical_above = $context->thresholds()->critical( $this->id() );
		$worst          = array();
		foreach ( $copies as $hook => $by_args ) {
			$most = max( $by_args );
			if ( $most > $critical_above ) {
				$worst[ (string) $hook ] = $most;
			}
		}
		if ( array() === $worst ) {
			return Result::pass();
		}

		$hooks = array_map( 'strval', array_keys( $worst ) );
		usort(
			$hooks,
			static function ( string $a, string $b ) use ( $worst ): int {
				$by_copies = $worst[ $b ] <=> $worst[ $a ];

				return 0 !== $by_copies ? $by_copies : strcmp( $a, $b );
			}
		);

		$evidence          = array();
		$evidence['hooks'] = array();
		foreach ( array_slice( $hooks, 0, self::MAX_HOOKS_LISTED ) as $hook ) {
			$evidence['hooks'][] = array(
				'hook'   => $hook,
				'copies' => $worst[ $hook ],
			);
		}
		$evidence['scheduled_events']      = $total;
		$evidence['cron_option_bytes']     = $bytes;
		$evidence['critical_above_copies'] = $critical_above;
		$evidence['threshold_basis']       = $context->thresholds()->basis( $this->id() );

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::critical(),
				'Hooks are likely being rescheduled in a loop: the same event is scheduled many times',
				$evidence,
				'Find the plugin or theme that schedules each listed hook (search wp-content for the hook name) and update or '
				. 'remove it. Then delete the duplicate events, for example with `wp cron event delete <hook>`, and let the '
				. 'plugin schedule one again. Take a database backup first.'
			)
		);
	}
}
