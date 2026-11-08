<?php
/**
 * Header fields of plugin and theme files, read as WordPress reads them.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Util;

/**
 * WordPress takes a header such as `Plugin Name:` from the first 8 KiB of a
 * file, on a line of its own after optional comment marks, and ignores an
 * empty one. This follows get_file_data() and _cleanup_header_comment().
 *
 * @since 0.2.0
 */
final class File_Headers {

	/**
	 * Bytes WordPress reads from the top of a file to find its headers.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const READ_BYTES = 8192;

	/**
	 * Value of one header field. Empty when the field is missing or blank.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The start of the file, at least READ_BYTES long when the file is.
	 * @param string $name Field name without the colon, such as Plugin Name.
	 * @return string
	 */
	public static function value( string $text, string $name ): string {
		$text = str_replace( "\r", "\n", substr( $text, 0, self::READ_BYTES ) );
		if ( 1 !== preg_match( '/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $text, $match ) || '' === $match[1] ) {
			return '';
		}

		return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
	}
}
