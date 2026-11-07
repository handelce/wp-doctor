<?php
/**
 * Renders a report.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report\Formatter;

use WP_Doctor\Report\Redacted_Report;

/**
 * A formatter can only be given a redacted report.
 *
 * @since 0.1.0
 */
interface Formatter {

	/**
	 * Renders the report.
	 *
	 * @since 0.1.0
	 *
	 * @param Redacted_Report $report Report whose text has already passed through the Redactor.
	 * @return string Ends with a newline.
	 */
	public function format( Redacted_Report $report ): string;
}
