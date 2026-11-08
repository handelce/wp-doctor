<?php
/**
 * Page and file caches that have grown large.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Disk;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Caching plugins write pages and files under the content directory and are
 * meant to prune them. A cache that is never pruned fills the disk and the
 * backups. The folders read are `cache` (WP Super Cache, W3 Total Cache and
 * others), `litespeed` (LiteSpeed Cache) and `wpo-cache` (WP-Optimize).
 *
 * @since 0.2.0
 */
final class Cache_Size_Check implements Check {

	private const FOLDERS = array( 'cache', 'litespeed', 'wpo-cache' );
	private const LISTED  = 5;

	/**
	 * Id that keys the cache size threshold.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'disk.cache.size';
	}

	/**
	 * Grouped under disk.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'disk';
	}

	/**
	 * Measures each cache folder that exists.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the content directory, the scanner and the threshold.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->content_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		$files         = $context->files();
		$warning_above = $context->thresholds()->warning( $this->id() );
		$large         = array();
		$gaps          = array();
		foreach ( self::FOLDERS as $folder ) {
			$path     = $context->content_dir() . '/' . $folder;
			$presence = $files->presence( $path );
			if ( File_Scanner::ABSENT === $presence ) {
				continue;
			}
			$info = $files->stat( $path );
			if ( null === $info || ! $info->is_dir() ) {
				$what   = null === $info ? $presence : ( $info->is_link() ? 'a symlink, whose target was not followed' : 'not a directory' );
				$gaps[] = "$path was not measured: it is $what";
				continue;
			}
			try {
				$size = new Directory_Size( $files, $path );
			} catch ( Path_Unavailable_Exception $e ) {
				$gaps[] = $e->getMessage();
				continue;
			}
			if ( $size->bytes() > $warning_above ) {
				$large[] = $size;
			} elseif ( null !== $size->gap() ) {
				$gaps[] = "$path: " . $size->gap();
			}
		}

		if ( array() === $large ) {
			return array() === $gaps ? Result::pass() : Result::skipped( 'No cache folder measured was above the threshold, but ' . implode( '; ', $gaps ) );
		}

		$evidence            = array(
			'warning_above_bytes' => $warning_above,
			'threshold_basis'     => $context->thresholds()->basis( $this->id() ),
		);
		$evidence['caches']  = array();
		$evidence['largest'] = array();
		foreach ( $large as $size ) {
			$evidence['caches'][] = $size->summary();
			$evidence['largest']  = array_merge( $evidence['largest'], $size->largest( self::LISTED ) );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'A cache folder is very large',
				$evidence,
				'Clear the cache from the caching plugin\'s settings, which rebuilds it as pages are visited, and set a cache '
				. 'lifetime or garbage collection there so it is pruned. If the plugin is no longer installed, delete the folder.'
			)
		);
	}
}
