<?php
/**
 * Builds and removes throwaway directory trees for filesystem tests.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use PHPUnit\Framework\SkippedTestError;

use function WP_Doctor\esc_cli;

final class Temp_Tree {

	/**
	 * Absolute path of the tree's root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Creates an empty root under the system temp directory.
	 */
	public function __construct() {
		$base       = (string) realpath( sys_get_temp_dir() );
		$this->root = $base . '/wpd-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/site', 0777, true );
	}

	/**
	 * The directory a scanner should be rooted at.
	 *
	 * @return string
	 */
	public function site(): string {
		return $this->root . '/site';
	}

	/**
	 * A directory next to the site, outside any scanner root.
	 *
	 * @return string
	 */
	public function outside(): string {
		$dir = $this->root . '/outside';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir );
		}

		return $dir;
	}

	/**
	 * Writes a file under the site, creating directories.
	 *
	 * @param string $relative Path relative to the site root.
	 * @param string $content  File content.
	 * @return string Absolute path.
	 */
	public function file( string $relative, string $content = 'x' ): string {
		$path = $this->site() . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content );

		return $path;
	}

	/**
	 * Creates a sparse file of the given size.
	 *
	 * @param string $relative Path relative to the site root.
	 * @param int    $size     Size in bytes.
	 * @return string Absolute path.
	 */
	public function sparse_file( string $relative, int $size ): string {
		$path   = $this->file( $relative, '' );
		$handle = fopen( $path, 'wb' );
		ftruncate( $handle, $size );
		fclose( $handle );

		return $path;
	}

	/**
	 * Sets ordinary permissions: 0755 for directories and 0644 for files, links untouched.
	 */
	public function normalise_permissions(): void {
		$this->set_modes( $this->site() );
	}

	/**
	 * Applies ordinary permissions below a path.
	 *
	 * @param string $path Directory whose contents are reset.
	 */
	private function set_modes( string $path ): void {
		chmod( $path, 0755 );
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' === $name || '..' === $name || is_link( $path . '/' . $name ) ) {
				continue;
			}
			if ( is_dir( $path . '/' . $name ) ) {
				$this->set_modes( $path . '/' . $name );
			} else {
				chmod( $path . '/' . $name, 0644 );
			}
		}
	}

	/**
	 * Creates a symlink under the site.
	 *
	 * @param string $relative Link path relative to the site root.
	 * @param string $target   Absolute link target.
	 */
	public function link( string $relative, string $target ): void {
		$path = $this->site() . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		self::symlink( $target, $path );
	}

	/**
	 * Creates a symlink, or skips the running test on Windows when the link
	 * cannot be made there: Windows needs the right to create links, and a
	 * target that exists.
	 *
	 * @param string $target Link target.
	 * @param string $path   Absolute link path.
	 * @throws SkippedTestError On Windows, when the link is refused.
	 * @throws \RuntimeException Elsewhere, when the link is refused.
	 */
	public static function symlink( string $target, string $path ): void {
		if ( @symlink( $target, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A refused link is reported by the exception below.
			return;
		}
		if ( 'Windows' === PHP_OS_FAMILY ) {
			throw new SkippedTestError( esc_cli( "Windows refused to create the symlink $path." ) );
		}
		throw new \RuntimeException( esc_cli( "Could not create the symlink $path" ) );
	}

	/**
	 * Removes the whole tree.
	 */
	public function remove(): void {
		$this->delete( $this->root );
	}

	/**
	 * Removes one path inside the site, recursively.
	 *
	 * @param string $relative Path relative to the site root.
	 */
	public function remove_path( string $relative ): void {
		$this->delete( $this->site() . '/' . $relative );
	}

	/**
	 * Deletes a path recursively without following symlinks.
	 *
	 * @param string $path Absolute path.
	 */
	private function delete( string $path ): void {
		if ( is_link( $path ) && is_dir( $path ) && 'Windows' === PHP_OS_FAMILY ) {
			rmdir( $path );
			return;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		chmod( $path, 0777 );
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$this->delete( $path . '/' . $name );
			}
		}
		rmdir( $path );
	}
}
