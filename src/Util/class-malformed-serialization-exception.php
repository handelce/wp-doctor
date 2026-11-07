<?php
/**
 * Serialized data that is not plain arrays and scalars.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Util;

/**
 * The message never contains the input.
 *
 * @since 0.1.0
 */
final class Malformed_Serialization_Exception extends \UnexpectedValueException {

	/**
	 * Byte offset in the input where reading stopped, or -1 when unknown.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $position;

	/**
	 * Records where reading stopped alongside the message.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message  Why the input could not be read, without quoting it.
	 * @param int    $position Byte offset where reading stopped. -1 means unknown.
	 */
	public function __construct( string $message, int $position = -1 ) {
		parent::__construct( $message );
		$this->position = $position;
	}

	/**
	 * Where in the input reading stopped. Callers treat -1 as unknown.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function position(): int {
		return $this->position;
	}
}
