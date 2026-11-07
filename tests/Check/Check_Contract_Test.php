<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Result;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Check_Contract_Test extends TestCase {

	private function context(): Context {
		$site = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );

		return Context::without_database( $site, new File_Scanner( $site->root() ), 'no database in this test' );
	}

	public function test_a_context_without_a_database_says_why_and_refuses_to_hand_one_out(): void {
		$context = $this->context();

		$this->assertSame( 'no database in this test', $context->database_unavailable() );
		$this->expectException( \LogicException::class );
		$context->database();
	}

	public function test_a_context_with_a_database_hands_it_out(): void {
		$site       = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$connection = new Read_Only_Connection( new Recording_Driver() );
		$context    = Context::with_database( $site, new File_Scanner( $site->root() ), $connection );

		$this->assertNull( $context->database_unavailable() );
		$this->assertSame( $connection, $context->database() );
		$this->assertSame( $site, $context->site() );
		$this->assertSame( $site->config(), $context->config() );
		$this->assertSame( $site->root(), $context->files()->root() );
	}

	public function test_a_context_without_a_database_needs_a_reason(): void {
		$site = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );

		$this->expectException( \InvalidArgumentException::class );
		Context::without_database( $site, new File_Scanner( $site->root() ), ' ' );
	}

	public function test_a_check_receives_a_context_and_returns_a_result(): void {
		$check = new class() implements Check {
			public function id(): string {
				return 'test.always.pass';
			}

			public function category(): string {
				return 'performance';
			}

			public function run( Context $context ): Result {
				return Result::pass();
			}
		};

		$result = $check->run( $this->context() );

		$this->assertSame( 'test.always.pass', $check->id() );
		$this->assertSame( 'performance', $check->category() );
		$this->assertTrue( $result->is_pass() );
	}

	public function test_a_table_name_is_the_quoted_site_prefix_and_name(): void {
		$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$context = Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( new Recording_Driver() ) );

		$this->assertNull( $context->site_tables_unavailable() );
		$this->assertSame( '`wptest_options`', $context->table( 'options' ) );
	}

	public function test_site_tables_are_unavailable_without_a_database(): void {
		$this->assertStringContainsString( 'no database in this test', (string) $this->context()->site_tables_unavailable() );
	}

	public function test_site_tables_are_unavailable_when_the_prefix_was_not_read(): void {
		$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/computed' );
		$context = Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( new Recording_Driver() ) );

		$this->assertStringContainsString( '$table_prefix', (string) $context->site_tables_unavailable() );
		$this->expectException( \LogicException::class );
		$context->table( 'options' );
	}

	/**
	 * @dataProvider unsafe_table_names
	 * @param string $name Table name without the prefix.
	 */
	public function test_a_table_name_that_is_not_plain_lowercase_is_refused( string $name ): void {
		$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$context = Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( new Recording_Driver() ) );

		$this->expectException( \InvalidArgumentException::class );
		$context->table( $name );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unsafe_table_names(): array {
		return array(
			'empty'            => array( '' ),
			'backtick'         => array( 'options`' ),
			'space'            => array( 'options x' ),
			'dot'              => array( 'db.options' ),
			'uppercase'        => array( 'Options' ),
			'trailing newline' => array( "options\n" ),
		);
	}

	public function test_the_unsafe_table_name_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unsafe_table_names() );
	}

	public function test_the_default_content_and_uploads_directories_are_used_when_wp_config_does_not_move_them(): void {
		$context = $this->context();

		$this->assertNull( $context->content_dir_unavailable() );
		$this->assertSame( 'wp-content', $context->content_dir() );
		$this->assertNull( $context->uploads_dir_unavailable() );
		$this->assertSame( 'wp-content/uploads', $context->uploads_dir() );
	}

	/**
	 * @dataProvider moved_directories
	 * @param string $config     wp-config.php statements.
	 * @param bool   $moves_content Whether the content directory is unavailable.
	 */
	public function test_a_directory_that_cannot_be_resolved_inside_the_root_is_unavailable( string $config, bool $moves_content ): void {
		$tree = new Temp_Tree();
		try {
			$context = Context_Factory::for_tree( $tree, $config );

			$this->assertStringContainsString( 'wp-config.php', (string) $context->uploads_dir_unavailable() );
			$this->assertSame( $moves_content, null !== $context->content_dir_unavailable() );
			$this->expectException( \LogicException::class );
			$context->uploads_dir();
		} finally {
			$tree->remove();
		}
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function moved_directories(): array {
		return array(
			'content dir outside the root' => array( "define( 'WP_CONTENT_DIR', '/srv/example/content' );", true ),
			'computed content dir'         => array( "define( 'WP_CONTENT_DIR', getenv( 'CONTENT' ) );", true ),
			'uploads escaping the root'    => array( "define( 'UPLOADS', '../media' );", false ),
		);
	}

	public function test_the_moved_directory_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->moved_directories() );
	}

	public function test_content_dir_refuses_to_answer_when_it_is_unavailable(): void {
		$tree = new Temp_Tree();
		try {
			$context = Context_Factory::for_tree( $tree, "define( 'WP_CONTENT_DIR', '/srv/example/content' );" );

			$this->expectException( \LogicException::class );
			$context->content_dir();
		} finally {
			$tree->remove();
		}
	}
}
