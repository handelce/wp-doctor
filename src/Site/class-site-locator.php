<?php
/**
 * Finds the WordPress root and wp-config.php for a path.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Site;

use WP_Doctor\Fs\File_Scanner;

use function WP_Doctor\esc_cli;
use function WP_Doctor\real_path;

/**
 * Reads wp-config.php and wp-includes/version.php as text. Neither file is
 * included or executed. The version file is read through File_Scanner, so a
 * symlink out of the root is refused. wp-config.php may sit in the root's
 * parent, outside any scanner, so it is read directly with a size cap.
 *
 * @since 0.1.0
 */
final class Site_Locator {

	private const MAX_CONFIG_BYTES  = 1048576;
	private const MAX_VERSION_BYTES = 65536;

	/**
	 * Parser for wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @var Config_Parser
	 */
	private $parser;

	/**
	 * Takes the parser that turns wp-config.php source into a Config.
	 *
	 * @since 0.1.0
	 *
	 * @param Config_Parser $parser Parser for wp-config.php.
	 */
	public function __construct( Config_Parser $parser ) {
		$this->parser = $parser;
	}

	/**
	 * Locates the site at a path.
	 *
	 * The path is the site root: the directory holding wp-includes, or the web
	 * root of a site whose core is in a subdirectory. The config file is read
	 * as WordPress reads it, from the core directory or, when that directory's
	 * parent is not a WordPress root too, from the parent.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Directory of the WordPress installation.
	 * @return Site
	 * @throws Site_Not_Found_Exception When the path is not a scannable site.
	 */
	public function locate( string $path ): Site {
		$root = real_path( $path );
		if ( false === $root ) {
			throw new Site_Not_Found_Exception( esc_cli( "Path does not exist: $path" ) );
		}
		if ( ! is_dir( $root ) ) {
			throw new Site_Not_Found_Exception( esc_cli( "Path is not a directory: $root" ) );
		}
		$core_dir = is_file( "$root/wp-includes/version.php" ) ? '' : $this->core_subdirectory( $root );
		if ( null === $core_dir ) {
			throw new Site_Not_Found_Exception( esc_cli( "No WordPress installation at $root: wp-includes/version.php is missing" ) );
		}

		$config_path = $this->find_config( '' === $core_dir ? $root : "$root/$core_dir" );
		$source      = $this->read( $config_path, self::MAX_CONFIG_BYTES );
		$config      = null === $source
			? Config::unreadable( 'wp-config.php could not be read by the account running wp-doctor, or is larger than ' . self::MAX_CONFIG_BYTES . ' bytes' )
			: $this->parser->parse( $source );

		return new Site( $root, $config_path, $this->read_version( $root, $core_dir ), $config, $core_dir );
	}

	/**
	 * The child directory holding WordPress core when the web root only holds
	 * an index.php that loads core from a subdirectory. The child is the one
	 * index.php names or, when wp-config.php is in the root, the only child
	 * that holds core.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root Directory given on the command line.
	 * @return string|null The subdirectory name, or null when the layout is not that one.
	 */
	private function core_subdirectory( string $root ): ?string {
		$scanner = new File_Scanner( $root, File_Scanner::DEFAULT_MAX_FILES, self::MAX_VERSION_BYTES );
		$index   = $scanner->read( 'index.php' );
		if ( null !== $index && 1 === preg_match( '#[\'"](?:\./|/)?([A-Za-z0-9_.\-]+)/wp-blog-header\.php[\'"]#', $index, $m )
			&& '.' !== $m[1] && '..' !== $m[1] && null !== $scanner->stat( $m[1] . '/wp-includes/version.php' ) ) {
			return $m[1];
		}
		if ( ! is_file( "$root/wp-config.php" ) ) {
			return null;
		}
		$found = array();
		foreach ( $scanner->walk( '', false ) as $entry ) {
			if ( $entry->is_dir() && null !== $scanner->stat( $entry->path() . '/wp-includes/version.php' ) ) {
				$found[] = $entry->name();
			}
		}

		return 1 === count( $found ) ? $found[0] : null;
	}

	/**
	 * Finds wp-config.php in the core directory, or in its parent when the parent is not a WordPress root too.
	 *
	 * @since 0.1.0
	 *
	 * @param string $core Absolute path of the directory holding core.
	 * @return string
	 * @throws Site_Not_Found_Exception When no config file exists.
	 */
	private function find_config( string $core ): string {
		if ( is_file( "$core/wp-config.php" ) ) {
			return "$core/wp-config.php";
		}

		$parent = dirname( $core );
		if ( is_file( "$parent/wp-config.php" ) && ! is_file( "$parent/wp-settings.php" ) ) {
			return "$parent/wp-config.php";
		}

		throw new Site_Not_Found_Exception( esc_cli( "wp-config.php not found in $core or its parent" ) );
	}

	/**
	 * Installed WordPress version from wp-includes/version.php.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root     Site root.
	 * @param string $core_dir Directory holding core, relative to the root.
	 * @return string|null Null when the file has no recognisable $wp_version.
	 */
	private function read_version( string $root, string $core_dir ): ?string {
		$path   = '' === $core_dir ? 'wp-includes/version.php' : "$core_dir/wp-includes/version.php";
		$source = ( new File_Scanner( $root, File_Scanner::DEFAULT_MAX_FILES, self::MAX_VERSION_BYTES ) )->read( $path );
		if ( null !== $source && 1 === preg_match( '/^\s*\$wp_version\s*=\s*\'([0-9A-Za-z.\-]+)\'\s*;/m', $source, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Reads a local file as text.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path      File path.
	 * @param int    $max_bytes Largest size accepted, in bytes.
	 * @return string|null Null when the file is unreadable or larger than the limit.
	 */
	private function read( string $path, int $max_bytes ): ?string {
		// A local path, so the WordPress advice about remote URLs does not apply.
		$contents = @file_get_contents( $path, false, null, 0, $max_bytes + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $contents || strlen( $contents ) > $max_bytes ) {
			return null;
		}

		return $contents;
	}
}
