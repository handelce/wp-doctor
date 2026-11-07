<?php
/**
 * PHP files in the uploads directory.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Check\Server_Config_Directives;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Uploads hold media. A file there that the web server runs as PHP is the usual
 * foothold of a compromise, and so is an .htaccess, .user.ini or php.ini that
 * makes other files run as PHP. An .htaccess that only stops PHP running, as
 * security plugins write, is not reported. The "Silence is golden" index.php
 * stubs that WordPress and plugins write are recognised and not reported. A
 * symlink inside uploads is not followed and is listed, so a clean result with
 * one is a skip.
 *
 * @since 0.1.0
 */
final class Uploads_Php_Check implements Check {

	private const EXTENSIONS          = array( 'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar' );
	private const STUB                = '/\A(?:\xEF\xBB\xBF)?<\?php\s*(?:(?:\/\/|\/\*)\s*Silence is golden\.?\s*(?:\*\/)?\s*)?(?:\?>)?\s*\z/i';
	private const SERVER_CONFIG_NAMES = array( '.htaccess', '.user.ini', 'php.ini' );
	private const CONFIG_READ_BYTES   = 65536;

	/**
	 * Id of the uploads PHP finding.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.uploads.php';
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
	 * Lists PHP files under the uploads directory.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the uploads directory to walk.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->uploads_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$files    = $context->files();
		$uploads  = $context->uploads_dir();
		$found    = array();
		$running  = array();
		$settings = array();
		$links    = array();
		$stubs    = 0;
		$seen     = 0;
		try {
			$walk = $files->walk( $uploads );
			foreach ( $walk as $entry ) {
				if ( $entry->is_link() ) {
					$links[] = $entry->path();
					continue;
				}
				if ( ! $entry->is_file() ) {
					continue;
				}
				++$seen;
				if ( in_array( strtolower( $entry->name() ), self::SERVER_CONFIG_NAMES, true ) ) {
					$lines = Server_Config_Directives::executing_lines( $entry->name(), (string) $files->read( $entry->path(), self::CONFIG_READ_BYTES ) );
					if ( array() !== $lines ) {
						$running[ $entry->path() ] = $lines;
					} elseif ( '.htaccess' !== strtolower( $entry->name() ) ) {
						$settings[] = $entry;
					}
					continue;
				}
				if ( ! in_array( $entry->extension(), self::EXTENSIONS, true ) ) {
					continue;
				}
				if ( $entry->size() <= 512 && 1 === preg_match( self::STUB, (string) $files->read( $entry->path(), 512 ) ) ) {
					++$stubs;
					continue;
				}
				$found[] = $entry;
			}
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( $e->getMessage() );
		}
		$gap         = $walk->incomplete_reason();
		$unconfirmed = $context->uploads_dir_unconfirmed();
		sort( $links, SORT_STRING );

		if ( array() === $found && array() === $running && array() === $settings ) {
			if ( null !== $gap ) {
				return Result::skipped( "No PHP files were found, but not all of $uploads was examined: $gap" );
			}
			if ( array() !== $links ) {
				return Result::skipped( "No PHP files were found, but symlinks in $uploads point elsewhere in the site and were not followed: " . implode( ', ', array_slice( $links, 0, Evidence::MAX_LISTED ) ) );
			}
			if ( 0 === $seen ) {
				return Result::skipped( "$uploads holds no files, so there was nothing to examine" );
			}

			return null === $unconfirmed ? Result::pass() : Result::skipped( "No PHP files were found in $uploads, but it may not be the uploads directory WordPress uses: $unconfirmed" );
		}

		$context_evidence = array( 'scan_incomplete' => null !== $gap );
		if ( array() !== $links ) {
			$context_evidence['symlinks_not_followed'] = implode( ', ', array_slice( $links, 0, Evidence::MAX_LISTED ) );
		}
		if ( null !== $unconfirmed ) {
			$context_evidence['uploads_location_unconfirmed'] = $unconfirmed;
		}

		$findings = array();
		if ( array() !== $found ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::critical(),
				'PHP files in the uploads directory',
				array_merge(
					array(
						'php_files'                 => count( $found ),
						'empty_index_stubs_ignored' => $stubs,
					),
					$context_evidence,
					Evidence::files( Evidence::by_path( $found ) )
				),
				'Uploads should hold media only. Open each file; if you did not put it there, treat the site as compromised: '
				. 'remove the file, work out how it arrived from the access logs, change the passwords and the keys and salts, and '
				. 'stop the web server from running PHP in the uploads directory.'
			);
		}
		if ( array() !== $running ) {
			ksort( $running, SORT_STRING );
			$evidence = array_merge( array( 'config_files' => count( $running ) ), $context_evidence );
			$rank     = 0;
			foreach ( array_slice( $running, 0, Evidence::MAX_LISTED, true ) as $path => $lines ) {
				++$rank;
				$evidence[ "file_$rank" ] = (string) $path;
				foreach ( $lines as $index => $line ) {
					$evidence[ "file_{$rank}_directive_" . ( $index + 1 ) ] = $line;
				}
			}
			$findings[] = new Finding(
				$this->id(),
				Severity::critical(),
				'Server configuration in the uploads directory likely makes files run as PHP',
				$evidence,
				'Uploads should never run code. If you did not write these lines, treat the site as compromised: remove them, look '
				. 'for the files they make executable or prepend, and find out from the access logs how they were written.'
			);
		}
		if ( array() !== $settings ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::warning(),
				'PHP settings files in the uploads directory are likely not needed there',
				array_merge(
					array( 'settings_files' => count( $settings ) ),
					$context_evidence,
					Evidence::files( Evidence::by_path( $settings ) )
				),
				'A .user.ini or php.ini in uploads changes PHP settings for that directory and has no normal use there. Open each one; '
				. 'remove it if you did not add it, and check the rest of the site for other changes made at the same time.'
			);
		}

		return Result::findings( ...$findings );
	}
}
