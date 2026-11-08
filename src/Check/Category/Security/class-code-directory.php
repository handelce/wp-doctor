<?php
/**
 * The plugins or themes directory, walked once for the checks that look for
 * code no plugin or theme accounts for.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\File_Info;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Util\File_Headers;

/**
 * Holds every entry under the directory, keyed by its path inside it, and
 * reads file headers as WordPress does. What could not be seen is collected
 * in gaps(): the walk's own gaps, symlinks that were not followed, and
 * headers that could not be read.
 *
 * @since 0.2.0
 */
final class Code_Directory {

	/**
	 * The empty index.php that WordPress and plugins write to stop directory listings.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INDEX_STUB = '/\A(?:\xEF\xBB\xBF)?<\?php\s*(?:(?:\/\/|\/\*)\s*Silence is golden\.?\s*(?:\*\/)?\s*)?(?:\?>)?\s*\z/i';

	private const STUB_MAX_BYTES = 512;

	/**
	 * Reads the files.
	 *
	 * @since 0.2.0
	 *
	 * @var File_Scanner
	 */
	private $files;

	/**
	 * Path of the directory relative to the site root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Every entry under the directory, keyed by its path inside it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, File_Info>
	 */
	private $entries = array();

	/**
	 * What the walk could not see. Null when it saw everything.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $walk_gap;

	/**
	 * Paths inside the directory of symlinks that were not followed.
	 *
	 * @since 0.2.0
	 *
	 * @var string[]
	 */
	private $links = array();

	/**
	 * Paths inside the directory of files whose header could not be read.
	 *
	 * @since 0.2.0
	 *
	 * @var string[]
	 */
	private $unreadable = array();

	/**
	 * Walks the directory to the end.
	 *
	 * @since 0.2.0
	 *
	 * @param File_Scanner $files     Scanner over the site root.
	 * @param string       $directory Plugins or themes directory, relative to the root.
	 * @throws \WP_Doctor\Fs\Path_Unavailable_Exception When the directory cannot be walked.
	 */
	public function __construct( File_Scanner $files, string $directory ) {
		$this->files     = $files;
		$this->directory = $directory;
		$walk            = $files->walk( $directory );
		$prefix          = strlen( $directory ) + 1;
		foreach ( $walk as $entry ) {
			$inside                   = substr( $entry->path(), $prefix );
			$this->entries[ $inside ] = $entry;
			if ( $entry->is_link() ) {
				$this->links[] = $inside;
			}
		}
		foreach ( $walk->skipped_links() as $path ) {
			$this->links[] = substr( $path, $prefix );
		}
		$this->walk_gap = $walk->incomplete_reason();
	}

	/**
	 * Path of the directory relative to the site root.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->directory;
	}

	/**
	 * Entries directly inside $relative, keyed by name. The whole directory when $relative is empty.
	 *
	 * @since 0.2.0
	 *
	 * @param string $relative Folder inside the directory.
	 * @return array<string, File_Info>
	 */
	public function children( string $relative = '' ): array {
		$prefix   = '' === $relative ? '' : $relative . '/';
		$children = array();
		foreach ( $this->entries as $inside => $entry ) {
			if ( '' !== $prefix && 0 !== strpos( $inside, $prefix ) ) {
				continue;
			}
			$name = substr( $inside, strlen( $prefix ) );
			if ( '' !== $name && false === strpos( $name, '/' ) ) {
				$children[ $name ] = $entry;
			}
		}
		ksort( $children, SORT_STRING );

		return $children;
	}

	/**
	 * Whether a regular file exists at $relative.
	 *
	 * @since 0.2.0
	 *
	 * @param string $relative Path inside the directory.
	 * @return bool
	 */
	public function has_file( string $relative ): bool {
		return isset( $this->entries[ $relative ] ) && $this->entries[ $relative ]->is_file();
	}

	/**
	 * PHP files under the folder $relative, at any depth, leaving out the
	 * folders in $except.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $relative Folder inside the directory.
	 * @param string[] $except   Folders inside the directory whose files are not counted.
	 * @return File_Info[]
	 */
	public function php_under( string $relative, array $except = array() ): array {
		$found = array();
		foreach ( $this->entries as $inside => $entry ) {
			if ( 0 !== strpos( $inside, $relative . '/' ) || ! self::is_php( $entry ) ) {
				continue;
			}
			foreach ( $except as $folder ) {
				if ( 0 === strpos( $inside, $folder . '/' ) ) {
					continue 2;
				}
			}
			$found[] = $entry;
		}

		return $found;
	}

	/**
	 * Value of a header field in the file at $relative, as WordPress reads it.
	 * Empty when the field is missing. Null when the file could not be read,
	 * which is recorded as a gap.
	 *
	 * @since 0.2.0
	 *
	 * @param string $relative Path inside the directory.
	 * @param string $name     Field name, such as Plugin Name.
	 * @return string|null
	 */
	public function header( string $relative, string $name ): ?string {
		$text = $this->files->read( $this->directory . '/' . $relative, File_Headers::READ_BYTES );
		if ( null === $text ) {
			$this->unreadable[] = $relative;

			return null;
		}

		return File_Headers::value( $text, $name );
	}

	/**
	 * Whether a file is the empty index.php stub.
	 *
	 * @since 0.2.0
	 *
	 * @param File_Info $entry File in the directory.
	 * @return bool
	 */
	public function is_index_stub( File_Info $entry ): bool {
		return 'index.php' === $entry->name()
			&& $entry->size() <= self::STUB_MAX_BYTES
			&& 1 === preg_match( self::INDEX_STUB, (string) $this->files->read( $entry->path(), self::STUB_MAX_BYTES ) );
	}

	/**
	 * Reasons some of the directory was not examined. Empty when all of it was.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function gaps(): array {
		$gaps = array();
		if ( null !== $this->walk_gap ) {
			$gaps[] = $this->walk_gap;
		}
		$links = array_values( array_unique( $this->links ) );
		if ( array() !== $links ) {
			$gaps[] = count( $links ) . ' symlink(s) in ' . $this->directory . ' were not followed (' . self::sample( $links ) . ')';
		}
		if ( array() !== $this->unreadable ) {
			$gaps[] = 'the headers of ' . count( $this->unreadable ) . ' file(s) could not be read (' . self::sample( $this->unreadable ) . ')';
		}

		return $gaps;
	}

	/**
	 * Evidence for the flagged entries: the counts, then each entry with the
	 * reason, its PHP files and when the newest of them changed.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $flagged Reason by path inside the directory.
	 * @return array<string, mixed>
	 */
	public function evidence( array $flagged ): array {
		ksort( $flagged, SORT_STRING );
		$gaps     = $this->gaps();
		$evidence = array(
			'directory'       => $this->directory,
			'entries_listed'  => count( $this->children() ),
			'flagged'         => count( $flagged ),
			'scan_incomplete' => array() !== $gaps,
		);
		if ( array() !== $gaps ) {
			$evidence['not_examined'] = implode( '; ', $gaps );
		}
		$rank = 0;
		foreach ( array_slice( $flagged, 0, Evidence::MAX_LISTED, true ) as $inside => $reason ) {
			++$rank;
			$inside = (string) $inside;
			$php    = isset( $this->entries[ $inside ] ) && $this->entries[ $inside ]->is_file() ? array( $this->entries[ $inside ] ) : $this->php_under( $inside );
			$newest = 0;
			foreach ( $php as $file ) {
				$newest = max( $newest, $file->mtime() );
			}
			$evidence[ "entry_$rank" ]              = $this->directory . '/' . $inside;
			$evidence[ "entry_{$rank}_reason" ]     = $reason;
			$evidence[ "entry_{$rank}_php_files" ]  = count( $php );
			$evidence[ "entry_{$rank}_php_newest" ] = gmdate( 'Y-m-d H:i', $newest ) . ' UTC';
		}

		return $evidence;
	}

	/**
	 * Whether the entry is a file with an extension PHP is commonly run for.
	 *
	 * @since 0.2.0
	 *
	 * @param File_Info $entry Entry from the walk.
	 * @return bool
	 */
	public static function is_php( File_Info $entry ): bool {
		return $entry->is_file() && in_array( $entry->extension(), Php_Sources::EXTENSIONS, true );
	}

	/**
	 * The first few paths, for a message.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $paths Paths inside the directory.
	 * @return string
	 */
	private static function sample( array $paths ): string {
		sort( $paths, SORT_STRING );

		return implode( ', ', array_slice( $paths, 0, 5 ) ) . ( count( $paths ) > 5 ? ', ...' : '' );
	}
}
