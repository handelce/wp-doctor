<?php
/**
 * Process exit codes.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report;

/**
 * 0 nothing above info, 1 warnings, 2 critical findings, 3 tool error. A check
 * that ended in an error counts as a tool error even when findings exist, so a
 * partly failed scan is never mistaken for a clean one. Skipped checks do not
 * change the code.
 *
 * @since 0.1.0
 */
final class Exit_Code {

	public const OK       = 0;
	public const WARNINGS = 1;
	public const CRITICAL = 2;
	public const ERROR    = 3;

	/**
	 * Maps a finished scan to its exit code.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Report whose errors and highest severity decide the code.
	 * @return int
	 */
	public static function for_report( Report $report ): int {
		if ( $report->has_errors() ) {
			return self::ERROR;
		}

		$highest = $report->highest_severity();
		if ( null === $highest ) {
			return self::OK;
		}
		if ( $highest->equals( Severity::critical() ) ) {
			return self::CRITICAL;
		}
		if ( $highest->equals( Severity::warning() ) ) {
			return self::WARNINGS;
		}

		return self::OK;
	}
}
