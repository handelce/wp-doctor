<?php
/**
 * Plugin files that differ from their wordpress.org release.
 *
 * @since 0.3.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Http\Checksum_Unavailable_Exception;
use WP_Doctor\Http\Not_Published_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Compares each plugin folder with the checksums wordpress.org publishes for
 * its slug and version, as `wp plugin verify-checksums` does: a file whose
 * hash is not one of those listed has changed, a file the list does not name
 * was added, and readme.txt and readme.md are passed over because they are
 * often edited. Files missing from the folder are not reported. The version
 * is the `Version:` header of the folder's plugin file. Hello Dolly ships with
 * WordPress and its updates leave hello.php in place, so it is compared with
 * the copy in the installed release and in the last release of each of the
 * eight series before it. Plugins that wordpress.org does not host and other single-file
 * plugins cannot be checked this way and are listed as not checked. Must-use
 * plugins are not read.
 *
 * @since 0.3.0
 */
final class Plugin_Checksums_Check implements Check {

	private const SOFT_CHANGES = array( 'readme.txt', 'readme.md' );
	private const HELLO_DOLLY  = 'hello.php';
	private const CHECKED      = 'checked';

	private const EARLIER_SERIES = 8;

	/**
	 * Id of the plugin checksum finding.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.plugins.checksums';
	}

	/**
	 * Grouped under security.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Checks every plugin folder against wordpress.org.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the plugins directory, the scanner and the checksum source.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->checksums_unavailable();
		if ( null === $unavailable ) {
			$unavailable = $context->plugins_dir_unavailable();
		}
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		try {
			$plugins = new Code_Directory( $context->files(), $context->plugins_dir() );
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( $e->getMessage() );
		}

		$checked   = 0;
		$unchecked = array();
		$found     = array();
		$unhashed  = array();
		foreach ( $plugins->children() as $name => $entry ) {
			$name = (string) $name;
			if ( '.' === $name[0] ) {
				continue;
			}
			if ( ! $entry->is_dir() ) {
				if ( self::HELLO_DOLLY === $name ) {
					$problem = $this->hello_dolly( $context, $entry->path() );
					if ( self::CHECKED === $problem || 'changed' === $problem ) {
						++$checked;
						if ( 'changed' === $problem ) {
							$found[] = array( $name, 'Hello Dolly, bundled with WordPress ' . $context->site()->wp_version() . ' and the ' . self::EARLIER_SERIES . ' series before it', 'changed' );
						}
					} else {
						$unchecked[ $name ] = $problem;
					}
				} elseif ( Code_Directory::is_php( $entry ) && ! $plugins->is_index_stub( $entry ) ) {
					$unchecked[ $name ] = 'a single-file plugin, which wordpress.org publishes no checksums for';
				}
				continue;
			}
			$version = $this->version( $plugins, $name );
			if ( null === $version ) {
				$unchecked[ $name ] = 'no plugin file with a Version header';
				continue;
			}
			try {
				$expected = $context->checksums()->plugin_checksums( $name, $version );
			} catch ( Not_Published_Exception $e ) {
				$unchecked[ $name ] = "version $version is not published on wordpress.org: a premium or custom plugin, or a release wordpress.org does not have";
				continue;
			} catch ( Checksum_Unavailable_Exception $e ) {
				$unchecked[ $name ] = 'the checksums could not be fetched: ' . $e->getMessage();
				continue;
			}
			++$checked;
			foreach ( $this->compare( $context->files(), $plugins, $name, $expected ) as $path => $problem ) {
				if ( null === $problem ) {
					$unhashed[] = "$name/$path";
				} else {
					$found[] = array( "$name/$path", "$name $version", $problem );
				}
			}
		}

		$gaps = $plugins->gaps();
		if ( array() !== $unhashed ) {
			sort( $unhashed, SORT_STRING );
			$gaps[] = count( $unhashed ) . ' plugin file(s) could not be read to hash (' . implode( ', ', array_slice( $unhashed, 0, 5 ) ) . ( count( $unhashed ) > 5 ? ', ...' : '' ) . ')';
		}
		if ( array() !== $unchecked ) {
			$gaps[] = count( $unchecked ) . ' plugin(s) could not be checked (' . implode( ', ', array_slice( array_keys( $unchecked ), 0, 5 ) ) . ( count( $unchecked ) > 5 ? ', ...' : '' ) . ')';
		}

		if ( array() === $found ) {
			if ( 0 === $checked && array() === $unchecked ) {
				return Result::skipped( 'No plugins are installed, so there was nothing to check' );
			}

			return array() === $gaps ? Result::pass() : Result::skipped( "No file differs in the $checked plugin(s) checked, but " . implode( '; ', $gaps ) );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Plugin files differ from their wordpress.org release',
				$this->evidence( $plugins->path(), $checked, $unchecked, $found, $gaps ),
				'Reinstall each plugin listed from wordpress.org with `wp plugin install <slug> --version=<version> --force`, after a '
				. 'backup. If a changed or added file is PHP and nobody on your side edited the plugin, treat the site as compromised: '
				. 'change the passwords and the keys and salts, and look for how the file was changed.'
			)
		);
	}

	/**
	 * Compares hello.php with the copy in the installed WordPress release and,
	 * since updates leave hello.php in place, with the copies in the last
	 * releases of the series before it.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the WordPress version, the locale and the checksum source.
	 * @param string  $path    Path of hello.php relative to the root.
	 * @return string CHECKED, changed, or why it could not be compared.
	 */
	private function hello_dolly( Context $context, string $path ): string {
		$version = $context->site()->wp_version();
		if ( null === $version ) {
			return 'Hello Dolly ships with WordPress, and the WordPress version could not be read';
		}
		$hash = $context->files()->hash( $path, 'md5' );
		if ( null === $hash ) {
			return 'hello.php could not be read to hash';
		}
		$source = $context->checksums();
		$locale = Core_Checksums_Check::package_locale( $context );
		$failed = null;
		try {
			$candidates = array_merge( array( $version ), self::earlier_series( $source->core_releases(), $version ) );
		} catch ( Checksum_Unavailable_Exception $e ) {
			$candidates = array( $version );
			$failed     = 'the list of WordPress releases could not be fetched: ' . $e->getMessage();
		}
		$compared = 0;
		foreach ( $candidates as $release ) {
			try {
				$core = $source->core_checksums( $release, $locale );
			} catch ( Checksum_Unavailable_Exception $e ) {
				$failed = $failed ?? "the checksums of WordPress $release could not be fetched: " . $e->getMessage();
				continue;
			}
			if ( ! isset( $core['wp-content/plugins/hello.php'] ) ) {
				continue;
			}
			++$compared;
			if ( $hash === $core['wp-content/plugins/hello.php'] ) {
				return self::CHECKED;
			}
		}

		if ( null !== $failed ) {
			return "it matches none of the releases compared, and $failed";
		}

		return 0 === $compared ? "no WordPress release it could come from had checksums to compare it with, from $version back" : 'changed';
	}

	/**
	 * The last release of each series before $version, newest first, up to EARLIER_SERIES of them.
	 *
	 * @since 0.3.0
	 *
	 * @param string[] $releases Every release, in any order.
	 * @param string   $version  Installed version.
	 * @return string[]
	 */
	private static function earlier_series( array $releases, string $version ): array {
		$installed = implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
		$latest    = array();
		foreach ( $releases as $release ) {
			$series = implode( '.', array_slice( explode( '.', $release ), 0, 2 ) );
			if ( version_compare( $series, $installed, '<' ) && ( ! isset( $latest[ $series ] ) || version_compare( $release, $latest[ $series ], '>' ) ) ) {
				$latest[ $series ] = $release;
			}
		}
		uksort( $latest, 'version_compare' );

		return array_slice( array_reverse( array_values( $latest ) ), 0, self::EARLIER_SERIES );
	}

	/**
	 * Version of a plugin folder: the Version header of its first plugin file, in name order.
	 *
	 * @since 0.3.0
	 *
	 * @param Code_Directory $plugins The plugins directory.
	 * @param string         $name    Folder name.
	 * @return string|null Null when no plugin file in the folder has a Version header.
	 */
	private function version( Code_Directory $plugins, string $name ): ?string {
		foreach ( $plugins->children( $name ) as $file => $entry ) {
			$file = (string) $file;
			if ( ! $entry->is_file() || '.' === $file[0] || '.php' !== substr( $file, -4 ) ) {
				continue;
			}
			$plugin_name = $plugins->header( "$name/$file", 'Plugin Name' );
			if ( null === $plugin_name || '' === $plugin_name ) {
				continue;
			}
			$version = (string) $plugins->header( "$name/$file", 'Version' );

			return '' === $version ? null : $version;
		}

		return null;
	}

	/**
	 * Files of one plugin folder that changed or were added. A file that could not be hashed maps to null.
	 *
	 * @since 0.3.0
	 *
	 * @param File_Scanner                                          $files    Reads and hashes the files.
	 * @param Code_Directory                                        $plugins  The plugins directory.
	 * @param string                                                $name     Folder name.
	 * @param array<string, array{sha256: string[], md5: string[]}> $expected Checksums from wordpress.org by path inside the folder.
	 * @return array<string, string|null> Problem by path inside the folder: changed, added, or null.
	 */
	private function compare( File_Scanner $files, Code_Directory $plugins, string $name, array $expected ): array {
		$problems = array();
		foreach ( $plugins->files_under( $name ) as $path => $entry ) {
			$path = (string) $path;
			if ( in_array( strtolower( $path ), self::SOFT_CHANGES, true ) ) {
				continue;
			}
			if ( ! isset( $expected[ $path ] ) ) {
				$problems[ $path ] = 'added';
				continue;
			}
			$algorithm = array() !== $expected[ $path ]['sha256'] ? 'sha256' : 'md5';
			$hash      = $files->hash( $entry->path(), $algorithm );
			if ( null === $hash ) {
				$problems[ $path ] = null;
			} elseif ( ! in_array( $hash, $expected[ $path ][ $algorithm ], true ) ) {
				$problems[ $path ] = 'changed';
			}
		}

		return $problems;
	}

	/**
	 * Evidence: the counts, each file found, then the plugins that were not checked.
	 *
	 * @since 0.3.0
	 *
	 * @param string                                             $directory Plugins directory relative to the root.
	 * @param int                                                $checked   Plugins compared.
	 * @param array<string, string>                              $unchecked Reason by plugin folder.
	 * @param array<int, array{0: string, 1: string, 2: string}> $found Path inside the plugins directory, plugin and version, and problem.
	 * @param string[]                                           $gaps      What could not be examined.
	 * @return array<string, mixed>
	 */
	private function evidence( string $directory, int $checked, array $unchecked, array $found, array $gaps ): array {
		$changed = 0;
		foreach ( $found as $row ) {
			$changed += 'changed' === $row[2] ? 1 : 0;
		}
		$evidence = array(
			'plugins_checked'     => $checked,
			'plugins_not_checked' => count( $unchecked ),
			'files_changed'       => $changed,
			'files_added'         => count( $found ) - $changed,
			'scan_incomplete'     => array() !== $gaps,
		);
		if ( array() !== $gaps ) {
			$evidence['not_examined'] = implode( '; ', $gaps );
		}
		foreach ( array_slice( $found, 0, Evidence::MAX_LISTED ) as $index => $row ) {
			$rank                               = $index + 1;
			$evidence[ "file_$rank" ]           = "$directory/{$row[0]}";
			$evidence[ "file_{$rank}_plugin" ]  = $row[1];
			$evidence[ "file_{$rank}_problem" ] = $row[2];
		}
		$rank = 0;
		foreach ( array_slice( $unchecked, 0, Evidence::MAX_LISTED, true ) as $name => $reason ) {
			++$rank;
			$evidence[ "not_checked_$rank" ] = "$name: $reason";
		}

		return $evidence;
	}
}
