<?php
/**
 * Helper functions.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor;

/**
 * Makes control characters in text from a scanned site visible instead of active.
 *
 * Escape sequences in a file name or option value can rewrite what a terminal
 * shows, and Unicode bidirectional controls can make `evil<RLO>gnp.php` read as
 * `evilphp.png`. Every C0 control, every character in Hidden_Characters, and
 * every byte that is not part of valid UTF-8 is rendered as a literal \xNN per
 * byte. Line
 * feeds can be kept for text that is meant to span lines.
 *
 * @since 0.1.0
 *
 * @param string $text          Text that may contain control characters.
 * @param bool   $keep_newlines Leave line feeds as they are.
 * @return string
 */
function esc_cli( string $text, bool $keep_newlines = false ): string {
	$c0      = $keep_newlines ? '[\x00-\x09\x0b-\x1f\x7f]' : '[\x00-\x1f\x7f]';
	$visible = Util\Hidden_Characters::UTF8_PATTERN;
	$utf8    = '[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xec\xee\xef][\x80-\xbf]{2}|\xed[\x80-\x9f][\x80-\xbf]'
		. '|\xf0[\x90-\xbf][\x80-\xbf]{2}|[\xf1-\xf3][\x80-\xbf]{3}|\xf4[\x80-\x8f][\x80-\xbf]{2}';

	// The escaped characters are tried before valid UTF-8, which is kept, so
	// only a byte outside any valid sequence reaches the final branch.
	$escaped = preg_replace_callback(
		'/' . $c0 . '|' . $visible . '|(' . $utf8 . ')|[\x80-\xff]/',
		static function ( array $found ): string {
			if ( isset( $found[1] ) && '' !== $found[1] ) {
				return $found[1];
			}

			return '\x' . implode( '\x', str_split( strtoupper( bin2hex( $found[0] ) ), 2 ) );
		},
		$text
	);

	return null === $escaped ? '' : $escaped;
}

/**
 * Path with the platform's directory separator replaced by a slash, so every
 * path the tool handles is compared and joined the same way.
 *
 * @since 0.1.0
 *
 * @param string $path      Path as the platform writes it.
 * @param string $separator Directory separator of the platform.
 * @return string
 */
function normalise_separators( string $path, string $separator = DIRECTORY_SEPARATOR ): string {
	return '/' === $separator ? $path : str_replace( $separator, '/', $path );
}

/**
 * The canonical form of a path that exists, with slash separators.
 *
 * @since 0.1.0
 *
 * @param string $path Relative or absolute path to resolve.
 * @return string|false False when the path does not exist.
 */
function real_path( string $path ) {
	$real = realpath( $path );

	return false === $real ? false : normalise_separators( $real );
}
