<?php
/**
 * Where official core file checksums come from.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

/**
 * Implemented by the wordpress.org client and by test doubles.
 *
 * @since 0.1.0
 */
interface Checksum_Source {

	/**
	 * MD5 checksums of the files in a WordPress release.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version WordPress version, such as 6.5.2.
	 * @param string $locale  Locale, such as en_US.
	 * @return array<string, string> Lowercase MD5 by path relative to the site root.
	 * @throws Checksum_Unavailable_Exception When the checksums cannot be obtained.
	 */
	public function core_checksums( string $version, string $locale ): array;
}
