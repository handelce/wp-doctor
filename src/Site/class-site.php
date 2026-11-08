<?php
/**
 * A located WordPress site.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Site;

/**
 * Immutable description of the site being scanned.
 *
 * @since 0.1.0
 */
final class Site {

	/**
	 * Absolute path of the site root: the directory scanned, which holds core
	 * itself or, when core is in a subdirectory, the directory holding it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Directory holding WordPress core, relative to the root. Empty when core
	 * is in the root itself.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private $core_dir;

	/**
	 * Absolute path of wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $config_path;

	/**
	 * Installed WordPress version. Null when it could not be read.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $wp_version;

	/**
	 * Values read from wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Collects the paths, version and config of a located site.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $root        Absolute path of the site root.
	 * @param string      $config_path Absolute path of wp-config.php.
	 * @param string|null $wp_version  Installed version, or null when version.php could not be read.
	 * @param Config      $config      Parsed wp-config.php.
	 * @param string      $core_dir    Directory holding core, relative to the root. Empty when core is in the root.
	 * @throws \InvalidArgumentException When the core directory is not a plain relative path.
	 */
	public function __construct( string $root, string $config_path, ?string $wp_version, Config $config, string $core_dir ) {
		if ( '' !== $core_dir && ! self::is_child_path( $core_dir ) ) {
			throw new \InvalidArgumentException( 'The core directory must be a relative path inside the root' );
		}
		$this->root        = $root;
		$this->core_dir    = $core_dir;
		$this->config_path = $config_path;
		$this->wp_version  = $wp_version;
		$this->config      = $config;
	}

	/**
	 * Absolute path of the site root, the directory scanned.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function root(): string {
		return $this->root;
	}

	/**
	 * Directory holding WordPress core, relative to the root. Empty when core
	 * is in the root itself. WordPress calls this directory ABSPATH.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function core_dir(): string {
		return $this->core_dir;
	}

	/**
	 * A path inside the core directory, relative to the root.
	 *
	 * @since 0.2.0
	 *
	 * @param string $relative Path relative to the core directory, such as wp-includes/version.php.
	 * @return string
	 */
	public function core_path( string $relative ): string {
		return '' === $this->core_dir ? $relative : $this->core_dir . '/' . $relative;
	}

	/**
	 * Absolute path of wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function config_path(): string {
		return $this->config_path;
	}

	/**
	 * Installed WordPress version. Null when it could not be read.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function wp_version(): ?string {
		return $this->wp_version;
	}

	/**
	 * Values read from wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @return Config
	 */
	public function config(): Config {
		return $this->config;
	}

	/**
	 * A copy of the site with different config values.
	 *
	 * @since 0.1.0
	 *
	 * @param Config $config Replacement values.
	 * @return Site
	 */
	public function with_config( Config $config ): Site {
		return new self( $this->root, $this->config_path, $this->wp_version, $config, $this->core_dir );
	}

	/**
	 * Whether a path names a directory below the root: slash-separated names,
	 * none of them empty, `.` or `..`.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	private static function is_child_path( string $path ): bool {
		foreach ( explode( '/', $path ) as $name ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_.\-]+$/', $name ) || '.' === $name || '..' === $name ) {
				return false;
			}
		}

		return true;
	}
}
