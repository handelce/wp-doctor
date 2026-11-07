<?php
/**
 * Tests for Json_Formatter.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Report\Formatter;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Formatter\Json_Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Tests\Support\Sample_Report;

final class Json_Formatter_Test extends TestCase {

	/**
	 * Formats and decodes a report.
	 *
	 * @param Report $report Report to format, redacted first.
	 * @return array<string, mixed>
	 */
	private function decode( Report $report ): array {
		$json    = ( new Json_Formatter() )->format( new Redacted_Report( $report, new Redactor() ) );
		$decoded = json_decode( $json, true );

		$this->assertIsArray( $decoded, 'The output is not valid JSON' );

		return $decoded;
	}

	public function test_the_document_has_the_documented_top_level_keys(): void {
		$data = $this->decode( Sample_Report::mixed() );

		$this->assertSame( array( 'schema_version', 'tool', 'site', 'summary', 'findings', 'checks', 'notices' ), array_keys( $data ) );
		$this->assertSame( 0, $data['schema_version'] );
		$this->assertSame(
			array(
				'name'    => 'wp-doctor',
				'version' => '0.1.0-test',
			),
			$data['tool']
		);
		$this->assertSame( array( 'wp_version' => '6.5.2' ), $data['site'] );
	}

	public function test_findings_carry_id_severity_title_evidence_and_fix_in_severity_order(): void {
		$findings = $this->decode( Sample_Report::mixed() )['findings'];

		$this->assertCount( 3, $findings );
		$this->assertSame( array( 'id', 'severity', 'title', 'evidence', 'fix' ), array_keys( $findings[0] ) );
		$this->assertSame( 'critical', $findings[0]['severity'] );
		$this->assertSame( 'wp-content/uploads/2026/shell.php', $findings[0]['evidence']['file'] );
		$this->assertSame( 120, $findings[0]['evidence']['size'] );
		$this->assertSame( false, $findings[1]['evidence']['object_cache'] );
		$this->assertStringContainsString( "\n", $findings[0]['fix'] );
	}

	public function test_evidence_keeps_its_order(): void {
		$findings = $this->decode( Sample_Report::mixed() )['findings'];

		$this->assertSame( array( 'autoload_bytes', 'rows', 'object_cache' ), array_keys( $findings[1]['evidence'] ) );
	}

	public function test_every_check_is_listed_with_its_status_and_reason(): void {
		$checks = $this->decode( Sample_Report::mixed() )['checks'];
		$by_id  = array();
		foreach ( $checks as $check ) {
			$by_id[ $check['id'] ] = $check;
		}

		$this->assertCount( 5, $checks );
		$this->assertSame( 'pass', $by_id['disk.debuglog.size']['status'] );
		$this->assertSame( 'findings', $by_id['sec.uploads.php']['status'] );
		$this->assertSame( 'skipped', $by_id['sec.core.checksums']['status'] );
		$this->assertSame( 'offline mode', $by_id['sec.core.checksums']['reason'] );
		$this->assertSame( 'error', $by_id['perf.cron.duplicates']['status'] );
		$this->assertSame( 'Database_Exception: query failed', $by_id['perf.cron.duplicates']['message'] );
		$this->assertArrayNotHasKey( 'reason', $by_id['disk.debuglog.size'] );
		$this->assertSame( 'security', $by_id['sec.uploads.php']['category'] );
	}

	public function test_the_summary_counts_match_the_report(): void {
		$summary = $this->decode( Sample_Report::mixed() )['summary'];

		$this->assertSame(
			array(
				'critical' => 1,
				'warning'  => 1,
				'info'     => 1,
			),
			$summary['findings']
		);
		$this->assertSame(
			array(
				'pass'     => 1,
				'findings' => 2,
				'skipped'  => 1,
				'error'    => 1,
				'ignored'  => 0,
			),
			$summary['checks']
		);
		$this->assertSame( 3, $summary['exit_code'] );
	}

	public function test_an_empty_report_still_has_arrays_not_null(): void {
		$data = $this->decode( Sample_Report::clean() );

		$this->assertSame( array(), $data['findings'] );
		$this->assertSame( array(), $data['notices'] );
		$this->assertSame( 0, $data['summary']['exit_code'] );
	}

	public function test_a_missing_wordpress_version_is_null(): void {
		$this->assertNull( $this->decode( Sample_Report::warning_only() )['site']['wp_version'] );
	}

	public function test_invalid_utf8_from_the_site_does_not_break_the_document(): void {
		$report = new Report(
			'0.1.0-test',
			null,
			array(
				new Report_Entry( 'a.b', 'disk', Result::findings( new Finding( 'a.b', Severity::info(), 'T', array( 'file' => "caf\xe9.txt" ), 'F' ) ) ),
			),
			array()
		);

		$data = $this->decode( $report );

		$this->assertStringStartsWith( 'caf', $data['findings'][0]['evidence']['file'] );
		$this->assertStringEndsWith( '.txt', $data['findings'][0]['evidence']['file'] );
	}

	public function test_output_ends_with_a_newline(): void {
		$json = ( new Json_Formatter() )->format( new Redacted_Report( Sample_Report::clean(), new Redactor() ) );

		$this->assertSame( "\n", substr( $json, -1 ) );
	}

	public function test_invisible_and_reordering_characters_are_written_as_escapes_but_decode_unchanged(): void {
		$hidden   = array(
			'right-to-left override' => "\u{202E}",
			'left-to-right isolate'  => "\u{2066}",
			'arabic letter mark'     => "\u{061C}",
			'zero width space'       => "\u{200B}",
			'word joiner'            => "\u{2060}",
			'byte order mark'        => "\u{FEFF}",
			'next line, C1 control'  => "\u{0085}",
		);
		$evidence = array();
		foreach ( $hidden as $name => $character ) {
			$evidence[ $name ] = 'evil' . $character . 'gnp.php';
		}
		$evidence['ordinary'] = 'café 文件.php';
		$report               = new Report(
			'0.1.0-test',
			null,
			array( new Report_Entry( 'a.b', 'security', Result::findings( new Finding( 'a.b', Severity::critical(), 'T', $evidence, 'F' ) ) ) ),
			array()
		);

		$json = ( new Json_Formatter() )->format( new Redacted_Report( $report, new Redactor() ) );

		$this->assertCount( 7, $hidden );
		foreach ( $hidden as $name => $character ) {
			$this->assertStringNotContainsString( $character, $json, "$name is written raw" );
			$this->assertStringContainsString( trim( (string) json_encode( $character ), '"' ), $json, "$name is not escaped" );
		}
		$this->assertStringContainsString( 'café 文件.php', $json );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( $evidence, $decoded['findings'][0]['evidence'] );
	}
}
