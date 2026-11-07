<?php
/**
 * Debug constants switched on in wp-config.php.
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
 * WP_DEBUG on a live site prints errors to visitors or fills the disk.
 * WordPress reads WP_DEBUG_DISPLAY and WP_DEBUG_LOG only while WP_DEBUG is on,
 * so WP_DEBUG alone decides, and the other two are shown as evidence. Whether
 * the site is live cannot be told, so the finding says "likely".
 *
 * @since 0.1.0
 */
final class Debug_Enabled_Check implements Check {

	/**
	 * Report id, also accepted by the `ignore` setting.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.debug.enabled';
	}

	/**
	 * Config, because the fix is a wp-config.php edit.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Reads WP_DEBUG, then what WP_DEBUG_DISPLAY and WP_DEBUG_LOG do while it is on.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Holds the constants parsed from wp-config.php, and the count of code it could not follow.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$config = $context->config();
		$read   = $this->value_of( $config, 'WP_DEBUG' );
		if ( null === $read ) {
			return Result::skipped( 'wp-config.php values unavailable: WP_DEBUG' );
		}
		if ( ! $read ) {
			return Result::pass();
		}

		$display = $this->value_of( $config, 'WP_DEBUG_DISPLAY' );
		$log     = $this->value_of( $config, 'WP_DEBUG_LOG' );
		$display = null === $display ? 'not read' : ( $display ? ( $config->has( 'WP_DEBUG_DISPLAY' ) ? 'on' : 'on, by default when WP_DEBUG is on' ) : 'off' );

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Debugging is likely switched on for a live site',
				array(
					'WP_DEBUG'         => 'on',
					'WP_DEBUG_DISPLAY' => $display,
					'WP_DEBUG_LOG'     => null === $log ? 'not read' : ( $log ? 'on' : 'off' ),
				),
				"On a live site set WP_DEBUG to false in wp-config.php; WP_DEBUG_DISPLAY and WP_DEBUG_LOG only apply while it is on.\n"
				. 'Keep debugging on only in a development copy.'
			)
		);
	}

	/**
	 * Whether a debug constant is on as WordPress would see it. An undefined
	 * constant takes the WordPress default: off, except WP_DEBUG_DISPLAY.
	 *
	 * @since 0.1.0
	 *
	 * @param Config $config Constants parsed from wp-config.php.
	 * @param string $name   One of WP_DEBUG, WP_DEBUG_DISPLAY and WP_DEBUG_LOG.
	 * @return bool|null Null when the value could not be read.
	 */
	private function value_of( Config $config, string $name ): ?bool {
		if ( $config->has( $name ) ) {
			return (bool) $config->value( $name );
		}
		if ( array_key_exists( $name, $config->unresolved() ) || $config->unread_code() > 0 ) {
			return null;
		}

		return 'WP_DEBUG_DISPLAY' === $name;
	}
}
