<?php
/**
 * A wp-doctor.json file is missing or invalid.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Cli;

/**
 * The message says which file and what was wrong. Application prints it and exits with code 3.
 *
 * @since 0.1.0
 */
final class Config_File_Exception extends \RuntimeException {
}
