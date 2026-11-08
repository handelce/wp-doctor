<?php
/**
 * WP-Cron turned off in wp-config.php.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * With DISABLE_WP_CRON true, page loads no longer run scheduled events, so
 * publishing, updates, backups and emails wait for a system cron job. Whether
 * such a job exists is not visible from the site's files, so this is a
 * notice; perf.cron.overdue reports events that are already late.
 *
 * @since 0.2.0
 */
final class Cron_Disabled_Check implements Check {

	/**
	 * Id of the disabled WP-Cron notice.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.cron.disabled';
	}

	/**
	 * Grouped under performance, with the other cron checks.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Reads DISABLE_WP_CRON.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives DISABLE_WP_CRON as parsed from wp-config.php.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$config = $context->config();
		if ( array_key_exists( 'DISABLE_WP_CRON', $config->unresolved() ) ) {
			return Result::skipped( 'DISABLE_WP_CRON could not be read: ' . $config->unresolved()['DISABLE_WP_CRON'] );
		}
		if ( ! $config->has( 'DISABLE_WP_CRON' ) ) {
			return $config->unread_code() > 0
				? Result::skipped( 'DISABLE_WP_CRON is not set in wp-config.php, but wp-config.php loads code that was not read' )
				: Result::pass();
		}
		if ( ! (bool) $config->value( 'DISABLE_WP_CRON' ) ) {
			return Result::pass();
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'WP-Cron is turned off, so scheduled events need a system cron job',
				array(
					'DISABLE_WP_CRON' => 'true in wp-config.php',
					'system_cron_job' => 'not visible from the site files; perf.cron.overdue reports events that are late',
				),
				'Make sure a system cron job runs the events every few minutes, for example '
				. '`*/5 * * * * wp --path=<site root> cron event run --due-now`, or one that requests wp-cron.php. '
				. 'If there is none, add one, or remove DISABLE_WP_CRON from wp-config.php.'
			)
		);
	}
}
