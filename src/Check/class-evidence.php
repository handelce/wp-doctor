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
 * Turns lists into the flat, ordered key/value pairs that a Finding carries.
 * Names and paths are stored as values under numbered keys, so a site-chosen
 * string can never become a key.
 *
 * @since 0.1.0
 */
final class Evidence {

	public const MAX_LISTED = 20;

	/**
	 * Numbered path and size pairs, in the order given.
	 *
	 * @since 0.1.0
	 *
	 * @param File_Info[] $files Files to list. Only the first MAX_LISTED appear.
	 * @return array<string, string|int>
	 */
	public static function files( array $files ): array {
		$evidence = array();
		foreach ( array_slice( array_values( $files ), 0, self::MAX_LISTED ) as $index => $file ) {
			$rank                             = $index + 1;
			$evidence[ "file_$rank" ]         = $file->path();
			$evidence[ "file_{$rank}_bytes" ] = $file->size();
		}

		return $evidence;
	}

	/**
	 * Numbered names, in the order given.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $label Key prefix, such as plugin.
	 * @param string[] $names Names to list, such as plugin slugs. Only the first MAX_LISTED appear.
	 * @return array<string, string>
	 */
	public static function names( string $label, array $names ): array {
		$evidence = array();
		foreach ( array_slice( array_values( $names ), 0, self::MAX_LISTED ) as $index => $name ) {
			$evidence[ $label . '_' . ( $index + 1 ) ] = (string) $name;
		}

		return $evidence;
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
