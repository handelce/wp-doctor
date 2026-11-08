<?php
/**
 * PHP source split into the tokens that carry meaning.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Util;

/**
 * Tokenizes PHP source with token_get_all(), which reads the source without
 * running it.
 *
 * @since 0.2.0
 */
final class Php_Tokens {

	/**
	 * Splits source into [id, text, line] triples without whitespace and
	 * comments. Punctuation gets id 0 and the line of the token before it. A
	 * fully qualified name such as `\define` becomes one T_STRING on every PHP
	 * version.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source PHP source.
	 * @return array<int, array{0: int, 1: string, 2: int}>
	 */
	public static function significant( string $source ): array {
		$tokens        = array();
		$qualified_ids = defined( 'T_NAME_FULLY_QUALIFIED' ) ? array( constant( 'T_NAME_FULLY_QUALIFIED' ) ) : array();
		$raw           = token_get_all( $source );
		$line          = 1;

		for ( $i = 0, $total = count( $raw ); $i < $total; $i++ ) {
			$token = $raw[ $i ];
			if ( ! is_array( $token ) ) {
				$tokens[] = array( 0, $token, $line );
				continue;
			}

			list( $id, $text, $line ) = $token;
			if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				$line += substr_count( $text, "\n" );
				continue;
			}
			if ( in_array( $id, $qualified_ids, true ) ) {
				$tokens[] = array( T_STRING, $text, $line );
				continue;
			}
			if ( T_NS_SEPARATOR === $id && isset( $raw[ $i + 1 ] ) && is_array( $raw[ $i + 1 ] ) && T_STRING === $raw[ $i + 1 ][0] ) {
				$tokens[] = array( T_STRING, '\\' . $raw[ $i + 1 ][1], $line );
				++$i;
				continue;
			}
			$tokens[] = array( $id, $text, $line );
			$line    += substr_count( $text, "\n" );
		}

		return $tokens;
	}
}
