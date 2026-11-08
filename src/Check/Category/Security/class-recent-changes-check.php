<?php
/**
 * PHP files written recently without the rest of their package.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * An install or update writes every file of a plugin, theme or core release
 * within seconds, so their inode change times cluster, even when the
 * modification times kept from the archive do not. A PHP file written in the
 * last few days apart from the rest of its package, as Package_Changes
 * groups them, was likely edited or added on its own. The cache folder of the
 * content directory is passed over, since caches rewrite their files all the
 * time, and so are empty index.php stubs.
 *
 * `touch` cannot set the change time back, but a copy of the site gives
 * every file the time of the copy.
 *
 * @since 0.2.0
 */
final class Recent_Changes_Check implements Check {

	private const DAY_SECONDS = 86400;

	/**
	 * Id of the recent changes finding.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.recent.changes';
	}

	/**
	 * Grouped under security.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Groups the site's files into packages and compares their change times.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the scanner, the core, content and plugins directories, and the number of days.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->plugins_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		$files = $context->files();
		if ( ! $files->has_change_times() ) {
			return Result::skipped( 'PHP on Windows reports when a file was created, not when it was last written' );
		}
		$days     = $context->thresholds()->info( $this->id() );
		$cache    = $context->content_dir() . '/cache/';
		$packages = new Package_Changes( $context->plugins_dir(), $context->content_dir(), $context->site()->core_dir() );
		try {
			$walk = $files->walk();
			foreach ( $walk as $entry ) {
				if ( $entry->is_file() && 0 !== strpos( $entry->path(), $cache ) ) {
					$packages->add( $entry, Code_Directory::is_php( $entry ) && ! Code_Directory::is_stub_in( $files, $entry ) );
				}
			}
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( 'The site root could not be walked: ' . $e->getMessage() );
		}
		$gap = $walk->incomplete_reason();

		if ( 0 === $packages->judged() ) {
			return Result::skipped( 'No PHP files were found, so there was nothing to examine' );
		}
		if ( $packages->written_together() ) {
			return Result::skipped( 'Every PHP file was written within the same hour, as after a fresh install or a copy of the site, so no change stands out' );
		}
		$flagged = $packages->written_apart( time() - $days * self::DAY_SECONDS );
		if ( array() === $flagged ) {
			return null === $gap ? Result::pass() : Result::skipped( 'No PHP file was written apart from its package, but ' . $gap );
		}

		$evidence = array(
			'php_files_examined' => $packages->judged(),
			'recent_days'        => $days,
			'threshold_basis'    => $context->thresholds()->basis( $this->id() ),
			'files_flagged'      => count( $flagged ),
			'scan_incomplete'    => null !== $gap,
		);
		if ( null !== $gap ) {
			$evidence['not_examined'] = $gap;
		}
		foreach ( array_slice( $flagged, 0, Evidence::MAX_LISTED ) as $index => $row ) {
			$rank                                     = $index + 1;
			$evidence[ "file_$rank" ]                 = $row['file']->path();
			$evidence[ "file_{$rank}_written" ]       = gmdate( 'Y-m-d H:i', $row['file']->ctime() ) . ' UTC';
			$evidence[ "file_{$rank}_modified" ]      = gmdate( 'Y-m-d H:i', $row['file']->mtime() ) . ' UTC';
			$evidence[ "file_{$rank}_package" ]       = $row['package'];
			$evidence[ "file_{$rank}_package_files" ] = $row['package_files'];
			$evidence[ "file_{$rank}_written_with" ]  = $row['written_with'];
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'PHP files were likely changed outside an install or update',
				$evidence,
				'For each file listed, ask whether you or someone you work with changed it at that time. If not, compare it with '
				. 'a clean copy of its plugin, theme or WordPress release; for core, `wp core verify-checksums` does this. An '
				. 'unexplained change means the site may be compromised: replace the file, change the passwords and the keys and '
				. 'salts, and look for how it was changed.'
			)
		);
	}
}
