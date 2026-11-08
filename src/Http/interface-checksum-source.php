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

	/**
	 * Checksums of the files in one version of a plugin hosted on wordpress.org.
	 *
	 * @since 0.3.0
	 *
	 * @param string $slug    Plugin slug, which is its folder name.
	 * @param string $version Plugin version from its header.
	 * @return array<string, array{sha256: string[], md5: string[]}> Lowercase hashes by path inside the plugin folder. A file may have more than one accepted hash.
	 * @throws Not_Published_Exception When wordpress.org has no checksums for that plugin or version.
	 * @throws Checksum_Unavailable_Exception When the checksums cannot be obtained.
	 */
	public function plugin_checksums( string $slug, string $version ): array;

	/**
	 * Every WordPress release wordpress.org lists, oldest first.
	 *
	 * @since 0.3.0
	 *
	 * @return string[] Versions, such as 6.5.2.
	 * @throws Checksum_Unavailable_Exception When the list cannot be obtained.
	 */
	public function core_releases(): array;
}
