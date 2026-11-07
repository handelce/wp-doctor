<?php
/**
 * Terminal output.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Cli;

/**
 * The only place the tool writes anything: to stdout and stderr.
 *
 * @since 0.1.0
 */
interface Console {

	/**
	 * Writes to stdout.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Output, written as given with no newline added.
	 */
	public function out( string $text ): void;

	/**
	 * Writes to stderr.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Message, written as given with no newline added.
	 */
	public function err( string $text ): void;

	/**
	 * Whether stdout is a terminal.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_tty(): bool;
}
