<?php
/**
 * Paste-ready Markdown report.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report\Formatter;

use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Result;

/**
 * The text report's structure in Markdown, for tickets and issues. Every
 * site-derived string passes through Markdown_Escape.
 *
 * @since 0.1.0
 */
final class Markdown_Formatter implements Formatter {

	/**
	 * Renders the report as a Markdown document.
	 *
	 * @since 0.1.0
	 *
	 * @param Redacted_Report $redacted Report already passed through the Redactor. Its text is still escaped for Markdown here.
	 * @return string
	 */
	public function format( Redacted_Report $redacted ): string {
		$report = $redacted->report();
		$lines  = array( '# wp-doctor report', '', $this->header( $report ), '' );

		$findings = $report->findings();
		foreach ( array( 'critical', 'warning', 'info' ) as $severity ) {
			$group = array_values(
				array_filter(
					$findings,
					static function ( Finding $finding ) use ( $severity ): bool {
						return $finding->severity()->name() === $severity;
					}
				)
			);
			if ( array() === $group ) {
				continue;
			}
			$lines[] = '## ' . ucfirst( $severity ) . ' (' . count( $group ) . ')';
			$lines[] = '';
			foreach ( $group as $finding ) {
				$lines = array_merge( $lines, $this->finding_lines( $finding ) );
			}
		}

		$lines = array_merge( $lines, $this->skipped_section( $report ), $this->failed_section( $report ), $this->ignored_section( $report ) );

		if ( array() !== $report->notices() ) {
			$lines[] = '## Notes';
			$lines[] = '';
			foreach ( $report->notices() as $notice ) {
				$lines[] = '- ' . Markdown_Escape::line_start( $notice );
			}
			$lines[] = '';
		}

		$lines[] = '---';
		$lines[] = '';
		$lines[] = $this->summary( $report );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Line naming the tool and WordPress versions.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Redacted report to read the versions from.
	 * @return string
	 */
	private function header( Report $report ): string {
		$line = 'wp-doctor ' . Markdown_Escape::text( $report->tool_version() );
		if ( null !== $report->wp_version() ) {
			$line .= ', WordPress ' . Markdown_Escape::text( $report->wp_version() );
		}

		return $line;
	}

	/**
	 * Heading, evidence list and fenced fix for one finding.
	 *
	 * @since 0.1.0
	 *
	 * @param Finding $finding Finding from the redacted report.
	 * @return string[]
	 */
	private function finding_lines( Finding $finding ): array {
		$lines = array( '### ' . Markdown_Escape::code( $finding->id() ) . ' ' . Markdown_Escape::text( $finding->title() ), '' );
		foreach ( $finding->evidence() as $key => $value ) {
			$label = '- ' . Markdown_Escape::line_start( (string) $key ) . ':';
			if ( ! is_array( $value ) ) {
				$lines[] = $label . ' ' . Markdown_Escape::code( self::shown( $value ) );
				continue;
			}
			$lines[] = array() === $value ? $label . ' none' : $label;
			foreach ( $value as $item ) {
				if ( ! is_array( $item ) ) {
					$lines[] = '  - ' . Markdown_Escape::code( self::shown( $item ) );
					continue;
				}
				$fields = array();
				foreach ( $item as $field => $field_value ) {
					$fields[] = Markdown_Escape::text( (string) $field ) . ': ' . Markdown_Escape::code( self::shown( $field_value ) );
				}
				$lines[] = '  - ' . implode( ', ', $fields );
			}
		}
		$lines[] = '';
		$lines[] = '**Fix:**';
		$lines[] = '';

		return array_merge( $lines, Markdown_Escape::block( $finding->fix() ), array( '' ) );
	}

	/**
	 * A scalar as text, with booleans spelled out.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int|float|bool $value Evidence scalar.
	 * @return string
	 */
	private static function shown( $value ): string {
		return is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
	}

	/**
	 * Skipped checks, one entry per distinct reason.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Redacted report whose skipped checks are grouped.
	 * @return string[]
	 */
	private function skipped_section( Report $report ): array {
		$by_reason = array();
		$count     = 0;
		foreach ( $report->counted_entries() as $entry ) {
			if ( Result::SKIPPED === $entry->result()->status() ) {
				$by_reason[ (string) $entry->result()->message() ][] = Markdown_Escape::code( $entry->check_id() );
				++$count;
			}
		}
		if ( 0 === $count ) {
			return array();
		}

		$lines = array( '## Skipped (' . $count . ')', '' );
		foreach ( $by_reason as $reason => $ids ) {
			$lines[] = '- ' . Markdown_Escape::line_start( (string) $reason );
			$lines[] = '  - ' . implode( ', ', $ids );
		}
		$lines[] = '';

		return $lines;
	}

	/**
	 * Counted checks that ended in an error, with their messages.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Redacted report whose counted entries are scanned for errors.
	 * @return string[]
	 */
	private function failed_section( Report $report ): array {
		$rows = array();
		foreach ( $report->counted_entries() as $entry ) {
			if ( Result::ERROR === $entry->result()->status() ) {
				$rows[] = '- ' . Markdown_Escape::code( $entry->check_id() ) . ': ' . Markdown_Escape::text( (string) $entry->result()->message() );
			}
		}

		return array() === $rows ? array() : array_merge( array( '## Failed to run (' . count( $rows ) . ')', '' ), $rows, array( '' ) );
	}

	/**
	 * Checks the config file silences, with what each reported.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Redacted report whose ignored entries are listed.
	 * @return string[]
	 */
	private function ignored_section( Report $report ): array {
		$rows = array();
		foreach ( $report->ignored_entries() as $entry ) {
			$result   = $entry->result();
			$findings = $result->finding_list();
			$highest  = $result->highest_severity();
			$outcome  = null === $highest
				? $result->status()
				: count( $findings ) . ( 1 === count( $findings ) ? ' finding, ' : ' findings, ' ) . 'most severe ' . $highest->name();
			$rows[]   = '- ' . Markdown_Escape::code( $entry->check_id() ) . ': ' . Markdown_Escape::text( $outcome ) . ' (not counted)';
		}

		return array() === $rows ? array() : array_merge( array( '## Ignored by configuration (' . count( $rows ) . ')', '' ), $rows, array( '' ) );
	}

	/**
	 * Closing line with finding and check counts.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Redacted report to count.
	 * @return string
	 */
	private function summary( Report $report ): string {
		$severity = $report->severity_counts();
		$status   = $report->status_counts();
		$total    = array_sum( $severity );
		$ignored  = count( $report->ignored_entries() );

		$findings = 0 === $total
			? 'No findings.'
			: sprintf( '%d %s (%d critical, %d warning, %d info).', $total, 1 === $total ? 'finding' : 'findings', $severity['critical'], $severity['warning'], $severity['info'] );

		return Markdown_Escape::line_start(
			sprintf(
				'%s Checks: %d passed, %d with findings, %d skipped, %d failed to run%s.',
				$findings,
				$status[ Result::PASS ],
				$status[ Result::FINDINGS ],
				$status[ Result::SKIPPED ],
				$status[ Result::ERROR ],
				0 === $ignored ? '' : ", $ignored ignored by configuration"
			)
		);
	}
}
