<?php
/**
 * One entry found by File_Scanner.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Fs;

/**
 * Immutable description of a file, directory or symlink.
 *
 * @since 0.1.0
 */
final class File_Info {

	public const FILE      = 'file';
	public const DIRECTORY = 'dir';
	public const LINK      = 'link';

	/**
	 * Path relative to the scanner root.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Size in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $size;

	/**
	 * Modification time as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $mtime;

	/**
	 * Inode change time as a Unix timestamp: when the file was last written or
	 * had its metadata changed. Creation time on Windows.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $ctime;

	/**
	 * Permission bits, for example 0644.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $mode;

	/**
	 * One of the type constants.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Creates an entry. The type must be one of the type constants.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path  Path relative to the scanner root.
	 * @param int    $size  Size in bytes.
	 * @param int    $mtime Modification time as a Unix timestamp.
	 * @param int    $ctime Inode change time as a Unix timestamp, or creation time on Windows.
	 * @param int    $mode  Permission bits without the file type, for example 0644.
	 * @param string $type  FILE, DIRECTORY or LINK.
	 * @throws \InvalidArgumentException When the type is unknown.
	 */
	public function __construct( string $path, int $size, int $mtime, int $ctime, int $mode, string $type ) {
		if ( ! in_array( $type, array( self::FILE, self::DIRECTORY, self::LINK ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown entry type' );
		}

		$this->path  = $path;
		$this->size  = $size;
		$this->mtime = $mtime;
		$this->ctime = $ctime;
		$this->mode  = $mode;
		$this->type  = $type;
	}

	/**
	 * Path relative to the scanner root.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Final path segment.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function name(): string {
		return basename( $this->path );
	}

	/**
	 * Lowercased extension without the dot. Empty when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function extension(): string {
		return strtolower( pathinfo( $this->path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Size in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function size(): int {
		return $this->size;
	}

	/**
	 * Modification time as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function mtime(): int {
		return $this->mtime;
	}

	/**
	 * Inode change time as a Unix timestamp. Creation time on Windows.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function ctime(): int {
		return $this->ctime;
	}

	/**
	 * Permission bits.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function mode(): int {
		return $this->mode;
	}

	/**
	 * Whether this is a regular file.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_file(): bool {
		return self::FILE === $this->type;
	}

	/**
	 * Whether this is a directory.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_dir(): bool {
		return self::DIRECTORY === $this->type;
	}

	/**
	 * Whether this is a symlink that stays inside the scanner root.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_link(): bool {
		return self::LINK === $this->type;
	}
}
