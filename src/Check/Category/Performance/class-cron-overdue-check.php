<?php
/**
 * Scheduled events that did not run on time.
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
 * WordPress runs its schedule when a visitor loads a page. Events that are more
 * than an hour late mean the schedule is not being triggered or is blocked.
 *
 * @since 0.1.0
 */
final class Cron_Overdue_Check implements Check {

	private const MAX_OPTION_BYTES = 16777216;
	private const MAX_HOOKS_LISTED = 10;

	/**
	 * Unix time to compare against, or null for the current time.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private $now;

	/**
	 * Takes the time that lateness is measured from.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $now Unix time to treat as now, or null for the clock.
	 */
	public function __construct( ?int $now = null ) {
		$this->now = $now;
	}

	/**
	 * Id that keys the lateness threshold.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.cron.overdue';
	}

	/**
	 * Reported under performance.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Counts events scheduled more than an hour ago.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the cron option, the lateness threshold and DISABLE_WP_CRON from wp-config.php.
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

		$now     = $this->now ?? time();
		$after   = $context->thresholds()->warning( $this->id() );
		$cutoff  = $now - $after;
		$total   = 0;
		$overdue = 0;
		$oldest  = $now;
		$by_hook = array();
		try {
			foreach ( Cron_Schedule::events( $value ) as $event ) {
				list( $time, $hook ) = $event;
				++$total;
				if ( $time < $cutoff ) {
					++$overdue;
					$oldest = min( $oldest, $time );
					if ( ! isset( $by_hook[ $hook ] ) ) {
						$by_hook[ $hook ] = 0;
					}
					++$by_hook[ $hook ];
				}
			}
		} catch ( Malformed_Serialization_Exception $e ) {
			return Result::findings( Cron_Schedule::unreadable( $this->id(), $e, strlen( $value ) ) );
		}

		if ( 0 === $overdue ) {
			return Result::pass();
		}

		$hooks = array_map( 'strval', array_keys( $by_hook ) );
		usort(
			$hooks,
			static function ( string $a, string $b ) use ( $by_hook ): int {
				$by_count = $by_hook[ $b ] <=> $by_hook[ $a ];

				return 0 !== $by_count ? $by_count : strcmp( $a, $b );
			}
		);

		$evidence = array(
			'overdue_events'         => $overdue,
			'scheduled_events'       => $total,
			'oldest_overdue_seconds' => $now - $oldest,
			'overdue_after_seconds'  => $after,
			'threshold_basis'        => $context->thresholds()->basis( $this->id() ),
		);
		foreach ( array_slice( $hooks, 0, self::MAX_HOOKS_LISTED ) as $index => $hook ) {
			$rank                               = $index + 1;
			$evidence[ "hook_$rank" ]           = $hook;
			$evidence[ "hook_{$rank}_overdue" ] = $by_hook[ $hook ];
		}
		$config = $context->config();
		if ( $config->has( 'DISABLE_WP_CRON' ) ) {
			if ( (bool) $config->value( 'DISABLE_WP_CRON' ) ) {
				$evidence['DISABLE_WP_CRON'] = 'true: events then depend on a system cron job';
			}
		} elseif ( array_key_exists( 'DISABLE_WP_CRON', $config->unresolved() ) ) {
			$evidence['DISABLE_WP_CRON'] = 'not read (' . $config->unresolved()['DISABLE_WP_CRON'] . '); if it is true, events depend on a system cron job';
		} elseif ( $config->unread_code() > 0 ) {
			$evidence['DISABLE_WP_CRON'] = 'not read (wp-config.php loads code that was not read); if it is true, events depend on a system cron job';
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Scheduled events are overdue',
				$evidence,
				'WordPress only runs scheduled events when someone visits the site. Add a system cron job that requests wp-cron.php '
				. 'every few minutes (or runs `wp cron event run --due-now`), and if DISABLE_WP_CRON is true make sure that job exists '
				. 'and works. If it does, check for a plugin or firewall rule that blocks loopback requests.'
			)
		);
	}
}
