<?php
/**
 * JSON report.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report\Formatter;

use WP_Doctor\Report\Exit_Code;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Result;
use WP_Doctor\Util\Hidden_Characters;

use function WP_Doctor\esc_cli;

/**
 * Stable document for machines. schema_version is 0 until the schema is frozen.
 * Bytes that are not valid UTF-8 are replaced, not dropped, so the document
 * always parses. Non-ASCII text stays readable, except the characters in
 * Hidden_Characters, which are written as \u escapes: a
 * program decoding the document gets the same string, and a person reading it
 * raw cannot be shown `evilphp.png` for `evil\u202egnp.php`.
 *
 * @since 0.1.0
 */
final class Json_Formatter implements Formatter {

	public const SCHEMA_VERSION = 0;

	/**
	 * Encodes the report as one pretty-printed JSON document.
	 *
	 * @since 0.1.0
	 *
	 * @param Redacted_Report $redacted Report already passed through the Redactor. Its strings go into the document with only JSON escaping.
	 * @return string
	 * @throws \RuntimeException When the document cannot be encoded.
	 */
	public function format( Redacted_Report $redacted ): string {
		$report = $redacted->report();

		$checks = array();
		foreach ( $report->entries() as $entry ) {
			$row = array(
				'id'       => $entry->check_id(),
				'category' => $entry->category(),
				'status'   => $entry->result()->status(),
			);
			if ( Result::SKIPPED === $entry->result()->status() ) {
				$row['reason'] = $entry->result()->message();
			}
			if ( Result::ERROR === $entry->result()->status() ) {
				$row['message'] = $entry->result()->message();
			}
			if ( $report->is_silenced( $entry ) ) {
				$row['ignored']  = true;
				$row['findings'] = array_map( array( $this, 'finding_row' ), $entry->result()->finding_list() );
			}
			$checks[] = $row;
		}

		$document = array(
			'schema_version' => self::SCHEMA_VERSION,
			'tool'           => array(
				'name'    => 'wp-doctor',
				'version' => $report->tool_version(),
			),
			'site'           => array( 'wp_version' => $report->wp_version() ),
			'summary'        => array(
				'findings'  => $report->severity_counts(),
				'checks'    => array_merge( $report->status_counts(), array( 'ignored' => count( $report->ignored_entries() ) ) ),
				'exit_code' => Exit_Code::for_report( $report ),
			),
			'findings'       => array_map( array( $this, 'finding_row' ), $report->findings() ),
			'checks'         => $checks,
			'notices'        => $report->notices(),
		);

		$json = json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			throw new \RuntimeException( esc_cli( 'The report could not be encoded as JSON: ' . json_last_error_msg() ) );
		}

		return $this->escape_hidden_characters( $json ) . "\n";
	}

	/**
	 * Rewrites hidden characters as \u escapes. They can only occur inside
	 * strings, since the document's own syntax is ASCII.
	 *
	 * @since 0.1.0
	 *
	 * @param string $json Encoded document.
	 * @return string
	 * @throws \RuntimeException When the pattern cannot be applied.
	 */
	private function escape_hidden_characters( string $json ): string {
		$escaped = preg_replace_callback(
			'/' . Hidden_Characters::UTF8_PATTERN . '/',
			static function ( array $found ): string {
				$bytes = array_values( (array) unpack( 'C*', $found[0] ) );
				$code  = 2 === count( $bytes )
					? ( ( $bytes[0] & 0x1f ) << 6 ) | ( $bytes[1] & 0x3f )
					: ( ( $bytes[0] & 0x0f ) << 12 ) | ( ( $bytes[1] & 0x3f ) << 6 ) | ( $bytes[2] & 0x3f );

				return sprintf( '\\u%04x', $code );
			},
			$json
		);
		if ( null === $escaped ) {
			throw new \RuntimeException( 'Hidden characters in the report could not be escaped' );
		}

		return $escaped;
	}

	/**
	 * One finding as a JSON object.
	 *
	 * @since 0.1.0
	 *
	 * @param Finding $finding Finding from the redacted report.
	 * @return array<string, mixed>
	 */
	private function finding_row( Finding $finding ): array {
		return array(
			'id'       => $finding->id(),
			'severity' => $finding->severity()->name(),
			'title'    => $finding->title(),
			'evidence' => $finding->evidence(),
			'fix'      => $finding->fix(),
		);
	}
}
