<?php
/**
 * Places in PHP source that run code built at runtime.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

/**
 * Finds eval(), assert(), create_function() and include or require in the
 * tokens of one file, and records what feeds each: a decoder such as
 * base64_decode(), a long encoded string, or content fetched from another
 * server. A variable passed in is followed to its assignments in the same
 * file, so `$x = base64_decode( ... ); eval( $x );` counts. Nothing is run.
 *
 * @since 0.2.0
 */
final class Code_Sinks {

	public const DECODED = 'decoded';
	public const ENCODED = 'encoded';
	public const REMOTE  = 'remote';

	/**
	 * Functions whose argument is run as PHP.
	 *
	 * @since 0.2.0
	 */
	private const FUNCTION_SINKS = array( 'assert', 'create_function' );

	/**
	 * Functions that turn an encoded string back into code.
	 *
	 * @since 0.2.0
	 */
	public const DECODERS = array( 'base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'strrev', 'hex2bin', 'convert_uudecode' );

	/**
	 * Functions that always read from another server.
	 *
	 * @since 0.2.0
	 */
	public const FETCHERS = array(
		'curl_exec',
		'curl_multi_getcontent',
		'download_url',
		'fsockopen',
		'stream_socket_client',
		'wp_remote_get',
		'wp_remote_post',
		'wp_remote_request',
		'wp_remote_retrieve_body',
		'wp_safe_remote_get',
		'wp_safe_remote_post',
		'wp_safe_remote_request',
	);

	/**
	 * Functions that read from another server when given a URL.
	 *
	 * @since 0.2.0
	 */
	private const URL_READERS = array( 'file_get_contents', 'file', 'fopen', 'fread', 'fgets', 'stream_get_contents' );

	/**
	 * String functions that return their argument changed, so what feeds the
	 * argument still feeds the result.
	 *
	 * @since 0.2.0
	 */
	private const TRANSFORMS = array( 'chr', 'implode', 'join', 'ltrim', 'rawurldecode', 'rtrim', 'sprintf', 'str_ireplace', 'str_replace', 'stripslashes', 'strtolower', 'strtoupper', 'strtr', 'substr', 'trim', 'urldecode' );

	private const INCLUDES           = array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE );
	private const MIN_ENCODED_LENGTH = 200;
	private const MIN_HEX_ESCAPES    = 50;
	private const MAX_PASSES         = 3;

	/**
	 * Each place that runs code fed by decoded, encoded or remote data.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Output of Php_Tokens::significant().
	 * @return array<int, array{sink: string, line: int, traits: string[], functions: string[]}> In source order. `functions` lists the decoders and fetchers involved.
	 */
	public static function in( array $tokens ): array {
		$variables = self::variable_sources( $tokens );
		$hits      = array();
		foreach ( $tokens as $i => $token ) {
			$sink = self::sink_at( $tokens, $i );
			if ( null === $sink ) {
				continue;
			}
			$is_include          = in_array( $token[0], self::INCLUDES, true );
			list( $start, $end ) = $is_include ? self::include_argument( $tokens, $i ) : self::call_arguments( $tokens, $i + 1 );
			if ( self::is_test( $tokens, $start, $end ) ) {
				continue;
			}
			$sources = self::sources( $tokens, $start, $end, $variables );
			$traits  = self::traits( $sources, $is_include );
			if ( array() !== $traits ) {
				$hits[] = array(
					'sink'      => $sink,
					'line'      => $token[2],
					'traits'    => $traits,
					'functions' => array_values( array_unique( $sources['functions'] ) ),
				);
			}
		}

		return $hits;
	}

	/**
	 * The name of the code-running construct at $i, or null when there is none.
	 * A method or a function declaration with a sink's name does not count.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @param int                                          $i      Position to look at.
	 * @return string|null
	 */
	private static function sink_at( array $tokens, int $i ): ?string {
		list( $id, $text ) = $tokens[ $i ];
		if ( in_array( $id, self::INCLUDES, true ) ) {
			return strtolower( $text );
		}
		if ( ! self::opens_call( $tokens, $i + 1 ) ) {
			return null;
		}
		if ( T_EVAL === $id ) {
			return 'eval';
		}

		$name = self::function_name( $tokens, $i );

		return null !== $name && in_array( $name, self::FUNCTION_SINKS, true ) ? $name : null;
	}

	/**
	 * Lowercase name of a global function called at $i. Null for anything
	 * else, including methods, static calls, declarations and `new`.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @param int                                          $i      Position of a possible function name.
	 * @return string|null
	 */
	private static function function_name( array $tokens, int $i ): ?string {
		if ( T_STRING !== $tokens[ $i ][0] || ! self::opens_call( $tokens, $i + 1 ) ) {
			return null;
		}
		if ( $i > 0 && in_array( $tokens[ $i - 1 ][0], self::not_global_call_prefixes(), true ) ) {
			return null;
		}

		return strtolower( ltrim( $tokens[ $i ][1], '\\' ) );
	}

	/**
	 * Token ids that make a following name a method, a static member or a declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return int[]
	 */
	private static function not_global_call_prefixes(): array {
		$ids = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$ids[] = (int) constant( 'T_NULLSAFE_OBJECT_OPERATOR' );
		}

		return $ids;
	}

	/**
	 * True when the token at $i is an opening parenthesis.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @param int                                          $i      Position to look at.
	 * @return bool
	 */
	private static function opens_call( array $tokens, int $i ): bool {
		return isset( $tokens[ $i ] ) && 0 === $tokens[ $i ][0] && '(' === $tokens[ $i ][1];
	}

	/**
	 * Range of the arguments inside the parentheses that open at $open.
	 * An unclosed call runs to the end of the file.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @param int                                          $open   Position of the opening parenthesis.
	 * @return array{0: int, 1: int} First position and the position after the last.
	 */
	private static function call_arguments( array $tokens, int $open ): array {
		$depth = 0;
		for ( $i = $open, $total = count( $tokens ); $i < $total; $i++ ) {
			$depth += self::depth_change( $tokens[ $i ] );
			if ( 0 === $depth ) {
				return array( $open + 1, $i );
			}
		}

		return array( $open + 1, count( $tokens ) );
	}

	/**
	 * Range of the expression an include or require loads. It ends at the
	 * semicolon, comma or closing bracket that ends the statement or the
	 * surrounding call.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @param int                                          $at     Position of the include keyword.
	 * @return array{0: int, 1: int}
	 */
	private static function include_argument( array $tokens, int $at ): array {
		return array( $at + 1, self::expression_end( $tokens, $at + 1 ) );
	}

	/**
	 * Position after the expression that starts at $start: the first
	 * semicolon, comma, closing bracket or closing tag at its own depth.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @param int                                          $start  First position of the expression.
	 * @return int
	 */
	private static function expression_end( array $tokens, int $start ): int {
		$depth = 0;
		for ( $i = $start, $total = count( $tokens ); $i < $total; $i++ ) {
			$token = $tokens[ $i ];
			if ( T_CLOSE_TAG === $token[0] ) {
				return $i;
			}
			$change = self::depth_change( $token );
			if ( 0 === $depth && ( $change < 0 || ( 0 === $token[0] && ( ';' === $token[1] || ',' === $token[1] ) ) ) ) {
				return $i;
			}
			$depth += $change;
		}

		return count( $tokens );
	}

	/**
	 * +1 for a token that opens a bracket, -1 for one that closes it, else 0.
	 *
	 * @since 0.2.0
	 *
	 * @param array{0: int, 1: string, 2: int} $token A significant token.
	 * @return int
	 */
	private static function depth_change( array $token ): int {
		if ( T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
			return 1;
		}
		if ( 0 !== $token[0] ) {
			return 0;
		}
		if ( in_array( $token[1], array( '(', '[', '{' ), true ) ) {
			return 1;
		}

		return in_array( $token[1], array( ')', ']', '}' ), true ) ? -1 : 0;
	}

	/**
	 * What each variable assigned in the file is built from. Repeated
	 * passes carry a source through `$b = $a;` chains a few steps deep.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @return array<string, array{flags: array<string, bool>, functions: string[]}> By variable name.
	 */
	private static function variable_sources( array $tokens ): array {
		$variables = array();
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$before = $variables;
			foreach ( $tokens as $i => $token ) {
				if ( T_VARIABLE !== $token[0] || ! isset( $tokens[ $i + 1 ] ) ) {
					continue;
				}
				$next = $tokens[ $i + 1 ];
				if ( ! ( ( 0 === $next[0] && '=' === $next[1] ) || T_CONCAT_EQUAL === $next[0] ) ) {
					continue;
				}
				if ( $i > 0 && in_array( $tokens[ $i - 1 ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
					continue;
				}
				$found = self::sources( $tokens, $i + 2, self::expression_end( $tokens, $i + 2 ), $variables );
				$name  = $token[1];
				$known = $variables[ $name ] ?? array(
					'flags'     => array(),
					'functions' => array(),
				);

				$variables[ $name ] = array(
					'flags'     => $known['flags'] + $found['flags'],
					'functions' => array_values( array_unique( array_merge( $known['functions'], $found['functions'] ) ) ),
				);
			}
			if ( $before === $variables ) {
				break;
			}
		}

		return $variables;
	}

	/**
	 * The decoders, fetchers, URLs and encoded strings in a range of tokens,
	 * including those behind the variables it uses. A variable inside the
	 * parentheses of any other call, such as is_array( $x ) or a method, is
	 * not followed: that call returns something else.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}>                          $tokens    Significant tokens.
	 * @param int                                                                   $start     First position.
	 * @param int                                                                   $end       Position after the last.
	 * @param array<string, array{flags: array<string, bool>, functions: string[]}> $variables Sources of assigned variables.
	 * @return array{flags: array<string, bool>, functions: string[]} Flags keyed decoder, encoded, url, fetcher and reader.
	 */
	private static function sources( array $tokens, int $start, int $end, array $variables ): array {
		$flags     = array();
		$functions = array();
		$opaque    = array();
		for ( $i = $start; $i < $end; $i++ ) {
			list( $id, $text ) = $tokens[ $i ];
			if ( 0 === $id && '(' === $text ) {
				$opaque[] = self::hides_argument( $tokens, $i );
				continue;
			}
			if ( 0 === $id && ')' === $text ) {
				array_pop( $opaque );
				continue;
			}
			if ( T_VARIABLE === $id && isset( $variables[ $text ] ) && ! in_array( true, $opaque, true ) ) {
				$flags    += $variables[ $text ]['flags'];
				$functions = array_merge( $functions, $variables[ $text ]['functions'] );
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id ) {
				$value = T_CONSTANT_ENCAPSED_STRING === $id ? substr( ltrim( $text, 'bB' ), 1, -1 ) : $text;
				if ( 1 === preg_match( '#^(?:https?|ftps?)://#i', $value ) ) {
					$flags['url'] = true;
				}
				if ( self::is_encoded( $value ) ) {
					$flags['encoded'] = true;
				}
				continue;
			}
			$name = self::function_name( $tokens, $i );
			if ( null === $name ) {
				continue;
			}
			if ( in_array( $name, self::DECODERS, true ) ) {
				$flags['decoder'] = true;
				$functions[]      = $name;
			} elseif ( in_array( $name, self::FETCHERS, true ) ) {
				$flags['fetcher'] = true;
				$functions[]      = $name;
			} elseif ( in_array( $name, self::URL_READERS, true ) ) {
				$flags['reader'] = true;
				$functions[]     = $name;
			}
		}

		return array(
			'flags'     => $flags,
			'functions' => $functions,
		);
	}

	/**
	 * True when the parenthesis at $open belongs to a call whose result is not
	 * its argument: any function outside the decoders, fetchers, readers and
	 * string transforms, a method, a variable function, isset() or empty().
	 * Grouping parentheses and array() hide nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @param int                                          $open   Position of the opening parenthesis.
	 * @return bool
	 */
	private static function hides_argument( array $tokens, int $open ): bool {
		if ( 0 === $open ) {
			return false;
		}
		list( $id, $text ) = $tokens[ $open - 1 ];
		if ( T_STRING === $id ) {
			$name = self::function_name( $tokens, $open - 1 );

			return null === $name || ! in_array( $name, array_merge( self::DECODERS, self::FETCHERS, self::URL_READERS, self::TRANSFORMS ), true );
		}
		if ( in_array( $id, array( T_VARIABLE, T_ISSET, T_EMPTY ), true ) ) {
			return true;
		}

		return 0 === $id && in_array( $text, array( ')', ']', '}' ), true );
	}

	/**
	 * True when an argument is a comparison or a logical test at its own
	 * level, such as `is_string( $x ) && '' !== $x`. Its value is a boolean,
	 * which never runs as code.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}> $tokens Significant tokens.
	 * @param int                                          $start  First position of the argument.
	 * @param int                                          $end    Position after the last.
	 * @return bool
	 */
	private static function is_test( array $tokens, int $start, int $end ): bool {
		$operators = array( T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_INSTANCEOF );
		$depth     = 0;
		for ( $i = $start; $i < $end; $i++ ) {
			$token  = $tokens[ $i ];
			$depth += self::depth_change( $token );
			if ( 0 !== $depth ) {
				continue;
			}
			if ( in_array( $token[0], $operators, true ) || ( 0 === $token[0] && in_array( $token[1], array( '<', '>', '!' ), true ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Turns the sources of an argument into the traits callers judge by.
	 * A file reader counts as remote only with a URL or decoded data in the
	 * same argument. An include of a URL fetches it by itself.
	 *
	 * @since 0.2.0
	 *
	 * @param array{flags: array<string, bool>, functions: string[]} $sources    Output of sources().
	 * @param bool                                                   $is_include True for include and require.
	 * @return string[]
	 */
	private static function traits( array $sources, bool $is_include ): array {
		$flags  = $sources['flags'];
		$traits = array();
		if ( isset( $flags['decoder'] ) ) {
			$traits[] = self::DECODED;
		}
		if ( isset( $flags['encoded'] ) ) {
			$traits[] = self::ENCODED;
		}
		$url = isset( $flags['url'] );
		if ( isset( $flags['fetcher'] ) || ( isset( $flags['reader'] ) && ( $url || isset( $flags['decoder'] ) ) ) || ( $is_include && $url ) ) {
			$traits[] = self::REMOTE;
		}

		return $traits;
	}

	/**
	 * True for a long base64 or hexadecimal string, or one written mostly as
	 * `\x` escapes.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value String literal content, without quotes.
	 * @return bool
	 */
	private static function is_encoded( string $value ): bool {
		if ( preg_match_all( '/\\\\x[0-9a-fA-F]{2}/', $value ) >= self::MIN_HEX_ESCAPES ) {
			return true;
		}
		$compact = (string) preg_replace( '/[\r\n]+/', '', $value );
		if ( strlen( $compact ) < self::MIN_ENCODED_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $compact ) && 1 === preg_match( '#[0-9+/]#', $compact );
	}

	/**
	 * A short account of one hit for the report, such as
	 * "eval() of base64_decode(), gzinflate()".
	 *
	 * @since 0.2.0
	 *
	 * @param array{sink: string, line: int, traits: string[], functions: string[]} $hit One entry from in().
	 * @return string
	 */
	public static function describe( array $hit ): string {
		$sink = in_array( $hit['sink'], array( 'eval', 'assert', 'create_function' ), true ) ? $hit['sink'] . '()' : $hit['sink'];
		if ( array() !== $hit['functions'] ) {
			return "$sink of " . implode( ', ', array_map( array( self::class, 'call_name' ), $hit['functions'] ) );
		}

		return in_array( self::ENCODED, $hit['traits'], true ) ? "$sink of a long encoded string" : "$sink of a URL";
	}

	/**
	 * A function name written as a call.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name Function name.
	 * @return string
	 */
	private static function call_name( string $name ): string {
		return "$name()";
	}
}
