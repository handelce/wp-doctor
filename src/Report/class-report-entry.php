<?php
/**
 * One check's outcome within a report.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report;

/**
 * Immutable pairing of a check with its result.
 *
 * @since 0.1.0
 */
final class Report_Entry {

	/**
	 * Id of the check that produced the result.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $check_id;

	/**
	 * Category the check is filed under.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $category;

	/**
	 * Result of running the check.
	 *
	 * @since 0.1.0
	 *
	 * @var Result
	 */
	private $result;

	/**
	 * Pairs a check's id and category with its result.
	 *
	 * @since 0.1.0
	 *
	 * @param string $check_id Id of the check. Must not be blank.
	 * @param string $category Category of the check. Must not be blank.
	 * @param Result $result   Outcome of running the check.
	 * @throws \InvalidArgumentException When the id or category is blank.
	 */
	public function __construct( string $check_id, string $category, Result $result ) {
		if ( '' === trim( $check_id ) || '' === trim( $category ) ) {
			throw new \InvalidArgumentException( 'A report entry needs a check id and a category' );
		}

		$this->check_id = $check_id;
		$this->category = $category;
		$this->result   = $result;
	}

	/**
	 * Id of the check that produced this entry.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function check_id(): string {
		return $this->check_id;
	}

	/**
	 * Category the check is filed under.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Result of running the check.
	 *
	 * @since 0.1.0
	 *
	 * @return Result
	 */
	public function result(): Result {
		return $this->result;
	}
}
