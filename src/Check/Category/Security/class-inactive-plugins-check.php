<?php
/**
 * Plugins that are installed but not active.
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
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Util\Serialized_Reader;

/**
 * An inactive plugin does not run, but its files can still be requested
 * directly, so a vulnerable one is still a way in. On multisite the network
 * activated plugins are stored elsewhere, so the check does not run there.
 *
 * @since 0.1.0
 */
final class Inactive_Plugins_Check implements Check {

	private const MAX_OPTION_BYTES = 1048576;

	/**
	 * Id of the inactive plugin notice.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.plugins.inactive';
	}

	/**
	 * Security, since the files of an inactive plugin stay reachable.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Compares the plugins on disk with the active_plugins option.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the active_plugins option, the plugins directory and MULTISITE from wp-config.php.
	 * @return Result
	 * @throws \WP_Doctor\Util\Malformed_Serialization_Exception When the active_plugins option is not a plain list.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null === $unavailable ) {
			$unavailable = $context->plugins_dir_unavailable();
		}
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		$config = $context->config();
		if ( ! $config->has( 'MULTISITE' ) && ( array_key_exists( 'MULTISITE', $config->unresolved() ) || $config->unread_code() > 0 ) ) {
			return Result::skipped( (string) $config->skip_reason( 'MULTISITE' ) );
		}
		if ( $config->has( 'MULTISITE' ) && (bool) $config->value( 'MULTISITE' ) ) {
			return Result::skipped( 'This is a multisite network; network-activated plugins are not read' );
		}

		list( $value, $reason ) = Option_Value::read( $context, 'active_plugins', self::MAX_OPTION_BYTES );
		if ( null === $value ) {
			return Result::skipped( (string) $reason );
		}

		$active  = $this->active_names( $value );
		$plugins = $context->plugins_dir();
		$on_disk = array();
		try {
			$walk = $context->files()->walk( $plugins, false );
			foreach ( $walk as $entry ) {
				// get_plugins() passes over names that begin with a dot, such as .git.
				if ( '.' === $entry->name()[0] ) {
					continue;
				}
				if ( $entry->is_dir() || $entry->is_link() || ( $entry->is_file() && 'php' === $entry->extension() && 'index.php' !== $entry->name() ) ) {
					$on_disk[] = $entry->name();
				}
			}
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( $e->getMessage() );
		}
		foreach ( $walk->skipped_links() as $path ) {
			if ( '.' !== basename( $path )[0] ) {
				$on_disk[] = basename( $path );
			}
		}
		sort( $on_disk, SORT_STRING );
		$gap = $walk->listing_gap();

		$inactive = array_values( array_diff( $on_disk, $active ) );
		if ( array() === $inactive ) {
			return null === $gap ? Result::pass() : Result::skipped( "Every plugin listed is active, but not every plugin could be listed: $gap" );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'Inactive plugins are still installed',
				array_merge(
					array(
						'plugins_on_disk' => count( $on_disk ),
						'inactive'        => count( $inactive ),
						'scan_incomplete' => null !== $gap,
					),
					Evidence::names( 'plugin', $inactive )
				),
				'Delete plugins you do not use from the Plugins screen, or with `wp plugin delete <plugin>`. Inactive plugins are not '
				. 'updated automatically on every host, and their files can still be requested directly.'
			)
		);
	}

	/**
	 * Folder and file names that active_plugins refers to.
	 *
	 * @since 0.1.0
	 *
	 * @param string $serialized Value of the active_plugins option.
	 * @return string[]
	 * @throws \WP_Doctor\Util\Malformed_Serialization_Exception When the value is not a plain list.
	 */
	private function active_names( string $serialized ): array {
		$reader  = new Serialized_Reader( $serialized );
		$entries = $reader->open_array();
		$names   = array();
		for ( $entry = 0; $entry < $entries; $entry++ ) {
			$reader->read_key();
			$plugin = $reader->read_text_or_skip();
			if ( null !== $plugin && '' !== $plugin ) {
				$names[] = explode( '/', $plugin, 2 )[0];
			}
		}
		$reader->close_array();
		$reader->assert_end();

		return $names;
	}
}
