<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Report\Formatter;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Formatter\Markdown_Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Tests\Support\Sample_Report;

final class Markdown_Formatter_Test extends TestCase {

	private function format( Report $report ): string {
		return ( new Markdown_Formatter() )->format( new Redacted_Report( $report, new Redactor() ) );
	}

	public function test_sections_follow_the_text_report_in_severity_order(): void {
		$md = $this->format( Sample_Report::mixed() );

		$this->assertStringStartsWith( "# wp-doctor report\n", $md );
		$critical = strpos( $md, '## Critical (1)' );
		$warning  = strpos( $md, '## Warning (1)' );
		$info     = strpos( $md, '## Info (1)' );
		$this->assertNotFalse( $critical );
		$this->assertNotFalse( $warning );
		$this->assertNotFalse( $info );
		$this->assertLessThan( $warning, $critical );
		$this->assertLessThan( $info, $warning );
		$this->assertStringContainsString( '## Skipped (1)', $md );
		$this->assertStringContainsString( '## Failed to run (1)', $md );
		$this->assertStringEndsWith( "\n", $md );
	}

	public function test_a_finding_shows_its_id_title_evidence_and_fix_block(): void {
		$md = $this->format( Sample_Report::mixed() );

		$this->assertStringContainsString( '### `sec.uploads.php` PHP files in uploads', $md );
		$this->assertStringContainsString( '- file: `wp-content/uploads/2026/shell.php`', $md );
		$this->assertStringContainsString( '- object\\_cache: `false`', $md );
		$this->assertStringContainsString( "**Fix:**\n\n```\nRemove the file:", $md );
	}

	public function test_a_planted_file_name_cannot_inject_markdown_or_html(): void {
		$name    = 'x](https://evil.example.test)<script>`` |a|';
		$finding = new Finding( 'sec.uploads.php', Severity::critical(), 'PHP file ' . $name, array( 'file_1' => $name ), "rm '$name'\n```\n# injected" );
		$md      = $this->format( new Report( '0.1.0', '6.5.2', array( new Report_Entry( 'sec.uploads.php', 'security', Result::findings( $finding ) ) ), array() ) );

		$outside_code = $this->code_free( $md );
		$this->assertStringContainsString( 'PHP file x\\]()\\<script\\>', $outside_code );
		$this->assertStringContainsString( 'PHP file x\\](`https://evil.example.test`)\\<script\\>', $md );
		$this->assertStringNotContainsString( '<script>', $outside_code );
		$this->assertSame( 0, preg_match( '/(?<!\\\\)\\]\\(/', $outside_code ), 'An unescaped link target survives outside code' );
		$this->assertStringContainsString( '- file\\_1: ```x](https://evil.example.test)<script>`` |a|```', $md );
		$this->assertStringContainsString( "````\nrm 'x](https://evil.example.test)<script>`` |a|'\n```\n# injected\n````", $md );
	}

	public function test_skipped_checks_are_grouped_by_reason_and_ignored_ones_listed_separately(): void {
		$entries = array(
			new Report_Entry( 'perf.a.one', 'performance', Result::skipped( 'database connection failed' ) ),
			new Report_Entry( 'perf.a.two', 'performance', Result::skipped( 'database connection failed' ) ),
			new Report_Entry( 'sec.b.three', 'security', Result::findings( new Finding( 'sec.b.three', Severity::critical(), 'Silenced', array( 'k' => 'v' ), 'Fix.' ) ) ),
		);
		$md      = $this->format( new Report( '0.1.0', null, $entries, array(), array( 'sec.b.three' ) ) );

		$this->assertSame( 1, substr_count( $md, 'database connection failed' ) );
		$this->assertStringContainsString( '  - `perf.a.one`, `perf.a.two`', $md );
		$this->assertStringContainsString( '## Ignored by configuration (1)', $md );
		$this->assertStringContainsString( '- `sec.b.three`: 1 finding, most severe critical (not counted)', $md );
		$this->assertStringNotContainsString( '## Critical', $md );
		$this->assertStringNotContainsString( 'WordPress', $md );
	}

	/**
	 * The document with every code span and code block removed.
	 *
	 * @param string $md Formatter output.
	 * @return string
	 */
	private function code_free( string $md ): string {
		$without_blocks = (string) preg_replace( '/^(`{3,})\n.*?\n\1$/ms', '', $md );

		return (string) preg_replace( '/(`+)(?:(?!\1).)*?\1/s', '', $without_blocks );
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
		$md    = $this->format( new Report( '0.1.0', null, array( $entry ), array(), array( 'sec.c.mixed' ) ) );

		$this->assertStringContainsString( '- `sec.c.mixed`: 3 findings, most severe critical (not counted)', $md );
	}

	public function test_evidence_lists_become_nested_bullets_with_one_line_per_item(): void {
		$md = $this->format( Sample_Report::with_lists() );

		$this->assertStringContainsString( "- plugins:\n  - `wp-zxq`\n  - `cache`\n", $md );
		$this->assertStringContainsString( "- entries:\n  - path: `wp-content/plugins/wp-zxq`, php\\_files: `1`\n  - path: `wp-content/plugins/cache`, php\\_files: `3`\n", $md );
		$this->assertStringContainsString( "- none: none\n", $md );
	}
}
