<?php
/**
 * Plain-text report for a terminal.
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

use function WP_Doctor\esc_cli;

/**
 * Findings grouped by severity, then skipped and failed checks, notices and a
 * summary. Everything that came from the site passes through esc_cli().
 *
 * @since 0.1.0
 */
final class Text_Formatter implements Formatter {

	private const COLOURS = array(
		'critical' => "\033[31m",
		'warning'  => "\033[33m",
		'info'     => "\033[36m",
	);
	private const RESET   = "\033[0m";
	private const BOLD    = "\033[1m";

	/**
	 * Whether to emit ANSI colour codes.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $colour;

	/**
	 * Sets whether output is coloured.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $colour Whether to emit ANSI colour codes. Set it only when writing to a terminal.
	 */
	public function __construct( bool $colour = false ) {
		$this->colour = $colour;
	}

	/**
	 * Renders the report as plain text.
	 *
	 * @since 0.1.0
	 *
	 * @param Redacted_Report $redacted Report already passed through the Redactor. Site text in it still goes through esc_cli().
	 * @return string
	 */
	public function format( Redacted_Report $redacted ): string {
		$report = $redacted->report();
		$lines  = array( $this->header( $report ), '' );

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

			$lines[] = $this->paint( strtoupper( $severity ) . ' (' . count( $group ) . ')', $severity );
			foreach ( $group as $finding ) {
				$lines = array_merge( $lines, $this->finding_lines( $finding ) );
			}
			$lines[] = '';
		}

		$lines = array_merge( $lines, $this->skipped_section( $report ) );
		$lines = array_merge( $lines, $this->status_section( $report, Result::ERROR, 'Failed to run' ) );
		$lines = array_merge( $lines, $this->ignored_section( $report ) );

		if ( array() !== $report->notices() ) {
			$lines[] = $this->bold( 'Notes' );
			foreach ( $report->notices() as $notice ) {
				$lines[] = '  ' . esc_cli( $notice );
			}
			$lines[] = '';
		}

		$lines[] = $this->summary( $report );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * First line: tool and WordPress versions.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Redacted report to read the versions from.
	 * @return string
	 */
	private function header( Report $report ): string {
		$line = 'wp-doctor ' . esc_cli( $report->tool_version() );
		if ( null !== $report->wp_version() ) {
			$line .= ', WordPress ' . esc_cli( $report->wp_version() );
		}

		return $this->bold( $line );
	}

	/**
	 * Id and title, evidence and fix for one finding, indented under its severity heading.
	 *
	 * @since 0.1.0
	 *
	 * @param Finding $finding Finding from the redacted report.
	 * @return string[]
	 */
	private function finding_lines( Finding $finding ): array {
		$lines = array( '  [' . esc_cli( $finding->id() ) . '] ' . esc_cli( $finding->title() ) );

		foreach ( $finding->evidence() as $key => $value ) {
			$lines = array_merge( $lines, $this->evidence_lines( (string) $key, $value ) );
		}

		$fix_lines = explode( "\n", esc_cli( $finding->fix(), true ) );
		$lines[]   = '    Fix: ' . array_shift( $fix_lines );
		foreach ( $fix_lines as $fix_line ) {
			$lines[] = '    ' . $fix_line;
		}

		return $lines;
	}

	/**
	 * Lines for one evidence entry. A list puts each item on a line of its
	 * own, and an object in a list puts each field on a line of its own.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Evidence key.
	 * @param mixed  $value Scalar, or list of scalars or of objects of scalars.
	 * @return string[]
	 */
	private function evidence_lines( string $key, $value ): array {
		if ( ! is_array( $value ) ) {
			return array( '    ' . esc_cli( $key ) . ': ' . $this->scalar( $value ) );
		}
		if ( array() === $value ) {
			return array( '    ' . esc_cli( $key ) . ': none' );
		}
		$lines = array( '    ' . esc_cli( $key ) . ':' );
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				$lines[] = '      - ' . $this->scalar( $item );
				continue;
			}
			$marker = '      - ';
			foreach ( $item as $field => $field_value ) {
				$lines[] = $marker . esc_cli( (string) $field ) . ': ' . $this->scalar( $field_value );
				$marker  = '        ';
			}
		}

		return $lines;
	}

	/**
	 * Lists the counted checks with one status, each with its message.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Redacted report whose counted entries are listed.
	 * @param string $status Result status to list.
	 * @param string $title  Section title.
	 * @return string[]
	 */
	private function status_section( Report $report, string $status, string $title ): array {
		$rows = array();
		foreach ( $report->counted_entries() as $entry ) {
			if ( $entry->result()->status() === $status ) {
				$rows[] = '  ' . esc_cli( $entry->check_id() ) . ': ' . esc_cli( (string) $entry->result()->message() );
			}
		}
		if ( array() === $rows ) {
			return array();
		}

		return array_merge( array( $this->bold( $title . ' (' . count( $rows ) . ')' ) ), $rows, array( '' ) );
	}

	/**
	 * Skipped checks, one line per distinct reason with the checks that share it.
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
			if ( Result::SKIPPED !== $entry->result()->status() ) {
				continue;
			}
			$by_reason[ (string) $entry->result()->message() ][] = $entry->check_id();
			++$count;
		}
		if ( 0 === $count ) {
			return array();
		}

		$lines = array( $this->bold( 'Skipped (' . $count . ')' ) );
		foreach ( $by_reason as $reason => $ids ) {
			$lines[] = '  ' . esc_cli( (string) $reason );
			foreach ( $this->wrapped( $ids ) as $row ) {
				$lines[] = '    ' . esc_cli( $row );
			}
		}
		$lines[] = '';

		return $lines;
	}

	/**
	 * Comma-separated ids in rows of at most 80 characters.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $ids Check ids.
	 * @return string[]
	 */
	private function wrapped( array $ids ): array {
		$rows    = array();
		$current = '';
		foreach ( $ids as $index => $id ) {
			$piece = $id . ( $index < count( $ids ) - 1 ? ',' : '' );
			if ( '' !== $current && strlen( $current ) + 1 + strlen( $piece ) > 80 ) {
				$rows[]  = $current;
				$current = $piece;
			} else {
				$current = '' === $current ? $piece : $current . ' ' . $piece;
			}
		}
		$rows[] = $current;

		return $rows;
	}

	/**
	 * Checks the config file silences, with what each would have reported.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Redacted report whose ignored entries are listed.
	 * @return string[]
	 */
	private function ignored_section( Report $report ): array {
		$entries = $report->ignored_entries();
		if ( array() === $entries ) {
			return array();
		}
		$rows = array();
		foreach ( $entries as $entry ) {
			$result   = $entry->result();
			$findings = $result->finding_list();
			$highest  = $result->highest_severity();
			$outcome  = null === $highest
				? $result->status()
				: count( $findings ) . ( 1 === count( $findings ) ? ' finding, ' : ' findings, ' ) . 'most severe ' . $highest->name();
			$rows[]   = '  ' . esc_cli( $entry->check_id() ) . ': ' . esc_cli( $outcome ) . ' (not counted)';
		}

		return array_merge( array( $this->bold( 'Ignored by configuration (' . count( $rows ) . ')' ) ), $rows, array( '' ) );
	}

	/**
	 * Closing line counting findings by severity and checks by status.
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

		$findings = 0 === $total
			? 'No findings.'
			: sprintf( '%d %s (%d critical, %d warning, %d info).', $total, 1 === $total ? 'finding' : 'findings', $severity['critical'], $severity['warning'], $severity['info'] );

		$ignored = count( $report->ignored_entries() );

		return sprintf(
			'%s Checks: %d passed, %d with findings, %d skipped, %d failed to run%s.',
			$findings,
			$status[ Result::PASS ],
			$status[ Result::FINDINGS ],
			$status[ Result::SKIPPED ],
			$status[ Result::ERROR ],
			0 === $ignored ? '' : ", $ignored ignored by configuration"
		);
	}

	/**
	 * Evidence value as escaped text. Booleans print as true or false.
	 *
	 * @since 0.1.0
	 *
	 * @param string|int|float|bool $value Evidence value.
	 * @return string
	 */
	private function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return esc_cli( (string) $value );
	}

	/**
	 * Colours text by severity when colour is on.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text     Heading text built by the formatter.
	 * @param string $severity Severity name, a key of COLOURS.
	 * @return string
	 */
	private function paint( string $text, string $severity ): string {
		return $this->colour ? self::BOLD . self::COLOURS[ $severity ] . $text . self::RESET : $text;
	}

	/**
	 * Bolds text when colour is on.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Line to bold. Site text in it must already be escaped.
	 * @return string
	 */
	private function bold( string $text ): string {
		return $this->colour ? self::BOLD . $text . self::RESET : $text;
	}
}
