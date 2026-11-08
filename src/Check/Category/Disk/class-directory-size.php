<?php
/**
 * Size of a directory and of each entry at its top.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Disk;

use WP_Doctor\Fs\File_Scanner;

/**
 * Adds up the sizes of the regular files under a directory, as the scanner
 * reports them, and keeps a total for each entry at its top. Symlinks are
 * not followed, so their targets are not counted.
 *
 * @since 0.2.0
 */
final class Directory_Size {

	/**
	 * Directory relative to the site root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Bytes in regular files under the directory.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $bytes = 0;

	/**
	 * Regular files under the directory.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $files = 0;

	/**
	 * Bytes by name of the entry at the top they are under.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int>
	 */
	private $children = array();

	/**
	 * What the walk could not see. Null when it saw everything.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $gap;

	/**
	 * Walks the directory to the end.
	 *
	 * @since 0.2.0
	 *
	 * @param File_Scanner $scanner Scanner over the site root.
	 * @param string       $path    Directory relative to the root.
	 * @throws \WP_Doctor\Fs\Path_Unavailable_Exception When the directory cannot be walked.
	 */
	public function __construct( File_Scanner $scanner, string $path ) {
		$this->path = $path;
		$walk       = $scanner->walk( $path );
		$prefix     = strlen( $path ) + 1;
		foreach ( $walk as $entry ) {
			if ( ! $entry->is_file() ) {
				continue;
			}
			$top                    = explode( '/', substr( $entry->path(), $prefix ), 2 )[0];
			$this->bytes           += $entry->size();
			$this->children[ $top ] = ( $this->children[ $top ] ?? 0 ) + $entry->size();
			++$this->files;
		}
		$gaps  = array_filter( array( $walk->listing_gap() ) );
		$links = $walk->skipped_links();
		if ( array() !== $links ) {
			sort( $links, SORT_STRING );
			$gaps[] = count( $links ) . ' symlink(s) leading outside the site root or nowhere were not followed ('
				. implode( ', ', array_slice( $links, 0, 3 ) ) . ( count( $links ) > 3 ? ', ...' : '' ) . ')';
		}
		$this->gap = array() === $gaps ? null : implode( '; ', $gaps );
	}

	/**
	 * Bytes in regular files under the directory.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function bytes(): int {
		return $this->bytes;
	}

	/**
	 * What the walk could not see, so that the total is a lower bound. Null when it saw everything.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null
	 */
	public function gap(): ?string {
		return $this->gap;
	}

	/**
	 * Path, bytes and file count, and what was not counted when the walk missed something.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string|int>
	 */
	public function summary(): array {
		$summary = array(
			'path'  => $this->path,
			'bytes' => $this->bytes,
			'files' => $this->files,
		);
		if ( null !== $this->gap ) {
			$summary['not_counted'] = $this->gap;
		}

		return $summary;
	}

	/**
	 * The largest entries at the top of the directory, largest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $listed Number of entries to list.
	 * @return array<int, array{path: string, bytes: int}>
	 */
	public function largest( int $listed ): array {
		$children = $this->children;
		uksort(
			$children,
			static function ( $a, $b ) use ( $children ): int {
				return array( $children[ $b ], (string) $a ) <=> array( $children[ $a ], (string) $b );
			}
		);
		$largest = array();
		foreach ( array_slice( $children, 0, $listed, true ) as $name => $bytes ) {
			$largest[] = array(
				'path'  => $this->path . '/' . $name,
				'bytes' => $bytes,
			);
		}

		return $largest;
	}
}
