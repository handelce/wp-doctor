<?php
/**
 * Builds reports for formatter and redaction tests.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

final class Sample_Report {

	/**
	 * A report with every status and severity.
	 *
	 * @return Report
	 */
	public static function mixed(): Report {
		return new Report(
			'0.1.0-test',
			'6.5.2',
			array(
				new Report_Entry(
					'perf.autoload.size',
					'performance',
					Result::findings(
						new Finding(
							'perf.autoload.size',
							Severity::warning(),
							'Autoloaded options are large',
							array(
								'autoload_bytes' => 2097152,
								'rows'           => 412,
								'object_cache'   => false,
							),
							'Delete unused options.'
						)
					)
				),
				new Report_Entry(
					'sec.uploads.php',
					'security',
					Result::findings(
						new Finding(
							'sec.uploads.php',
							Severity::critical(),
							'PHP files in uploads',
							array(
								'file' => 'wp-content/uploads/2026/shell.php',
								'size' => 120,
							),
							"Remove the file:\n  rm wp-content/uploads/2026/shell.php"
						),
						new Finding( 'sec.uploads.php', Severity::info(), 'Extra note', array( 'count' => 1 ), 'Nothing to do.' )
					)
				),
				new Report_Entry( 'disk.debuglog.size', 'disk', Result::pass() ),
				new Report_Entry( 'sec.core.checksums', 'security', Result::skipped( 'offline mode' ) ),
				new Report_Entry( 'perf.cron.duplicates', 'performance', Result::error( 'Database_Exception: query failed' ) ),
			),
			array( 'The walk of wp-content/uploads stopped after 4 entries (--max-files).' )
		);
	}

	/**
	 * A report with one warning and nothing else.
	 *
	 * @return Report
	 */
	public static function warning_only(): Report {
		return new Report(
			'0.1.0-test',
			null,
			array(
				new Report_Entry(
					'perf.autoload.size',
					'performance',
					Result::findings(
						new Finding( 'perf.autoload.size', Severity::warning(), 'Autoloaded options are large', array( 'autoload_bytes' => 2097152 ), 'Delete unused options.' )
					)
				),
			),
			array()
		);
	}

	/**
	 * A report where every check passed.
	 *
	 * @return Report
	 */
	public static function clean(): Report {
		return new Report(
			'0.1.0-test',
			'6.5.2',
			array(
				new Report_Entry( 'disk.debuglog.size', 'disk', Result::pass() ),
				new Report_Entry( 'sec.uploads.php', 'security', Result::pass() ),
			),
			array()
		);
	}
}
