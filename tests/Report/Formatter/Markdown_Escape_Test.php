<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Report\Formatter;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Formatter\Markdown_Escape;

final class Markdown_Escape_Test extends TestCase {

	/**
	 * @dataProvider hostile_text
	 * @param string $input   Untrusted text.
	 * @param string $escaped Expected prose form.
	 */
	public function test_prose_has_every_markdown_character_escaped( string $input, string $escaped ): void {
		$this->assertSame( $escaped, Markdown_Escape::line_start( $input ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function hostile_text(): array {
		return array(
			'link'          => array( '[click](https://evil.example.test)', '\\[click\\](`https://evil.example.test`)' ),
			'html'          => array( '<img src=x onerror=alert(1)>', '\\<img src=x onerror=alert(1)\\>' ),
			'emphasis'      => array( '**bold** _it_', '\\*\\*bold\\*\\* \\_it\\_' ),
			'heading start' => array( '# not a heading', '\\# not a heading' ),
			'list start'    => array( '- not a list', '\\- not a list' ),
			'ordered start' => array( '1. not a list', '1\\. not a list' ),
			'quote start'   => array( '> not a quote', '\\> not a quote' ),
			'table pipe'    => array( 'a|b', 'a\\|b' ),
			'entity'        => array( '&lt;b&gt;', '\\&lt;b\\&gt;' ),
			'backslash'     => array( 'C:\\path', 'C:\\\\path' ),
			'newline'       => array( "one\n## two", 'one ## two' ),
			'plain prose'   => array( 'Large logs, 3 files. Fix: rotate.', 'Large logs, 3 files. Fix: rotate.' ),
		);
	}

	public function test_markers_are_escaped_only_where_a_line_starts(): void {
		$this->assertSame( '0.1.0', Markdown_Escape::text( '0.1.0' ) );
		$this->assertSame( '- item', Markdown_Escape::text( '- item' ) );
		$this->assertSame( '0\\.1.0', Markdown_Escape::line_start( '0.1.0' ) );
		$this->assertSame( '\\- item', Markdown_Escape::line_start( '- item' ) );
		$this->assertSame( '\\* item', Markdown_Escape::line_start( '* item' ) );
		$this->assertSame( 'plain words', Markdown_Escape::line_start( 'plain words' ) );
	}

	public function test_the_hostile_text_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->hostile_text() );
	}

	/**
	 * @dataProvider code_spans
	 * @param string $input    Untrusted text.
	 * @param string $expected Code span.
	 */
	public function test_a_code_span_cannot_be_closed_by_its_content( string $input, string $expected ): void {
		$this->assertSame( $expected, Markdown_Escape::code( $input ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function code_spans(): array {
		return array(
			'plain'              => array( 'wp-content/uploads/a.php', '`wp-content/uploads/a.php`' ),
			'one backtick'       => array( 'a`b', '``a`b``' ),
			'run of three'       => array( 'x```y', '````x```y````' ),
			'starts with a tick' => array( '`x', '`` `x ``' ),
			'empty'              => array( '', '` `' ),
			'newline flattened'  => array( "a\nb", '`a b`' ),
		);
	}

	public function test_the_code_spans_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->code_spans() );
	}

	public function test_a_block_fence_is_longer_than_any_backtick_run_inside(): void {
		$lines = Markdown_Escape::block( "rm x\n````\ndone" );

		$this->assertSame( '`````', $lines[0] );
		$this->assertSame( '`````', end( $lines ) );
		$this->assertSame( array( '`````', 'rm x', '````', 'done', '`````' ), $lines );
		$this->assertSame( '```', Markdown_Escape::block( 'plain' )[0] );
	}

	public function test_control_characters_are_made_visible_everywhere(): void {
		$this->assertStringContainsString( '\\x1B', Markdown_Escape::code( "a\033[2Jb" ) );
		$this->assertStringNotContainsString( "\033", Markdown_Escape::text( "a\033b" ) );
		$this->assertStringNotContainsString( "\033", implode( "\n", Markdown_Escape::block( "a\033b" ) ) );
	}

	public function test_bidi_controls_and_stray_bytes_are_made_visible_in_every_form(): void {
		$name = "evil\xe2\x80\xaegnp.php\x9b";

		foreach ( array( Markdown_Escape::text( $name ), Markdown_Escape::line_start( $name ), Markdown_Escape::code( $name ), implode( "\n", Markdown_Escape::block( $name ) ) ) as $escaped ) {
			$this->assertStringContainsString( 'evil\\xE2\\x80\\xAEgnp.php\\x9B', str_replace( '\\\\', '\\', $escaped ) );
			$this->assertStringNotContainsString( "\xe2\x80\xae", $escaped );
			$this->assertStringNotContainsString( "\x9b", $escaped );
		}
	}

	/**
	 * @dataProvider bare_links
	 * @param string $text     Text with something GitHub would turn into a link.
	 * @param string $expected Text as written to the report.
	 */
	public function test_a_bare_link_is_put_in_a_code_span_so_it_is_not_clickable( string $text, string $expected ): void {
		$this->assertSame( $expected, Markdown_Escape::text( $text ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function bare_links(): array {
		return array(
			'https url'   => array( 'Visit https://phish.example/x now', 'Visit `https://phish.example/x` now' ),
			'http url'    => array( 'http://phish.example', '`http://phish.example`' ),
			'www host'    => array( 'see www.phish.example/login', 'see `www.phish.example/login`' ),
			'email'       => array( 'mail jane@phish.example today', 'mail `jane@phish.example` today' ),
			'with markup' => array( '*bold* https://a.example/_x_ <b>', '\*bold\* `https://a.example/_x_` \<b\>' ),
			'backtick'    => array( 'https://a.example/`x`', '`https://a.example/`\\`x\\`' ),
		);
	}

	public function test_the_bare_links_provider_is_not_empty(): void {
		$this->assertCount( 6, $this->bare_links() );
	}

	public function test_text_without_links_is_escaped_as_before(): void {
		$this->assertSame( 'wp\_options is 3 MiB', Markdown_Escape::text( 'wp_options is 3 MiB' ) );
	}
}
