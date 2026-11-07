<?php
/**
 * Bad command-line arguments.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Cli;

/**
 * Application prints the message with a pointer to --help and exits with code 3.
 *
 * @since 0.1.0
 */
final class Usage_Exception extends \RuntimeException {
}
