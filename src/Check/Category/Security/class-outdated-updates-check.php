<?php
/**
 * Updates that WordPress already knows about but have not been applied.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Check\Option_Value;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Site\Config;
use WP_Doctor\Util\Update_Transients;

/**
 * Reads the update data WordPress stores after asking wordpress.org: in the
 * options table on a single site, in sitemeta on a multisite network. The
 * data is as fresh as the last time WordPress checked, and is absent when an
 * external object cache holds transients.
 *
 * @since 0.1.0
 */
final class Outdated_Updates_Check implements Check {

	private const MAX_OPTION_BYTES = 8388608;

	/**
	 * Report id for pending updates.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.updates.outdated';
	}

	/**
	 * Reported under security.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Lists pending core, plugin and theme updates.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the three update transients, the MULTISITE setting and the installed version.
	 * @return Result
	 * @throws \WP_Doctor\Util\Malformed_Serialization_Exception When an update value is not readable.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$config         = $context->config();
		$multisite      = $config->has( 'MULTISITE' ) && (bool) $config->value( 'MULTISITE' );
		$multisite_open = ! $config->has( 'MULTISITE' ) && ( array_key_exists( 'MULTISITE', $config->unresolved() ) || $config->unread_code() > 0 );
		$network_id     = null;
		if ( $multisite ) {
			$network_id = $this->network_id( $config );
			if ( is_string( $network_id ) ) {
				return Result::skipped( $network_id );
			}
		}

		$missing = array();
		$values  = array();
		foreach ( array( 'plugins', 'themes', 'core' ) as $kind ) {
			$name                   = '_site_transient_update_' . $kind;
			list( $value, $reason ) = null === $network_id
				? Option_Value::read( $context, $name, self::MAX_OPTION_BYTES )
				: Option_Value::read_network( $context, $name, self::MAX_OPTION_BYTES, $network_id );
			if ( null === $value ) {
				$missing[ $kind ] = (string) $reason;
			} else {
				$values[ $kind ] = $value;
			}
		}
		if ( array() === $values ) {
			$where = null === $network_id
				? ' in ' . $context->table( 'options' )
				: ' in ' . $context->table( 'sitemeta' ) . " for network $network_id";
			$also  = $multisite_open
				? '. If this is a multisite network the data is in ' . $context->table( 'sitemeta' ) . ', which is read only when MULTISITE is known to be true: ' . $config->skip_reason( 'MULTISITE' )
				: '';

			return Result::skipped( "WordPress has no stored update data$where (an external object cache may hold it): " . implode( '; ', $missing ) . $also );
		}

		$plugins = isset( $values['plugins'] ) ? Update_Transients::pending_extensions( $values['plugins'] ) : array();
		$themes  = isset( $values['themes'] ) ? Update_Transients::pending_extensions( $values['themes'] ) : array();
		$core    = isset( $values['core'] ) ? Update_Transients::pending_core( $values['core'], $context->site()->wp_version() ) : array();

		if ( array() === $plugins && array() === $themes && array() === $core ) {
			return array() === $missing
				? Result::pass()
				: Result::skipped( 'No pending updates in the data that exists; no stored update data for: ' . implode( ', ', array_keys( $missing ) ) );
		}

		$evidence = array(
			'plugin_updates' => count( $plugins ),
			'theme_updates'  => count( $themes ),
			'core_updates'   => count( $core ),
		);
		if ( array() !== $core ) {
			$evidence['core_available'] = implode( ', ', $core );
		}
		if ( array() !== $missing ) {
			$evidence['no_update_data_for'] = implode( ', ', array_keys( $missing ) );
		}
		$evidence += Evidence::names( 'plugin', $this->described( $plugins ) );
		$evidence += Evidence::names( 'theme', $this->described( $themes ) );

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Updates are available and not yet installed',
				$evidence,
				'Back up the site, then update from the Updates screen, or with `wp core update`, `wp plugin update --all` and '
				. '`wp theme update --all`. Update a copy first if the site is business critical. This list is as fresh as the '
				. 'last time WordPress checked for updates.'
			)
		);
	}

	/**
	 * The network whose sitemeta holds the update data: SITE_ID_CURRENT_SITE
	 * when wp-config.php sets it, else 1, which WordPress uses when it is not set.
	 *
	 * @since 0.1.0
	 *
	 * @param Config $config Constants parsed from wp-config.php.
	 * @return int|string The network id, or why it could not be read.
	 */
	private function network_id( Config $config ) {
		if ( array_key_exists( 'SITE_ID_CURRENT_SITE', $config->unresolved() ) ) {
			return (string) $config->skip_reason( 'SITE_ID_CURRENT_SITE' );
		}
		if ( ! $config->has( 'SITE_ID_CURRENT_SITE' ) ) {
			return 1;
		}
		$value = $config->value( 'SITE_ID_CURRENT_SITE' );
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || 1 !== preg_match( '/^[1-9][0-9]{0,9}\z/', (string) $value ) ) {
			return 'SITE_ID_CURRENT_SITE in wp-config.php is not a positive whole number';
		}

		return (int) $value;
	}

	/**
	 * Names with their new versions, in name order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $pending New version by name.
	 * @return string[]
	 */
	private function described( array $pending ): array {
		ksort( $pending, SORT_STRING );
		$lines = array();
		foreach ( $pending as $name => $version ) {
			$lines[] = '' === $version ? (string) $name : $name . ' -> ' . $version;
		}

		return $lines;
	}
}
