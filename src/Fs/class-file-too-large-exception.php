<?php
/**
 * A file is larger than the scanner will hash.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Fs;

/**
 * Raised by File_Scanner::hash() above File_Scanner::MAX_HASH_BYTES.
 *
 * @since 0.1.0
 */
final class File_Too_Large_Exception extends \RuntimeException {
}
