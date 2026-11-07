<?php
/**
 * A single health or compromise check.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

use WP_Doctor\Report\Result;

/**
 * Implemented once per row of docs/CHECKS.md.
 *
 * @since 0.1.0
 */
interface Check {

	/**
	 * Dotted id such as perf.autoload.size, used in reports and in wp-doctor.json.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * One of: performance, disk, security, config.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string;

	/**
	 * Examines the site. A check that cannot examine it returns Result::skipped(), never a pass.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Everything the check may read: site, files, database and limits.
	 * @return Result
	 */
	public function run( Context $context ): Result;
}
