<?php
/**
 * Console that collects output in memory.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use WP_Doctor\Cli\Console;

final class Buffer_Console implements Console {

	/**
	 * Everything written to stdout.
	 *
	 * @var string
	 */
	public $out = '';

	/**
	 * Everything written to stderr.
	 *
	 * @var string
	 */
	public $err = '';

	/**
	 * Whether stdout pretends to be a terminal.
	 *
	 * @var bool
	 */
	public $tty = false;

	/**
	 * Appends to stdout.
	 *
	 * @param string $text Output the application wrote.
	 */
	public function out( string $text ): void {
		$this->out .= $text;
	}

	/**
	 * Appends to stderr.
	 *
	 * @param string $text Error output the application wrote.
	 */
	public function err( string $text ): void {
		$this->err .= $text;
	}

	/**
	 * Whether stdout is a terminal.
	 *
	 * @return bool
	 */
	public function is_tty(): bool {
		return $this->tty;
	}
}
