<?php
/**
 * Pending updates recorded in WordPress's update transients.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Util;

/**
 * Reads the serialized values that WordPress stores after asking wordpress.org
 * for updates. They hold objects and arrays, which are walked and never unserialized.
 *
 * @since 0.1.0
 */
final class Update_Transients {

	/**
	 * Plugins or themes that have a newer version available.
	 *
	 * @since 0.1.0
	 *
	 * @param string $serialized Value of the update_plugins or update_themes site transient.
	 * @return array<string, string> New version by plugin file or theme folder. The version is empty when it is not a string.
	 * @throws Malformed_Serialization_Exception When the value is not readable.
	 */
	public static function pending_extensions( string $serialized ): array {
		$reader  = new Serialized_Reader( $serialized, true );
		$pending = array();
		$props   = $reader->open_array();
		for ( $prop = 0; $prop < $props; $prop++ ) {
			if ( 'response' !== $reader->read_key() ) {
				$reader->skip_value();
				continue;
			}

			$items = $reader->open_array();
			for ( $item = 0; $item < $items; $item++ ) {
				$name = (string) $reader->read_key();
				$new  = '';
				$keys = $reader->open_array();
				for ( $key = 0; $key < $keys; $key++ ) {
					if ( 'new_version' === $reader->read_key() ) {
						$new = (string) $reader->read_text_or_skip();
					} else {
						$reader->skip_value();
					}
				}
				$reader->close_array();
				$pending[ $name ] = $new;
			}
			$reader->close_array();
		}
		$reader->close_array();
		$reader->assert_end();

		return $pending;
	}

	/**
	 * Core versions offered as an upgrade, either for an administrator to
	 * install or waiting for the background updater.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $serialized Value of the update_core site transient.
	 * @param string|null $installed  Installed WordPress version, or null when unknown.
	 * @return string[] Each version offered with the response upgrade or autoupdate, once, in offer order, newer than $installed when it is known.
	 * @throws Malformed_Serialization_Exception When the value is not readable.
	 */
	public static function pending_core( string $serialized, ?string $installed = null ): array {
		$reader   = new Serialized_Reader( $serialized, true );
		$versions = array();
		$props    = $reader->open_array();
		for ( $prop = 0; $prop < $props; $prop++ ) {
			if ( 'updates' !== $reader->read_key() ) {
				$reader->skip_value();
				continue;
			}

			$offers = $reader->open_array();
			for ( $offer = 0; $offer < $offers; $offer++ ) {
				$reader->read_key();
				$response = null;
				$current  = '';
				$keys     = $reader->open_array();
				for ( $key = 0; $key < $keys; $key++ ) {
					$name = $reader->read_key();
					if ( 'response' === $name ) {
						$response = $reader->read_text_or_skip();
					} elseif ( 'current' === $name ) {
						$current = (string) $reader->read_text_or_skip();
					} else {
						$reader->skip_value();
					}
				}
				$reader->close_array();
				if ( in_array( $response, array( 'upgrade', 'autoupdate' ), true ) && ! in_array( $current, $versions, true )
					&& ( null === $installed || version_compare( $current, $installed, '>' ) ) ) {
					$versions[] = $current;
				}
			}
			$reader->close_array();
		}
		$reader->close_array();
		$reader->assert_end();

		return $versions;
	}
}
