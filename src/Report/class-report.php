<?php
/**
 * The outcome of a whole scan.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report;

/**
 * Immutable. Holds every check's result plus notices about limits that were hit.
 *
 * @since 0.1.0
 */
final class Report {

	/**
	 * Version of wp-doctor that ran the scan.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $tool_version;

	/**
	 * Installed WordPress version, when known.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $wp_version;

	/**
	 * One entry per check that ran or was skipped.
	 *
	 * @since 0.1.0
	 *
	 * @var Report_Entry[]
	 */
	private $entries;

	/**
	 * Notes about limits hit and paths skipped.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private $notices;

	/**
	 * Ignored check ids, as keys.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private $ignored;

	/**
	 * Collects the outcome of a finished scan.
	 *
	 * @since 0.1.0
	 *
	 * @param string         $tool_version Version of wp-doctor.
	 * @param string|null    $wp_version   Installed WordPress version. Null when it could not be read.
	 * @param Report_Entry[] $entries      Check outcomes.
	 * @param string[]       $notices      Notes about limits hit and paths skipped.
	 * @param string[]       $ignored      Check ids the config file silences. Their outcomes are listed but do not count.
	 * @throws \InvalidArgumentException When an entry is not a Report_Entry.
	 */
	public function __construct( string $tool_version, ?string $wp_version, array $entries, array $notices, array $ignored = array() ) {
		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof Report_Entry ) {
				throw new \InvalidArgumentException( 'Report entries must be Report_Entry objects' );
			}
		}

		$this->tool_version = $tool_version;
		$this->wp_version   = $wp_version;
		$this->entries      = array_values( $entries );
		$this->notices      = array_values( $notices );
		$this->ignored      = array_fill_keys( array_map( 'strval', $ignored ), true );
	}

	/**
	 * Version of wp-doctor that ran the scan.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function tool_version(): string {
		return $this->tool_version;
	}

	/**
	 * Installed WordPress version, or null when it could not be read.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function wp_version(): ?string {
		return $this->wp_version;
	}

	/**
	 * Every check's outcome, in the order given.
	 *
	 * @since 0.1.0
	 *
	 * @return Report_Entry[]
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Notes about limits hit and paths skipped.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function notices(): array {
		return $this->notices;
	}

	/**
	 * Whether the config file names a check in `ignore`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $check_id Id to look up.
	 * @return bool
	 */
	public function is_ignored( string $check_id ): bool {
		return isset( $this->ignored[ $check_id ] );
	}

	/**
	 * Whether an outcome is left out of the counts. `ignore` silences what a
	 * check found, never a check that failed to run, so an error always counts.
	 *
	 * @since 0.1.0
	 *
	 * @param Report_Entry $entry Outcome of one check.
	 * @return bool
	 */
	public function is_silenced( Report_Entry $entry ): bool {
		return $this->is_ignored( $entry->check_id() ) && Result::ERROR !== $entry->result()->status();
	}

	/**
	 * Check ids the config file silences.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function ignored_ids(): array {
		return array_map( 'strval', array_keys( $this->ignored ) );
	}

	/**
	 * Silenced outcomes of the ignored checks, in run order.
	 *
	 * @since 0.1.0
	 *
	 * @return Report_Entry[]
	 */
	public function ignored_entries(): array {
		return array_values(
			array_filter(
				$this->entries,
				function ( Report_Entry $entry ): bool {
					return $this->is_silenced( $entry );
				}
			)
		);
	}

	/**
	 * Outcomes that count: every entry that is not silenced.
	 *
	 * @since 0.1.0
	 *
	 * @return Report_Entry[]
	 */
	public function counted_entries(): array {
		return array_values(
			array_filter(
				$this->entries,
				function ( Report_Entry $entry ): bool {
					return ! $this->is_silenced( $entry );
				}
			)
		);
	}

	/**
	 * Every finding of a counted check, most severe first and then by id.
	 * Findings that tie keep the order they were given in.
	 *
	 * @since 0.1.0
	 *
	 * @return Finding[]
	 */
	public function findings(): array {
		$indexed = array();
		foreach ( $this->counted_entries() as $entry ) {
			foreach ( $entry->result()->finding_list() as $finding ) {
				$indexed[] = array( count( $indexed ), $finding );
			}
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				return array( $b[1]->severity()->rank(), $a[1]->id(), $a[0] ) <=> array( $a[1]->severity()->rank(), $b[1]->id(), $b[0] );
			}
		);

		return array_map(
			static function ( array $pair ): Finding {
				return $pair[1];
			},
			$indexed
		);
	}

	/**
	 * Number of counted checks with each status.
	 *
	 * @since 0.1.0
	 *
	 * @return array{pass: int, findings: int, skipped: int, error: int}
	 */
	public function status_counts(): array {
		$counts = array(
			Result::PASS     => 0,
			Result::FINDINGS => 0,
			Result::SKIPPED  => 0,
			Result::ERROR    => 0,
		);
		foreach ( $this->counted_entries() as $entry ) {
			++$counts[ $entry->result()->status() ];
		}

		return $counts;
	}

	/**
	 * Number of findings at each severity.
	 *
	 * @since 0.1.0
	 *
	 * @return array{critical: int, warning: int, info: int}
	 */
	public function severity_counts(): array {
		$counts = array(
			'critical' => 0,
			'warning'  => 0,
			'info'     => 0,
		);
		foreach ( $this->findings() as $finding ) {
			++$counts[ $finding->severity()->name() ];
		}

		return $counts;
	}

	/**
	 * Whether any check ended in an error.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_errors(): bool {
		return $this->status_counts()[ Result::ERROR ] > 0;
	}

	/**
	 * Severity of the most severe finding, or null when there are none.
	 *
	 * @since 0.1.0
	 *
	 * @return Severity|null
	 */
	public function highest_severity(): ?Severity {
		$findings = $this->findings();

		return array() === $findings ? null : $findings[0]->severity();
	}
}
