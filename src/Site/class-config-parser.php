<?php
/**
 * Token-based reader for wp-config.php.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Site;

/**
 * Reads define() and const literals, $table_prefix and literal ini_set() calls
 * from source text. The source is tokenized, never included or evaluated.
 * Anything that is not a plain top-level literal is recorded as unresolved
 * instead of being guessed.
 *
 * @since 0.1.0
 */
final class Config_Parser {

	private const COMPUTED        = 'computed value';
	private const CONDITIONAL     = 'defined conditionally';
	private const ESCAPE          = 'unsupported string escape';
	private const INCLUDED_BEFORE = 'an include or eval() before it is not read and may define it';
	private const INCLUDED_AFTER  = 'an include or eval() after it is not read and may change it';
	private const STATIC_DEFINE   = 'set through %s::define(), as Bedrock does, which this parser does not follow';
	private const DYNAMIC         = 'the file uses a variable variable, extract() or parse_str(), which can change variables unseen';
	private const UNFOLLOWED      = 'it is changed by a statement this parser does not follow, such as a reference, destructuring or unset()';

	/**
	 * Keywords that open a block when their header is followed by a colon.
	 *
	 * @since 0.1.0
	 */
	private const ALTERNATIVE_OPENERS = array( T_IF, T_ELSEIF, T_ELSE, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_DECLARE );

	/**
	 * Keywords that close an alternative-syntax block.
	 *
	 * @since 0.1.0
	 */
	private const ALTERNATIVE_CLOSERS = array( T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH, T_ENDSWITCH, T_ENDDECLARE );

	/**
	 * Parses wp-config.php source.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source Contents of wp-config.php.
	 * @return Config
	 */
	public function parse( string $source ): Config {
		$tokens = $this->tokenize( $source );
		$count  = count( $tokens );

		$constants    = array();
		$unresolved   = array();
		$paths        = array();
		$ini          = array();
		$ini_problems = array();
		$computed     = 0;
		$includes     = 0;
		$prefix       = null;
		$prefix_issue = null;
		$depth        = 0;

		$guarded_defines = array();
		$block_colons    = array();
		$shadowed        = false;
		$dynamic         = false;
		$unfollowed      = false;
		$namespaced      = false;

		for ( $i = 0; $i < $count; $i++ ) {
			list( $id, $text ) = $tokens[ $i ];

			if ( '{' === $text || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				++$depth;
				continue;
			}
			if ( '}' === $text || in_array( $id, self::ALTERNATIVE_CLOSERS, true ) ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}
			if ( isset( $block_colons[ $i ] ) ) {
				++$depth;
				continue;
			}
			if ( in_array( $id, self::ALTERNATIVE_OPENERS, true ) ) {
				$colon = $this->alternative_block_colon( $tokens, $i );
				if ( null !== $colon ) {
					$block_colons[ $colon ] = true;
					if ( T_ELSE === $id || T_ELSEIF === $id ) {
						$depth = max( 0, $depth - 1 );
					}
				}
			}
			if ( T_NAMESPACE === $id && isset( $tokens[ $i + 1 ] ) && in_array( $tokens[ $i + 1 ][0], $this->namespace_name_ids(), true ) && '\\' !== $tokens[ $i + 1 ][1][0] ) {
				$namespaced = true;
			}
			if ( T_EVAL === $id || in_array( $id, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$evaluated = T_EVAL === $id;
				if ( $evaluated || ! $this->include_names_file( $tokens, $i, array( 'wp-settings.php' ) ) ) {
					++$includes;
				}
				if ( $evaluated || ! $this->include_names_file( $tokens, $i, array( 'wp-settings.php', 'autoload.php' ) ) ) {
					$shadowed = true;
					if ( null !== $prefix ) {
						list( $prefix, $prefix_issue ) = array( null, self::INCLUDED_AFTER );
					}
					foreach ( array_keys( $ini ) as $setting ) {
						$ini_problems[ $setting ] = self::INCLUDED_AFTER;
					}
					$ini = array();
				}
				continue;
			}

			$top_level = 0 === $depth && $this->starts_statement( $tokens, $i );

			if ( $top_level && T_IF === $id ) {
				$guarded = $this->guarded_define_at( $tokens, $i );
				if ( null !== $guarded ) {
					$guarded_defines[ $guarded ] = true;
				}
				continue;
			}

			if ( isset( $guarded_defines[ $i ] ) ) {
				$top_level = true;
			}

			if ( $this->is_call( $tokens, $i, 'ini_set' ) ) {
				$name = $this->string_at( $tokens, $i + 2 );
				if ( null !== $name && $this->is_char( $tokens, $i + 3, ',' ) ) {
					unset( $ini[ $name ], $ini_problems[ $name ] );
					$this->record( $tokens, $i + 4, $name, $top_level, $ini, $ini_problems );
				}
				continue;
			}

			if ( $this->is_static_define( $tokens, $i ) ) {
				$name = $this->string_at( $tokens, $i + 4 );
				if ( null !== $name && ! array_key_exists( $name, $constants ) ) {
					$unresolved[ $name ] = sprintf( self::STATIC_DEFINE, $tokens[ $i ][1] );
				}
				continue;
			}

			if ( $this->is_define_call( $tokens, $i ) ) {
				$name = $this->string_at( $tokens, $i + 2 );
				if ( null === $name || ! $this->is_char( $tokens, $i + 3, ',' ) ) {
					++$computed;
					continue;
				}
				$this->record_unless_shadowed( $tokens, $i + 4, $name, $top_level, $shadowed, $constants, $unresolved, $paths );
				continue;
			}

			if ( T_CONST === $id && $this->is_char( $tokens, $i + 2, '=' ) && T_STRING === $tokens[ $i + 1 ][0] ) {
				if ( $namespaced ) {
					continue;
				}
				$this->record_unless_shadowed( $tokens, $i + 3, $tokens[ $i + 1 ][1], $top_level, $shadowed, $constants, $unresolved, $paths );
				continue;
			}

			if ( T_VARIABLE === $id && '$GLOBALS' === $text && $this->is_char( $tokens, $i + 1, '[' ) && 'table_prefix' === $this->string_at( $tokens, $i + 2 ) ) {
				list( $prefix, $prefix_issue ) = array( null, self::COMPUTED );
			}

			if ( $this->changes_variables_unseen( $tokens, $i ) ) {
				$dynamic = true;
			}

			if ( T_VARIABLE === $id && '$table_prefix' === $text && ! $this->follows_member_access( $tokens, $i ) ) {
				$operator = isset( $tokens[ $i + 1 ] ) ? $tokens[ $i + 1 ][1] : '';
				if ( '=' === $operator ) {
					list( $prefix, $prefix_issue ) = $this->read_prefix( $tokens, $i + 2, $top_level );
				} elseif ( 1 === preg_match( '/^(\.|\?\?|\+|-|\*\*?|\/|%|&|\||\^|<<|>>)=$/', $operator ) ) {
					list( $prefix, $prefix_issue ) = array( null, self::COMPUTED );
				} elseif ( $this->may_write_variable( $tokens, $i ) ) {
					$unfollowed = true;
				}
			}
		}

		if ( $dynamic && null !== $prefix ) {
			list( $prefix, $prefix_issue ) = array( null, self::DYNAMIC );
		}
		if ( $unfollowed && null !== $prefix ) {
			list( $prefix, $prefix_issue ) = array( null, self::UNFOLLOWED );
		}

		return new Config( $constants, $unresolved, $prefix, $prefix_issue, $computed, $includes, $ini, $ini_problems, $paths );
	}

	/**
	 * Splits source into [id, text] pairs without whitespace and comments.
	 * Punctuation gets id 0. A fully qualified name such as `\define` becomes
	 * one T_STRING on every PHP version.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source PHP source.
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function tokenize( string $source ): array {
		$tokens        = array();
		$qualified_ids = defined( 'T_NAME_FULLY_QUALIFIED' ) ? array( constant( 'T_NAME_FULLY_QUALIFIED' ) ) : array();
		$raw           = token_get_all( $source );

		for ( $i = 0, $total = count( $raw ); $i < $total; $i++ ) {
			$token = $raw[ $i ];
			if ( ! is_array( $token ) ) {
				$tokens[] = array( 0, $token );
				continue;
			}

			list( $id, $text ) = $token;
			if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				continue;
			}
			if ( in_array( $id, $qualified_ids, true ) ) {
				$tokens[] = array( T_STRING, $text );
				continue;
			}
			if ( T_NS_SEPARATOR === $id && isset( $raw[ $i + 1 ] ) && is_array( $raw[ $i + 1 ] ) && T_STRING === $raw[ $i + 1 ][0] ) {
				$tokens[] = array( T_STRING, '\\' . $raw[ $i + 1 ][1] );
				++$i;
				continue;
			}
			$tokens[] = array( $id, $text );
		}

		return $tokens;
	}

	/**
	 * Whether the token at $i begins a statement at the current nesting. A
	 * leading @ is looked past.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the token to test.
	 * @return bool
	 */
	private function starts_statement( array $tokens, int $i ): bool {
		if ( $i > 0 && 0 === $tokens[ $i - 1 ][0] && '@' === $tokens[ $i - 1 ][1] ) {
			--$i;
		}
		if ( 0 === $i ) {
			return true;
		}
		list( $id, $text ) = $tokens[ $i - 1 ];

		return ';' === $text || '}' === $text || in_array( $id, array( T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML ), true );
	}

	/**
	 * Token ids a namespace declaration's name can have: a single name on every
	 * PHP version, and the qualified-name token PHP 8 adds.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, int>
	 */
	private function namespace_name_ids(): array {
		$ids = array( T_STRING );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$ids[] = (int) constant( 'T_NAME_QUALIFIED' );
		}

		return $ids;
	}

	/**
	 * Finds the colon that opens an alternative-syntax block for the control keyword at $i,
	 * as in `if ( ... ):`, `else:` or `foreach ( ... ):`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the keyword.
	 * @return int|null Index of the colon, or null when the keyword uses braces, a single statement or ends in `;`.
	 */
	private function alternative_block_colon( array $tokens, int $i ): ?int {
		if ( T_ELSE === $tokens[ $i ][0] ) {
			return $this->is_char( $tokens, $i + 1, ':' ) ? $i + 1 : null;
		}
		if ( ! $this->is_char( $tokens, $i + 1, '(' ) ) {
			return null;
		}
		$close = $this->matching_close( $tokens, $i + 1 );

		return null !== $close && $this->is_char( $tokens, $close + 1, ':' ) ? $close + 1 : null;
	}

	/**
	 * Finds the bracket that closes the one opened at $open.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $open   Index of a `(` or `[`.
	 * @return int|null Index of the matching `)` or `]`, or null in truncated source.
	 */
	private function matching_close( array $tokens, int $open ): ?int {
		$depth = 0;
		for ( $j = $open, $total = count( $tokens ); $j < $total; $j++ ) {
			$text = $tokens[ $j ][1];
			if ( 0 !== $tokens[ $j ][0] ) {
				continue;
			}
			if ( '(' === $text || '[' === $text ) {
				++$depth;
			} elseif ( ')' === $text || ']' === $text ) {
				--$depth;
				if ( 0 === $depth ) {
					return $j;
				}
			}
		}

		return null;
	}

	/**
	 * Whether the variable at $i, which is not followed by an assignment operator,
	 * is in a position that can change it: a reference, ++ or --, a foreach target,
	 * a catch variable, unset(), list() or `[...] =` destructuring, or a bare
	 * argument to a function that may take it by reference.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the variable.
	 * @return bool
	 */
	private function may_write_variable( array $tokens, int $i ): bool {
		$before = $i > 0 ? $tokens[ $i - 1 ] : array( 0, '' );
		$after  = isset( $tokens[ $i + 1 ] ) ? $tokens[ $i + 1 ] : array( 0, '' );

		if ( '&' === $before[1] || in_array( $before[0], array( T_INC, T_DEC, T_AS, T_STRING ), true ) || in_array( $after[0], array( T_INC, T_DEC ), true ) ) {
			return true;
		}

		$bare  = in_array( $before[1], array( '(', ',' ), true ) && in_array( $after[1], array( ')', ',' ), true );
		$inner = true;
		for ( $j = $i - 1, $depth = 0; $j >= 0; $j-- ) {
			$text = 0 === $tokens[ $j ][0] ? $tokens[ $j ][1] : '';
			if ( ')' === $text || ']' === $text ) {
				++$depth;
				continue;
			}
			if ( ( '(' !== $text && '[' !== $text ) || $depth-- > 0 ) {
				if ( ';' === $text || '{' === $text || '}' === $text ) {
					return false;
				}
				continue;
			}
			$depth  = 0;
			$opener = $j > 0 ? $tokens[ $j - 1 ] : array( 0, '' );
			if ( in_array( $opener[0], array( T_UNSET, T_LIST ), true ) || '[' === $text ) {
				$close = $this->matching_close( $tokens, $j );
				if ( T_UNSET === $opener[0] || ( null !== $close && $this->is_char( $tokens, $close + 1, '=' ) ) ) {
					return true;
				}
			} elseif ( T_FOREACH === $opener[0] ) {
				for ( $k = $j + 1; $k < $i; $k++ ) {
					if ( T_AS === $tokens[ $k ][0] ) {
						return true;
					}
				}
				return false;
			} elseif ( $inner && $bare && T_STRING === $opener[0] && 'define' !== strtolower( ltrim( $opener[1], '\\' ) ) ) {
				return true;
			}
			$inner = false;
		}

		return false;
	}

	/**
	 * Whether the token follows ->, ?-> or ::, so it is a member and not a variable or function in scope.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the name or variable token.
	 * @return bool
	 */
	private function follows_member_access( array $tokens, int $i ): bool {
		return $i > 0 && in_array( $tokens[ $i - 1 ][1], array( '->', '?->', '::' ), true );
	}

	/**
	 * Finds the define call in `if ( ! defined( 'NAME' ) ) { define( 'NAME', ... ); }` starting at $i.
	 *
	 * Only that exact shape qualifies: the same name in both calls, nothing else in the block, and no else branch.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the if.
	 * @return int|null Index of the define token, or null when the shape does not match.
	 */
	private function guarded_define_at( array $tokens, int $i ): ?int {
		$name = $this->string_at( $tokens, $i + 5 );
		if (
			null === $name
			|| ! $this->is_char( $tokens, $i + 1, '(' )
			|| ! $this->is_char( $tokens, $i + 2, '!' )
			|| ! isset( $tokens[ $i + 3 ] ) || 'defined' !== strtolower( ltrim( $tokens[ $i + 3 ][1], '\\' ) )
			|| ! $this->is_char( $tokens, $i + 4, '(' )
			|| ! $this->is_char( $tokens, $i + 6, ')' )
			|| ! $this->is_char( $tokens, $i + 7, ')' )
			|| ! $this->is_char( $tokens, $i + 8, '{' )
			|| ! isset( $tokens[ $i + 9 ] )
			|| ! $this->is_define_call( $tokens, $i + 9 )
			|| $name !== $this->string_at( $tokens, $i + 11 )
		) {
			return null;
		}

		$parentheses = 0;
		for ( $j = $i + 10; isset( $tokens[ $j ] ); $j++ ) {
			if ( $this->is_char( $tokens, $j, '(' ) ) {
				++$parentheses;
			} elseif ( $this->is_char( $tokens, $j, ')' ) ) {
				--$parentheses;
				if ( 0 === $parentheses ) {
					break;
				}
			}
		}

		$closes = $this->is_char( $tokens, $j + 1, ';' ) && $this->is_char( $tokens, $j + 2, '}' );
		$else   = isset( $tokens[ $j + 3 ] ) && in_array( $tokens[ $j + 3 ][0], array( T_ELSE, T_ELSEIF ), true );

		return $closes && ! $else ? $i + 9 : null;
	}

	/**
	 * Whether the token at $i starts a call to the global define().
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the candidate function name.
	 * @return bool
	 */
	private function is_define_call( array $tokens, int $i ): bool {
		return $this->is_call( $tokens, $i, 'define' );
	}

	/**
	 * Whether the token at $i starts `Name::define(`, a class method that
	 * defines a constant later, such as Bedrock's Config::define(). $i is the
	 * class name.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the token to test.
	 * @return bool
	 */
	private function is_static_define( array $tokens, int $i ): bool {
		return isset( $tokens[ $i + 2 ] ) && T_STRING === $tokens[ $i ][0]
			&& T_DOUBLE_COLON === $tokens[ $i + 1 ][0]
			&& 'define' === strtolower( $tokens[ $i + 2 ][1] )
			&& $this->is_char( $tokens, $i + 3, '(' );
	}

	/**
	 * Whether the token at $i is a construct that can assign variables without
	 * naming them: `$$name`, `${...}`, extract(), or parse_str() with one argument.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the token to test.
	 * @return bool
	 */
	private function changes_variables_unseen( array $tokens, int $i ): bool {
		if ( $this->is_char( $tokens, $i, '$' ) ) {
			return isset( $tokens[ $i + 1 ] ) && ( T_VARIABLE === $tokens[ $i + 1 ][0] || '{' === $tokens[ $i + 1 ][1] || $this->is_char( $tokens, $i + 1, '$' ) );
		}
		if ( $this->is_call( $tokens, $i, 'extract' ) ) {
			return true;
		}
		if ( ! $this->is_call( $tokens, $i, 'parse_str' ) ) {
			return false;
		}
		$depth = 0;
		for ( $j = $i + 1, $total = count( $tokens ); $j < $total; $j++ ) {
			$text = $tokens[ $j ][1];
			if ( '(' === $text || '[' === $text ) {
				++$depth;
			} elseif ( ')' === $text || ']' === $text ) {
				--$depth;
				if ( 0 === $depth ) {
					return true;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the token at $i calls the named global function. Method calls and
	 * function declarations of the same name do not count.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the candidate function name.
	 * @param string                               $name   Lowercase function name.
	 * @return bool
	 */
	private function is_call( array $tokens, int $i, string $name ): bool {
		return T_STRING === $tokens[ $i ][0]
			&& strtolower( ltrim( $tokens[ $i ][1], '\\' ) ) === $name
			&& $this->is_char( $tokens, $i + 1, '(' )
			&& ! $this->follows_member_access( $tokens, $i )
			&& ! ( $i > 0 && T_FUNCTION === $tokens[ $i - 1 ][0] );
	}

	/**
	 * Whether the token at $i is the single character $char.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the token to test. May be past the end.
	 * @param string                               $char   Punctuation to match, such as '(' or ';'.
	 * @return bool
	 */
	private function is_char( array $tokens, int $i, string $char ): bool {
		return isset( $tokens[ $i ] ) && 0 === $tokens[ $i ][0] && $char === $tokens[ $i ][1];
	}

	/**
	 * Whether the include or require statement at $i names a file ending in one of $files.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the include keyword.
	 * @param array<int, string>                   $files  File names the quoted path may end with.
	 * @return bool
	 */
	private function include_names_file( array $tokens, int $i, array $files ): bool {
		for ( $j = $i + 1, $total = count( $tokens ); $j < $total && ';' !== $tokens[ $j ][1]; $j++ ) {
			if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ][0] ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( 1 === preg_match( '/(\A[\'"]|[\/\\\\])' . preg_quote( $file, '/' ) . '[\'"]\z/', $tokens[ $j ][1] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Decodes the plain string literal at $i.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the expected literal. May be past the end.
	 * @return string|null Null when there is no string literal there or it uses an unsupported escape.
	 */
	private function string_at( array $tokens, int $i ): ?string {
		if ( ! isset( $tokens[ $i ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i ][0] ) {
			return null;
		}

		return $this->decode_string( $tokens[ $i ][1] );
	}

	/**
	 * Decodes a quoted string literal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $literal Literal including its quotes.
	 * @return string|null Null when it uses an escape this parser does not decode.
	 */
	private function decode_string( string $literal ): ?string {
		if ( 'b' === strtolower( $literal[0] ) ) {
			$literal = substr( $literal, 1 );
		}
		$inner = substr( $literal, 1, -1 );

		if ( "'" === $literal[0] ) {
			return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $inner );
		}

		$supported = array(
			'n'  => "\n",
			'r'  => "\r",
			't'  => "\t",
			'v'  => "\v",
			'e'  => "\e",
			'f'  => "\f",
			'\\' => '\\',
			'$'  => '$',
			'"'  => '"',
		);
		$ok        = true;
		$decoded   = preg_replace_callback(
			'/\\\\(x[0-9A-Fa-f]|u\{|[0-7]|.)/s',
			static function ( array $m ) use ( $supported, &$ok ): string {
				if ( isset( $supported[ $m[1] ] ) ) {
					return $supported[ $m[1] ];
				}
				if ( 1 === preg_match( '/^(x|u|[0-7])/', $m[1] ) ) {
					$ok = false;
				}
				return $m[0];
			},
			$inner
		);

		return $ok && null !== $decoded ? $decoded : null;
	}

	/**
	 * Records the first definition of a name. When an unread include came
	 * earlier, a literal or path is recorded as unresolved, since the include
	 * may have defined the name first.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}>               $tokens     Output of tokenize().
	 * @param int                                                $i          Index of the value's first token.
	 * @param string                                             $name       Constant name.
	 * @param bool                                               $top_level  Whether the definition is a plain top-level statement.
	 * @param bool                                               $shadowed   Whether an include that is not read came earlier.
	 * @param array<string, string|int|float|bool|null>          $constants  Resolved constants, updated.
	 * @param array<string, string>                              $unresolved Unresolved constants, updated.
	 * @param array<string, array{base: string, suffix: string}> $paths Constants built from a base directory and a literal suffix, updated.
	 */
	private function record_unless_shadowed( array $tokens, int $i, string $name, bool $top_level, bool $shadowed, array &$constants, array &$unresolved, array &$paths ): void {
		$known = array_key_exists( $name, $constants ) || isset( $unresolved[ $name ] ) || isset( $paths[ $name ] );
		if ( $known ) {
			return;
		}
		$path = $top_level ? $this->path_expression_at( $tokens, $i ) : null;
		if ( null !== $path ) {
			$paths[ $name ] = $path;
		} else {
			$this->record( $tokens, $i, $name, $top_level, $constants, $unresolved );
		}
		if ( $shadowed && ( array_key_exists( $name, $constants ) || isset( $paths[ $name ] ) ) ) {
			unset( $constants[ $name ], $paths[ $name ] );
			$unresolved[ $name ] = self::INCLUDED_BEFORE;
		}
	}

	/**
	 * Reads `__DIR__ . '<literal>'`, `dirname( __FILE__ ) . '<literal>'` or
	 * `ABSPATH . '<literal>'` at $i, the shapes that move WordPress directories.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the expression's first token.
	 * @return array{base: string, suffix: string}|null Null when the tokens are not exactly one of those shapes.
	 */
	private function path_expression_at( array $tokens, int $i ): ?array {
		if ( ! isset( $tokens[ $i ] ) ) {
			return null;
		}
		list( $id, $text ) = $tokens[ $i ];
		if ( T_DIR === $id ) {
			$base = '__DIR__';
			$next = $i + 1;
		} elseif ( T_STRING === $id && 'ABSPATH' === ltrim( $text, '\\' ) ) {
			$base = 'ABSPATH';
			$next = $i + 1;
		} elseif ( T_STRING === $id && 'dirname' === strtolower( ltrim( $text, '\\' ) ) && $this->is_char( $tokens, $i + 1, '(' )
			&& isset( $tokens[ $i + 2 ] ) && T_FILE === $tokens[ $i + 2 ][0] && $this->is_char( $tokens, $i + 3, ')' ) ) {
			$base = '__DIR__';
			$next = $i + 4;
		} else {
			return null;
		}
		if ( ! $this->is_char( $tokens, $next, '.' ) ) {
			return null;
		}
		$suffix = $this->string_at( $tokens, $next + 1 );
		if ( null === $suffix || ! isset( $tokens[ $next + 2 ] ) || ! in_array( $tokens[ $next + 2 ][1], array( ')', ',', ';' ), true ) ) {
			return null;
		}

		return array(
			'base'   => $base,
			'suffix' => $suffix,
		);
	}

	/**
	 * Records the value of a define() or const at $i under $name, unless the name is already known.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}>      $tokens     Output of tokenize().
	 * @param int                                       $i          Index of the value's first token.
	 * @param string                                    $name       Constant name.
	 * @param bool                                      $top_level  Whether the definition is a plain top-level statement.
	 * @param array<string, string|int|float|bool|null> $constants  Resolved constants, updated.
	 * @param array<string, string>                     $unresolved Unresolved constants, updated.
	 */
	private function record( array $tokens, int $i, string $name, bool $top_level, array &$constants, array &$unresolved ): void {
		if ( array_key_exists( $name, $constants ) || isset( $unresolved[ $name ] ) ) {
			return;
		}

		if ( ! $top_level ) {
			$unresolved[ $name ] = self::CONDITIONAL;
			return;
		}

		list( $ok, $value, $next ) = $this->literal_at( $tokens, $i );
		$closes                    = isset( $tokens[ $next ] ) && in_array( $tokens[ $next ][1], array( ')', ',', ';' ), true );

		if ( $ok && $closes ) {
			$constants[ $name ] = $value;
			return;
		}

		$unresolved[ $name ] = $ok || self::COMPUTED === $value ? self::COMPUTED : (string) $value;
	}

	/**
	 * Reads the table prefix assigned at $i.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens    Output of tokenize().
	 * @param int                                  $i         Index of the assigned value.
	 * @param bool                                 $top_level Whether the assignment is a plain top-level statement.
	 * @return array{0: string|null, 1: string|null} The usable prefix and the problem with it. Exactly one is null.
	 */
	private function read_prefix( array $tokens, int $i, bool $top_level ): array {
		if ( ! $top_level ) {
			return array( null, self::CONDITIONAL );
		}

		list( $ok, $value, $next ) = $this->literal_at( $tokens, $i );
		if ( ! $ok ) {
			return array( null, (string) $value );
		}
		if ( ! isset( $tokens[ $next ] ) || ( ';' !== $tokens[ $next ][1] && T_CLOSE_TAG !== $tokens[ $next ][0] ) ) {
			return array( null, self::COMPUTED );
		}
		if ( ! is_string( $value ) ) {
			return array( null, 'not a string' );
		}
		if ( '' === $value ) {
			return array( null, 'is empty' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+\z/', $value ) ) {
			return array( null, 'contains characters other than letters, digits and underscore' );
		}

		return array( $value, null );
	}

	/**
	 * Reads a string, number, boolean or null literal at $i. A number may carry a leading minus.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: int, 1: string}> $tokens Output of tokenize().
	 * @param int                                  $i      Index of the value's first token.
	 * @return array{0: bool, 1: mixed, 2: int} Whether it is a literal, the value (or the reason when it is not), and the index after it.
	 */
	private function literal_at( array $tokens, int $i ): array {
		if ( ! isset( $tokens[ $i ] ) ) {
			return array( false, self::COMPUTED, $i );
		}
		list( $id, $text ) = $tokens[ $i ];

		if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
			$decoded = $this->decode_string( $text );
			return null === $decoded ? array( false, self::ESCAPE, $i + 1 ) : array( true, $decoded, $i + 1 );
		}

		$negative = false;
		if ( '-' === $text && isset( $tokens[ $i + 1 ] ) && in_array( $tokens[ $i + 1 ][0], array( T_LNUMBER, T_DNUMBER ), true ) ) {
			$negative = true;
			++$i;
			list( $id, $text ) = $tokens[ $i ];
		}

		if ( T_DNUMBER === $id ) {
			$float = (float) str_replace( '_', '', $text );
			return array( true, $negative ? -$float : $float, $i + 1 );
		}

		if ( T_LNUMBER === $id ) {
			$number = $this->integer_from( $text );
			if ( null === $number ) {
				return array( false, self::COMPUTED, $i + 1 );
			}
			return array( true, $negative ? -$number : $number, $i + 1 );
		}

		if ( T_STRING === $id ) {
			$keyword = strtolower( ltrim( $text, '\\' ) );
			if ( 'true' === $keyword || 'false' === $keyword ) {
				return array( true, 'true' === $keyword, $i + 1 );
			}
			if ( 'null' === $keyword ) {
				return array( true, null, $i + 1 );
			}
		}

		return array( false, self::COMPUTED, $i + 1 );
	}

	/**
	 * Converts an integer literal to an int.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Decimal, hexadecimal or octal literal.
	 * @return int|null Null for formats this parser does not read.
	 */
	private function integer_from( string $text ): ?int {
		$text = str_replace( '_', '', $text );

		if ( 1 === preg_match( '/^0[xX][0-9A-Fa-f]+$/', $text ) ) {
			return (int) hexdec( $text );
		}
		if ( 1 === preg_match( '/^0[0-7]+$/', $text ) ) {
			return (int) octdec( $text );
		}
		if ( 1 === preg_match( '/^(0|[1-9][0-9]*)$/', $text ) ) {
			return (int) $text;
		}

		return null;
	}
}
