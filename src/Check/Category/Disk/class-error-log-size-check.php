<?php
/**
 * Size of error_log files.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Disk;

use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\File_Info;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * PHP writes error_log files into the directory of the failing script, so they
 * can be anywhere under the site.
 *
 * @since 0.1.0
 */
final class Error_Log_Size_Check implements Runs_Without_Config {


	/**
	 * Id the report and the threshold setting refer to.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'disk.errorlog.size';
	}

	/**
	 * Grouped with the other disk usage checks.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'disk';
	}

	/**
	 * Finds large error_log files.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the file scanner that walks the whole site, and the size threshold.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$files         = $context->files();
		$warning_above = $context->thresholds()->warning( $this->id() );
		$large         = array();
		$walk          = $files->walk();
		foreach ( $walk as $entry ) {
			if ( $entry->is_file() && 'error_log' === $entry->name() && $entry->size() > $warning_above ) {
				$large[] = $entry;
			}
		}
		$gap = $walk->incomplete_reason();

		if ( array() === $large ) {
			return null === $gap ? Result::pass() : Result::skipped( "No large error_log files were found, but not every path was examined: $gap" );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Large error_log files',
				array(
					'large_files'         => count( $large ),
					'warning_above_bytes' => $warning_above,
					'threshold_basis'     => $context->thresholds()->basis( $this->id() ),
					'scan_incomplete'     => null !== $gap,
					'files'               => Evidence::files( Evidence::by_size( $large ) ),
				),
				'Read the end of each file to find the repeating error and fix its cause, then empty the file '
				. '(`truncate -s 0 <path>`). Turn on log rotation on the host if the file keeps growing.'
			)
		);
	}
}
