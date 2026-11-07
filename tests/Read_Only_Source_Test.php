<?php
/**
 * Guards the rule that nothing in src/ writes, runs commands, or reaches the database outside the gate.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests;

use PHPUnit\Framework\TestCase;

final class Read_Only_Source_Test extends TestCase {

	private const FORBIDDEN_FUNCTIONS = array(
		'file_put_contents',
		'fputcsv',
		'ftruncate',
		'unlink',
		'rename',
		'copy',
		'mkdir',
		'rmdir',
		'touch',
		'chmod',
		'chown',
		'chgrp',
		'symlink',
		'link',
		'tempnam',
		'tmpfile',
		'move_uploaded_file',
		'exec',
		'shell_exec',
		'system',
		'passthru',
		'popen',
		'proc_open',
		'pcntl_exec',
		'assert',
		'unserialize',
		'create_function',
		'call_user_func',
		'call_user_func_array',
		'error_log',
		'stream_copy_to_stream',
	);

	/**
	 * Variables the source calls as functions: clocks and openers handed in by
	 * the code's own callers, never names read from a site.
	 */
	private const CALLABLE_VARIABLES = array( '$now', '$open' );

	/**
	 * PHP files under src/.
	 *
	 * @return string[]
	 */
	private function source_files(): array {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );

		return $files;
	}

	/**
	 * Problems found in a piece of source.
	 *
	 * @param string $code       PHP source.
	 * @param bool   $is_driver  Whether the source is the one file allowed to use mysqli.
	 * @return string[]
	 */
	private function violations_in( string $code, bool $is_driver ): array {
		$tokens = token_get_all( $code );
		$found  = array();

		foreach ( $tokens as $i => $token ) {
			if ( '`' === $token ) {
				$found[] = 'backtick shell execution';
				continue;
			}
			if ( is_array( $token ) && T_VARIABLE === $token[0] && '(' === $this->neighbour( $tokens, $i, 1 )
				&& ! in_array( $this->neighbour( $tokens, $i, -1 ), array( '->', '::' ), true ) && ! in_array( $token[1], self::CALLABLE_VARIABLES, true ) ) {
				$found[] = "$token[1]() variable function call";
				continue;
			}
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$text = strtolower( substr( $token[1], 1, -1 ) );
				if ( in_array( $text, array_merge( self::FORBIDDEN_FUNCTIONS, array( 'fwrite', 'fputs', 'fprintf', 'fopen' ) ), true )
					&& in_array( $this->neighbour( $tokens, $i, -1 ), array( '(', ',' ), true ) && '=>' !== $this->neighbour( $tokens, $i, 1 )
					&& 'array' !== $this->enclosing_call( $tokens, $i ) ) {
					$found[] = "'$text' as a string, which PHP can call";
				}
				continue;
			}
			if ( ! is_array( $token ) || ! $this->is_name_token( $token[0] ) ) {
				continue;
			}

			$segments = explode( '\\', $token[1] );
			$name     = strtolower( (string) end( $segments ) );
			$previous = $this->before_name( $tokens, $i );
			$next     = $this->neighbour( $tokens, $i, 1 );
			$is_call  = '(' === $next && ! in_array( $previous, array( '->', '?->', '::', 'function', 'new' ), true );

			if ( $is_call && in_array( $name, self::FORBIDDEN_FUNCTIONS, true ) ) {
				$found[] = "$name()";
			}
			if ( '(' === $next && in_array( $previous, array( '->', '?->', '::' ), true ) && 'unserialize' === $name ) {
				$found[] = 'unserialize() method';
			}
			if ( 'splfileobject' === $name && 'new' === $previous && ! $this->mode_is_read( $tokens, $i, true ) ) {
				$found[] = 'SplFileObject without a literal read mode';
			}
			if ( 'openfile' === $name && in_array( $previous, array( '->', '?->' ), true ) && ! $this->mode_is_read( $tokens, $i, false ) ) {
				$found[] = 'SplFileObject without a literal read mode';
			}
			if ( 'spltempfileobject' === $name && 'new' === $previous ) {
				$found[] = 'SplTempFileObject';
			}
			if ( $is_call && in_array( $name, array( 'fwrite', 'fputs', 'fprintf', 'vfprintf' ), true ) && ! in_array( $this->first_argument( $tokens, $i ), array( 'stdout', 'stderr' ), true ) ) {
				$found[] = "$name() to a stream other than STDOUT or STDERR";
			}
			if ( $is_call && 'fopen' === $name && ! $this->opens_for_reading( $tokens, $i ) ) {
				$found[] = 'fopen() without a literal read mode';
			}
			$database_call = $is_call && 1 === preg_match( '/^(mysqli_|mysql_|pg_)/', $name );
			$database_type = in_array( $name, array( 'mysqli', 'pdo' ), true );
			if ( ! $is_driver && ( $database_call || $database_type ) ) {
				$found[] = "$name outside the database driver";
			}
		}

		return $found;
	}

	/**
	 * Whether a token id is a name, which PHP 8 may deliver as one qualified token and PHP 7 as separate parts.
	 *
	 * @param int $id Token type from token_get_all().
	 * @return bool
	 */
	private function is_name_token( int $id ): bool {
		$ids = array( T_STRING );
		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ) as $constant ) {
			if ( defined( $constant ) ) {
				$ids[] = constant( $constant );
			}
		}

		return in_array( $id, $ids, true );
	}

	/**
	 * Text of the nearest non-whitespace token before or after $i.
	 *
	 * @param array<int, mixed> $tokens    Output of token_get_all().
	 * @param int               $i         Index to start from, not itself examined.
	 * @param int               $direction -1 to look back, 1 to look ahead.
	 * @return string
	 */
	private function neighbour( array $tokens, int $i, int $direction ): string {
		for ( $j = $i + $direction; isset( $tokens[ $j ] ); $j += $direction ) {
			$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return strtolower( $text );
		}

		return '';
	}

	/**
	 * Text of the token before the name at $i and any namespace in front of
	 * it, which PHP 7 gives as separate `\` and name tokens.
	 *
	 * @param array<int, mixed> $tokens Output of token_get_all().
	 * @param int               $i      Index of the name.
	 * @return string
	 */
	private function before_name( array $tokens, int $i ): string {
		for ( $j = $i - 1; isset( $tokens[ $j ] ); $j-- ) {
			$token = $tokens[ $j ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_NS_SEPARATOR, T_STRING ), true ) ) {
				if ( T_STRING === $token[0] && ( ! isset( $tokens[ $j + 1 ] ) || ! is_array( $tokens[ $j + 1 ] ) || T_NS_SEPARATOR !== $tokens[ $j + 1 ][0] ) ) {
					return strtolower( $token[1] );
				}
				continue;
			}

			return strtolower( is_array( $token ) ? $token[1] : $token );
		}

		return '';
	}

	/**
	 * Text of the first token inside the call parentheses that follow $i.
	 *
	 * @param array<int, mixed> $tokens Output of token_get_all().
	 * @param int               $i      Index of the function name.
	 * @return string
	 */
	private function first_argument( array $tokens, int $i ): string {
		for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
			$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			if ( '(' === $text ) {
				return $this->neighbour( $tokens, $j, 1 );
			}
		}

		return '';
	}

	/**
	 * Whether the fopen call at $i has a literal r or rb mode.
	 *
	 * @param array<int, mixed> $tokens Output of token_get_all().
	 * @param int               $i      Index of fopen.
	 * @return bool
	 */
	private function opens_for_reading( array $tokens, int $i ): bool {
		$depth  = 0;
		$commas = 0;
		for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
			$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			if ( '(' === $text ) {
				++$depth;
			} elseif ( ')' === $text ) {
				--$depth;
				if ( 0 === $depth ) {
					return false;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				++$commas;
				$mode = $this->neighbour( $tokens, $j, 1 );
				return 1 === $commas && in_array( $mode, array( "'r'", "'rb'", '"r"', '"rb"' ), true );
			}
		}

		return false;
	}

	/**
	 * Text of the token before the parenthesis that encloses $i, such as the
	 * name of the function whose argument $i is.
	 *
	 * @param array<int, mixed> $tokens Output of token_get_all().
	 * @param int               $i      Index inside the parentheses.
	 * @return string
	 */
	private function enclosing_call( array $tokens, int $i ): string {
		$depth = 0;
		for ( $j = $i - 1; isset( $tokens[ $j ] ); $j-- ) {
			if ( ')' === $tokens[ $j ] ) {
				++$depth;
			} elseif ( '(' === $tokens[ $j ] ) {
				if ( 0 === $depth ) {
					return $this->neighbour( $tokens, $j, -1 );
				}
				--$depth;
			}
		}

		return '';
	}

	/**
	 * Whether a file-object mode is a literal r or rb, or left to its default of r.
	 *
	 * @param array<int, mixed> $tokens        Output of token_get_all().
	 * @param int               $i             Index of SplFileObject or openFile.
	 * @param bool              $mode_is_second Whether the mode is the second argument rather than the first.
	 * @return bool
	 */
	private function mode_is_read( array $tokens, int $i, bool $mode_is_second ): bool {
		$depth  = 0;
		$commas = 0;
		$wanted = $mode_is_second ? 1 : 0;
		for ( $j = $i + 1; isset( $tokens[ $j ] ); $j++ ) {
			$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			if ( '(' === $text ) {
				++$depth;
				if ( 1 === $depth && 0 === $wanted ) {
					$mode = $this->neighbour( $tokens, $j, 1 );
					return ')' === $mode || in_array( $mode, array( "'r'", "'rb'", '"r"', '"rb"' ), true );
				}
			} elseif ( ')' === $text ) {
				--$depth;
				if ( 0 === $depth ) {
					return true;
				}
			} elseif ( ',' === $text && 1 === $depth ) {
				++$commas;
				if ( $commas === $wanted ) {
					return in_array( $this->neighbour( $tokens, $j, 1 ), array( "'r'", "'rb'", '"r"', '"rb"' ), true );
				}
			} elseif ( 0 === $depth && ! is_array( $tokens[ $j ] ) ) {
				return true;
			}
		}

		return true;
	}

	public function test_no_source_file_writes_runs_commands_or_bypasses_the_database_driver(): void {
		$files = $this->source_files();
		$this->assertGreaterThan( 5, count( $files ), 'Too few source files were found to check.' );

		$violations = array();
		foreach ( $files as $path ) {
			$is_driver = 'class-mysqli-driver.php' === basename( $path );
			foreach ( $this->violations_in( (string) file_get_contents( $path ), $is_driver ) as $violation ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$violations[] = basename( $path ) . ': ' . $violation;
			}
		}

		$this->assertSame( array(), $violations );
	}

	public function test_the_database_driver_is_the_one_file_that_uses_mysqli(): void {
		$driver = dirname( __DIR__ ) . '/src/Db/class-mysqli-driver.php';

		$this->assertFileExists( $driver );
		$this->assertStringContainsString( 'mysqli', (string) file_get_contents( $driver ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function test_the_detector_flags_writes_commands_and_stray_database_access(): void {
		$bad = '<?php file_put_contents( "a", "b" ); unlink( "a" ); exec( "ls" ); unserialize( $data ); $x = `ls`; mysqli_connect(); new PDO( "x" ); new \\mysqli(); \\unlink( "a" ); fopen( "a", "w" ); fopen( "a", $mode ); fwrite( $handle, "x" ); fputs( STDIN, "x" );';

		$found = $this->violations_in( $bad, false );

		$this->assertContains( 'file_put_contents()', $found );
		$this->assertContains( 'unlink()', $found );
		$this->assertContains( 'exec()', $found );
		$this->assertContains( 'unserialize()', $found );
		$this->assertContains( 'backtick shell execution', $found );
		$this->assertContains( 'mysqli_connect outside the database driver', $found );
		$this->assertContains( 'pdo outside the database driver', $found );
		$this->assertContains( 'mysqli outside the database driver', $found );
		$this->assertSame( 2, count( array_keys( $found, 'unlink()', true ) ) );
		$this->assertCount( 2, array_keys( $found, 'fopen() without a literal read mode', true ) );
		$this->assertContains( 'fwrite() to a stream other than STDOUT or STDERR', $found );
		$this->assertContains( 'fputs() to a stream other than STDOUT or STDERR', $found );
	}

	public function test_the_detector_flags_indirect_writes_and_calls(): void {
		$bad = '<?php error_log( $m, 3, "/tmp/a" ); fprintf( $handle, "%s", $x ); stream_copy_to_stream( $in, $out ); new SplFileObject( "a", "w" ); new \\SplTempFileObject(); $file->openFile( "a+" ); array_map( \'unlink\', $paths ); $fn( "a" ); $parser->unserialize( $data );';

		$found = $this->violations_in( $bad, false );

		$this->assertContains( 'error_log()', $found );
		$this->assertContains( 'fprintf() to a stream other than STDOUT or STDERR', $found );
		$this->assertContains( 'stream_copy_to_stream()', $found );
		$this->assertCount( 2, array_keys( $found, 'SplFileObject without a literal read mode', true ) );
		$this->assertContains( 'SplTempFileObject', $found );
		$this->assertContains( "'unlink' as a string, which PHP can call", $found );
		$this->assertContains( '$fn() variable function call', $found );
		$this->assertContains( 'unserialize() method', $found );
		$this->assertCount( 9, $found );
	}

	public function test_the_detector_accepts_read_only_file_objects_and_the_allowed_callables(): void {
		$good = '<?php new \\SplFileObject( "a" ); new SplFileObject( "a", "rb" ); $info->openFile(); fprintf( STDERR, "x" ); $left = $deadline - $now(); self::opened( $open() ); $msg = "link"; echo \'copy of the file\'; $names = array( \'error_log\', \'.ds_store\' );';

		$this->assertSame( array(), $this->violations_in( $good, false ) );
	}

	public function test_the_detector_accepts_reads_and_method_calls_with_forbidden_names(): void {
		$good = '<?php $s = file_get_contents( "a" ); $f = fopen( "a", "rb" ); $o->copy( 1 ); Foo::unlink( 1 ); function link() {} hash_file( "md5", "a" ); Db\\Mysqli_Driver::connect(); $d = Mysqli_Driver::class; fwrite( STDERR, "x" ); fwrite( STDOUT, "x" );';

		$this->assertSame( array(), $this->violations_in( $good, false ) );
	}
}
