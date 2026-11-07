<?php
/**
 * One walk of a directory, and whether it saw everything under it.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Fs;

/**
 * Iterated once with foreach. After the last entry it says what the walk could
 * not see in that directory: the entry cap, paths that could not be read, and
 * symlinks that were broken or left the site root. Excluded paths are not
 * counted, since the report lists the exclude globs. Asking before the last
 * entry has been reached is an error, so a walk that was cut short cannot be
 * taken for a complete one.
 *
 * @since 0.1.0
 *
 * @implements \IteratorAggregate<int, File_Info>
 */
final class Walk implements \IteratorAggregate {

	public const CAPPED     = 'capped';
	public const UNREADABLE = 'unreadable';
	public const LINK       = 'link';
	public const EXCLUDED   = 'excluded';

	private const LINKS_NAMED = 3;

	/**
	 * Name of the walk for messages, such as "the site root".
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Entries still to be yielded.
	 *
	 * @since 0.1.0
	 *
	 * @var \Generator<int, File_Info>
	 */
	private $entries;

	/**
	 * Entry cap of the scanner, for the message.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $max_files;

	/**
	 * Gaps found so far, each with a kind, the path concerned and the directory whose listing it affects. Filled while the entries are yielded.
	 *
	 * @since 0.1.0
	 *
	 * @var \ArrayObject<int, array{kind: string, path: string, dir: string}>
	 */
	private $gaps;

	/**
	 * Whether iteration has started.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $started = false;

	/**
	 * Number of entries yielded.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $yielded = 0;

	/**
	 * Whether every entry has been yielded.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $finished = false;

	/**
	 * Wraps the entries of a walk and the list its gaps are added to.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $label     Name of the walk for messages.
	 * @param \Generator   $entries   Entries, yielding File_Info.
	 * @param \ArrayObject $gaps      Gaps, added to while $entries runs.
	 * @param int          $max_files Entry cap of the scanner.
	 * @phpstan-param \Generator<int, File_Info> $entries
	 * @phpstan-param \ArrayObject<int, array{kind: string, path: string, dir: string}> $gaps
	 */
	public function __construct( string $label, \Generator $entries, \ArrayObject $gaps, int $max_files ) {
		$this->label     = $label;
		$this->entries   = $entries;
		$this->gaps      = $gaps;
		$this->max_files = $max_files;
	}

	/**
	 * Yields the entries. A walk can be iterated only once.
	 *
	 * @since 0.1.0
	 *
	 * @return \Generator<int, File_Info>
	 * @throws \LogicException When the walk was already iterated.
	 */
	public function getIterator(): \Generator { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Named by IteratorAggregate.
		if ( $this->started ) {
			throw new \LogicException( 'A walk can be iterated only once' );
		}
		$this->started = true;

		return $this->run();
	}

	/**
	 * Whether the walk saw every entry under its directory.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 * @throws \LogicException When the walk has not reached its last entry.
	 */
	public function is_complete(): bool {
		return null === $this->incomplete_reason();
	}

	/**
	 * What the walk could not see, or null when it saw everything. A walk that
	 * yielded nothing has seen nothing, whether the directory is empty or every
	 * entry was excluded.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 * @throws \LogicException When the walk has not reached its last entry.
	 */
	public function incomplete_reason(): ?string {
		return $this->describe( array( self::CAPPED, self::UNREADABLE, self::LINK ), true );
	}

	/**
	 * Why names are missing from the listing, or null when every name was listed.
	 *
	 * Unlike incomplete_reason(), symlinks that were not followed do not count,
	 * since their names are in skipped_links().
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 * @throws \LogicException When the walk has not reached its last entry.
	 */
	public function listing_gap(): ?string {
		return $this->describe( array( self::CAPPED, self::UNREADABLE ), false );
	}

	/**
	 * Number of entries the walk yielded.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 * @throws \LogicException When the walk has not reached its last entry.
	 */
	public function entries_seen(): int {
		$this->finished_gaps();

		return $this->yielded;
	}

	/**
	 * Paths of symlinks that were broken or led outside the root, which the walk did not list.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 * @throws \LogicException When the walk has not reached its last entry.
	 */
	public function skipped_links(): array {
		$paths = array();
		foreach ( $this->finished_gaps() as $gap ) {
			if ( self::LINK === $gap['kind'] ) {
				$paths[] = $gap['path'];
			}
		}

		return $paths;
	}

	/**
	 * Passes the entries through and marks the walk finished after the last one.
	 *
	 * @since 0.1.0
	 *
	 * @return \Generator<int, File_Info>
	 */
	private function run(): \Generator {
		foreach ( $this->entries as $entry ) {
			++$this->yielded;
			yield $entry;
		}
		$this->finished = true;
	}

	/**
	 * The gaps, once the walk has finished.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{kind: string, path: string, dir: string}>
	 * @throws \LogicException When the walk has not reached its last entry.
	 */
	private function finished_gaps(): array {
		if ( ! $this->finished ) {
			throw new \LogicException( 'A walk cannot say what it missed before its last entry has been read' );
		}

		return $this->gaps->getArrayCopy();
	}

	/**
	 * Sentence listing the gaps of the given kinds. A walk that yielded nothing
	 * because every entry was excluded is always a gap; one that yielded
	 * nothing from an empty directory is a gap only when $empty_counts is set.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $kinds        Gap kinds to include.
	 * @param bool     $empty_counts Whether an empty directory is a gap.
	 * @return string|null Null when there are none.
	 * @throws \LogicException When the walk has not reached its last entry.
	 */
	private function describe( array $kinds, bool $empty_counts ): ?string {
		$counts = array_fill_keys( array_merge( $kinds, array( self::EXCLUDED ) ), 0 );
		foreach ( $this->finished_gaps() as $gap ) {
			if ( isset( $counts[ $gap['kind'] ] ) ) {
				++$counts[ $gap['kind'] ];
			}
		}

		$parts = array();
		if ( ! empty( $counts[ self::CAPPED ] ) ) {
			$parts[] = sprintf( 'the walk of %s stopped at the limit of %d entries (--max-files)', $this->label, $this->max_files );
		}
		if ( ! empty( $counts[ self::UNREADABLE ] ) ) {
			$parts[] = sprintf( '%d path(s) in %s could not be read', $counts[ self::UNREADABLE ], $this->label );
		}
		if ( ! empty( $counts[ self::LINK ] ) ) {
			$links = $this->skipped_links();
			sort( $links, SORT_STRING );
			$named   = implode( ', ', array_slice( $links, 0, self::LINKS_NAMED ) ) . ( count( $links ) > self::LINKS_NAMED ? ', ...' : '' );
			$parts[] = sprintf(
				'%d symlink(s) in %s were broken or left the site root and were not followed (%s); exclude them with --exclude to scan without them',
				$counts[ self::LINK ],
				$this->label,
				$named
			);
		}
		if ( 0 === $this->yielded && $counts[ self::EXCLUDED ] > 0 ) {
			$parts[] = sprintf( 'every entry in %s (%d) was excluded by --exclude, so nothing was examined', $this->label, $counts[ self::EXCLUDED ] );
		} elseif ( 0 === $this->yielded && $empty_counts && array() === $parts ) {
			$parts[] = sprintf( '%s is empty, so there was nothing to examine', $this->label );
		}

		return array() === $parts ? null : implode( '; ', $parts );
	}
}
