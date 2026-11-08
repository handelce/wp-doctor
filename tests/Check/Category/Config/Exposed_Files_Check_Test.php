<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Exposed_Files_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Exposed_Files_Check_Test extends TestCase {

	/**
	 * Web root holding the .env, .git, phpinfo and readme files under test.
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
	 * Runs the check on the temporary site.
	 *
	 * @return Result
	 */
	private function run_check(): Result {
		return ( new Exposed_Files_Check() )->run( Context_Factory::for_tree( $this->tree ) );
	}

	/**
	 * Findings keyed by severity name.
	 *
	 * @param Result $result Result with findings.
	 * @return array<string, \WP_Doctor\Report\Finding>
	 */
	private function by_severity( Result $result ): array {
		$by = array();
		foreach ( $result->finding_list() as $finding ) {
			$by[ $finding->severity()->name() ] = $finding;
		}

		return $by;
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Exposed_Files_Check();

		$this->assertSame( 'config.exposed.files', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	public function test_an_ordinary_web_root_passes(): void {
		$this->tree->file( 'index.php', '<?php // front controller' );
		$this->tree->file( 'wp-login.php', '<?php // phpinfo( ) is only text in a core file' );
		$this->tree->file( 'license.txt' );
		$this->tree->file( 'wp-content/.git/config' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	/**
	 * @dataProvider serious_paths
	 * @param string $relative Path created in the site root.
	 */
	public function test_version_control_data_environment_files_and_phpinfo_pages_are_warnings( string $relative ): void {
		$this->tree->file( $relative, '<?php phpinfo(); // inert test marker' );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertSame( 'warning', $findings['warning']->severity()->name() );
		$this->assertSame( explode( '/', $relative )[0], $findings['warning']->evidence()['path_1'] );
		$this->assertStringContainsString( 'likely', $findings['warning']->title() );
		$this->assertNotSame( '', $findings['warning']->fix() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function serious_paths(): array {
		return array(
			'git directory' => array( '.git/HEAD' ),
			'svn directory' => array( '.svn/entries' ),
			'hg directory'  => array( '.hg/store' ),
			'env file'      => array( '.env' ),
			'env variant'   => array( '.env.production' ),
			'phpinfo page'  => array( 'phpinfo.php' ),
			'odd name'      => array( 'zz-diagnostics.php' ),
			'wp- name'      => array( 'wp-info.php' ),
		);
	}

	public function test_the_serious_provider_is_not_empty(): void {
		$this->assertCount( 8, $this->serious_paths() );
	}

	public function test_the_readme_alone_is_an_info_finding_not_a_warning(): void {
		$this->tree->file( 'readme.html' );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertArrayHasKey( 'info', $findings );
		$this->assertArrayNotHasKey( 'warning', $findings );
		$this->assertSame( 'readme.html', $findings['info']->evidence()['path_1'] );
	}

	public function test_both_kinds_become_two_findings_with_paths_in_name_order(): void {
		$this->tree->file( 'readme.html' );
		$this->tree->file( '.env' );
		$this->tree->file( '.git/HEAD' );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertSame( '.env', $findings['warning']->evidence()['path_1'] );
		$this->assertSame( '.git', $findings['warning']->evidence()['path_2'] );
		$this->assertArrayHasKey( 'info', $findings );
	}

	public function test_only_the_top_of_the_web_root_is_read(): void {
		$this->tree->file( 'wp-content/plugins/example/.env' );
		$this->tree->file( 'wp-content/plugins/example/info.php', '<?php phpinfo();' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_second_run_on_the_same_scanner_is_still_a_skip_when_the_walk_stops_at_the_cap(): void {
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'b.txt' );
		$this->tree->file( 'c.txt' );
		$context = Context_Factory::for_tree( $this->tree, '', 2 );

		$first  = ( new Exposed_Files_Check() )->run( $context );
		$second = ( new Exposed_Files_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $first->status() );
		$this->assertSame( Result::SKIPPED, $second->status() );
		$this->assertStringContainsString( '--max-files', (string) $second->message() );
	}

	public function test_an_environment_file_behind_a_symlink_leaving_the_root_is_still_reported(): void {
		file_put_contents( $this->tree->outside() . '/env', 'APP_KEY=example' );
		$this->tree->link( '.env', $this->tree->outside() . '/env' );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertArrayHasKey( 'warning', $findings );
		$this->assertSame( '.env', $findings['warning']->evidence()['path_1'] );
	}

	public function test_with_core_in_a_subdirectory_the_top_of_the_core_directory_is_read_too(): void {
		$this->tree->file( '.env' );
		$this->tree->file( 'wp/readme.html' );
		$this->tree->file( 'wp/info.php', '<?php phpinfo();' );
		$this->tree->file( 'wp/wp-includes/deeper.php', '<?php phpinfo();' );
		$context = Context_Factory::for_core_in( $this->tree, 'wp' );
		$this->assertSame( 'wp', $context->site()->core_dir() );

		$findings = $this->by_severity( ( new Exposed_Files_Check() )->run( $context ) );

		$this->assertSame( '.env', $findings['warning']->evidence()['path_1'] );
		$this->assertSame( 'wp/info.php', $findings['warning']->evidence()['path_2'] );
		$this->assertArrayNotHasKey( 'path_3', $findings['warning']->evidence() );
		$this->assertSame( 'wp/readme.html', $findings['info']->evidence()['path_1'] );
	}
}
