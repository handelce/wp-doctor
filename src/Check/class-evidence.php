<?php
/**
 * Evidence lists that checks share.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

use WP_Doctor\Fs\File_Info;

/**
 * Turns files and names into the lists a Finding carries as evidence, capped
 * at MAX_LISTED items. Names and paths are always values, never keys, so a
 * site-chosen string can never become a key.
 *
 * @since 0.1.0
 */
final class Evidence {

	public const MAX_LISTED = 20;

	/**
	 * Path and size of each file, in the order given.
	 *
	 * @since 0.1.0
	 *
	 * @param File_Info[] $files Files to list. Only the first MAX_LISTED appear.
	 * @return array<int, array{path: string, bytes: int}>
	 */
	public static function files( array $files ): array {
		$listed = array();
		foreach ( array_slice( array_values( $files ), 0, self::MAX_LISTED ) as $file ) {
			$listed[] = array(
				'path'  => $file->path(),
				'bytes' => $file->size(),
			);
		}

		return $listed;
	}

	/**
	 * The first MAX_LISTED names, in the order given.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $names Names to list, such as plugin slugs.
	 * @return string[]
	 */
	public static function listed( array $names ): array {
		return array_map( 'strval', array_slice( array_values( $names ), 0, self::MAX_LISTED ) );
	}

	/**
	 * Sorts files by path.
	 *
	 * @since 0.1.0
	 *
	 * @param File_Info[] $files Files to sort.
	 * @return File_Info[]
	 */
	public static function by_path( array $files ): array {
		usort(
			$files,
			static function ( File_Info $a, File_Info $b ): int {
				return strcmp( $a->path(), $b->path() );
			}
		);

		return $files;
	}

	/**
	 * Sorts files by size, largest first, then by path.
	 *
	 * @since 0.1.0
	 *
	 * @param File_Info[] $files Files to sort.
	 * @return File_Info[]
	 */
	public static function by_size( array $files ): array {
		usort(
			$files,
			static function ( File_Info $a, File_Info $b ): int {
				$by_size = $b->size() <=> $a->size();

				return 0 !== $by_size ? $by_size : strcmp( $a->path(), $b->path() );
			}
		);

		return $files;
	}
}
