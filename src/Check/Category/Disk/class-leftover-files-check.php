<?php
/**
 * Archives, database dumps and backup copies left in the web root.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Disk;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\File_Info;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Everything under the site root is served by the web server unless it is
 * blocked, so a database dump or a copy of wp-config.php left there is likely
 * downloadable. Which files are reachable is not tested. A .sql file inside a
 * plugin, theme, vendor or node_modules directory counts only when it holds
 * INSERT INTO statements, since shipped schema files hold no data, and
 * precompressed pages that cache plugins write (index.html.gz) are not archives.
 *
 * @since 0.1.0
 */
final class Leftover_Files_Check implements Check {

	private const ARCHIVE_EXTENSIONS = array( 'zip', 'tar', 'tgz', 'gz', 'bz2', 'xz', '7z', 'rar' );
	private const BACKUP_SUFFIXES    = array( '.bak', '.old', '.orig', '.save', '.swp', '.backup', '~' );
	private const PRECOMPRESSED      = '/\.(html?|css|js|mjs|json|xml|svg|txt|map)\.gz$/';
	private const PHP_CONFIG_COPY    = '/^wp-config[-.](old|bak|backup|copy|orig|save|prev|previous|\d[\d-]*)\.php$/';
	private const DUMP_DATA          = '/\bINSERT\s+INTO\b/i';
	private const DUMP_READ_BYTES    = 65536;

	/**
	 * Id that config.exposed.files defers to for copies of wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'disk.leftovers.web';
	}

	/**
	 * Reported under disk.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'disk';
	}

	/**
	 * Lists leftover files.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the file scanner and the uploads path, where archives are expected.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->uploads_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$files   = $context->files();
		$uploads = $context->uploads_dir() . '/';
		$code    = $this->code_directories( $context );
		$serious = array();
		$other   = array();
		$bundled = 0;
		$walk    = $files->walk();
		foreach ( $walk as $entry ) {
			if ( ! $entry->is_file() ) {
				continue;
			}
			$name    = strtolower( $entry->name() );
			$in_code = $this->is_in_code( $entry->path(), $code );
			if ( $this->is_database_dump( $name ) ) {
				if ( ! $in_code || ( '.sql' === substr( $name, -4 ) && 1 === preg_match( self::DUMP_DATA, (string) $files->read( $entry->path(), self::DUMP_READ_BYTES ) ) ) ) {
					$serious[] = $entry;
				}
			} elseif ( $this->is_config_copy( $name ) ) {
				$serious[] = $entry;
			} elseif ( $this->is_backup_copy( $name ) || $this->is_php_config_copy( $name ) ) {
				$other[] = $entry;
			} elseif ( $this->is_archive( $name ) && 0 !== strpos( $entry->path(), $uploads ) ) {
				$other[] = $entry;
				if ( $in_code ) {
					++$bundled;
				}
			}
		}
		$gap        = $walk->incomplete_reason();
		$incomplete = null !== $gap;

		if ( array() === $serious && array() === $other ) {
			return $incomplete ? Result::skipped( "No leftover files were found, but not every path was examined: $gap" ) : Result::pass();
		}

		$findings = array();
		if ( array() !== $serious ) {
			$findings[] = $this->finding(
				Severity::critical(),
				'Database dumps or copies of wp-config.php are likely downloadable from the web root',
				$serious,
				$incomplete,
				'Move each file out of the web root (or delete it). If a copy of wp-config.php or a dump was reachable, treat the '
				. 'database password and the keys and salts in it as exposed and change them.'
			);
		}
		if ( array() !== $other ) {
			$findings[] = $this->finding(
				Severity::warning(),
				'Archives and backup copies are likely left in the web root',
				$other,
				$incomplete,
				'Move the files out of the web root, or delete them if they are no longer needed.'
				. ( 0 === $bundled ? '' : ' An archive inside a plugin or theme folder may be one the plugin or theme bundles; leave those, and move only the site backups.' ),
				0 === $bundled ? array() : array( 'archives_in_code_folders' => "$bundled; themes and plugins often bundle archives, so check whether each is a site backup" )
			);
		}

		return Result::findings( ...$findings );
	}

	/**
	 * One finding that lists a group of matching files.
	 *
	 * @since 0.1.0
	 *
	 * @param Severity              $severity   Critical for dumps and config copies, warning otherwise.
	 * @param string                $title      Finding title, worded as likely.
	 * @param File_Info[]           $group      Files in the group.
	 * @param bool                  $incomplete Whether the walk stopped early.
	 * @param string                $fix        Fix text that covers the whole group.
	 * @param array<string, string> $notes      Evidence placed before the file list.
	 * @return Finding
	 */
	private function finding( Severity $severity, string $title, array $group, bool $incomplete, string $fix, array $notes = array() ): Finding {
		return new Finding(
			$this->id(),
			$severity,
			$title,
			array_merge(
				array(
					'matching_files'  => count( $group ),
					'scan_incomplete' => $incomplete,
				),
				$notes,
				Evidence::files( Evidence::by_path( $group ) )
			),
			$fix
		);
	}

	/**
	 * Whether a lowercase file name is a SQL dump, plain or compressed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Lowercase file name.
	 * @return bool
	 */
	private function is_database_dump( string $name ): bool {
		return 1 === preg_match( '/\.sql(\.(gz|zip|bz2|xz|7z))?$/', $name );
	}

	/**
	 * True for a copy of wp-config.php, such as wp-config.php.bak or wp-config-old.txt.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Lowercase file name.
	 * @return bool
	 */
	private function is_config_copy( string $name ): bool {
		return 0 === strpos( $name, 'wp-config' ) && 1 === preg_match( '/\.(bak|old|orig|save|swp|backup|txt|zip|gz|tar)$|~$|^wp-config\.php\./', $name );
	}

	/**
	 * True when a lowercase file name ends in a backup suffix such as .bak or ~.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Lowercase file name.
	 * @return bool
	 */
	private function is_backup_copy( string $name ): bool {
		foreach ( self::BACKUP_SUFFIXES as $suffix ) {
			if ( substr( $name, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a lowercase file name has an archive extension.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Lowercase file name.
	 * @return bool
	 */
	private function is_archive( string $name ): bool {
		if ( 1 === preg_match( self::PRECOMPRESSED, $name ) ) {
			return false;
		}
		$dot = strrpos( $name, '.' );

		return false !== $dot && in_array( substr( $name, $dot + 1 ), self::ARCHIVE_EXTENSIONS, true );
	}

	/**
	 * True for a copy of wp-config.php that keeps a .php extension, such as
	 * wp-config-old.php. It runs rather than downloads, but holds the same
	 * credentials. wp-config-local.php and other names that load alongside
	 * wp-config.php do not match.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Lowercase file name.
	 * @return bool
	 */
	private function is_php_config_copy( string $name ): bool {
		return 1 === preg_match( self::PHP_CONFIG_COPY, $name );
	}

	/**
	 * Directory prefixes holding shipped code, where schema files are normal:
	 * the plugin, must-use plugin and theme directories.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the content directory.
	 * @return string[] Prefixes ending in a slash. Empty when the content directory is unknown.
	 */
	private function code_directories( Context $context ): array {
		if ( null !== $context->content_dir_unavailable() ) {
			return array();
		}
		$content = $context->content_dir();

		return array( "$content/plugins/", "$content/mu-plugins/", "$content/themes/" );
	}

	/**
	 * True for a path inside shipped code: a plugin, must-use plugin or theme
	 * directory, or any vendor or node_modules directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $path Path relative to the site root.
	 * @param string[] $code Prefixes from code_directories().
	 * @return bool
	 */
	private function is_in_code( string $path, array $code ): bool {
		foreach ( $code as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}

		return 1 === preg_match( '#(^|/)(vendor|node_modules)/#', $path );
	}
}
