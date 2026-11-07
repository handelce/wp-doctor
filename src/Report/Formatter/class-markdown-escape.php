<?php
/**
 * Makes site-derived text inert in Markdown.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report\Formatter;

use function WP_Doctor\esc_cli;

/**
 * File names, option names and messages come from the scanned site and may be
 * crafted to inject links, HTML or formatting into a ticket. In prose the
 * characters that start inline markup or HTML are backslash-escaped, and so is
 * a list, heading or quote marker at the start of the text; CommonMark renders
 * each escaped character literally. Values go in code spans whose fence is
 * longer than any backtick run inside them. Control characters are shown by
 * esc_cli().
 *
 * @since 0.1.0
 */
final class Markdown_Escape {

	/**
	 * What GitHub turns into a link without any markup: a URL, a www. host or
	 * an email address. Such text is put in a code span, where it stays
	 * readable but is not clickable.
	 *
	 * @since 0.1.0
	 */
	private const BARE_LINK = '~((?:https?|ftp)://[^\s<>()\[\]"\'`]+|www\.[^\s<>()\[\]"\'`]+|[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+)~i';

	/**
	 * Text for the middle of a line, such as after a heading's code span.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Untrusted text.
	 * @return string
	 */
	public static function text( string $text ): string {
		$parts = preg_split( self::BARE_LINK, self::one_line( $text ), -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return '';
		}
		$out = '';
		foreach ( $parts as $index => $part ) {
			if ( 1 === $index % 2 ) {
				$out .= self::fenced( $part );
				continue;
			}
			$escaped = preg_replace( '/[\\\\`*_\[\]<>|~&]/', '\\\\$0', $part );
			$out    .= null === $escaped ? '' : $escaped;
		}

		return $out;
	}

	/**
	 * Text that starts a line or a list item, where a leading marker would
	 * otherwise begin a heading, a quote or a nested list.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Untrusted text.
	 * @return string
	 */
	public static function line_start( string $text ): string {
		$leading = preg_replace( array( '/^(\s*)([-+*#>=])/', '/^(\s*\d+)([.)])/' ), '$1\\\\$2', self::text( $text ) );

		return null === $leading ? '' : $leading;
	}

	/**
	 * Text as an inline code span.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Untrusted text.
	 * @return string
	 */
	public static function code( string $text ): string {
		return self::fenced( self::one_line( $text ) );
	}

	/**
	 * A line that is already on one line with control characters shown, as a code span.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line Text from one_line().
	 * @return string
	 */
	private static function fenced( string $line ): string {
		if ( '' === $line ) {
			return '` `';
		}
		$fence = str_repeat( '`', self::longest_backtick_run( $line ) + 1 );
		$pad   = '`' === $line[0] || '`' === substr( $line, -1 ) || ' ' === $line[0] ? ' ' : '';

		return $fence . $pad . $line . $pad . $fence;
	}

	/**
	 * Text as a fenced code block, newlines kept.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Untrusted text.
	 * @return string[] Lines, fences included.
	 */
	public static function block( string $text ): array {
		$body  = esc_cli( $text, true );
		$fence = str_repeat( '`', max( 3, self::longest_backtick_run( $body ) + 1 ) );

		return array_merge( array( $fence ), explode( "\n", $body ), array( $fence ) );
	}

	/**
	 * Control characters made visible and line breaks turned into spaces.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Untrusted text, possibly spanning several lines.
	 * @return string
	 */
	private static function one_line( string $text ): string {
		return esc_cli( str_replace( array( "\r\n", "\n", "\r" ), ' ', $text ) );
	}

	/**
	 * Length of the longest run of backticks.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text that will go inside a code span or block.
	 * @return int
	 */
	private static function longest_backtick_run( string $text ): int {
		$longest = 0;
		if ( preg_match_all( '/`+/', $text, $runs ) > 0 ) {
			foreach ( $runs[0] as $run ) {
				$longest = max( $longest, strlen( $run ) );
			}
		}

		return $longest;
	}
}
