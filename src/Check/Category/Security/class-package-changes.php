<?php
/**
 * Files grouped into the packages that install them, with their change times.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Fs\File_Info;

/**
 * The packages are WordPress core (the top of the core directory, wp-admin
 * and wp-includes), each entry at the top of the plugins, must-use plugins
 * and themes directories, and for any other file its own directory. A file is
 * written apart from its package when fewer than half of the package's other
 * files were written within an hour of it.
 *
 * @since 0.2.0
 */
final class Package_Changes {

	public const WINDOW_SECONDS = 3600;

	private const CORE_PACKAGE     = 'WordPress core';
	private const CORE_DIRECTORIES = array( 'wp-admin', 'wp-includes' );

	/**
	 * Label prefix by directory, with a trailing slash, whose top entries are packages.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private $tops;

	/**
	 * Core directory relative to the root. Empty when core is in the root.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private $core;

	/**
	 * Change times of every file added, by package.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, int[]>
	 */
	private $times = array();

	/**
	 * Files to judge, each with its package.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, array{0: string, 1: File_Info}>
	 */
	private $candidates = array();

	/**
	 * Takes the directories that decide the packages.
	 *
	 * @since 0.2.0
	 *
	 * @param string $plugins Plugins directory relative to the root.
	 * @param string $content Content directory relative to the root.
	 * @param string $core    Core directory relative to the root. Empty when core is in the root.
	 */
	public function __construct( string $plugins, string $content, string $core ) {
		$this->tops = array(
			$plugins . '/'            => 'plugin ',
			$content . '/mu-plugins/' => 'must-use plugin ',
			$content . '/themes/'     => 'theme ',
		);
		$this->core = $core;
	}

	/**
	 * Counts a file in its package, and judges it later when $judge is set.
	 *
	 * @since 0.2.0
	 *
	 * @param File_Info $file  File from the walk.
	 * @param bool      $judge Whether to report the file when it was written apart from its package.
	 * @return void
	 */
	public function add( File_Info $file, bool $judge ): void {
		$package                   = $this->package( $file->path() );
		$this->times[ $package ][] = $file->ctime();
		if ( $judge ) {
			$this->candidates[] = array( $package, $file );
		}
	}

	/**
	 * Number of files to judge.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function judged(): int {
		return count( $this->candidates );
	}

	/**
	 * Whether every file to judge was written within one window, as after a
	 * fresh install or a copy, so that none can stand out.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function written_together(): bool {
		$times = array();
		foreach ( $this->candidates as $candidate ) {
			$times[] = $candidate[1]->ctime();
		}

		return array() !== $times && max( $times ) - min( $times ) <= self::WINDOW_SECONDS;
	}

	/**
	 * Files to judge written at or after $since apart from their package, newest first.
	 *
	 * @since 0.2.0
	 *
	 * @param int $since Unix time from which a change is recent.
	 * @return array<int, array{file: File_Info, package: string, package_files: int, written_with: int}>
	 */
	public function written_apart( int $since ): array {
		$sorted = array();
		foreach ( $this->times as $package => $times ) {
			sort( $times );
			$sorted[ $package ] = $times;
		}
		$found = array();
		foreach ( $this->candidates as $candidate ) {
			list( $package, $file ) = $candidate;
			$time                   = $file->ctime();
			if ( $time < $since ) {
				continue;
			}
			$times  = $sorted[ $package ];
			$others = count( $times ) - 1;
			$with   = self::count_below( $times, $time + self::WINDOW_SECONDS + 1 ) - self::count_below( $times, $time - self::WINDOW_SECONDS ) - 1;
			if ( 0 === $others || $with * 2 < $others ) {
				$found[] = array(
					'file'          => $file,
					'package'       => $package,
					'package_files' => $others + 1,
					'written_with'  => $with,
				);
			}
		}
		usort(
			$found,
			static function ( array $a, array $b ): int {
				return array( $b['file']->ctime(), $a['file']->path() ) <=> array( $a['file']->ctime(), $b['file']->path() );
			}
		);

		return $found;
	}

	/**
	 * Package a file belongs to, as a label for the evidence.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path Path relative to the root.
	 * @return string
	 */
	public function package( string $path ): string {
		foreach ( $this->tops as $directory => $label ) {
			if ( 0 === strpos( $path, $directory ) ) {
				return $label . explode( '/', substr( $path, strlen( $directory ) ), 2 )[0];
			}
		}
		$inside = null;
		if ( '' === $this->core ) {
			$inside = $path;
		} elseif ( 0 === strpos( $path, $this->core . '/' ) ) {
			$inside = substr( $path, strlen( $this->core ) + 1 );
		}
		if ( null !== $inside && ( false === strpos( $inside, '/' ) || in_array( explode( '/', $inside, 2 )[0], self::CORE_DIRECTORIES, true ) ) ) {
			return self::CORE_PACKAGE;
		}
		$directory = dirname( $path );

		return '.' === $directory ? 'the site root' : "directory $directory";
	}

	/**
	 * Number of values below $limit in a sorted list.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $sorted Values in ascending order.
	 * @param int   $limit  Exclusive upper bound.
	 * @return int
	 */
	private static function count_below( array $sorted, int $limit ): int {
		$low  = 0;
		$high = count( $sorted );
		while ( $low < $high ) {
			$middle = intdiv( $low + $high, 2 );
			if ( $sorted[ $middle ] < $limit ) {
				$low = $middle + 1;
			} else {
				$high = $middle;
			}
		}

		return $low;
	}
}
