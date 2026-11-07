<?php
/**
 * Marks a check that does not depend on wp-config.php.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

/**
 * A check that still runs when wp-config.php could not be read. Such a check
 * must not read constants from the config, the database, or any directory that
 * wp-config.php can move (content, uploads, plugins, the debug log). Every
 * other check is skipped in that case.
 *
 * @since 0.1.0
 */
interface Runs_Without_Config extends Check {
}
