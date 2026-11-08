<?php
/**
 * Skips tests that need Unix file modes where the platform does not have them.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use PHPUnit\Framework\SkippedTestError;

final class File_Modes {

	/**
	 * Skips the running test on Windows, where chmod sets only the read-only flag
	 * and the permission checks skip before reading a mode.
	 *
	 * @throws SkippedTestError On Windows.
	 */
	public static function require_unix_modes(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			throw new SkippedTestError( 'Windows has no Unix file modes.' );
		}
	}

	/**
	 * Skips the running test where a mode cannot make a path unreadable: on
	 * Windows, and for root, which reads everything.
	 *
	 * @throws SkippedTestError On Windows or as root.
	 */
	public static function require_enforced_modes(): void {
		self::require_unix_modes();
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			throw new SkippedTestError( 'Permissions are not enforced for root.' );
		}
	}
}
