<?php
/**
 * Tests for Report and Report_Entry.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Sample_Report;

final class Report_Test extends TestCase {

	public function test_findings_are_ordered_by_severity_then_id(): void {
		$order = array();
		foreach ( Sample_Report::mixed()->findings() as $finding ) {
			$order[] = $finding->severity()->name() . ' ' . $finding->id();
		}

		$this->assertSame( array( 'critical sec.uploads.php', 'warning perf.autoload.size', 'info sec.uploads.php' ), $order );
	}

	public function test_findings_of_equal_rank_and_id_keep_their_given_order(): void {
		$report = Sample_Report::mixed();
		$titles = array();
		foreach ( $report->findings() as $finding ) {
			$titles[] = $finding->title();
		}

		$this->assertSame( array( 'PHP files in uploads', 'Autoloaded options are large', 'Extra note' ), $titles );
	}

	public function test_check_statuses_are_counted(): void {
		$this->assertSame(
			array(
				'pass'     => 1,
				'findings' => 2,
				'skipped'  => 1,
				'error'    => 1,
			),
			Sample_Report::mixed()->status_counts()
		);
	}

	public function test_findings_are_counted_by_severity(): void {
		$this->assertSame(
			array(
				'critical' => 1,
				'warning'  => 1,
				'info'     => 1,
			),
			Sample_Report::mixed()->severity_counts()
		);
	}

	public function test_errors_are_detected(): void {
		$this->assertTrue( Sample_Report::mixed()->has_errors() );
		$this->assertFalse( Sample_Report::clean()->has_errors() );
	}

	public function test_the_highest_severity_is_null_without_findings(): void {
		$this->assertSame( 'critical', Sample_Report::mixed()->highest_severity()->name() );
		$this->assertNull( Sample_Report::clean()->highest_severity() );
	}

	public function test_accessors_return_what_was_given(): void {
		$report = Sample_Report::mixed();

		$this->assertSame( '0.1.0-test', $report->tool_version() );
		$this->assertSame( '6.5.2', $report->wp_version() );
		$this->assertCount( 5, $report->entries() );
		$this->assertCount( 1, $report->notices() );
	}

	public function test_an_entry_needs_a_check_id_and_category(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Report_Entry( ' ', 'security', Result::pass() );
	}
}
