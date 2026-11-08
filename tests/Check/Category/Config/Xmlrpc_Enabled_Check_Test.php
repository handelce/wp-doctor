<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Xmlrpc_Enabled_Check;
use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Xmlrpc_Enabled_Check_Test extends TestCase {

	private const WORDPRESS_RULES = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( 'xmlrpc.php', "<?php\n" );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check over the tree.
	 *
	 * @return Result
	 */
	private function run_check(): Result {
		return ( new Xmlrpc_Enabled_Check() )->run( Context_Factory::for_tree( $this->tree ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function blocking_files(): array {
		return array(
			'Files with Require'        => array( "<Files xmlrpc.php>\nRequire all denied\n</Files>" ),
			'quoted Files'              => array( "<Files \"xmlrpc.php\">\n  Order Deny,Allow\n  Deny from all\n</Files>" ),
			'FilesMatch'                => array( "<FilesMatch \"^(xmlrpc\\.php|wp-trackback\\.php)$\">\nRequire all denied\n</FilesMatch>" ),
			'rewrite to forbidden'      => array( "RewriteEngine On\nRewriteRule ^xmlrpc\\.php$ - [F,L]" ),
			'rewrite with a 403'        => array( 'RewriteRule ^xmlrpc\.php$ - [R=403,L]' ),
			'rewrite gone'              => array( 'RewriteRule xmlrpc - [G]' ),
			'RedirectMatch 403'         => array( 'RedirectMatch 403 /xmlrpc\.php' ),
			'after the WordPress rules' => array( self::WORDPRESS_RULES . "<Files xmlrpc.php>\nRequire all denied\n</Files>\n" ),
		);
	}

	/**
	 * @dataProvider blocking_files
	 * @param string $htaccess Text of the .htaccess.
	 */
	public function test_a_rule_that_denies_xmlrpc_is_recognised( string $htaccess ): void {
		$this->assertNotNull( Xmlrpc_Enabled_Check::blocking_rule( $htaccess ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function open_files(): array {
		return array(
			'WordPress rules only'   => array( self::WORDPRESS_RULES ),
			'commented out'          => array( "# <Files xmlrpc.php>\n# Require all denied\n# </Files>" ),
			'another file denied'    => array( "<Files wp-config.php>\nRequire all denied\n</Files>" ),
			'xmlrpc allowed'         => array( "<Files xmlrpc.php>\nRequire all granted\n</Files>" ),
			'rewrite without a deny' => array( 'RewriteRule ^xmlrpc\.php$ /index.php [L]' ),
			'deny after the block'   => array( "<Files xmlrpc.php>\n</Files>\nRequire all denied" ),
		);
	}

	/**
	 * @dataProvider open_files
	 * @param string $htaccess Text of the .htaccess.
	 */
	public function test_rules_that_leave_xmlrpc_open_are_not_taken_for_a_block( string $htaccess ): void {
		$this->assertNull( Xmlrpc_Enabled_Check::blocking_rule( $htaccess ) );
	}

	public function test_the_providers_have_samples(): void {
		$this->assertGreaterThan( 5, count( $this->blocking_files() ) );
		$this->assertGreaterThan( 4, count( $this->open_files() ) );
	}

	public function test_it_has_an_id_and_a_category_and_runs_without_the_config(): void {
		$check = new Xmlrpc_Enabled_Check();

		$this->assertSame( 'config.xmlrpc.enabled', $check->id() );
		$this->assertSame( 'config', $check->category() );
		$this->assertInstanceOf( Runs_Without_Config::class, $check );
	}

	public function test_an_endpoint_with_only_the_wordpress_rules_is_a_likely_info_notice(): void {
		$this->tree->file( '.htaccess', self::WORDPRESS_RULES );

		$finding = $this->run_check()->finding_list()[0];

		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 'xmlrpc.php', $finding->evidence()['endpoint'] );
		$this->assertSame( '.htaccess', $finding->evidence()['htaccess_read'] );
		$this->assertStringContainsString( 'Require all denied', $finding->fix() );
	}

	public function test_a_blocked_endpoint_passes(): void {
		$this->tree->file( '.htaccess', "<Files xmlrpc.php>\nRequire all denied\n</Files>\n" );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_missing_endpoint_passes(): void {
		$this->tree->remove_path( 'xmlrpc.php' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_with_core_in_a_subdirectory_the_core_htaccess_is_read_too(): void {
		$this->tree->remove_path( 'xmlrpc.php' );
		$this->tree->file( 'wp/xmlrpc.php', "<?php\n" );
		$this->tree->file( 'wp/.htaccess', "<Files xmlrpc.php>\nRequire all denied\n</Files>\n" );
		$this->tree->file( '.htaccess', self::WORDPRESS_RULES );

		$this->assertTrue( ( new Xmlrpc_Enabled_Check() )->run( Context_Factory::for_core_in( $this->tree, 'wp' ) )->is_pass() );

		$this->tree->remove_path( 'wp/.htaccess' );
		$evidence = ( new Xmlrpc_Enabled_Check() )->run( Context_Factory::for_core_in( $this->tree, 'wp' ) )->finding_list()[0]->evidence();
		$this->assertSame( 'wp/xmlrpc.php', $evidence['endpoint'] );
	}

	public function test_an_unreadable_htaccess_is_a_skip(): void {
		File_Modes::require_enforced_modes();
		$path = $this->tree->file( '.htaccess', self::WORDPRESS_RULES );
		chmod( $path, 0000 );

		$result = $this->run_check();

		chmod( $path, 0644 );
		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '.htaccess', (string) $result->message() );
	}
}
