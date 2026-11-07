<?php
/**
 * Console backed by the process's standard streams.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Cli;

/**
 * Writes to STDOUT and STDERR. A closed pipe, as in `wp-doctor scan . | head`,
 * is not an error.
 *
 * @since 0.1.0
 */
final class Stream_Console implements Console {

	/**
	 * Writes to stdout.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Output, written as given with no newline added.
	 */
	public function out( string $text ): void {
		@fwrite( STDOUT, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Standard output, and a closed pipe is expected.
	}

	/**
	 * Writes to stderr.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Message, written as given with no newline added.
	 */
	public function err( string $text ): void {
		@fwrite( STDERR, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Standard error, and a closed pipe is expected.
	}

	/**
	 * Whether stdout is a terminal.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_tty(): bool {
		return function_exists( 'stream_isatty' ) && stream_isatty( STDOUT );
	}
}
