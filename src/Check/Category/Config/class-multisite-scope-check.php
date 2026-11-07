<?php
/**
 * Tells the reader that a multisite network was scanned as its main site only.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Config;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Site\Config;

/**
 * Every other site on a network keeps its own tables (the prefix plus the site
 * number), and the database checks read only the main site's. Without this
 * finding a clean report would read as a clean network.
 *
 * @since 0.1.0
 */
final class Multisite_Scope_Check implements Check {

	/**
	 * Id of the multisite scope notice.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.multisite.scope';
	}

	/**
	 * Config, since MULTISITE comes from wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Reports a network whose other sites were not read.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Source of MULTISITE, SUBDOMAIN_INSTALL, the table prefix and the count of unfollowed includes from wp-config.php.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$config = $context->config();
		if ( array_key_exists( 'MULTISITE', $config->unresolved() ) ) {
			return Result::skipped( 'wp-config.php values unavailable: MULTISITE (' . $config->unresolved()['MULTISITE'] . ')' );
		}
		if ( ! $config->has( 'MULTISITE' ) && $config->unread_code() > 0 ) {
			return Result::skipped( 'wp-config.php values unavailable: MULTISITE' );
		}
		if ( ! $config->has( 'MULTISITE' ) || ! (bool) $config->value( 'MULTISITE' ) ) {
			return Result::pass();
		}

		$evidence = array(
			'MULTISITE'         => 'true',
			'SUBDOMAIN_INSTALL' => $config->has( 'SUBDOMAIN_INSTALL' ) ? (bool) $config->value( 'SUBDOMAIN_INSTALL' ) ? 'true' : 'false' : 'not read from wp-config.php',
			'sites_scanned'     => 'main site only',
		);
		if ( null === $config->skip_reason( Config::TABLE_PREFIX ) ) {
			$evidence['main_site_table_prefix'] = $config->table_prefix();
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'This is a multisite network and only its main site was scanned',
				$evidence,
				'Treat a clean database result as covering the main site only. The other sites keep their own tables (the prefix, '
				. 'the site number and the table name, such as wp_2_options), which no check reads yet.'
			)
		);
	}
}
