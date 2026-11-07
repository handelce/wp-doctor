<?php
/**
 * Class that records when PHP wakes an instance, to show a reader never builds one.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

final class Wakeup_Probe {

	/**
	 * Whether an instance has been woken since the last reset.
	 *
	 * @var bool
	 */
	public static $woken = false;

	/**
	 * Property that the serialized test data sets.
	 *
	 * @var int|null
	 */
	public $a;

	/**
	 * Records the wake-up.
	 */
	public function __wakeup() {
		self::$woken = true;
	}
}
