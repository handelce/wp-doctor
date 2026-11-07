<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Exit_Code;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Formatter\Json_Formatter;
use WP_Doctor\Report\Formatter\Text_Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

final class Report_Ignored_Test extends TestCase {

	/**
	 * A report with one critical check, one passing check and one erroring check, with the given ids ignored.
	 *
	 * @param string[] $ignored Ignored check ids.
	 * @return Report
	 */
	private function report( array $ignored ): Report {
		return new Report(
			'0.1.0',
			'6.5.2',
			array(
				new Report_Entry( 'sec.uploads.php', 'security', Result::findings( new Finding( 'sec.uploads.php', Severity::critical(), 'Shell found', array( 'file' => 'x.php' ), 'Remove it.' ) ) ),
				new Report_Entry( 'perf.autoload.size', 'performance', Result::pass() ),
				new Report_Entry( 'perf.cron.duplicates', 'performance', Result::error( 'boom' ) ),
			),
			array(),
			$ignored
		);
	}

	public function test_ignored_findings_do_not_count_but_stay_listed(): void {
		$report = $this->report( array( 'sec.uploads.php' ) );

		$this->assertSame( array(), $report->findings() );
		$this->assertNull( $report->highest_severity() );
		$this->assertSame( 1, $report->status_counts()['pass'] );
		$this->assertSame( 0, $report->status_counts()['findings'] );
		$this->assertCount( 1, $report->ignored_entries() );
		$this->assertCount( 3, $report->entries() );
		$this->assertTrue( $report->is_ignored( 'sec.uploads.php' ) );
		$this->assertFalse( $report->is_ignored( 'perf.autoload.size' ) );
	}

	public function test_an_ignored_check_that_fails_to_run_still_counts_as_a_failure(): void {
		$report = $this->report( array( 'sec.uploads.php', 'perf.cron.duplicates' ) );

		$this->assertTrue( $report->has_errors() );
		$this->assertSame( Exit_Code::ERROR, Exit_Code::for_report( $report ) );
		$this->assertSame( 1, $report->status_counts()['error'] );
		$this->assertCount( 1, $report->ignored_entries() );
		$this->assertSame( 'sec.uploads.php', $report->ignored_entries()[0]->check_id() );
		$this->assertSame( array(), $report->findings() );
	}

	public function test_nothing_ignored_counts_everything(): void {
		$report = $this->report( array() );

		$this->assertCount( 1, $report->findings() );
		$this->assertTrue( $report->has_errors() );
		$this->assertSame( Exit_Code::ERROR, Exit_Code::for_report( $report ) );
		$this->assertSame( array(), $report->ignored_entries() );
	}

	public function test_the_text_report_lists_ignored_checks_separately_with_their_outcome(): void {
		$text = ( new Text_Formatter() )->format( new Redacted_Report( $this->report( array( 'sec.uploads.php', 'perf.cron.duplicates' ) ), new Redactor() ) );

		$this->assertStringContainsString( 'Ignored by configuration (1)', $text );
		$this->assertStringContainsString( 'sec.uploads.php: 1 finding, most severe critical (not counted)', $text );
		$this->assertStringNotContainsString( 'perf.cron.duplicates: error (not counted)', $text );
		$this->assertStringNotContainsString( 'CRITICAL (1)', $text );
		$this->assertStringContainsString( 'Failed to run', $text );
		$this->assertStringContainsString( 'perf.cron.duplicates', $text );
		$this->assertStringContainsString( '1 failed to run, 1 ignored by configuration.', $text );
	}

	public function test_the_json_report_marks_ignored_checks_and_keeps_their_findings_on_the_check(): void {
		$json = ( new Json_Formatter() )->format( new Redacted_Report( $this->report( array( 'sec.uploads.php', 'perf.cron.duplicates' ) ), new Redactor() ) );
		$data = json_decode( $json, true );

		$this->assertSame( array(), $data['findings'] );
		$this->assertSame( 1, $data['summary']['checks']['ignored'] );
		$this->assertSame( 1, $data['summary']['checks']['error'] );
		$this->assertTrue( $data['checks'][0]['ignored'] );
		$this->assertSame( 'Shell found', $data['checks'][0]['findings'][0]['title'] );
		$this->assertArrayNotHasKey( 'ignored', $data['checks'][1] );
		$this->assertSame( 'perf.cron.duplicates', $data['checks'][2]['id'] );
		$this->assertArrayNotHasKey( 'ignored', $data['checks'][2] );
	}
}
