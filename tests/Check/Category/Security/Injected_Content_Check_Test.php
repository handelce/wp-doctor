<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- The samples are post content holding script tags, which the check reads; nothing is output.

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Injected_Content_Check;
use WP_Doctor\Db\Database_Driver;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * The scripts are inert strings; every host is under example.test or .invalid.
 */
final class Injected_Content_Check_Test extends TestCase {

	private const NOW  = 1794096000;
	private const SITE = array( 'example.test' );
	private const LONG = 'eval(atob(...))';

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * A database stand-in answering the option lookups and the two posts queries.
	 *
	 * @param array<string, string>                  $options  Option values by name.
	 * @param array<int, array<string, string|null>> $scripted Rows for the script query.
	 * @param array<int, array<string, string|null>> $defaced  Rows for the defacement query.
	 * @return Database_Driver
	 */
	private static function driver( array $options, array $scripted, array $defaced = array() ): Database_Driver {
		return new class( $options, $scripted, $defaced ) implements Database_Driver {
			/**
			 * @var array<string, string>
			 */
			private $options;

			/**
			 * @var array<int, array<string, string|null>>
			 */
			private $scripted;

			/**
			 * @var array<int, array<string, string|null>>
			 */
			private $defaced;

			/**
			 * @var string[]
			 */
			public $statements = array();

			/**
			 * @param array<string, string>                  $options  Option values by name.
			 * @param array<int, array<string, string|null>> $scripted Rows for the script query.
			 * @param array<int, array<string, string|null>> $defaced  Rows for the defacement query.
			 */
			public function __construct( array $options, array $scripted, array $defaced ) {
				$this->options  = $options;
				$this->scripted = $scripted;
				$this->defaced  = $defaced;
			}

			/**
			 * @param string $sql      Statement.
			 * @param int    $max_rows Row limit.
			 * @return array<int, array<string, string|null>>
			 */
			public function execute( string $sql, int $max_rows ): array {
				$this->statements[] = $sql;
				foreach ( $this->options as $name => $value ) {
					if ( false !== strpos( $sql, "option_name = '$name'" ) ) {
						return false !== strpos( $sql, 'LENGTH(option_value)' ) ? array( array( 'size_bytes' => (string) strlen( $value ) ) ) : array( array( 'option_value' => $value ) );
					}
				}
				if ( false !== strpos( $sql, "LIKE '%<script%'" ) ) {
					return $this->scripted;
				}
				if ( false !== strpos( $sql, "LIKE '%hacked by%'" ) ) {
					return $this->defaced;
				}

				return array();
			}
		};
	}

	/**
	 * A post row as the script query returns it.
	 *
	 * @param int    $id      Post ID.
	 * @param string $content Post content.
	 * @return array<string, string|null>
	 */
	private static function post( int $id, string $content ): array {
		return array(
			'id'      => (string) $id,
			'type'    => 'post',
			'title'   => "Example post $id",
			'bytes'   => (string) strlen( $content ),
			'content' => $content,
		);
	}

	/**
	 * Runs the check.
	 *
	 * @param Database_Driver $driver Database stand-in.
	 * @param string          $config Statements for wp-config.php.
	 * @return Result
	 */
	private function run_with( Database_Driver $driver, string $config = '' ): Result {
		return ( new Injected_Content_Check( self::NOW ) )->run( Context_Factory::for_tree_with_driver( $this->tree, $driver, $config ) );
	}

	/**
	 * The finding with the given severity.
	 *
	 * @param Result $result   Check result.
	 * @param string $severity Severity name.
	 * @return Finding
	 */
	private function finding( Result $result, string $severity ): Finding {
		foreach ( $result->finding_list() as $finding ) {
			if ( $severity === $finding->severity()->name() ) {
				return $finding;
			}
		}
		$this->fail( "No $severity finding; status " . $result->status() );
	}

	/**
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public function scripts(): array {
		return array(
			'another host'          => array( '<p>Hi</p><script src="https://cdn.bad.invalid/a.js"></script>', array( 'script from cdn.bad.invalid' ) ),
			'protocol relative'     => array( "<script type='text/javascript' src='//bad.invalid/x.js'></script>", array( 'script from bad.invalid' ) ),
			'unquoted, upper case'  => array( '<SCRIPT SRC=https://Bad.Invalid/x.js></SCRIPT>', array( 'script from bad.invalid' ) ),
			'entity in the url'     => array( '<script src="https://bad.invalid/x.js?a=1&amp;b=2"></script>', array( 'script from bad.invalid' ) ),
			'inline eval'           => array( '<script>eval(atob("Ly8gaW5lcnQ="))</script>', array( 'inline script using eval()' ) ),
			'inline fromCharCode'   => array( '<script>var s = String.fromCharCode(47, 47);</script>', array( 'inline script using String.fromCharCode()' ) ),
			'inline document.write' => array( "<script>document.write('<p>')</script>", array( 'inline script using document.write()' ) ),
			'long encoded string'   => array( '<script>var d = "' . str_repeat( 'TG9yZW0g', 30 ) . '";</script>', array( 'inline script holding a long encoded string' ) ),
			'unclosed tag'          => array( '<script src="https://bad.invalid/x.js">', array( 'script from bad.invalid' ) ),
			'lookalike host'        => array( '<script src="https://notexample.test/x.js"></script>', array( 'script from notexample.test' ) ),
			'two hosts'             => array( '<script src="https://a.invalid/1.js"></script><script src="https://b.invalid/2.js"></script>', array( 'script from a.invalid', 'script from b.invalid' ) ),
		);
	}

	/**
	 * @dataProvider scripts
	 * @param string   $content  Post content.
	 * @param string[] $expected What is reported.
	 */
	public function test_likely_injected_scripts_are_described( string $content, array $expected ): void {
		$this->assertSame( $expected, Injected_Content_Check::suspicious_scripts( $content, self::SITE ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function ordinary_scripts(): array {
		return array(
			'own host'        => array( '<script src="https://example.test/wp-content/x.js"></script>' ),
			'www of own host' => array( '<script src="https://www.example.test/x.js"></script>' ),
			'subdomain'       => array( '<script src="https://cdn.example.test/x.js"></script>' ),
			'relative path'   => array( '<script src="/wp-includes/js/x.js"></script>' ),
			'plain inline'    => array( '<script>window.dataLayer = window.dataLayer || [];</script>' ),
			'json data block' => array( '<script type="application/ld+json">{"@type":"Article"}</script>' ),
		);
	}

	/**
	 * @dataProvider ordinary_scripts
	 * @param string $content Post content.
	 */
	public function test_scripts_from_the_site_or_without_hiding_tricks_are_not_reported( string $content ): void {
		$this->assertSame( array(), Injected_Content_Check::suspicious_scripts( $content, self::SITE ) );
	}

	public function test_the_providers_have_samples(): void {
		$this->assertGreaterThan( 8, count( $this->scripts() ) );
		$this->assertGreaterThan( 5, count( $this->ordinary_scripts() ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Injected_Content_Check();

		$this->assertSame( 'sec.content.injected', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_a_post_with_a_foreign_script_is_a_likely_warning_naming_the_post(): void {
		$driver = self::driver(
			array(
				'home'    => 'https://example.test',
				'siteurl' => 'https://example.test/wp',
			),
			array( self::post( 12, '<p>ok</p><script src="https://example.test/a.js"></script>' ), self::post( 40, '<script src="https://cdn.bad.invalid/a.js"></script>' ) )
		);

		$finding = $this->finding( $this->run_with( $driver ), 'warning' );

		$evidence = $finding->evidence();
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 2, $evidence['posts_with_scripts'] );
		$this->assertSame( 1, $evidence['posts_flagged'] );
		$this->assertSame( 'example.test', $evidence['site_hosts'] );
		$this->assertSame( 40, $evidence['post_1'] );
		$this->assertSame( 'Example post 40', $evidence['post_1_title'] );
		$this->assertSame( 'script from cdn.bad.invalid', $evidence['post_1_scripts'] );
		$this->assertStringContainsString( 'wp db search <host> --all-tables', $finding->fix() );
	}

	public function test_wp_home_in_wp_config_counts_as_the_site_host(): void {
		$driver = self::driver( array( 'home' => 'https://old.example.test' ), array( self::post( 3, '<script src="https://moved.invalid/x.js"></script>' ) ) );

		$this->assertTrue( $this->run_with( $driver, "define( 'WP_HOME', 'https://moved.invalid' );" )->is_pass() );
	}

	public function test_recent_defacement_text_is_an_info_notice(): void {
		$driver = self::driver(
			array( 'home' => 'https://example.test' ),
			array(),
			array(
				array(
					'id'       => '7',
					'type'     => 'page',
					'title'    => 'Hacked by someone',
					'modified' => '2026-11-07 10:00:00',
				),
			)
		);

		$finding = $this->finding( $this->run_with( $driver ), 'info' );

		$this->assertSame( 7, $finding->evidence()['post_1'] );
		$this->assertSame( '2026-11-07 10:00:00 UTC', $finding->evidence()['post_1_modified'] );
		$this->assertSame( 30, $finding->evidence()['recent_days'] );
	}

	public function test_the_defacement_query_only_reads_the_last_30_days_of_published_posts(): void {
		$driver = self::driver( array( 'home' => 'https://example.test' ), array() );

		$this->assertTrue( $this->run_with( $driver )->is_pass() );
		$query = implode( "\n", preg_grep( '/hacked by/', $driver->statements ) );
		$this->assertStringContainsString( "post_modified_gmt >= '2026-10-09 00:00:00'", $query );
		$this->assertStringContainsString( "post_status = 'publish'", $query );
	}

	public function test_a_post_longer_than_the_read_limit_makes_a_clean_result_a_skip(): void {
		$long          = self::post( 9, '<script>var a = 1;</script>' );
		$long['bytes'] = '2000000';
		$driver        = self::driver( array( 'home' => 'https://example.test' ), array( $long ) );

		$result = $this->run_with( $driver );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'read only in part', (string) $result->message() );
	}

	public function test_without_any_site_address_it_is_skipped(): void {
		$result = $this->run_with( self::driver( array(), array() ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'WP_HOME', (string) $result->message() );
	}
}
