<?php
/**
 * Lines in server configuration files that make a directory run code.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

/**
 * Reads an .htaccess, .user.ini or php.ini as text and returns the lines that
 * make files run as PHP or add code to every request: an Apache handler or
 * type mapped to PHP, and auto_prepend_file or auto_append_file. Rules that
 * stop PHP running, such as `php_flag engine off`, `SetHandler none` or
 * `AddType text/plain .php`, are not returned.
 *
 * @since 0.1.0
 */
final class Server_Config_Directives {

	public const MAX_LINES = 5;

	private const PHP_HANDLER = '/(php|x-httpd|lsapi|fcgid|cgi-script|proxy:fcgi)/i';

	/**
	 * Lines that make files run as PHP or add code to every request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    File name, such as .htaccess or .user.ini.
	 * @param string $content File content.
	 * @return string[] At most MAX_LINES lines, trimmed.
	 */
	public static function executing_lines( string $name, string $content ): array {
		$apache = '.htaccess' === strtolower( $name );
		$found  = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === $line[0] || ';' === $line[0] ) {
				continue;
			}
			if ( ( $apache && self::is_apache_execution( $line ) ) || self::is_prepend( $line, $apache ) ) {
				$found[] = $line;
				if ( count( $found ) >= self::MAX_LINES ) {
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Whether an Apache line maps a handler or type that runs PHP.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line Trimmed line that is not a comment.
	 * @return bool
	 */
	private static function is_apache_execution( string $line ): bool {
		if ( 1 === preg_match( '/^php_(admin_)?flag\s+engine\s+(on|1|true)\b/i', $line ) ) {
			return true;
		}
		if ( 1 !== preg_match( '/^(AddType|AddHandler|SetHandler|ForceType|Action)\s+("?)([^\s"]+)\2/i', $line, $match ) ) {
			return false;
		}

		return 1 === preg_match( self::PHP_HANDLER, $match[3] );
	}

	/**
	 * Whether a line sets auto_prepend_file or auto_append_file to a file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line   Trimmed line that is not a comment.
	 * @param bool   $apache Whether the line is from an .htaccess, where the setting follows php_value.
	 * @return bool
	 */
	private static function is_prepend( string $line, bool $apache ): bool {
		$pattern = $apache
			? '/^php_(admin_)?value\s+auto_(prepend|append)_file\s+("?)([^"\s]*)\3/i'
			: '/^auto_(prepend|append)_file\s*=\s*("?)([^"\s;]*)\2/i';
		if ( 1 !== preg_match( $pattern, $line, $match ) ) {
			return false;
		}
		$value = strtolower( (string) end( $match ) );

		return '' !== $value && 'none' !== $value;
	}
}
