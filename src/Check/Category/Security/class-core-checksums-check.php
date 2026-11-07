<?php
/**
 * WordPress core files compared with the official checksums.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Fs\File_Too_Large_Exception;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Http\Checksum_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Fetches the MD5 of every file in the installed WordPress release and
 * compares it with the site. Files that differ or are gone are reported, and so
 * are files inside wp-admin and wp-includes, or PHP files in the site root,
 * that the release does not contain. wp-content is not compared: themes,
 * plugins and the sample documents are removed or edited on healthy sites.
 * Release files that are excluded, lead outside the site root or are too large
 * to hash are counted as not compared, never as missing.
 *
 * @since 0.1.0
 */
final class Core_Checksums_Check implements Runs_Without_Config {

	private const IGNORED_ROOT_FILES  = array( 'readme.html', 'license.txt', 'wp-config-sample.php', 'wp-config.php', 'wp-config-docker.php' );
	private const IGNORED_EXTRA_NAMES = array( 'error_log', '.ds_store' );
	private const CORE_DIRECTORIES    = array( 'wp-admin', 'wp-includes' );
	private const MAX_VERSION_BYTES   = 65536;

	/**
	 * Id for both the modified-file and the unknown-file findings.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.core.checksums';
	}

	/**
	 * Grouped under security.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Compares the site's core files with the official checksums.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the checksum source, the WordPress version and the file scanner.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->checksums_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		$version = $context->site()->wp_version();
		if ( null === $version ) {
			return Result::skipped( 'The WordPress version could not be read from wp-includes/version.php' );
		}

		$locale = $this->package_locale( $context );

		try {
			$expected = $context->checksums()->core_checksums( $version, $locale );
		} catch ( Checksum_Unavailable_Exception $e ) {
			return Result::skipped( 'Official checksums for WordPress ' . $version . ' could not be obtained: ' . $e->getMessage() );
		}

		$files        = $context->files();
		$modified     = array();
		$missing      = array();
		$not_compared = array();
		$unreadable   = 0;
		foreach ( $expected as $path => $md5 ) {
			$path = (string) $path;
			if ( $this->is_not_compared( $path ) ) {
				continue;
			}
			$presence = $files->presence( $path );
			if ( File_Scanner::ABSENT === $presence || File_Scanner::BROKEN_LINK === $presence ) {
				$missing[] = $path;
				continue;
			}
			if ( File_Scanner::UNREADABLE === $presence ) {
				++$unreadable;
				continue;
			}
			if ( File_Scanner::PRESENT !== $presence ) {
				$not_compared[ $path ] = $presence;
				continue;
			}
			try {
				$hash = $files->hash( $path );
			} catch ( File_Too_Large_Exception $e ) {
				$not_compared[ $path ] = 'larger than ' . File_Scanner::MAX_HASH_BYTES . ' bytes';
				continue;
			}
			if ( null === $hash ) {
				++$unreadable;
			} elseif ( $hash !== $md5 ) {
				$modified[] = $path;
			}
		}

		$unknown_core = array();
		$unknown_root = array();
		$gaps         = array();
		try {
			foreach ( self::CORE_DIRECTORIES as $directory ) {
				$walk = $files->walk( $directory );
				foreach ( $walk as $entry ) {
					if ( $entry->is_file() && ! isset( $expected[ $entry->path() ] ) && ! $this->is_ignored_extra( $entry->name() ) ) {
						$unknown_core[] = $entry->path();
					}
				}
				$gaps[] = $walk->incomplete_reason();
			}
			$walk = $files->walk( '', false );
			foreach ( $walk as $entry ) {
				if ( $entry->is_file() && 'php' === $entry->extension() && ! isset( $expected[ $entry->path() ] )
					&& ! in_array( $entry->name(), self::IGNORED_ROOT_FILES, true ) && ! $this->is_ignored_extra( $entry->name() ) ) {
					$unknown_root[] = $entry->path();
				}
			}
			$gaps[] = $walk->incomplete_reason();
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( 'A WordPress core directory could not be read: ' . $e->getMessage() );
		}
		$gaps = array_values( array_filter( $gaps ) );
		if ( array() !== $not_compared ) {
			$reasons = array();
			foreach ( array_count_values( $not_compared ) as $reason => $count ) {
				$reasons[] = "$count $reason";
			}
			$gaps[] = count( $not_compared ) . ' release file(s) were not compared (' . implode( ', ', $reasons ) . ')';
		}
		$incomplete = array() !== $gaps;

		if ( array() === $modified && array() === $missing && array() === $unknown_core && array() === $unknown_root ) {
			if ( $unreadable > 0 ) {
				array_unshift( $gaps, "$unreadable could not be read" );
			}

			return array() === $gaps ? Result::pass() : Result::skipped( 'Every file compared matched, but ' . implode( '; ', $gaps ) );
		}

		$findings = array();
		if ( array() !== $modified || array() !== $missing ) {
			sort( $modified, SORT_STRING );
			sort( $missing, SORT_STRING );
			ksort( $not_compared, SORT_STRING );
			$findings[] = new Finding(
				$this->id(),
				Severity::critical(),
				'WordPress core files differ from the official release',
				array_merge(
					array(
						'wordpress_version'  => $version,
						'locale_used'        => $locale,
						'files_compared'     => count( $expected ),
						'modified_files'     => count( $modified ),
						'missing_files'      => count( $missing ),
						'files_unreadable'   => $unreadable,
						'files_not_compared' => count( $not_compared ),
						'scan_incomplete'    => $incomplete,
					),
					Evidence::names( 'modified', $modified ),
					Evidence::names( 'missing', $missing ),
					Evidence::names( 'not_compared', array_keys( $not_compared ) )
				),
				'Do not edit core files. Reinstall them from a clean copy of the same version (`wp core download --force --skip-content '
				. '--version=' . $version . '`), then look for how they were changed. If you did not change them, treat the site as compromised.'
			);
		}
		if ( array() !== $unknown_core || array() !== $unknown_root ) {
			sort( $unknown_core, SORT_STRING );
			sort( $unknown_root, SORT_STRING );
			$findings[] = new Finding(
				$this->id(),
				array() !== $unknown_core ? Severity::critical() : Severity::warning(),
				'Files that are not part of this WordPress release were likely added to core directories',
				array_merge(
					array(
						'wordpress_version'    => $version,
						'unknown_in_core_dirs' => count( $unknown_core ),
						'unknown_php_in_root'  => count( $unknown_root ),
						'scan_incomplete'      => $incomplete,
					),
					Evidence::names( 'unknown', array_merge( $unknown_core, $unknown_root ) )
				),
				array() !== $unknown_core
					? 'Open each file. wp-admin and wp-includes hold only WordPress files, so anything else there is likely planted. '
						. 'An .htaccess in wp-admin is sometimes added to password-protect it; AddHandler, SetHandler or auto_prepend_file '
						. 'lines in an .htaccess, .user.ini or php.ini there are signs of compromise. '
						. 'Remove files you cannot account for and treat the site as compromised.'
					: 'Open each file. Some hosts and deployment tools add PHP files to the site root, so ask your host if you do not recognise one. '
						. 'A PHP file nobody can account for in the site root is a common sign of a compromise: remove it and look for how it got there.'
			);
		}

		return Result::findings( ...$findings );
	}

	/**
	 * Locale of the installed WordPress package.
	 *
	 * Translated packages record it in wp-includes/version.php. The US English
	 * package has no such line. The WPLANG setting is not used: it changes the
	 * display language, not the files on disk.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives wp-includes/version.php.
	 * @return string
	 */
	private function package_locale( Context $context ): string {
		$source = $context->files()->read( 'wp-includes/version.php', self::MAX_VERSION_BYTES );
		if ( null !== $source && 1 === preg_match( '/^\s*\$wp_local_package\s*=\s*\'([^\']*)\'\s*;/m', $source, $match ) && '' !== $match[1] ) {
			return $match[1];
		}

		return 'en_US';
	}

	/**
	 * Whether a release file is left out of the comparison.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path relative to the site root.
	 * @return bool
	 */
	private function is_not_compared( string $path ): bool {
		return 0 === strpos( $path, 'wp-content/' ) || in_array( $path, self::IGNORED_ROOT_FILES, true );
	}

	/**
	 * True for names such as error_log and .htaccess that servers add to core directories.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private function is_ignored_extra( string $name ): bool {
		return in_array( strtolower( $name ), self::IGNORED_EXTRA_NAMES, true );
	}
}
