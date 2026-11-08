<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Php_Sources;
use WP_Doctor\Check\Context;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Php_Sources_Test extends TestCase {

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
	 * Paths handed over by a reader, in order.
	 *
	 * @param Php_Sources $sources Reader to drain.
	 * @return string[]
	 */
	private function paths( Php_Sources $sources ): array {
		return array_map( 'strval', array_keys( iterator_to_array( $sources->matching() ) ) );
	}

	public function test_only_php_files_holding_a_word_from_every_group_are_handed_over(): void {
		$this->tree->file( 'wp-content/plugins/a/a.php', '<?php EVAL( $x ); // http' );
		$this->tree->file( 'wp-content/plugins/a/b.php', '<?php eval( $x );' );
		$this->tree->file( 'wp-content/plugins/a/c.phtml', '<?php eval( "http" );' );
		$this->tree->file( 'wp-content/plugins/a/d.txt', 'eval http' );
		$context = Context_Factory::for_tree( $this->tree );

		$sources = new Php_Sources( $context, array( array( 'eval' ), array( 'http', 'curl_' ) ) );
		$paths   = $this->paths( $sources );
		sort( $paths );

		$this->assertSame( array( 'wp-content/plugins/a/a.php', 'wp-content/plugins/a/c.phtml' ), $paths );
		$this->assertSame( 5, $sources->examined(), 'The three plugin files, wp-config.php and version.php are read' );
		$this->assertSame( array(), $sources->gaps() );
	}

	public function test_a_wp_config_above_the_root_is_read_and_named_by_its_relative_path(): void {
		$this->tree->file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n" );
		file_put_contents( dirname( $this->tree->site() ) . '/wp-config.php', "<?php\n\$table_prefix = 'wptest_';\neval( \$x );\n" );
		$site    = ( new Site_Locator( new Config_Parser() ) )->locate( $this->tree->site() );
		$context = Context::without_database( $site, new File_Scanner( $site->root() ), 'no database in this test' );
		$this->assertSame( dirname( $this->tree->site() ) . '/wp-config.php', $site->config_path() );

		$sources = new Php_Sources( $context, array( array( 'eval' ) ) );

		$this->assertSame( array( '../wp-config.php' ), $this->paths( $sources ) );
		$this->assertSame( 2, $sources->examined() );
	}

	public function test_a_file_larger_than_the_read_limit_is_named_as_read_in_part(): void {
		$this->tree->sparse_file( 'wp-content/plugins/big/big.php', File_Scanner::DEFAULT_MAX_BYTES + 10 );
		$sources = new Php_Sources( Context_Factory::for_tree( $this->tree ), array( array( 'eval' ) ) );

		$this->paths( $sources );

		$this->assertCount( 1, $sources->gaps() );
		$this->assertStringContainsString( 'read only in part (wp-content/plugins/big/big.php)', $sources->gaps()[0] );
	}

	public function test_an_unreadable_php_file_is_counted_as_a_gap(): void {
		File_Modes::require_enforced_modes();
		$path = $this->tree->file( 'wp-content/plugins/a/locked.php', '<?php eval( $x );' );
		chmod( $path, 0000 );
		$sources = new Php_Sources( Context_Factory::for_tree( $this->tree ), array( array( 'eval' ) ) );

		$this->assertSame( array(), $this->paths( $sources ) );
		$this->assertSame( array( '1 PHP file(s) could not be read' ), $sources->gaps() );
	}

	public function test_a_walk_that_stops_at_the_file_cap_is_a_gap(): void {
		$this->tree->file( 'wp-content/plugins/a/a.php', '<?php' );
		$this->tree->file( 'wp-content/plugins/a/b.php', '<?php' );
		$sources = new Php_Sources( Context_Factory::for_tree( $this->tree, '', 3 ), array( array( 'eval' ) ) );

		$this->paths( $sources );

		$this->assertNotSame( array(), $sources->gaps() );
	}

	public function test_word_groups_cannot_be_empty(): void {
		$context = Context_Factory::for_tree( $this->tree );

		$this->expectException( \InvalidArgumentException::class );
		new Php_Sources( $context, array( array( 'eval' ), array() ) );
	}

	public function test_no_word_groups_at_all_are_refused(): void {
		$context = Context_Factory::for_tree( $this->tree );

		$this->expectException( \InvalidArgumentException::class );
		new Php_Sources( $context, array() );
	}
}
