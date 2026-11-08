<?php
/**
 * Builds the Context a check test runs against.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use WP_Doctor\Check\Context;
use WP_Doctor\Db\Database_Driver;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;

final class Context_Factory {

	/**
	 * A context whose database answers every query with the given rows.
	 *
	 * @param Database_Driver $driver Driver behind the read-only connection.
	 * @param string           $site   Fixture site name under tests/fixtures/sites.
	 * @return Context
	 */
	public static function with_driver( Database_Driver $driver, string $site = 'plain' ): Context {
		$located = self::locate( $site );

		return Context::with_database( $located, new File_Scanner( $located->root() ), new Read_Only_Connection( $driver ) );
	}

	/**
	 * A context with no database.
	 *
	 * @param string $reason Why there is none.
	 * @param string $site   Fixture site name under tests/fixtures/sites.
	 * @return Context
	 */
	public static function without_database( string $reason = 'no database in this test', string $site = 'plain' ): Context {
		$located = self::locate( $site );

		return Context::without_database( $located, new File_Scanner( $located->root() ), $reason );
	}

	/**
	 * A context over a temporary site tree, with no database.
	 *
	 * Writes a wp-config.php holding the given statements and a version file
	 * when the tree has none, so the tree is a locatable site.
	 *
	 * @param Temp_Tree $tree   Tree to read from.
	 * @param string    $config PHP statements for wp-config.php, without the opening tag.
	 * @param int       $max_files Entry cap for each walk.
	 * @param string[]  $exclude   Exclude globs for the scanner.
	 * @return Context
	 */
	public static function for_tree( Temp_Tree $tree, string $config = '', int $max_files = File_Scanner::DEFAULT_MAX_FILES, array $exclude = array() ): Context {
		if ( ! is_file( $tree->site() . '/wp-config.php' ) ) {
			$tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" . $config );
		}
		if ( ! is_file( $tree->site() . '/wp-includes/version.php' ) ) {
			$tree->file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n" );
		}
		$located = ( new Site_Locator( new Config_Parser() ) )->locate( $tree->site() );

		return Context::without_database( $located, new File_Scanner( $located->root(), $max_files, File_Scanner::DEFAULT_MAX_BYTES, $exclude ), 'no database in this test' );
	}

	/**
	 * A context over a temporary site whose core is in a subdirectory: the web
	 * root holds wp-config.php and an index.php that loads core from there.
	 * The config, index and version files are written only when missing.
	 *
	 * @param Temp_Tree $tree     Tree to read from.
	 * @param string    $core_dir Subdirectory holding core.
	 * @param string    $config   PHP statements for wp-config.php in the web root, without the opening tag.
	 * @return Context
	 */
	public static function for_core_in( Temp_Tree $tree, string $core_dir, string $config = '' ): Context {
		$site = self::core_in_site( $tree, $core_dir, $config );

		return Context::without_database( $site, new File_Scanner( $site->root() ), 'no database in this test' );
	}

	/**
	 * A context over a temporary site whose core is in a subdirectory and
	 * whose options table holds the given options.
	 *
	 * @param Temp_Tree             $tree     Tree to read from.
	 * @param string                $core_dir Subdirectory holding core.
	 * @param array<string, string> $options  Option values by name, such as upload_path.
	 * @return Context
	 */
	public static function for_core_in_with_options( Temp_Tree $tree, string $core_dir, array $options ): Context {
		$site            = self::core_in_site( $tree, $core_dir, '' );
		$driver          = new Options_Driver();
		$driver->options = $options;

		return Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( $driver ) );
	}

	/**
	 * Writes the files of a web root that loads core from a subdirectory, and locates it.
	 *
	 * @param Temp_Tree $tree     Tree to write to.
	 * @param string    $core_dir Subdirectory holding core.
	 * @param string    $config   PHP statements for wp-config.php in the web root.
	 * @return \WP_Doctor\Site\Site
	 */
	private static function core_in_site( Temp_Tree $tree, string $core_dir, string $config ) {
		if ( ! is_file( $tree->site() . '/wp-config.php' ) && ! is_file( $tree->site() . "/$core_dir/wp-config.php" ) ) {
			$tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" . $config );
		}
		if ( ! is_file( $tree->site() . '/index.php' ) ) {
			$tree->file( 'index.php', "<?php\ndefine( 'WP_USE_THEMES', true );\nrequire __DIR__ . '/$core_dir/wp-blog-header.php';\n" );
		}
		if ( ! is_file( $tree->site() . "/$core_dir/wp-includes/version.php" ) ) {
			$tree->file( "$core_dir/wp-includes/version.php", "<?php\n\$wp_version = '6.5.2';\n" );
		}

		return ( new Site_Locator( new Config_Parser() ) )->locate( $tree->site() );
	}

	/**
	 * The same context after one full walk of its tree, so later walks replay it.
	 *
	 * @param Context $context Context whose scanner walks.
	 * @return Context
	 */
	public static function after_full_walk( Context $context ): Context {
		iterator_to_array( $context->files()->walk(), false );

		return $context;
	}

	/**
	 * A context over a temporary site tree on a given operating system family.
	 *
	 * @param Temp_Tree $tree      Tree to read from.
	 * @param string    $os_family Family as PHP_OS_FAMILY reports it.
	 * @return Context
	 */
	public static function for_tree_on( Temp_Tree $tree, string $os_family ): Context {
		$site = self::for_tree( $tree )->site();

		return Context::without_database( $site, new File_Scanner( $site->root(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array(), $os_family ), 'no database in this test' );
	}

	/**
	 * A context over a temporary site tree with a recording database.
	 *
	 * @param Temp_Tree        $tree   Tree to read from.
	 * @param Database_Driver $driver Driver behind the read-only connection.
	 * @param string           $config PHP statements for wp-config.php, without the opening tag.
	 * @return Context
	 */
	public static function for_tree_with_driver( Temp_Tree $tree, Database_Driver $driver, string $config = '' ): Context {
		$site = self::for_tree( $tree, $config )->site();

		return Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( $driver ) );
	}

	/**
	 * A context over a temporary site tree whose options table holds the given options.
	 *
	 * @param Temp_Tree             $tree    Tree to read from.
	 * @param array<string, string> $options Option values by name, such as upload_path.
	 * @param string                $config  PHP statements for wp-config.php, without the opening tag.
	 * @return Context
	 */
	public static function for_tree_with_options( Temp_Tree $tree, array $options = array( 'upload_path' => '' ), string $config = '' ): Context {
		$driver          = new Options_Driver();
		$driver->options = $options;

		return self::for_tree_with_driver( $tree, $driver, $config );
	}

	/**
	 * Locates a fixture site.
	 *
	 * @param string $site Fixture site name.
	 * @return \WP_Doctor\Site\Site
	 */
	private static function locate( string $site ) {
		return ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/' . $site );
	}
}
