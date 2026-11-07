<?php
/**
 * Bounded, read-only access to the site's files.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Fs;

use function WP_Doctor\esc_cli;
use function WP_Doctor\real_path;

/**
 * The only way checks reach the filesystem. It has no method that writes,
 * moves or deletes. Every path is resolved and must stay inside the root, so a
 * symlink or a ".." segment cannot lead out of the site. Limits that are hit
 * are reported through notices() for the report, and each walk says what it
 * could not see. Nothing is truncated silently.
 *
 * @since 0.1.0
 */
final class File_Scanner {

	public const DEFAULT_MAX_FILES = 200000;
	public const DEFAULT_MAX_BYTES = 524288;

	/**
	 * Largest full walk kept in memory for later walks to replay.
	 *
	 * @since 0.1.0
	 */
	public const DEFAULT_CACHE_ENTRIES = 100000;

	/**
	 * Largest file hash() reads.
	 *
	 * @since 0.1.0
	 */
	public const MAX_HASH_BYTES = 67108864;

	/**
	 * Values of presence(): the path resolves to an entry inside the root.
	 *
	 * @since 0.1.0
	 */
	public const PRESENT = 'present';

	/**
	 * Nothing is there.
	 *
	 * @since 0.1.0
	 */
	public const ABSENT = 'absent';

	/**
	 * A symlink whose target does not exist.
	 *
	 * @since 0.1.0
	 */
	public const BROKEN_LINK = 'broken link';

	/**
	 * Matched by an exclude glob.
	 *
	 * @since 0.1.0
	 */
	public const EXCLUDED = 'excluded';

	/**
	 * Leads outside the root through a symlink or a ".." segment.
	 *
	 * @since 0.1.0
	 */
	public const OUTSIDE_ROOT = 'outside the site root';

	/**
	 * A directory on the way could not be searched, so it is not known.
	 *
	 * @since 0.1.0
	 */
	public const UNREADABLE = 'unreadable';

	private const HASH_ALGORITHMS = array( 'md5', 'sha1', 'sha256' );
	private const TYPE_MASK       = 0170000;
	private const TYPE_LINK       = 0120000;
	private const TYPE_DIRECTORY  = 0040000;

	/**
	 * Resolved site root.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Entries yielded per walk before it stops.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $max_files;

	/**
	 * Most entries a recorded full walk may hold.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $cache_entries;

	/**
	 * Every entry of the first complete, uncapped walk of the whole tree, in walk order. Null until one finishes.
	 *
	 * @since 0.1.0
	 *
	 * @var File_Info[]|null
	 */
	private $tree;

	/**
	 * Gaps of the recorded full walk, replayed with the entries they concern.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{kind: string, path: string, dir: string}>
	 */
	private $tree_gaps = array();

	/**
	 * Bytes returned per read before it truncates.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $max_bytes;

	/**
	 * Regular expressions built from the exclude globs.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private $excluded = array();

	/**
	 * Walks that stopped at the entry cap.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private $capped_walks = array();

	/**
	 * Symlinks that were broken or led outside the root.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $skipped_links = 0;

	/**
	 * Paths that could not be read.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $unreadable = 0;

	/**
	 * Whole-file reads cut at the byte cap.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $truncated_reads = 0;

	/**
	 * Whether the platform reports real Unix permission bits.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $unix_permissions;

	/**
	 * Resolves the root and compiles the exclude globs.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $root      Site root. Symlinks in it are resolved.
	 * @param int      $max_files Most entries one walk yields before it stops.
	 * @param int      $max_bytes Most bytes one read returns.
	 * @param string[] $exclude   Globs relative to the root. ** crosses directories, * does not.
	 * @param string   $os_family Operating system family, as PHP_OS_FAMILY reports it.
	 * @param int      $cache_entries Most entries of a full walk kept for later walks. 0 turns the cache off.
	 * @throws \InvalidArgumentException When the root is not a directory.
	 */
	public function __construct( string $root, int $max_files = self::DEFAULT_MAX_FILES, int $max_bytes = self::DEFAULT_MAX_BYTES, array $exclude = array(), string $os_family = PHP_OS_FAMILY, int $cache_entries = self::DEFAULT_CACHE_ENTRIES ) {
		$real = real_path( $root );
		if ( false === $real || ! is_dir( $real ) ) {
			throw new \InvalidArgumentException( esc_cli( "Scanner root is not a directory: $root" ) );
		}

		$this->root             = rtrim( $real, '/' );
		$this->max_files        = $max_files;
		$this->cache_entries    = $cache_entries;
		$this->max_bytes        = $max_bytes;
		$this->unix_permissions = 'Windows' !== $os_family;
		foreach ( $exclude as $glob ) {
			$this->excluded[] = $this->glob_to_regex( $glob );
		}
	}

	/**
	 * Whether file modes mean what Unix permission bits mean. PHP on Windows
	 * reports made-up modes, so a check must not judge them.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_unix_permissions(): bool {
		return $this->unix_permissions;
	}

	/**
	 * Resolved site root.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function root(): string {
		return '' === $this->root ? '/' : $this->root;
	}

	/**
	 * Lists a directory's entries in name order, directories before their contents.
	 *
	 * Symlinks are listed only when their target is inside the root, and are not
	 * descended into. The entry cap applies to each walk separately. The walk
	 * says afterwards what it could not see in this directory.
	 *
	 * Once a full walk of the tree has run to the end uncapped and within the
	 * cache limit, later walks of the tree or of any directory in it replay that
	 * record in fresh-walk order, with its gaps. A directory named through a
	 * symlink or a non-canonical spelling such as "./x" is walked afresh.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative  Directory relative to the root. Empty for the root.
	 * @param bool   $recursive Whether to descend into subdirectories.
	 * @return Walk
	 * @throws Path_Unavailable_Exception When the directory is missing, excluded or outside the root.
	 */
	public function walk( string $relative = '', bool $recursive = true ): Walk {
		$relative = rtrim( $relative, '/' );
		if ( '' !== $relative && $this->is_safe( $relative ) && $this->is_excluded( $relative ) ) {
			throw new Path_Unavailable_Exception( esc_cli( "$relative is excluded from the scan by --exclude" ) );
		}
		$real = $this->resolve( $relative );
		if ( null === $real || ! is_dir( $real ) ) {
			throw new Path_Unavailable_Exception( esc_cli( ( '' === $relative ? '.' : $relative ) . ' does not exist or cannot be read' ) );
		}
		$label = '' === $relative ? 'the site root' : $relative;

		if ( null !== $this->tree && $this->full_path( $relative ) === $real ) {
			return new Walk( $label, $this->replay( $relative, $recursive ), new \ArrayObject( $this->replayed_gaps( $relative, $recursive ) ), $this->max_files );
		}

		$budget  = new \ArrayObject(
			array(
				'seen'    => 0,
				'stopped' => false,
			)
		);
		$gaps    = new \ArrayObject( array() );
		$entries = $this->entries_of( $real, $relative, $recursive, $label, $budget, $gaps );
		if ( '' === $relative && $recursive && $this->cache_entries > 0 ) {
			$entries = $this->recording( $entries, $budget, $gaps );
		}

		return new Walk( $label, $entries, $gaps, $this->max_files );
	}

	/**
	 * Passes a full walk through and keeps it when it finishes uncapped and small enough.
	 * A walk the caller abandons part-way is not kept.
	 *
	 * @since 0.1.0
	 *
	 * @param \Generator   $entries Full walk, yielding File_Info.
	 * @param \ArrayObject $budget  The walk's counter and stop flag.
	 * @param \ArrayObject $gaps    The walk's gaps.
	 * @return \Generator Yields File_Info.
	 * @phpstan-param \Generator<int, File_Info> $entries
	 * @phpstan-param \ArrayObject<string, int|bool> $budget
	 * @phpstan-param \ArrayObject<int, array{kind: string, path: string, dir: string}> $gaps
	 * @phpstan-return \Generator<int, File_Info>
	 */
	private function recording( \Generator $entries, \ArrayObject $budget, \ArrayObject $gaps ): \Generator {
		$recorded = array();
		$keep     = true;
		foreach ( $entries as $entry ) {
			if ( $keep ) {
				$recorded[] = $entry;
				if ( count( $recorded ) > $this->cache_entries ) {
					$recorded = array();
					$keep     = false;
				}
			}
			yield $entry;
		}
		if ( $keep && ! $budget['stopped'] ) {
			$this->tree      = $recorded;
			$this->tree_gaps = $gaps->getArrayCopy();
		}
	}

	/**
	 * Gaps of the recorded full walk that a walk of this directory would have met.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative  Directory relative to the root. Empty for the root.
	 * @param bool   $recursive Whether subdirectories are included.
	 * @return array<int, array{kind: string, path: string, dir: string}>
	 */
	private function replayed_gaps( string $relative, bool $recursive ): array {
		$gaps = array();
		foreach ( $this->tree_gaps as $gap ) {
			$below = '' === $relative || 0 === strpos( $gap['dir'], $relative . '/' );
			if ( $gap['dir'] === $relative || ( $recursive && $below ) ) {
				$gaps[] = $gap;
			}
		}

		return $gaps;
	}

	/**
	 * Root joined with a relative path, without resolving anything.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the root. Empty for the root.
	 * @return string
	 */
	private function full_path( string $relative ): string {
		return '' === $relative ? $this->root() : $this->root . '/' . $relative;
	}

	/**
	 * Entries of a directory taken from the recorded full walk.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative  Directory relative to the root. Empty for the root.
	 * @param bool   $recursive Whether to include entries below its direct children.
	 * @return \Generator Yields File_Info.
	 * @phpstan-return \Generator<int, File_Info>
	 */
	private function replay( string $relative, bool $recursive ): \Generator {
		$prefix = '' === $relative ? '' : $relative . '/';
		foreach ( (array) $this->tree as $entry ) {
			$path = $entry->path();
			if ( '' !== $prefix && 0 !== strpos( $path, $prefix ) ) {
				continue;
			}
			if ( ! $recursive && false !== strpos( substr( $path, strlen( $prefix ) ), '/' ) ) {
				continue;
			}
			yield $entry;
		}
	}

	/**
	 * Describes one path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the root.
	 * @return File_Info|null Null when the path is missing, excluded or outside the root.
	 */
	public function stat( string $relative ): ?File_Info {
		if ( null === $this->resolve( $relative ) ) {
			return null;
		}

		$stat = @lstat( $this->full_path( $relative ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished file is reported as null.
		if ( false === $stat ) {
			return null;
		}

		return $this->describe( $relative, $stat );
	}

	/**
	 * Whether a path exists, and when it cannot be examined, why.
	 *
	 * Tells a file that is gone apart from one that is excluded, behind a
	 * symlink that leaves the root, or under a directory that cannot be searched.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the root.
	 * @return string One of PRESENT, ABSENT, BROKEN_LINK, EXCLUDED, OUTSIDE_ROOT or UNREADABLE.
	 */
	public function presence( string $relative ): string {
		if ( '' === $relative ) {
			return self::PRESENT;
		}
		if ( ! $this->is_safe( $relative ) ) {
			return self::OUTSIDE_ROOT;
		}
		if ( $this->is_excluded( $relative ) ) {
			return self::EXCLUDED;
		}

		$full = $this->full_path( $relative );
		$real = real_path( $full );
		if ( false !== $real ) {
			return $this->contains( $real ) ? self::PRESENT : self::OUTSIDE_ROOT;
		}
		if ( false !== @lstat( $full ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure means nothing is there.
			return self::BROKEN_LINK;
		}

		$ancestor = dirname( $relative );
		while ( '.' !== $ancestor && false === @lstat( $this->full_path( $ancestor ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure means nothing is there.
			$ancestor = dirname( $ancestor );
		}
		$ancestor      = '.' === $ancestor ? '' : $ancestor;
		$ancestor_real = real_path( $this->full_path( $ancestor ) );
		if ( false === $ancestor_real ) {
			return self::ABSENT;
		}
		if ( ! $this->contains( $ancestor_real ) ) {
			return self::OUTSIDE_ROOT;
		}
		if ( is_dir( $ancestor_real ) && false === @scandir( $ancestor_real ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is the answer.
			return self::UNREADABLE;
		}

		return self::ABSENT;
	}

	/**
	 * Reads a file's content.
	 *
	 * Without $length, reads up to the byte cap and records a notice when the
	 * file is larger. With $length, reads at most that many bytes, still within
	 * the cap, and records no notice.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $relative Path relative to the root.
	 * @param int|null $length   Bytes wanted, or null for the whole file.
	 * @return string|null Null when the path is not a readable file inside the root.
	 * @throws \InvalidArgumentException When the length is negative.
	 */
	public function read( string $relative, ?int $length = null ): ?string {
		if ( null !== $length && $length < 0 ) {
			throw new \InvalidArgumentException( 'Length must not be negative' );
		}

		$real = $this->resolve( $relative );
		if ( null === $real || ! is_file( $real ) ) {
			return null;
		}

		$handle = $this->open( $real );
		if ( null === $handle ) {
			++$this->unreadable;
			return null;
		}

		$limit = null === $length ? $this->max_bytes : min( $length, $this->max_bytes );
		$data  = 0 === $limit ? '' : stream_get_contents( $handle, $limit );
		$stat  = fstat( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a read-only handle.
		if ( false === $data ) {
			++$this->unreadable;
			return null;
		}

		if ( null === $length && false !== $stat && $stat['size'] > $this->max_bytes ) {
			++$this->truncated_reads;
		}

		return $data;
	}

	/**
	 * Hashes a file's full content.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative  Path relative to the root.
	 * @param string $algorithm md5, sha1 or sha256.
	 * @return string|null Hex digest, or null when the path is not a readable file inside the root.
	 * @throws \InvalidArgumentException When the algorithm is not supported.
	 * @throws File_Too_Large_Exception When the file is larger than MAX_HASH_BYTES.
	 */
	public function hash( string $relative, string $algorithm = 'md5' ): ?string {
		if ( ! in_array( $algorithm, self::HASH_ALGORITHMS, true ) ) {
			throw new \InvalidArgumentException( esc_cli( "Unsupported hash algorithm: $algorithm" ) );
		}

		$real = $this->resolve( $relative );
		if ( null === $real || ! is_file( $real ) ) {
			return null;
		}

		$handle = $this->open( $real );
		if ( null === $handle ) {
			++$this->unreadable;
			return null;
		}

		$stat = fstat( $handle );
		if ( false !== $stat && $stat['size'] > self::MAX_HASH_BYTES ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a read-only handle.
			throw new File_Too_Large_Exception( esc_cli( sprintf( '%s is larger than %d bytes and was not hashed', $relative, self::MAX_HASH_BYTES ) ) );
		}

		$context = hash_init( $algorithm );
		$read    = hash_update_stream( $context, $handle );
		$ended   = feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a read-only handle.
		if ( ! $ended || ( false !== $stat && $read !== $stat['size'] ) ) {
			++$this->unreadable;
			return null;
		}

		return hash_final( $context );
	}

	/**
	 * Limits hit and paths skipped so far, for the report.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function notices(): array {
		$notices = array();
		foreach ( $this->capped_walks as $label ) {
			$notices[] = sprintf( 'The walk of %s stopped after %d entries (--max-files).', $label, $this->max_files );
		}
		if ( $this->skipped_links > 0 ) {
			$notices[] = sprintf( '%d symlink(s) that were broken or left the site root were skipped.', $this->skipped_links );
		}
		if ( $this->unreadable > 0 ) {
			$notices[] = sprintf( '%d unreadable path(s) were skipped.', $this->unreadable );
		}
		if ( $this->truncated_reads > 0 ) {
			$notices[] = sprintf( '%d file(s) were read only up to %d bytes.', $this->truncated_reads, $this->max_bytes );
		}

		return $notices;
	}

	/**
	 * Resolves a relative path to a real path inside the root.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the root.
	 * @return string|null Null when the path is unsafe, excluded, missing or outside the root.
	 */
	private function resolve( string $relative ): ?string {
		if ( '' === $relative ) {
			return $this->root();
		}
		if ( ! $this->is_safe( $relative ) || $this->is_excluded( $relative ) ) {
			return null;
		}

		$real = real_path( $this->root . '/' . $relative );
		if ( false === $real ) {
			return null;
		}
		if ( ! $this->contains( $real ) ) {
			++$this->skipped_links;
			return null;
		}

		return $real;
	}

	/**
	 * Whether a relative path has no NUL byte, no leading slash and no ".." segment.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the root, not empty.
	 * @return bool
	 */
	private function is_safe( string $relative ): bool {
		return false === strpos( $relative, "\0" ) && '/' !== $relative[0] && ! in_array( '..', explode( '/', $relative ), true );
	}

	/**
	 * Opens a resolved file for reading and checks that the handle is the file
	 * that path still resolves to.
	 *
	 * The path is resolved again after opening with the realpath cache cleared, so
	 * a directory swapped for a symlink in the meantime is refused. Where the
	 * platform reports inode numbers the opened file must also be the same inode.
	 *
	 * @since 0.1.0
	 *
	 * @param string $real Path returned by resolve().
	 * @return resource|null Null when it cannot be opened or no longer resolves to itself.
	 */
	private function open( string $real ) {
		$handle = @fopen( $real, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only; failure is reported as null.
		if ( false === $handle ) {
			return null;
		}

		$opened = fstat( $handle );
		if ( false !== $opened && $this->still_resolves_to( $real ) ) {
			$current = @stat( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished file is refused.
			if ( false !== $current && ( 0 === $opened['ino'] || ( $opened['dev'] === $current['dev'] && $opened['ino'] === $current['ino'] ) ) ) {
				return $handle;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a read-only handle.

		return null;
	}

	/**
	 * Whether a real path inside the root still resolves to itself, bypassing the realpath cache.
	 *
	 * @since 0.1.0
	 *
	 * @param string $real Path that resolved to itself earlier.
	 * @return bool
	 */
	private function still_resolves_to( string $real ): bool {
		clearstatcache( true );

		return real_path( $real ) === $real && $this->contains( $real );
	}

	/**
	 * Whether a real path is the root or inside it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $real Path already resolved by real_path().
	 * @return bool
	 */
	private function contains( string $real ): bool {
		return $real === $this->root() || 0 === strpos( $real, $this->root . '/' );
	}

	/**
	 * Whether a relative path, or a directory above it, matches an exclude glob.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Path relative to the root.
	 * @return bool
	 */
	private function is_excluded( string $relative ): bool {
		if ( array() === $this->excluded ) {
			return false;
		}

		$path = '';
		foreach ( explode( '/', $relative ) as $segment ) {
			$path = '' === $path ? $segment : $path . '/' . $segment;
			foreach ( $this->excluded as $regex ) {
				if ( 1 === preg_match( $regex, $path ) || 1 === preg_match( $regex, $path . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Converts an exclude glob to a regular expression.
	 *
	 * @since 0.1.0
	 *
	 * @param string $glob Pattern relative to the root, using *, ** and ?.
	 * @return string
	 */
	private function glob_to_regex( string $glob ): string {
		$regex = '';
		$last  = strlen( $glob ) - 1;
		for ( $i = 0; $i <= $last; $i++ ) {
			$char = $glob[ $i ];
			if ( '*' === $char && $i < $last && '*' === $glob[ $i + 1 ] ) {
				++$i;
				if ( $i < $last && '/' === $glob[ $i + 1 ] ) {
					++$i;
					$regex .= '(?:.*/)?';
				} else {
					$regex .= '.*';
				}
			} elseif ( '*' === $char ) {
				$regex .= '[^/]*';
			} elseif ( '?' === $char ) {
				$regex .= '[^/]';
			} else {
				$regex .= preg_quote( $char, '#' );
			}
		}

		return '#^' . $regex . '$#';
	}

	/**
	 * Turns lstat() data into a File_Info, keeping only the permission bits of the mode.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $relative Path relative to the root.
	 * @param array<string, int> $stat     Result of lstat().
	 * @return File_Info
	 */
	private function describe( string $relative, array $stat ): File_Info {
		$kind = $stat['mode'] & self::TYPE_MASK;
		if ( self::TYPE_LINK === $kind ) {
			$type = File_Info::LINK;
		} elseif ( self::TYPE_DIRECTORY === $kind ) {
			$type = File_Info::DIRECTORY;
		} else {
			$type = File_Info::FILE;
		}

		return new File_Info( $relative, $stat['size'], $stat['mtime'], $stat['mode'] & 07777, $type );
	}

	/**
	 * Yields the entries of one directory and, when recursive, of its subdirectories.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $real      Real path of the directory.
	 * @param string       $relative  Its path relative to the root.
	 * @param bool         $recursive Whether to descend into subdirectories.
	 * @param string       $label     Name of the walk, for the cap notice.
	 * @param \ArrayObject $budget    Entries seen and whether the walk has stopped, shared across the recursion.
	 * @param \ArrayObject $gaps      What the walk could not see, added to.
	 * @return \Generator<int, File_Info>
	 * @phpstan-param \ArrayObject<string, int|bool> $budget
	 * @phpstan-param \ArrayObject<int, array{kind: string, path: string, dir: string}> $gaps
	 */
	private function entries_of( string $real, string $relative, bool $recursive, string $label, \ArrayObject $budget, \ArrayObject $gaps ): \Generator {
		$names = @scandir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable directory is counted and skipped.
		if ( false === $names || ! $this->still_resolves_to( $real ) ) {
			++$this->unreadable;
			$gaps[] = $this->gap( Walk::UNREADABLE, $relative, $relative );
			return;
		}

		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$path = '' === $relative ? $name : $relative . '/' . $name;
			if ( $this->is_excluded( $path ) ) {
				$gaps[] = $this->gap( Walk::EXCLUDED, $path, $relative );
				continue;
			}

			$full = $real . '/' . $name;
			$stat = @lstat( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An entry that vanished is skipped.
			if ( false === $stat ) {
				++$this->unreadable;
				$gaps[] = $this->gap( Walk::UNREADABLE, $path, $relative );
				continue;
			}

			if ( self::TYPE_LINK === ( $stat['mode'] & self::TYPE_MASK ) ) {
				$target = real_path( $full );
				if ( false === $target || ! $this->contains( $target ) ) {
					++$this->skipped_links;
					$gaps[] = $this->gap( Walk::LINK, $path, $relative );
					continue;
				}
			}

			if ( $budget['seen'] >= $this->max_files ) {
				$budget['stopped']            = true;
				$this->capped_walks[ $label ] = $label;
				$gaps[]                       = $this->gap( Walk::CAPPED, $relative, $relative );
				return;
			}
			++$budget['seen'];

			$info = $this->describe( $path, $stat );
			yield $info;

			if ( $recursive && $info->is_dir() ) {
				foreach ( $this->entries_of( $full, $path, true, $label, $budget, $gaps ) as $child ) {
					yield $child;
				}
				if ( $budget['stopped'] ) {
					return;
				}
			}
		}
	}

	/**
	 * One gap record.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind A Walk gap kind.
	 * @param string $path Path the gap concerns.
	 * @param string $dir  Directory whose listing it leaves incomplete.
	 * @return array{kind: string, path: string, dir: string}
	 */
	private function gap( string $kind, string $path, string $dir ): array {
		return array(
			'kind' => $kind,
			'path' => $path,
			'dir'  => $dir,
		);
	}
}
