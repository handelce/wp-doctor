<?php
/**
 * Tests for Config_Parser.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Site;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Site\Config;
use WP_Doctor\Site\Config_Parser;

final class Config_Parser_Test extends TestCase {

	private function parse( string $body ): Config {
		return ( new Config_Parser() )->parse( "<?php\n" . $body );
	}

	/**
	 * @dataProvider literal_values
	 * @param string $literal PHP source for the value.
	 * @param mixed  $expected Value after parsing.
	 */
	public function test_a_literal_define_is_resolved( string $literal, $expected ): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', $literal );" );

		$this->assertTrue( $config->has( 'EXAMPLE_VALUE' ), "$literal was not resolved" );
		$this->assertSame( $expected, $config->value( 'EXAMPLE_VALUE' ) );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function literal_values(): array {
		return array(
			'single quoted'         => array( "'a b'", 'a b' ),
			'single quoted escapes' => array( "'it\\'s a \\\\ path'", "it's a \\ path" ),
			'double quoted'         => array( '"a b"', 'a b' ),
			'double quoted escapes' => array( '"line\\nnext \\$x \\"q\\""', "line\nnext \$x \"q\"" ),
			'integer'               => array( '42', 42 ),
			'negative integer'      => array( '-1', -1 ),
			'hex integer'           => array( '0x1F', 31 ), // phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- PHP source text for the parser.
			'octal integer'         => array( '0644', 420 ),
			'float'                 => array( '1.5', 1.5 ),
			'true'                  => array( 'true', true ),
			'false upper case'      => array( 'FALSE', false ),
			'null'                  => array( 'null', null ),
			'fully qualified true'  => array( '\\true', true ),
		);
	}

	public function test_the_literal_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->literal_values() );
	}

	/**
	 * @dataProvider computed_values
	 * @param string $expression PHP source for the value.
	 * @param string $reason     Expected unresolved reason.
	 */
	public function test_a_computed_define_is_recorded_as_unresolved( string $expression, string $reason ): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', $expression );" );

		$this->assertFalse( $config->has( 'EXAMPLE_VALUE' ), "$expression was resolved" );
		$this->assertSame( array( 'EXAMPLE_VALUE' => $reason ), $config->unresolved() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function computed_values(): array {
		return array(
			'function call'        => array( "getenv( 'X' )", 'computed value' ),
			'concatenation'        => array( "'a' . 'b'", 'computed value' ),
			'arithmetic'           => array( '1024 * 1024', 'computed value' ),
			'variable'             => array( '$value', 'computed value' ),
			'superglobal'          => array( "\$_SERVER['X']", 'computed value' ),
			'named constant'       => array( 'E_ALL', 'computed value' ),
			'magic constant'       => array( '__DIR__', 'computed value' ),
			'array'                => array( "array( 'a' )", 'computed value' ),
			'interpolated string'  => array( '"a $b"', 'computed value' ),
			'hex escape in string' => array( '"\\x41"', 'unsupported string escape' ),
			'ternary'              => array( "true ? 'a' : 'b'", 'computed value' ),
		);
	}

	public function test_the_computed_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->computed_values() );
	}

	public function test_a_heredoc_value_is_unresolved(): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', <<<EOT\nabc\nEOT\n);" );

		$this->assertSame( array( 'EXAMPLE_VALUE' => 'computed value' ), $config->unresolved() );
	}

	/**
	 * @dataProvider conditional_defines
	 * @param string $source PHP source containing one define.
	 */
	public function test_a_define_that_is_not_a_plain_top_level_statement_is_unresolved( string $source ): void {
		$config = $this->parse( $source );

		$this->assertFalse( $config->has( 'EXAMPLE_VALUE' ), 'The conditional define was resolved' );
		$this->assertSame( array( 'EXAMPLE_VALUE' => 'defined conditionally' ), $config->unresolved() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function conditional_defines(): array {
		return array(
			'inside an if block'     => array( "if ( \$x ) { define( 'EXAMPLE_VALUE', 1 ); }" ),
			'guard names another'    => array( "if ( ! defined( 'EXAMPLE_OTHER' ) ) { define( 'EXAMPLE_VALUE', 1 ); }" ),
			'guard without negation' => array( "if ( defined( 'EXAMPLE_VALUE' ) ) { define( 'EXAMPLE_VALUE', 1 ); }" ),
			'guard with more code'   => array( "if ( ! defined( 'EXAMPLE_VALUE' ) ) { define( 'EXAMPLE_VALUE', 1 ); run(); }" ),
			'guard with an else'     => array( "if ( ! defined( 'EXAMPLE_VALUE' ) ) { define( 'EXAMPLE_VALUE', 1 ); } else { run(); }" ),
			'guard with an elseif'   => array( "if ( ! defined( 'EXAMPLE_VALUE' ) ) { define( 'EXAMPLE_VALUE', 1 ); } elseif ( \$x ) { run(); }" ),
			'guard in a function'    => array( "function setup() { if ( ! defined( 'EXAMPLE_VALUE' ) ) { define( 'EXAMPLE_VALUE', 1 ); } }" ),
			'braceless if'           => array( "if ( \$x ) define( 'EXAMPLE_VALUE', 1 );" ),
			'braceless else'         => array( "if ( \$x ) { \$y = 1; } else define( 'EXAMPLE_VALUE', 1 );" ),
			'after logical or'       => array( "defined( 'EXAMPLE_VALUE' ) or define( 'EXAMPLE_VALUE', 1 );" ),
			'after double pipe'      => array( "defined( 'EXAMPLE_VALUE' ) || define( 'EXAMPLE_VALUE', 1 );" ),
			'inside a function body' => array( "function setup() { define( 'EXAMPLE_VALUE', 1 ); }" ),
			'inside a switch'        => array( "switch ( \$x ) { case 'a': define( 'EXAMPLE_VALUE', 1 ); break; }" ),
			'alternative syntax'     => array( "if ( \$x ):\n define( 'EXAMPLE_VALUE', 1 );\nendif;" ),
			'inside a ternary'       => array( "\$x ? define( 'EXAMPLE_VALUE', 1 ) : null;" ),
			'inside another call'    => array( "run( define( 'EXAMPLE_VALUE', 1 ) );" ),
		);
	}

	public function test_the_conditional_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->conditional_defines() );
	}

	public function test_a_define_guarded_by_defined_for_the_same_name_is_resolved(): void {
		$config = $this->parse( "if ( ! defined( 'EXAMPLE_VALUE' ) ) {\n\tdefine( 'EXAMPLE_VALUE', false );\n}" );

		$this->assertTrue( $config->has( 'EXAMPLE_VALUE' ) );
		$this->assertFalse( $config->value( 'EXAMPLE_VALUE' ) );
		$this->assertSame( array(), $config->unresolved() );
	}

	public function test_a_guarded_define_with_a_computed_value_is_unresolved_as_computed(): void {
		$config = $this->parse( "if ( ! defined( 'EXAMPLE_VALUE' ) ) { define( 'EXAMPLE_VALUE', getenv( 'EXAMPLE' ) ); }" );

		$this->assertFalse( $config->has( 'EXAMPLE_VALUE' ) );
		$this->assertSame( array( 'EXAMPLE_VALUE' => 'computed value' ), $config->unresolved() );
	}

	public function test_an_earlier_define_wins_over_a_guarded_one(): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', true );\nif ( ! defined( 'EXAMPLE_VALUE' ) ) { define( 'EXAMPLE_VALUE', false ); }" );

		$this->assertTrue( $config->value( 'EXAMPLE_VALUE' ) );
	}

	public function test_a_guarded_define_at_the_end_of_a_truncated_file_does_not_throw(): void {
		$config = $this->parse( "if ( ! defined( 'EXAMPLE_VALUE' ) ) {" );

		$this->assertSame( array(), $config->constants() );
	}

	public function test_a_define_after_a_closed_block_is_top_level(): void {
		$config = $this->parse( "if ( \$x ) { \$y = 1; }\ndefine( 'EXAMPLE_VALUE', 7 );" );

		$this->assertSame( 7, $config->value( 'EXAMPLE_VALUE' ) );
	}

	public function test_a_fully_qualified_define_is_recognised(): void {
		$config = $this->parse( "\\define( 'EXAMPLE_VALUE', 'x' );" );

		$this->assertSame( 'x', $config->value( 'EXAMPLE_VALUE' ) );
	}

	public function test_method_calls_named_define_are_not_definitions_and_static_ones_are_unresolved(): void {
		$config = $this->parse( "\$o->define( 'EXAMPLE_VALUE', 1 ); Foo::define( 'EXAMPLE_OTHER', 2 ); function define_all() {}" );

		$this->assertSame( array(), $config->constants() );
		$this->assertSame( array( 'EXAMPLE_OTHER' ), array_keys( $config->unresolved() ) );
		$this->assertStringContainsString( 'Foo::define()', $config->unresolved()['EXAMPLE_OTHER'] );
		$this->assertSame( 0, $config->computed_name_defines() );
	}

	public function test_a_third_define_argument_is_accepted(): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', 'x', true );" );

		$this->assertSame( 'x', $config->value( 'EXAMPLE_VALUE' ) );
	}

	public function test_the_first_definition_of_a_constant_wins(): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', 'first' );\ndefine( 'EXAMPLE_VALUE', 'second' );" );

		$this->assertSame( 'first', $config->value( 'EXAMPLE_VALUE' ) );
	}

	public function test_a_conditional_first_definition_keeps_the_constant_unresolved(): void {
		$config = $this->parse( "if ( \$x ) { define( 'EXAMPLE_VALUE', 'a' ); }\ndefine( 'EXAMPLE_VALUE', 'b' );" );

		$this->assertFalse( $config->has( 'EXAMPLE_VALUE' ) );
		$this->assertArrayHasKey( 'EXAMPLE_VALUE', $config->unresolved() );
	}

	public function test_comments_and_strings_that_mention_define_are_ignored(): void {
		$config = $this->parse(
			"// define( 'IN_COMMENT', 1 );\n/* define( 'IN_BLOCK', 1 ); */\n# define( 'IN_HASH', 1 );\n\$s = \"define( 'IN_STRING', 1 );\";"
		);

		$this->assertSame( array(), $config->constants() );
		$this->assertSame( array(), $config->unresolved() );
	}

	public function test_a_define_with_a_computed_name_is_counted(): void {
		$config = $this->parse( "define( \$name, 'x' );\ndefine( 'A_' . 'B', 'x' );" );

		$this->assertSame( 2, $config->computed_name_defines() );
	}

	public function test_const_statements_are_read_like_defines(): void {
		$config = $this->parse( "const EXAMPLE_VALUE = 'x';\nconst EXAMPLE_OTHER = getenv( 'X' );" );

		$this->assertSame( 'x', $config->value( 'EXAMPLE_VALUE' ) );
		$this->assertSame( array( 'EXAMPLE_OTHER' => 'computed value' ), $config->unresolved() );
	}

	public function test_the_wp_settings_require_is_not_an_unread_include(): void {
		$config = $this->parse( "require_once ABSPATH . 'wp-settings.php';" );

		$this->assertSame( 0, $config->unread_code() );
	}

	public function test_other_includes_are_counted_at_any_depth(): void {
		$config = $this->parse(
			"include 'a.php';\nrequire_once __DIR__ . '/b.php';\nif ( \$x ) { include_once 'c.php'; }\nrequire_once ABSPATH . 'wp-settings.php';"
		);

		$this->assertSame( 3, $config->unread_code() );
	}

	public function test_a_literal_table_prefix_is_read(): void {
		$config = $this->parse( "\$table_prefix = 'wptest_';" );

		$this->assertSame( 'wptest_', $config->table_prefix() );
	}

	/**
	 * @dataProvider bad_table_prefixes
	 * @param string $source PHP source assigning the prefix.
	 * @param string $reason Expected problem.
	 */
	public function test_a_table_prefix_that_is_not_usable_is_unresolved( string $source, string $reason ): void {
		$config = $this->parse( $source );

		$this->assertNotNull( $config->skip_reason( Config::TABLE_PREFIX ) );
		$this->assertStringContainsString( $reason, (string) $config->skip_reason( Config::TABLE_PREFIX ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function bad_table_prefixes(): array {
		return array(
			'computed'         => array( "\$table_prefix = getenv( 'X' );", 'computed value' ),
			'concatenated'     => array( "\$table_prefix = 'wp' . '_';", 'computed value' ),
			'appended'         => array( "\$table_prefix = 'wp'; \$table_prefix .= '_';", 'computed value' ),
			'conditional'      => array( "if ( \$x ) { \$table_prefix = 'wp_'; }", 'defined conditionally' ),
			'trailing newline' => array( "\$table_prefix = 'wp_\n';", 'letters, digits and underscore' ),
			'quote character'  => array( "\$table_prefix = \"wp'_\";", 'letters, digits and underscore' ),
			'backtick'         => array( "\$table_prefix = 'wp`_';", 'letters, digits and underscore' ),
			'space'            => array( "\$table_prefix = 'wp _';", 'letters, digits and underscore' ),
			'empty'            => array( "\$table_prefix = '';", 'empty' ),
			'not a string'     => array( '$table_prefix = 5;', 'not a string' ),
			'never assigned'   => array( "define( 'X', 1 );", 'not defined' ),
		);
	}

	public function test_the_bad_prefix_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->bad_table_prefixes() );
	}

	public function test_reading_a_table_prefix_variable_is_not_an_assignment(): void {
		$config = $this->parse( "\$table_prefix = 'wp_';\n\$other = \$table_prefix;\n\$o->\$table_prefix = 1;\nglobal \$table_prefix;" );

		$this->assertSame( 'wp_', $config->table_prefix() );
	}

	public function test_the_last_top_level_prefix_assignment_wins(): void {
		$config = $this->parse( "\$table_prefix = 'a_';\n\$table_prefix = 'b_';" );

		$this->assertSame( 'b_', $config->table_prefix() );
	}

	public function test_a_byte_order_mark_and_windows_line_endings_do_not_change_the_result(): void {
		$body  = "// note\ndefine( 'EXAMPLE_NAME', 'example_db' );\n\$table_prefix = 'wp_';\n/* block\ncomment */\ndefine( 'EXAMPLE_FLAG', getenv( 'X' ) );\n";
		$plain = $this->parse( $body );

		$bom  = ( new Config_Parser() )->parse( "\xEF\xBB\xBF<?php\n" . $body );
		$crlf = ( new Config_Parser() )->parse( str_replace( "\n", "\r\n", "<?php\n" . $body ) );

		$this->assertSame( 'example_db', $plain->value( 'EXAMPLE_NAME' ), 'Nothing was read to compare' );
		foreach ( array( $bom, $crlf ) as $config ) {
			$this->assertSame( $plain->constants(), $config->constants() );
			$this->assertSame( $plain->unresolved(), $config->unresolved() );
			$this->assertSame( 'wp_', $config->table_prefix() );
		}
	}

	public function test_a_define_after_an_include_that_is_not_read_is_unresolved(): void {
		$config = $this->parse( "define( 'EXAMPLE_FIRST', 'a' );\nrequire __DIR__ . '/local-config.php';\ndefine( 'EXAMPLE_SECOND', 'b' );" );

		$this->assertSame( 'a', $config->value( 'EXAMPLE_FIRST' ), 'A define before the include cannot be overridden' );
		$this->assertFalse( $config->has( 'EXAMPLE_SECOND' ) );
		$this->assertStringContainsString( 'an include or eval() before it is not read', $config->unresolved()['EXAMPLE_SECOND'] );
	}

	public function test_a_conditional_include_also_shadows_later_defines(): void {
		$config = $this->parse( "if ( file_exists( __DIR__ . '/local-config.php' ) ) { include __DIR__ . '/local-config.php'; }\ndefine( 'EXAMPLE_VALUE', 'b' );" );

		$this->assertArrayHasKey( 'EXAMPLE_VALUE', $config->unresolved() );
		$this->assertFalse( $config->has( 'EXAMPLE_VALUE' ) );
	}

	/**
	 * @dataProvider harmless_includes
	 * @param string $statement Include statement.
	 */
	public function test_the_autoloader_and_wp_settings_do_not_shadow_defines( string $statement ): void {
		$config = $this->parse( "$statement\ndefine( 'EXAMPLE_VALUE', 'b' );\n\$table_prefix = 'wp_';\n$statement" );

		$this->assertSame( 'b', $config->value( 'EXAMPLE_VALUE' ) );
		$this->assertSame( 'wp_', $config->table_prefix() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function harmless_includes(): array {
		return array(
			'composer autoloader' => array( "require_once dirname( __DIR__ ) . '/vendor/autoload.php';" ),
			'double quoted'       => array( 'require_once __DIR__ . "/vendor/autoload.php";' ),
			'wp-settings'         => array( "require_once ABSPATH . 'wp-settings.php';" ),
		);
	}

	public function test_the_harmless_include_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->harmless_includes() );
	}

	public function test_a_table_prefix_before_an_include_that_is_not_read_is_unavailable(): void {
		$config = $this->parse( "\$table_prefix = 'wp_';\nrequire __DIR__ . '/local-config.php';" );

		$this->assertStringContainsString( 'an include or eval() after it is not read', (string) $config->skip_reason( Config::TABLE_PREFIX ) );
	}

	public function test_a_table_prefix_assigned_after_an_include_is_read(): void {
		$config = $this->parse( "require __DIR__ . '/local-config.php';\n\$table_prefix = 'wp_';" );

		$this->assertNull( $config->skip_reason( Config::TABLE_PREFIX ) );
		$this->assertSame( 'wp_', $config->table_prefix() );
	}

	public function test_a_table_prefix_set_through_globals_is_unavailable(): void {
		$config = $this->parse( "\$table_prefix = 'wp_';\n\$GLOBALS['table_prefix'] = 'other_';" );

		$this->assertStringContainsString( 'computed value', (string) $config->skip_reason( Config::TABLE_PREFIX ) );
	}

	public function test_html_outside_php_tags_is_ignored(): void {
		$config = ( new Config_Parser() )->parse( "<html><?php define( 'EXAMPLE_VALUE', 1 ); ?>\n<p>define( 'IN_HTML', 1 );</p>" );

		$this->assertSame( array( 'EXAMPLE_VALUE' => 1 ), $config->constants() );
	}

	public function test_unparseable_source_does_not_throw(): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', 1 ); if ( {{{ \$x" );

		$this->assertSame( 1, $config->value( 'EXAMPLE_VALUE' ) );
	}

	public function test_a_literal_ini_set_is_recorded_with_the_last_call_winning(): void {
		$config = $this->parse( "ini_set( 'display_errors', '1' );\n@ini_set( 'memory_limit', '256M' );\n\\ini_set( 'display_errors', 0 );" );

		$this->assertTrue( $config->has_ini_setting( 'display_errors' ) );
		$this->assertSame( 0, $config->ini_setting( 'display_errors' ) );
		$this->assertSame( '256M', $config->ini_setting( 'memory_limit' ) );
		$this->assertSame( array(), $config->unresolved_ini_settings() );
	}

	public function test_a_computed_or_conditional_ini_set_is_unresolved_with_the_reason(): void {
		$config = $this->parse( "ini_set( 'memory_limit', getenv( 'M' ) );\nif ( \$x ) { ini_set( 'display_errors', '1' ); }" );

		$this->assertFalse( $config->has_ini_setting( 'memory_limit' ) );
		$this->assertSame( 'computed value', $config->unresolved_ini_settings()['memory_limit'] );
		$this->assertSame( 'defined conditionally', $config->unresolved_ini_settings()['display_errors'] );
	}

	public function test_a_later_conditional_ini_set_makes_an_earlier_literal_unresolved(): void {
		$config = $this->parse( "ini_set( 'display_errors', '0' );\nif ( \$x ) { ini_set( 'display_errors', '1' ); }" );

		$this->assertFalse( $config->has_ini_setting( 'display_errors' ) );
		$this->assertSame( 'defined conditionally', $config->unresolved_ini_settings()['display_errors'] );
	}

	public function test_an_unread_include_after_an_ini_set_makes_it_unresolved(): void {
		$config = $this->parse( "ini_set( 'display_errors', '0' );\nrequire __DIR__ . '/local.php';\nini_set( 'memory_limit', '64M' );" );

		$this->assertFalse( $config->has_ini_setting( 'display_errors' ) );
		$this->assertSame( 'an include or eval() after it is not read and may change it', $config->unresolved_ini_settings()['display_errors'] );
		$this->assertSame( '64M', $config->ini_setting( 'memory_limit' ) );
	}

	public function test_an_ini_set_with_a_computed_name_or_a_method_of_that_name_is_ignored(): void {
		$config = $this->parse( "ini_set( \$name, '1' );\n\$obj->ini_set( 'display_errors', '1' );\nfunction ini_set( \$a, \$b ) {}" );

		$this->assertSame( array(), $config->unresolved_ini_settings() );
		$this->assertFalse( $config->has_ini_setting( 'display_errors' ) );
	}

	public function test_reading_an_ini_setting_that_was_not_read_throws(): void {
		$this->expectException( \LogicException::class );
		$this->parse( '' )->ini_setting( 'display_errors' );
	}

	/**
	 * @dataProvider path_expressions
	 * @param string $expression PHP source for the value.
	 * @param string $base       Expected base.
	 * @param string $suffix     Expected literal.
	 */
	public function test_a_directory_built_from_a_base_and_one_literal_is_a_path_constant( string $expression, string $base, string $suffix ): void {
		$config = $this->parse( "define( 'WP_CONTENT_DIR', $expression );" );

		$this->assertSame(
			array(
				'base'   => $base,
				'suffix' => $suffix,
			),
			$config->path_constant( 'WP_CONTENT_DIR' )
		);
		$this->assertFalse( $config->has( 'WP_CONTENT_DIR' ) );
		$this->assertArrayNotHasKey( 'WP_CONTENT_DIR', $config->unresolved() );
		$this->assertTrue( $config->is_defined( 'WP_CONTENT_DIR' ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function path_expressions(): array {
		return array(
			'__DIR__'           => array( "__DIR__ . '/app'", '__DIR__', '/app' ),
			'dirname __FILE__'  => array( "dirname( __FILE__ ) . '/content'", '__DIR__', '/content' ),
			'ABSPATH'           => array( "ABSPATH . 'app/uploads'", 'ABSPATH', 'app/uploads' ),
			'qualified ABSPATH' => array( "\\ABSPATH . 'files'", 'ABSPATH', 'files' ),
			'double quotes'     => array( '__DIR__ . "/app"', '__DIR__', '/app' ),
		);
	}

	public function test_the_path_expressions_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->path_expressions() );
	}

	/**
	 * @dataProvider not_path_expressions
	 * @param string $expression PHP source for the value.
	 */
	public function test_any_other_path_arithmetic_stays_unresolved( string $expression ): void {
		$config = $this->parse( "define( 'WP_CONTENT_DIR', $expression );" );

		$this->assertNull( $config->path_constant( 'WP_CONTENT_DIR' ) );
		$this->assertSame( 'computed value', $config->unresolved()['WP_CONTENT_DIR'] );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function not_path_expressions(): array {
		return array(
			'two literals'   => array( "__DIR__ . '/app' . '/content'" ),
			'variable'       => array( '__DIR__ . $dir' ),
			'function'       => array( "__DIR__ . getenv( 'CONTENT' )" ),
			'dirname of dir' => array( "dirname( __DIR__ ) . '/app'" ),
			'other constant' => array( "WP_HOME . '/app'" ),
			'literal first'  => array( "'/var/www' . __DIR__" ),
		);
	}

	public function test_the_not_path_expressions_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->not_path_expressions() );
	}

	public function test_a_conditional_path_constant_is_unresolved_and_an_earlier_define_wins(): void {
		$conditional = $this->parse( "if ( \$x ) { define( 'WP_CONTENT_DIR', __DIR__ . '/app' ); }" );
		$twice       = $this->parse( "define( 'WP_CONTENT_DIR', '/srv/site/app' );\ndefine( 'WP_CONTENT_DIR', __DIR__ . '/other' );" );

		$this->assertNull( $conditional->path_constant( 'WP_CONTENT_DIR' ) );
		$this->assertSame( 'defined conditionally', $conditional->unresolved()['WP_CONTENT_DIR'] );
		$this->assertSame( '/srv/site/app', $twice->value( 'WP_CONTENT_DIR' ) );
		$this->assertNull( $twice->path_constant( 'WP_CONTENT_DIR' ) );
	}

	public function test_a_path_constant_after_an_unread_include_is_unresolved(): void {
		$config = $this->parse( "require __DIR__ . '/local.php';\ndefine( 'WP_CONTENT_DIR', __DIR__ . '/app' );" );

		$this->assertNull( $config->path_constant( 'WP_CONTENT_DIR' ) );
		$this->assertSame( 'an include or eval() before it is not read and may define it', $config->unresolved()['WP_CONTENT_DIR'] );
	}

	/**
	 * @dataProvider unseen_variable_changes
	 * @param string $statement Statement that can change variables without naming them.
	 */
	public function test_a_construct_that_can_change_variables_unseen_makes_the_prefix_unavailable( string $statement ): void {
		$config = $this->parse( "\$table_prefix = 'wptest_';\n$statement" );

		$this->assertStringContainsString( 'change variables unseen', (string) $config->skip_reason( Config::TABLE_PREFIX ) );
		$this->assertSame( 'wptest_', $this->parse( "\$table_prefix = 'wptest_';" )->table_prefix() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unseen_variable_changes(): array {
		return array(
			'variable variable' => array( "\$name = 'table_prefix';\n\$\$name = 'other_';" ),
			'braced variable'   => array( "\${'table_' . 'prefix'} = 'other_';" ),
			'extract'           => array( 'extract( $settings );' ),
			'qualified extract' => array( "\\extract( \$settings, EXTR_OVERWRITE );" ),
			'parse_str one arg' => array( "parse_str( \$_SERVER['QUERY_STRING'] );" ),
			'nested in a block' => array( "if ( \$x ) {\n\textract( \$settings );\n}" ),
		);
	}

	public function test_the_unseen_variable_changes_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unseen_variable_changes() );
	}

	public function test_parse_str_with_a_result_argument_and_a_method_named_extract_leave_the_prefix_alone(): void {
		$config = $this->parse( "\$table_prefix = 'wptest_';\nparse_str( \$query, \$result );\n\$settings->extract( 'x' );\n\$price = \$total . '\$';" );

		$this->assertSame( 'wptest_', $config->table_prefix() );
	}

	public function test_an_unseen_change_does_not_touch_constants(): void {
		$config = $this->parse( "define( 'DB_NAME', 'example_db' );\nextract( \$settings );" );

		$this->assertSame( 'example_db', $config->value( 'DB_NAME' ) );
		$this->assertNotNull( $config->skip_reason( Config::TABLE_PREFIX ) );
	}

	public function test_no_branch_of_an_alternative_syntax_if_is_read_as_a_top_level_literal(): void {
		$config = $this->parse(
			"if ( getenv( 'ENV' ) === 'staging' ):\n\tdefine( 'DB_HOST', 'staging.example.test' );\n\tdefine( 'DB_NAME', 'staging_db' );\n\t\$table_prefix = 'stg_';\n"
			. "elseif ( \$x ):\n\tdefine( 'DB_USER', 'example_user' );\n\t\$table_prefix = 'alt_';\n"
			. "else:\n\tdefine( 'DB_NAME', 'example_db' );\n\t\$table_prefix = 'prod_';\nendif;\n"
			. "define( 'EXAMPLE_AFTER', 'after' );"
		);

		$this->assertSame( array( 'EXAMPLE_AFTER' => 'after' ), $config->constants() );
		$this->assertSame(
			array(
				'DB_HOST' => 'defined conditionally',
				'DB_NAME' => 'defined conditionally',
				'DB_USER' => 'defined conditionally',
			),
			$config->unresolved()
		);
		$this->assertStringContainsString( 'defined conditionally', (string) $config->skip_reason( Config::TABLE_PREFIX ) );
	}

	/**
	 * @dataProvider alternative_syntax_blocks
	 * @param string $source PHP source with a second statement inside an alternative-syntax block.
	 */
	public function test_every_statement_inside_an_alternative_syntax_block_is_conditional( string $source ): void {
		$config = $this->parse( $source . "\n\$table_prefix = 'wp_';" );

		$this->assertSame( array( 'EXAMPLE_VALUE' => 'defined conditionally' ), $config->unresolved() );
		$this->assertSame( array(), $config->constants() );
		$this->assertSame( 'wp_', $config->table_prefix() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function alternative_syntax_blocks(): array {
		return array(
			'while'   => array( "while ( \$x ):\n\t\$y = 1;\n\tdefine( 'EXAMPLE_VALUE', 1 );\nendwhile;" ),
			'for'     => array( "for ( \$i = 0; \$i < 1; \$i++ ):\n\t\$y = 1;\n\tdefine( 'EXAMPLE_VALUE', 1 );\nendfor;" ),
			'foreach' => array( "foreach ( \$list as \$item ):\n\t\$y = 1;\n\tdefine( 'EXAMPLE_VALUE', 1 );\nendforeach;" ),
			'switch'  => array( "switch ( \$x ):\n\tcase 'a':\n\t\t\$y = 1;\n\t\tdefine( 'EXAMPLE_VALUE', 1 );\n\t\tbreak;\nendswitch;" ),
			'declare' => array( "declare( ticks = 1 ):\n\t\$y = 1;\n\tdefine( 'EXAMPLE_VALUE', 1 );\nenddeclare;" ),
			'else'    => array( "if ( \$x ):\n\t\$y = 1;\nelse:\n\t\$y = 2;\n\tdefine( 'EXAMPLE_VALUE', 1 );\nendif;" ),
		);
	}

	public function test_the_alternative_syntax_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->alternative_syntax_blocks() );
	}

	public function test_a_binary_string_prefix_is_not_part_of_the_value(): void {
		$config = $this->parse( "define( 'EXAMPLE_VALUE', b'example' );\ndefine( B\"EXAMPLE_OTHER\", B\"line\\n\" );\n\$table_prefix = b'wp_';" );

		$this->assertSame(
			array(
				'EXAMPLE_VALUE' => 'example',
				'EXAMPLE_OTHER' => "line\n",
			),
			$config->constants()
		);
		$this->assertSame( 'wp_', $config->table_prefix() );
	}

	/**
	 * @dataProvider unfollowed_prefix_changes
	 * @param string $statement Statement that changes $table_prefix in a way the parser does not follow.
	 */
	public function test_a_table_prefix_changed_by_an_unfollowed_statement_is_unavailable( string $statement ): void {
		$config = $this->parse( "\$table_prefix = 'wptest_';\n$statement" );

		$this->assertNotNull( $config->skip_reason( Config::TABLE_PREFIX ) );
		$this->assertSame( 'wptest_', $this->parse( "\$table_prefix = 'wptest_';" )->table_prefix() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unfollowed_prefix_changes(): array {
		return array(
			'list'                 => array( "list( \$table_prefix ) = array( 'other_' );" ),
			'keyed list'           => array( "list( 'p' => \$table_prefix ) = \$settings;" ),
			'short destructuring'  => array( "[ \$table_prefix ] = array( 'other_' );" ),
			'nested destructuring' => array( '[ $a, [ $table_prefix ] ] = $settings;' ),
			'reference'            => array( "\$p = &\$table_prefix;\n\$p = 'other_';" ),
			'foreach target'       => array( 'foreach ( $list as $table_prefix ) {}' ),
			'foreach value'        => array( 'foreach ( $list as $k => $table_prefix ) {}' ),
			'post increment'       => array( '$table_prefix++;' ),
			'pre decrement'        => array( '--$table_prefix;' ),
			'unset'                => array( 'unset( $table_prefix );' ),
			'by-reference arg'     => array( "preg_match( '/x/', \$s, \$table_prefix );" ),
			'eval'                 => array( 'eval( $code );' ),
		);
	}

	public function test_the_unfollowed_prefix_changes_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unfollowed_prefix_changes() );
	}

	public function test_reading_the_table_prefix_into_an_array_or_a_define_leaves_it_resolved(): void {
		$config = $this->parse( "\$table_prefix = 'wptest_';\n\$a = [ \$table_prefix ];\n\$b = array( 'p' => \$table_prefix );\ndefine( 'CUSTOM_USER_TABLE', \$table_prefix . 'users' );\nif ( \$table_prefix == 'x' ) {}" );

		$this->assertSame( 'wptest_', $config->table_prefix() );
	}

	public function test_a_const_in_a_namespace_does_not_define_the_global_constant(): void {
		$config = $this->parse( "namespace Example\\Site;\nconst DB_NAME = 'example_db';\ndefine( 'DB_USER', 'example_user' );" );

		$this->assertFalse( $config->has( 'DB_NAME' ) );
		$this->assertArrayNotHasKey( 'DB_NAME', $config->unresolved() );
		$this->assertSame( array( 'DB_USER' => 'example_user' ), $config->constants() );
	}

	public function test_a_namespace_relative_call_does_not_put_the_file_in_a_namespace(): void {
		$config = $this->parse( "namespace\\run();\nconst DB_NAME = 'example_db';" );

		$this->assertSame( array( 'DB_NAME' => 'example_db' ), $config->constants() );
	}

	public function test_eval_is_treated_like_an_include_that_is_not_read(): void {
		$config = $this->parse(
			"define( 'DB_NAME', 'example_db' );\n\$table_prefix = 'before_';\n"
			. "eval( \$code );\n"
			. "define( 'DB_USER', 'example_user' );\n\$table_prefix = 'after_';"
		);

		$this->assertSame( array( 'DB_NAME' => 'example_db' ), $config->constants(), 'A define before eval() is kept, since PHP keeps the first definition' );
		$this->assertArrayHasKey( 'DB_USER', $config->unresolved() );
		$this->assertStringContainsString( 'eval()', $config->unresolved()['DB_USER'] );
		$this->assertSame( 1, $config->unread_code() );
		$this->assertSame( 'after_', $config->table_prefix(), 'A prefix assigned after eval() is the one PHP keeps' );
	}

	public function test_a_table_prefix_assigned_before_eval_is_unavailable_with_the_reason(): void {
		$config = $this->parse( "\$table_prefix = 'before_';\neval( \$code );" );

		$this->assertStringContainsString( 'eval()', (string) $config->skip_reason( Config::TABLE_PREFIX ) );
	}

	public function test_an_include_whose_name_only_ends_like_wp_settings_is_not_wp_settings(): void {
		$config = $this->parse( "require __DIR__ . '/my-wp-settings.php';\ndefine( 'DB_NAME', 'example_db' );" );

		$this->assertSame( 1, $config->unread_code() );
		$this->assertArrayHasKey( 'DB_NAME', $config->unresolved() );
	}

	/**
	 * @dataProvider real_settings_includes
	 * @param string $statement Include of the real wp-settings.php.
	 */
	public function test_the_real_wp_settings_include_is_recognised( string $statement ): void {
		$config = $this->parse( "$statement\ndefine( 'DB_NAME', 'example_db' );" );

		$this->assertSame( 0, $config->unread_code() );
		$this->assertSame( array( 'DB_NAME' => 'example_db' ), $config->constants() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function real_settings_includes(): array {
		return array(
			'abspath'      => array( "require_once ABSPATH . 'wp-settings.php';" ),
			'dir'          => array( "require_once __DIR__ . '/wp-settings.php';" ),
			'double quote' => array( 'require_once( ABSPATH . "wp-settings.php" );' ),
		);
	}

	public function test_the_real_settings_includes_provider_is_not_empty(): void {
		$this->assertCount( 3, $this->real_settings_includes() );
	}

	public function test_a_static_define_call_is_unresolved_with_a_hint(): void {
		$config = $this->parse( "use Roots\\WPConfig\\Config;\nConfig::define( 'DB_NAME', env( 'DB_NAME' ) );\nConfig::apply();" );

		$this->assertFalse( $config->has( 'DB_NAME' ) );
		$this->assertStringContainsString( 'Config::define()', $config->unresolved()['DB_NAME'] );
		$this->assertStringContainsString( 'Config::define()', (string) $config->skip_reason( 'DB_NAME' ) );
	}
}
