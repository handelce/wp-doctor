<?php
/**
 * Tests for Exit_Code.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Exit_Code;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Tests\Support\Sample_Report;

final class Exit_Code_Test extends TestCase {

	public function test_the_codes_are_the_documented_values(): void {
		$this->assertSame( 0, Exit_Code::OK );
		$this->assertSame( 1, Exit_Code::WARNINGS );
		$this->assertSame( 2, Exit_Code::CRITICAL );
		$this->assertSame( 3, Exit_Code::ERROR );
	}

	public function test_a_clean_report_exits_zero(): void {
		$this->assertSame( 0, Exit_Code::for_report( Sample_Report::clean() ) );
	}

	public function test_info_findings_alone_exit_zero(): void {
		$report = new Report(
			'0.1.0-test',
			null,
			array(
				new Report_Entry( 'a.b', 'disk', Result::findings( new Finding( 'a.b', Severity::info(), 'T', array( 'k' => 'v' ), 'F' ) ) ),
			),
			array()
		);

		$this->assertSame( 0, Exit_Code::for_report( $report ) );
	}

	public function test_skipped_checks_do_not_change_the_code(): void {
		$report = new Report( '0.1.0-test', null, array( new Report_Entry( 'a.b', 'disk', Result::skipped( 'offline' ) ) ), array() );

		$this->assertSame( 0, Exit_Code::for_report( $report ) );
	}

	public function test_a_warning_exits_one(): void {
		$this->assertSame( 1, Exit_Code::for_report( Sample_Report::warning_only() ) );
	}

	public function test_a_critical_finding_exits_two(): void {
		$report = new Report(
			'0.1.0-test',
			null,
			array(
				new Report_Entry( 'a.b', 'disk', Result::findings( new Finding( 'a.b', Severity::critical(), 'T', array( 'k' => 'v' ), 'F' ) ) ),
			),
			array()
		);

		$this->assertSame( 2, Exit_Code::for_report( $report ) );
	}

	public function test_a_check_error_exits_three_even_with_critical_findings(): void {
		$this->assertTrue( Sample_Report::mixed()->highest_severity()->equals( Severity::critical() ) );
		$this->assertTrue( Sample_Report::mixed()->has_errors() );

		$this->assertSame( 3, Exit_Code::for_report( Sample_Report::mixed() ) );
	}

	public function test_a_check_error_alone_exits_three(): void {
		$report = new Report( '0.1.0-test', null, array( new Report_Entry( 'a.b', 'disk', Result::error( 'boom' ) ) ), array() );

		$this->assertSame( 3, Exit_Code::for_report( $report ) );
	}
}
