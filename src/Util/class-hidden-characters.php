<?php
/**
 * Characters that are invisible or reorder text on screen.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Util;

/**
 * One list of the characters that text from a site must never show raw. Each
 * is valid UTF-8 but invisible or able to reorder what follows, so a planted
 * name can pass for another: C1 controls, the soft hyphen, the Arabic letter
 * mark, zero-width spaces, joiners and directional marks, the line and
 * paragraph separators, bidi embeddings, overrides and isolates, the word
 * joiner and invisible operators, and the byte order mark. esc_cli() writes
 * them as \xNN bytes and the JSON formatter as \u escapes.
 *
 * @since 0.1.0
 */
final class Hidden_Characters {

	/**
	 * The UTF-8 forms as a regular expression alternation, without delimiters.
	 *
	 * @since 0.1.0
	 */
	public const UTF8_PATTERN = '\xc2[\x80-\x9f\xad]|\xd8\x9c|\xe2\x80[\x8b-\x8f\xa8-\xae]|\xe2\x81[\xa0-\xa4\xa6-\xa9]|\xef\xbb\xbf';
}
