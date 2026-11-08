<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Config_Permissions_Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Result;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Config_Permissions_Check_Test extends TestCase {

	/**
	 * Site whose wp-config.php each test gives a permission mode.
	 *
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
	 * Runs the check after giving wp-config.php a mode.
	 *
	 * @param int $mode Permission bits.
	 * @return Result
	 */
	private function run_with_mode( int $mode ): Result {
		File_Modes::require_unix_modes();
		$context = Context_Factory::for_tree( $this->tree );
		chmod( $this->tree->site() . '/wp-config.php', $mode );

		return ( new Config_Permissions_Check() )->run( $context );
	}

	public function test_file_modes_are_not_judged_on_windows(): void {
		$context = Context_Factory::for_tree_on( $this->tree, 'Windows' );
		$this->tree->file( 'wp-content/index.php', "<?php\n" );
		chmod( $this->tree->site() . '/wp-content/index.php', 0666 );
		chmod( $this->tree->site() . '/wp-config.php', 0666 );

		$result = ( new Config_Permissions_Check() )->run( $context );

		$this->assertSame( 'skipped', $result->status() );
		$this->assertStringContainsString( 'Windows', (string) $result->message() );
	}

	public function test_file_modes_are_judged_on_other_systems(): void {
		$context = Context_Factory::for_tree_on( $this->tree, 'Linux' );
		$this->tree->file( 'wp-content/index.php', "<?php\n" );
		chmod( $this->tree->site() . '/wp-content/index.php', 0666 );
		chmod( $this->tree->site() . '/wp-config.php', 0666 );

		$result = ( new Config_Permissions_Check() )->run( $context );

		$this->assertSame( 'findings', $result->status() );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Config_Permissions_Check();

		$this->assertSame( 'config.perms.wpconfig', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	/**
	 * @dataProvider private_modes
	 * @param int $mode Permission bits.
	 */
	public function test_a_file_others_cannot_read_and_nobody_else_can_write_passes( int $mode ): void {
		$this->assertTrue( $this->run_with_mode( $mode )->is_pass() );
	}

	/**
	 * @return array<string, array<int, int>>
	 */
	public function private_modes(): array {
		return array(
			'owner read-write'          => array( 0600 ),
			'owner read'                => array( 0400 ),
			'group readable'            => array( 0640 ),
			'owner and group read only' => array( 0440 ),
		);
	}

	/**
	 * @dataProvider readable_modes
	 * @param int    $mode     Permission bits.
	 * @param string $expected Octal text in the evidence.
	 */
	public function test_a_file_others_can_read_or_anyone_else_can_write_is_a_warning( int $mode, string $expected ): void {
		$finding = $this->run_with_mode( $mode )->finding_list()[0];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( $expected, $finding->evidence()['mode'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function readable_modes(): array {
		return array(
			'world readable'   => array( 0644, '0644' ),
			'others only'      => array( 0604, '0604' ),
			'group writable'   => array( 0620, '0620' ),
			'group read-write' => array( 0660, '0660' ),
			'others writable'  => array( 0602, '0602' ),
			'all'              => array( 0777, '0777' ),
		);
	}

	public function test_the_fix_names_a_mode_that_passes(): void {
		$fix = $this->run_with_mode( 0644 )->finding_list()[0]->fix();

		$this->assertStringContainsString( 'chmod 640', $fix );
		$this->assertStringContainsString( 'chmod 600', $fix );
		$this->assertTrue( $this->run_with_mode( 0640 )->is_pass() );
		$this->assertTrue( $this->run_with_mode( 0600 )->is_pass() );
	}

	public function test_the_mode_providers_are_not_empty(): void {
		$this->assertNotEmpty( $this->private_modes() );
		$this->assertNotEmpty( $this->readable_modes() );
	}

	public function test_a_config_above_the_site_root_is_skipped(): void {
		$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__, 3 ) . '/fixtures/sites/config-above/public' );
		$context = Context::without_database( $site, new File_Scanner( $site->root() ), 'no database in this test' );

		$result = ( new Config_Permissions_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'outside the site root', (string) $result->message() );
	}
}
