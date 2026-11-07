<?php
/**
 * Who can read wp-config.php.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Config;

use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * The configuration file holds the database password and the keys. Bits that
 * let other accounts read it expose them; bits that let the group or others
 * write it let another account change the site's code. Group read is left
 * alone, since the web server often reads the file through the group.
 *
 * @since 0.1.0
 */
final class Config_Permissions_Check implements Runs_Without_Config {

	/**
	 * Id for the wp-config.php permission finding.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.perms.wpconfig';
	}

	/**
	 * Grouped under config, with the other file permission check.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Reads the permission bits of wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Site paths, and the scanner that reads the permission bits.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$site = $context->site();
		if ( dirname( $site->config_path() ) !== $site->root() ) {
			return Result::skipped( 'wp-config.php is outside the site root, so its permissions are not read' );
		}

		if ( ! $context->files()->has_unix_permissions() ) {
			return Result::skipped( 'File permission bits are not meaningful on Windows' );
		}

		$file = $context->files()->stat( 'wp-config.php' );
		if ( null === $file || ! $file->is_file() ) {
			return Result::skipped( 'wp-config.php could not be read from the site root' );
		}
		if ( 0 === ( $file->mode() & 0026 ) ) {
			return Result::pass();
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'wp-config.php can be read or changed by other accounts on the server',
				array(
					'path' => 'wp-config.php',
					'mode' => sprintf( '%04o', $file->mode() ),
				),
				'Run `chmod 640 wp-config.php`, or `chmod 600 wp-config.php` when the web server runs as the file owner. '
				. 'If the database password may have been read by someone else, change it. If others could write the file, '
				. 'compare it with a known good copy before trusting it.'
			)
		);
	}
}
