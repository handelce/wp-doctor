<?php
/**
 * The outcome of running one check.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report;

/**
 * Exactly one of: pass, findings, skipped, error. Only the named constructors
 * build one.
 *
 * @since 0.1.0
 */
final class Result {

	public const PASS     = 'pass';
	public const FINDINGS = 'findings';
	public const SKIPPED  = 'skipped';
	public const ERROR    = 'error';

	/**
	 * One of the status constants.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Findings, empty unless the status is findings.
	 *
	 * @since 0.1.0
	 *
	 * @var Finding[]
	 */
	private $findings;

	/**
	 * Severity of the highest-ranked finding. Null unless the status is findings.
	 *
	 * @since 0.1.0
	 *
	 * @return Severity|null
	 */
	public function highest_severity(): ?Severity {
		$highest = null;
		foreach ( $this->findings as $finding ) {
			if ( null === $highest || $finding->severity()->rank() > $highest->rank() ) {
				$highest = $finding->severity();
			}
		}

		return $highest;
	}

	/**
	 * Skip reason or error message. Null for pass and findings.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $message;

	/**
	 * Use the named constructors.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $status   Status constant.
	 * @param Finding[]   $findings Findings, empty for every status but findings.
	 * @param string|null $message  Skip reason or error message, or null.
	 */
	private function __construct( string $status, array $findings, ?string $message ) {
		$this->status   = $status;
		$this->findings = $findings;
		$this->message  = $message;
	}

	/**
	 * The check ran and found nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function pass(): self {
		return new self( self::PASS, array(), null );
	}

	/**
	 * The check found one or more problems.
	 *
	 * @since 0.1.0
	 *
	 * @param Finding ...$findings At least one finding.
	 * @return self
	 * @throws \InvalidArgumentException When no finding is given.
	 */
	public static function findings( Finding ...$findings ): self {
		if ( array() === $findings ) {
			throw new \InvalidArgumentException( 'Result::findings needs at least one Finding; use Result::pass() for none' );
		}

		return new self( self::FINDINGS, $findings, null );
	}

	/**
	 * The check could not run.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Why, for example which value was unresolved.
	 * @return self
	 * @throws \InvalidArgumentException When the reason is blank.
	 */
	public static function skipped( string $reason ): self {
		return new self( self::SKIPPED, array(), self::require_reason( $reason ) );
	}

	/**
	 * The check threw. Built by the runner, not by checks.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Description of the failure.
	 * @return self
	 * @throws \InvalidArgumentException When the message is blank.
	 */
	public static function error( string $message ): self {
		return new self( self::ERROR, array(), self::require_reason( $message ) );
	}

	/**
	 * One of the status constants.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Whether the check ran and found nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_pass(): bool {
		return self::PASS === $this->status;
	}

	/**
	 * Findings in the order they were given. Empty unless the status is findings.
	 *
	 * @since 0.1.0
	 *
	 * @return Finding[]
	 */
	public function finding_list(): array {
		return $this->findings;
	}

	/**
	 * Skip reason or error message. Null for pass and findings.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function message(): ?string {
		return $this->message;
	}

	/**
	 * Rejects a blank reason.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Skip reason or error message.
	 * @return string The reason.
	 * @throws \InvalidArgumentException When the reason is blank.
	 */
	private static function require_reason( string $reason ): string {
		if ( '' === trim( $reason ) ) {
			throw new \InvalidArgumentException( 'A skipped or error result needs a reason' );
		}

		return $reason;
	}
}
