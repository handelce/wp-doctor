<?php
/**
 * No persistent object cache drop-in.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Without a persistent object cache WordPress repeats the same database
 * queries on every request.
 *
 * @since 0.1.0
 */
final class Object_Cache_Missing_Check implements Check {

	/**
	 * Report id for the object cache notice.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.objectcache.missing';
	}

	/**
	 * Performance, because the cost is repeated queries.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Looks for the object-cache.php drop-in.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the wp-content path and the file scanner.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->content_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$path     = $context->content_dir() . '/object-cache.php';
		$presence = $context->files()->presence( $path );
		if ( File_Scanner::PRESENT !== $presence && File_Scanner::ABSENT !== $presence && File_Scanner::BROKEN_LINK !== $presence ) {
			return Result::skipped( "$path was not examined: it is $presence" );
		}
		$dropin = $context->files()->stat( $path );
		if ( null !== $dropin && ( $dropin->is_file() || $dropin->is_link() ) ) {
			return Result::pass();
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'No persistent object cache is installed',
				array(
					'expected_file' => $path,
					'found'         => File_Scanner::BROKEN_LINK === $presence ? 'broken symlink' : false,
				),
				'If the host offers Redis or Memcached, install an object cache plugin that provides wp-content/object-cache.php. '
				. 'Small sites with little traffic may not need one.'
			)
		);
	}
}
