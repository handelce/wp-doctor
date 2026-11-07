<?php
/**
 * Files and directories that anyone can write.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Config;

use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * A world-writable path lets any account on the server, or any exploited
 * script, replace the code that WordPress runs. Symbolic links are skipped
 * because their own permission bits mean nothing.
 *
 * @since 0.1.0
 */
final class Writable_Files_Check implements Runs_Without_Config {

	/**
	 * Id used in reports and in the `ignore` setting.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.perms.writable';
	}

	/**
	 * Config, with the wp-config.php permission check.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Lists world-writable paths.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the file scanner that walks the whole site.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$files = $context->files();
		if ( ! $files->has_unix_permissions() ) {
			return Result::skipped( 'File permission bits are not meaningful on Windows' );
		}
		$writable = array();
		$dirs     = 0;
		$walk     = $files->walk();
		foreach ( $walk as $entry ) {
			if ( ! $entry->is_link() && 0 !== ( $entry->mode() & 0002 ) ) {
				$writable[] = $entry;
				if ( $entry->is_dir() ) {
					++$dirs;
				}
			}
		}
		$gap = $walk->incomplete_reason();

		if ( array() === $writable ) {
			return null === $gap ? Result::pass() : Result::skipped( "No world-writable paths were found, but not every path was examined: $gap" );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Files or directories can be written by anyone',
				array_merge(
					array(
						'world_writable_files'       => count( $writable ) - $dirs,
						'world_writable_directories' => $dirs,
						'scan_incomplete'            => null !== $gap,
					),
					Evidence::files( Evidence::by_path( $writable ) )
				),
				'Run `find . -type f -perm -o+w -exec chmod o-w {} +` and the same with `-type d` from the site root, then check the '
				. 'site still works. Files are normally 644 and directories 755.'
			)
		);
	}
}
