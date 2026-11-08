<?php
/**
 * Tests for Text_Formatter.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Report\Formatter;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Formatter\Text_Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Tests\Support\Sample_Report;

final class Text_Formatter_Test extends TestCase {

	private function format( Report $report, bool $colour = false ): string {
		return ( new Text_Formatter( $colour ) )->format( new Redacted_Report( $report, new Redactor() ) );
	}

	public function test_findings_are_grouped_by_severity_most_severe_first(): void {
		$text = $this->format( Sample_Report::mixed() );

		$critical = strpos( $text, 'CRITICAL (1)' );
		$warning  = strpos( $text, 'WARNING (1)' );
		$info     = strpos( $text, 'INFO (1)' );

		$this->assertNotFalse( $critical );
		$this->assertNotFalse( $warning );
		$this->assertNotFalse( $info );
		$this->assertLessThan( $warning, $critical );
		$this->assertLessThan( $info, $warning );
	}

	public function test_a_finding_shows_its_id_title_evidence_and_fix(): void {
		$text = $this->format( Sample_Report::mixed() );

		$this->assertStringContainsString( 'sec.uploads.php', $text );
		$this->assertStringContainsString( 'PHP files in uploads', $text );
		$this->assertStringContainsString( 'file: wp-content/uploads/2026/shell.php', $text );
		$this->assertStringContainsString( 'size: 120', $text );
		$this->assertStringContainsString( 'autoload_bytes: 2097152', $text );
		$this->assertStringContainsString( 'object_cache: false', $text );
		$this->assertStringContainsString( 'Fix: Remove the file:', $text );
		$this->assertStringContainsString( 'rm wp-content/uploads/2026/shell.php', $text );
	}

	public function test_skipped_and_failed_checks_are_listed_with_their_reason(): void {
		$text = $this->format( Sample_Report::mixed() );

		$this->assertStringContainsString( "  offline mode\n    sec.core.checksums\n", $text );
		$this->assertStringContainsString( 'perf.cron.duplicates: Database_Exception: query failed', $text );
	}

	public function test_notices_are_shown(): void {
		$this->assertStringContainsString( 'stopped after 4 entries', $this->format( Sample_Report::mixed() ) );
	}

	public function test_the_summary_counts_findings_and_checks(): void {
		$text = $this->format( Sample_Report::mixed() );

		$this->assertStringContainsString( '3 findings (1 critical, 1 warning, 1 info)', $text );
		$this->assertStringContainsString( '1 passed, 2 with findings, 1 skipped, 1 failed to run', $text );
	}

	public function test_a_clean_report_says_so(): void {
		$text = $this->format( Sample_Report::clean() );

		$this->assertStringContainsString( 'No findings.', $text );
		$this->assertStringNotContainsString( 'CRITICAL', $text );
		$this->assertStringContainsString( '2 passed', $text );
	}

	public function test_a_missing_wordpress_version_is_not_invented(): void {
		$this->assertStringNotContainsString( 'WordPress', $this->format( Sample_Report::warning_only() ) );
		$this->assertStringContainsString( 'WordPress 6.5.2', $this->format( Sample_Report::clean() ) );
	}

	public function test_colour_is_off_by_default_and_on_when_asked(): void {
		$this->assertStringNotContainsString( "\033", $this->format( Sample_Report::mixed() ) );
		$this->assertStringContainsString( "\033[31m", $this->format( Sample_Report::mixed(), true ) );
		$this->assertStringContainsString( "\033[0m", $this->format( Sample_Report::mixed(), true ) );
	}

	public function test_control_characters_from_the_site_are_made_visible(): void {
		$hostile = new Report(
			'0.1.0-test',
			null,
			array(
				new Report_Entry(
					'a.b',
					'security',
					Result::findings(
						new Finding( 'a.b', Severity::critical(), "Title \033[2J", array( "k\033" => "name\033[31m\nFix: forged line" ), "Fix \033[2J\nsecond line" )
					)
				),
				new Report_Entry( 'a.c', 'security', Result::skipped( "reason \033[2J" ) ),
			),
			array( "notice \033[2J" )
		);

		$text = $this->format( $hostile );

		$this->assertStringNotContainsString( "\033", $text );
		$this->assertStringContainsString( '\x1B[2J', $text );
		$this->assertStringContainsString( 'name\x1B[31m\x0AFix: forged line', $text );
		$this->assertStringContainsString( 'second line', $text );
	}

	public function test_multi_line_fix_text_keeps_its_line_breaks_and_is_indented(): void {
		$text = $this->format( Sample_Report::mixed() );

		$this->assertSame( 1, preg_match( '/\n {4}Fix: Remove the file:\n {6}rm wp-content/', $text ), $text );
	}

	public function test_output_ends_with_one_newline(): void {
		$text = $this->format( Sample_Report::mixed() );

		$this->assertSame( "\n", substr( $text, -1 ) );
		$this->assertNotSame( "\n\n", substr( $text, -2 ) );
	}

	public function test_skipped_checks_sharing_a_reason_are_listed_under_it_once(): void {
		$entries = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$entries[] = new Report_Entry( sprintf( 'perf.example.check%02d', $i ), 'performance', Result::skipped( 'database connection failed: refused' ) );
		}
		$entries[] = new Report_Entry( 'sec.core.checksums', 'security', Result::skipped( 'offline' ) );
		$entries[] = new Report_Entry( 'sec.other.check', 'security', Result::skipped( 'database connection failed: refused' ) );

		$text = $this->format( new Report( '0.1.0', '6.5.2', $entries, array() ) );

		$this->assertStringContainsString( 'Skipped (14)', $text );
		$this->assertSame( 1, substr_count( $text, 'database connection failed: refused' ) );
		$this->assertSame( 1, substr_count( $text, 'offline' ) );
		$this->assertStringContainsString( 'perf.example.check01, perf.example.check02,', $text );
		$this->assertStringContainsString( 'sec.other.check', $text );
		$this->assertLessThan( strpos( $text, '  offline' ), strpos( $text, 'sec.other.check' ) );
		foreach ( explode( "\n", $text ) as $line ) {
			$this->assertLessThanOrEqual( 84, strlen( $line ), "Line too long: $line" );
		}
	}

	public function test_an_ignored_entry_names_its_highest_severity_whatever_the_finding_order(): void {
		$entry = new Report_Entry(
			'sec.c.mixed',
			'security',
			Result::findings(
				new Finding( 'sec.c.mixed', Severity::info(), 'Low', array( 'k' => 'v' ), 'Fix.' ),
				new Finding( 'sec.c.mixed', Severity::critical(), 'High', array( 'k' => 'v' ), 'Fix.' ),
				new Finding( 'sec.c.mixed', Severity::warning(), 'Mid', array( 'k' => 'v' ), 'Fix.' )
			)
		);
		$text  = $this->format( new Report( '0.1.0', null, array( $entry ), array(), array( 'sec.c.mixed' ) ) );

		$this->assertStringContainsString( '  sec.c.mixed: 3 findings, most severe critical (not counted)', $text );
	}

	public function test_evidence_lists_put_each_item_and_each_field_on_its_own_line(): void {
		$text = $this->format( Sample_Report::with_lists() );

		$this->assertStringContainsString( "    plugins:\n      - wp-zxq\n      - cache\n", $text );
		$this->assertStringContainsString( "    entries:\n      - path: wp-content/plugins/wp-zxq\n        php_files: 1\n      - path: wp-content/plugins/cache\n        php_files: 3\n", $text );
		$this->assertStringContainsString( "    none: none\n", $text );
	}
}
