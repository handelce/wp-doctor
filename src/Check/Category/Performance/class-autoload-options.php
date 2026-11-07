<?php
/**
 * Which wp_options rows WordPress loads on every request.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

/**
 * WordPress before 6.6 stores yes or no. Later versions also store on, off,
 * auto, auto-on and auto-off. Core loads the yes, on, auto and auto-on rows.
 *
 * @since 0.1.0
 */
final class Autoload_Options {

	public const CONDITION = "autoload IN ('yes', 'on', 'auto', 'auto-on')";
}
