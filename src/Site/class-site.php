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
	 * Absolute path of the WordPress root.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $root;

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
	 * @param string      $root        Absolute path of the WordPress root.
	 * @param string      $config_path Absolute path of wp-config.php.
	 * @param string|null $wp_version  Installed version, or null when version.php could not be read.
	 * @param Config      $config      Parsed wp-config.php.
	 */
	public function __construct( string $root, string $config_path, ?string $wp_version, Config $config ) {
		$this->root        = $root;
		$this->config_path = $config_path;
		$this->wp_version  = $wp_version;
		$this->config      = $config;
	}

	/**
	 * Absolute path of the WordPress root.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function root(): string {
		return $this->root;
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
		return new self( $this->root, $this->config_path, $this->wp_version, $config );
	}
}
