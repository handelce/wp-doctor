<?php
/**
 * The dashboard code editor is available.
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

/**
 * With the editor on, anyone who gets an administrator login can write PHP
 * from the dashboard. The constant might be set elsewhere than wp-config.php,
 * which is why the finding says "likely".
 *
 * @since 0.1.0
 */
final class File_Edit_Enabled_Check implements Check {

	/**
	 * Id that the `ignore` setting uses to silence the editor notice.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.fileedit.enabled';
	}

	/**
	 * Config, since the fix is a constant in wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Reads DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Source of DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS as parsed from wp-config.php, and the count of includes it could not follow.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$config     = $context->config();
		$includes   = $config->unread_code() > 0;
		$unresolved = array();
		foreach ( array( 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS' ) as $name ) {
			if ( $config->has( $name ) && (bool) $config->value( $name ) ) {
				return Result::pass();
			}
			if ( array_key_exists( $name, $config->unresolved() ) || ( $includes && ! $config->has( $name ) ) ) {
				$unresolved[] = $name;
			}
		}
		if ( array() !== $unresolved ) {
			return Result::skipped( 'wp-config.php values unavailable: ' . implode( ', ', $unresolved ) );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'The theme and plugin code editor is likely enabled',
				array(
					'DISALLOW_FILE_EDIT' => 'not set to true in wp-config.php',
				),
				"Add this line to wp-config.php above the line that loads wp-settings.php:\n"
				. "define( 'DISALLOW_FILE_EDIT', true );"
			)
		);
	}
}
