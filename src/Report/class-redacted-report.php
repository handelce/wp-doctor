<?php
/**
 * A report that has passed through the Redactor.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report;

/**
 * The only report type formatters accept. The constructor redacts, so a
 * Redacted_Report cannot hold unredacted text.
 *
 * @since 0.1.0
 */
final class Redacted_Report {

	/**
	 * The redacted copy.
	 *
	 * @since 0.1.0
	 *
	 * @var Report
	 */
	private $report;

	/**
	 * Redacts the report.
	 *
	 * @since 0.1.0
	 *
	 * @param Report   $report   Report as produced by the scan.
	 * @param Redactor $redactor Redactor holding the site's secrets.
	 */
	public function __construct( Report $report, Redactor $redactor ) {
		$this->report = $redactor->redact_report( $report );
	}

	/**
	 * The redacted report.
	 *
	 * @since 0.1.0
	 *
	 * @return Report
	 */
	public function report(): Report {
		return $this->report;
	}
}
