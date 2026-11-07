<?php
/**
 * Check whose behaviour is supplied by a closure.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Result;

final class Stub_Check implements Check {

	/**
	 * Id the stub reports.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Body of run().
	 *
	 * @var callable
	 */
	private $body;

	/**
	 * Creates a check with the given id that runs the closure.
	 *
	 * @param string   $id   Check id.
	 * @param callable $body Receives the Context, returns a Result.
	 */
	public function __construct( string $id, callable $body ) {
		$this->id   = $id;
		$this->body = $body;
	}

	/**
	 * Returns the id given to the constructor.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Always the performance category.
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Runs the closure.
	 *
	 * @param Context $context Passed to the closure unchanged.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		return ( $this->body )( $context );
	}
}
