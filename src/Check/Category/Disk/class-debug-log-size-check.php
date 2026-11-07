<?php
/**
 * Size of wp-content/debug.log.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Disk;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * A debug log that is never rotated grows until the disk is full.
 *
 * @since 0.1.0
 */
final class Debug_Log_Size_Check implements Check {


	/**
	 * Id that the size thresholds are configured under.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'disk.debuglog.size';
	}

	/**
	 * Disk, because the risk is a full disk.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'disk';
	}

	/**
	 * Measures the debug log, at the default path or the one WP_DEBUG_LOG names inside the site root.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the debug log path and the size thresholds.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->debug_log_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$path     = $context->debug_log_path();
		$presence = $context->files()->presence( $path );
		if ( File_Scanner::BROKEN_LINK === $presence ) {
			return Result::skipped( "$path is a broken symlink, so there is no log to measure" );
		}
		if ( File_Scanner::PRESENT !== $presence && File_Scanner::ABSENT !== $presence ) {
			return Result::skipped( "$path was not measured: it is $presence" );
		}
		$log = $context->files()->stat( $path );
		if ( null !== $log && $log->is_link() ) {
			return Result::skipped( "$path is a symlink, and its target was not measured" );
		}
		if ( null !== $log && ! $log->is_file() ) {
			return Result::skipped( "$path is a directory, not a log file, and was not measured" );
		}
		if ( File_Scanner::PRESENT === $presence && null === $log ) {
			return Result::skipped( "$path exists but could not be measured" );
		}

		$bytes          = null === $log ? 0 : $log->size();
		$warning_above  = $context->thresholds()->warning( $this->id() );
		$critical_above = $context->thresholds()->critical( $this->id() );

		if ( $bytes <= $warning_above ) {
			return Result::pass();
		}

		return Result::findings(
			new Finding(
				$this->id(),
				$bytes > $critical_above ? Severity::critical() : Severity::warning(),
				'The WordPress debug log is very large',
				array(
					'path'                 => $path,
					'bytes'                => $bytes,
					'warning_above_bytes'  => $warning_above,
					'critical_above_bytes' => $critical_above,
					'threshold_basis'      => $context->thresholds()->basis( $this->id() ),
				),
				'Empty the file with `truncate -s 0 ' . $this->shell_quoted( $path ) . '`, find what keeps writing to it, and turn WP_DEBUG_LOG '
				. 'off in wp-config.php when you are not debugging. Set up log rotation if it must stay on.'
			)
		);
	}

	/**
	 * A path quoted for a POSIX shell, whatever the platform the scan runs on.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path from the site.
	 * @return string
	 */
	private function shell_quoted( string $path ): string {
		return "'" . str_replace( "'", "'\\''", $path ) . "'";
	}
}
