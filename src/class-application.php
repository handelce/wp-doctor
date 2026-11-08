<?php
/**
 * Command-line application.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor;

use WP_Doctor\Check\Check_Registry;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Runner;
use WP_Doctor\Check\Supplied_Queries;
use WP_Doctor\Check\Thresholds;
use WP_Doctor\Cli\Argument_Parser;
use WP_Doctor\Cli\Cli_Options;
use WP_Doctor\Cli\Config_File_Exception;
use WP_Doctor\Cli\Console;
use WP_Doctor\Cli\Tool_Config;
use WP_Doctor\Cli\Usage_Exception;
use WP_Doctor\Db\Database_Exception;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Exit_Code;
use WP_Doctor\Report\Formatter\Formatter;
use WP_Doctor\Report\Formatter\Json_Formatter;
use WP_Doctor\Report\Formatter\Markdown_Formatter;
use WP_Doctor\Report\Formatter\Text_Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Site\Config;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Site\Site_Not_Found_Exception;

/**
 * Parses arguments, wires a scan together, prints the report and returns the
 * exit code.
 *
 * @since 0.1.0
 */
final class Application {

	public const VERSION = '0.3.0';

	private const MAX_CONFIG_BYTES = 65536;
	private const ENV_TABLE_PREFIX = 'WPD_DB_TABLE_PREFIX';
	private const ENV_VARIABLES    = array(
		'host'     => 'WPD_DB_HOST',
		'name'     => 'WPD_DB_NAME',
		'user'     => 'WPD_DB_USER',
		'password' => 'WPD_DB_PASSWORD',
	);

	private const USAGE = <<<'TEXT'
Usage:
  wp-doctor scan <path> [options]
  wp-doctor --version
  wp-doctor --help

Scans a WordPress site that you administer and prints what is wrong with it.
Read-only: nothing is written to the site or its database.

Options:
  --format=FORMAT      text, json or markdown (default: text)
  --offline            Do not contact wordpress.org for checksums
  --config=FILE        Read settings from a wp-doctor.json file (a file inside the site is never read)
  --queries=FILE       Check the SELECT statements in FILE, one per line, for joins
                       that likely cannot use an index (a file inside the site is never read)
  --db-from-env        Take database credentials from WPD_DB_HOST, WPD_DB_NAME,
                       WPD_DB_USER and WPD_DB_PASSWORD instead of wp-config.php;
                       WPD_DB_TABLE_PREFIX, when set, replaces $table_prefix
  --max-files=N        Stop each directory walk after N entries (default 200000)
  --exclude=GLOB       Skip paths matching GLOB, relative to the site root
                       (repeatable; ** crosses directories)
  --redact-emails      Mask the local part of email addresses in the output
  --color, --no-color  Force colour on or off (default: on for a terminal)
  -V, --version        Print the version
  -h, --help           Print this help

Exit codes:
  0  nothing above info
  1  warnings
  2  critical findings
  3  tool error: no site found, bad arguments, or a check failed to run

TEXT;

	/**
	 * Checks to run.
	 *
	 * @since 0.1.0
	 *
	 * @var Check_Registry
	 */
	private $registry;

	/**
	 * Terminal output.
	 *
	 * @since 0.1.0
	 *
	 * @var Console
	 */
	private $console;

	/**
	 * Environment variables, read for --db-from-env, NO_COLOR and TERM.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private $env;

	/**
	 * Opens a database connection: (host, user, password, name) to a driver.
	 *
	 * @since 0.1.0
	 *
	 * @var callable
	 */
	private $connect;

	/**
	 * Source of official checksums. Unless one is injected, this is the wordpress.org client.
	 *
	 * @since 0.1.0
	 *
	 * @var Http\Checksum_Source
	 */
	private $checksums;

	/**
	 * Takes the database connector and checksum source as arguments so tests can replace them.
	 *
	 * @since 0.1.0
	 *
	 * @param Check_Registry            $registry Checks to run.
	 * @param Console                   $console  Terminal output.
	 * @param array<string, string>     $env      Environment variables, as getenv() returns them.
	 * @param callable|null             $connect  Takes host, user, password and name and returns a driver. Null means Mysqli_Driver::connect().
	 * @param Http\Checksum_Source|null $checksums Source of checksums. Null means the wordpress.org client.
	 */
	public function __construct( Check_Registry $registry, Console $console, array $env, ?callable $connect = null, ?Http\Checksum_Source $checksums = null ) {
		$this->registry = $registry;
		$this->console  = $console;
		$this->env      = $env;
		$this->connect  = $connect ?? array( Db\Mysqli_Driver::class, 'connect' );

		$this->checksums = $checksums ?? new Http\Checksum_Client( Http\Transport_Choice::for_this_php( 'wp-doctor/' . self::VERSION ) );
	}

	/**
	 * Handles one invocation. Any failure is printed and ends in Exit_Code::ERROR.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Arguments after the script name.
	 * @return int Exit code.
	 */
	public function run( array $args ): int {
		try {
			$options = ( new Argument_Parser() )->parse( $args );

			if ( Cli_Options::VERSION === $options->command() ) {
				$this->console->out( 'wp-doctor ' . self::VERSION . "\n" );
				return Exit_Code::OK;
			}
			if ( Cli_Options::HELP === $options->command() ) {
				$this->console->out( self::USAGE );
				return Exit_Code::OK;
			}

			$platform = self::platform_problem( PHP_INT_SIZE );
			if ( null !== $platform ) {
				$this->console->err( "wp-doctor: $platform\n" );
				return Exit_Code::ERROR;
			}

			return $this->scan( $options );
		} catch ( Usage_Exception $e ) {
			$this->console->err( 'wp-doctor: ' . $e->getMessage() . "\nTry 'wp-doctor --help' for usage.\n" );
		} catch ( Site_Not_Found_Exception $e ) {
			$this->console->err( 'wp-doctor: ' . $e->getMessage() . "\n" );
		} catch ( Config_File_Exception $e ) {
			$this->console->err( 'wp-doctor: ' . $e->getMessage() . "\n" );
		} catch ( \Throwable $e ) {
			$this->console->err( 'wp-doctor: unexpected error: ' . esc_cli( get_class( $e ) . ': ' . $e->getMessage() ) . "\n" );
		}

		return Exit_Code::ERROR;
	}

	/**
	 * Why this PHP cannot scan, or null when it can. A 32-bit integer cannot
	 * hold a size above 2 GiB, so file and table sizes would be wrong.
	 *
	 * @since 0.1.0
	 *
	 * @param int $int_size PHP_INT_SIZE of the PHP that would scan.
	 * @return string|null
	 */
	public static function platform_problem( int $int_size ): ?string {
		return $int_size < 8 ? 'wp-doctor needs a 64-bit PHP; this one is 32-bit, so file and table sizes above 2 GiB would be wrong' : null;
	}

	/**
	 * Scans a site and prints the report.
	 *
	 * @since 0.1.0
	 *
	 * @param Cli_Options $options Parsed options.
	 * @return int Exit code.
	 * @throws Site_Not_Found_Exception When the path is not a site.
	 * @throws Config_File_Exception    When a config file is invalid.
	 */
	private function scan( Cli_Options $options ): int {
		$site        = $this->with_environment_prefix( ( new Site_Locator( new Config_Parser() ) )->locate( $options->path() ), $options );
		$tool_config = $this->load_tool_config( $options, $site );
		$tool_config->assert_check_ids( $this->registry->ids() );
		$excludes = array_merge( $tool_config->excludes(), $options->excludes() );

		$files   = new File_Scanner( $site->root(), $options->max_files(), File_Scanner::DEFAULT_MAX_BYTES, $excludes );
		$context = Context::with_lazy_database(
			$site,
			$files,
			function () use ( $site, $options ): array {
				return $this->open_database( $site, $options );
			}
		);

		$context = $options->offline()
			? $context->with_checksums( null, '--offline was given, so wordpress.org was not contacted' )
			: $context->with_checksums( $this->checksums );
		if ( array() !== $tool_config->thresholds() ) {
			$context = $context->with_thresholds( $this->thresholds_from( $tool_config ) );
		}
		if ( null !== $options->queries_path() ) {
			$context = $context->with_queries( $this->load_queries( (string) $options->queries_path(), $site ) );
		}

		$entries = ( new Runner() )->run( $this->registry, $context );
		$notices = array_merge( $this->config_notices( $tool_config, $excludes, $site ), $files->notices() );
		$report  = new Report( self::VERSION, $site->wp_version(), $entries, $notices, $tool_config->ignore() );

		$redactor = Redactor::for_config( $site->config(), $options->redact_emails() );
		if ( $options->db_from_env() && isset( $this->env[ self::ENV_VARIABLES['password'] ] ) ) {
			$redactor = $redactor->with_secret( (string) $this->env[ self::ENV_VARIABLES['password'] ] );
		}
		foreach ( array( 'host', 'user', 'name' ) as $key ) {
			if ( ! $options->db_from_env() || ! isset( $this->env[ self::ENV_VARIABLES[ $key ] ] ) ) {
				continue;
			}
			foreach ( Redactor::connection_identifiers( (string) $this->env[ self::ENV_VARIABLES[ $key ] ] ) as $identifier ) {
				$redactor = $redactor->with_secret( $identifier );
			}
		}

		$formatter = $this->formatter_for( $options );
		$this->console->out( $formatter->format( new Redacted_Report( $report, $redactor ) ) );

		return Exit_Code::for_report( $report );
	}

	/**
	 * Limits from the config file, refused when a check or level cannot take them.
	 *
	 * @since 0.1.0
	 *
	 * @param Tool_Config $config Loaded settings with at least one threshold.
	 * @return Thresholds
	 * @throws Config_File_Exception When a check has no such threshold.
	 */
	private function thresholds_from( Tool_Config $config ): Thresholds {
		try {
			return Thresholds::from_config( $config->thresholds(), (string) $config->source() );
		} catch ( \InvalidArgumentException $e ) {
			throw new Config_File_Exception( esc_cli( $e->getMessage() ) );
		}
	}

	/**
	 * Loads the config file named by --config. A wp-doctor.json inside the
	 * scanned site is never read: the site is the thing under suspicion and
	 * must not be able to steer its own scan.
	 *
	 * @since 0.1.0
	 *
	 * @param Cli_Options $options Parsed options.
	 * @param Site        $site    Located site, which the file must lie outside.
	 * @return Tool_Config
	 * @throws Config_File_Exception When a file cannot be read or is invalid.
	 * @throws Usage_Exception       When the file is inside the scanned site.
	 */
	private function load_tool_config( Cli_Options $options, Site $site ): Tool_Config {
		$path = $options->config_path();
		if ( null === $path ) {
			return Tool_Config::none();
		}
		$resolved = real_path( $path );
		if ( false === $resolved ) {
			throw new Config_File_Exception( esc_cli( "Config file cannot be read: $path" ) );
		}
		if ( is_dir( $resolved ) ) {
			throw new Config_File_Exception( esc_cli( "Config file $path is a directory; give the path of a JSON file" ) );
		}
		if ( $this->is_inside( $resolved, $site->root() ) ) {
			throw new Usage_Exception( esc_cli( "Config file $path is inside the scanned site and is not read; pass a copy from outside the site" ) );
		}
		// A local path read as text, so the WordPress remote-URL advice does not apply.
		$json = @file_get_contents( $resolved, false, null, 0, self::MAX_CONFIG_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $json ) {
			throw new Config_File_Exception( esc_cli( "Config file cannot be read: $path" ) );
		}

		return Tool_Config::from_json( $json, $path );
	}

	/**
	 * Reads the file named by --queries. Like the config file, it must lie
	 * outside the scanned site.
	 *
	 * @since 0.3.0
	 *
	 * @param string $path Path given with --queries.
	 * @param Site   $site Located site, which the file must lie outside.
	 * @return Supplied_Queries
	 * @throws Usage_Exception When the file is inside the site, cannot be read, or holds too many statements.
	 */
	private function load_queries( string $path, Site $site ): Supplied_Queries {
		$resolved = real_path( $path );
		if ( false === $resolved || is_dir( $resolved ) ) {
			throw new Usage_Exception( esc_cli( "Queries file cannot be read: $path" ) );
		}
		if ( $this->is_inside( $resolved, $site->root() ) ) {
			throw new Usage_Exception( esc_cli( "Queries file $path is inside the scanned site and is not read; pass a copy from outside the site" ) );
		}
		$text = @file_get_contents( $resolved, false, null, 0, Supplied_Queries::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- A local file read as text; failure is reported below.
		if ( false === $text ) {
			throw new Usage_Exception( esc_cli( "Queries file cannot be read: $path" ) );
		}
		try {
			return Supplied_Queries::from_text( $text );
		} catch ( \InvalidArgumentException $e ) {
			throw new Usage_Exception( esc_cli( "Queries file $path: " . $e->getMessage() ) );
		}
	}

	/**
	 * Whether a resolved path is the directory $root or lies below it. Directories
	 * are compared by device and inode, so a difference in letter case or a
	 * second mount of the same directory does not hide the match. Where stat()
	 * reports no inode, as on Windows, the names are compared without case.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Resolved absolute path.
	 * @param string $root Resolved absolute directory.
	 * @return bool
	 */
	private function is_inside( string $path, string $root ): bool {
		$root_stat = @stat( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A root that cannot be stat'ed is compared by name.
		if ( false === $root_stat || 0 === $root_stat['ino'] ) {
			return 0 === stripos( $path . '/', rtrim( $root, '/' ) . '/' );
		}
		for ( $dir = $path; ; $dir = dirname( $dir ) ) {
			$stat = @stat( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable directory cannot match.
			if ( false !== $stat && $stat['dev'] === $root_stat['dev'] && $stat['ino'] === $root_stat['ino'] ) {
				return true;
			}
			if ( dirname( $dir ) === $dir ) {
				return false;
			}
		}
	}

	/**
	 * Report notices about the config file and active excludes.
	 *
	 * @since 0.1.0
	 *
	 * @param Tool_Config $config   Settings from --config, or Tool_Config::none().
	 * @param string[]    $excludes Every active exclude glob.
	 * @param Site        $site     Located site, checked for an unused wp-doctor.json.
	 * @return string[]
	 */
	private function config_notices( Tool_Config $config, array $excludes, Site $site ): array {
		$notices = array();
		if ( null !== $site->config()->unreadable_reason() ) {
			$notices[] = 'Partial scan: ' . $site->config()->unreadable_reason() . '. Only checks that do not depend on it ran. '
				. 'Run wp-doctor as the account that owns the site for a full scan.';
		}
		if ( null !== $config->source() ) {
			$notices[] = 'Using ' . $config->source() . '.';
		}
		if ( null !== ( new File_Scanner( $site->root() ) )->stat( 'wp-doctor.json' ) ) {
			$notices[] = 'A wp-doctor.json in the site root was not used; files inside the scanned site never configure the scan. Pass a copy with --config to apply it.';
		}
		if ( array() !== $excludes ) {
			$notices[] = 'Excluded from the scan: ' . implode( ', ', $excludes ) . '.';
		}
		if ( array() !== $config->thresholds() ) {
			$notices[] = 'Thresholds from ' . $config->source() . ' apply to: ' . implode( ', ', array_map( 'strval', array_keys( $config->thresholds() ) ) ) . '.';
		}
		if ( array() !== $config->ignore() ) {
			$notices[] = 'Ignored by ' . $config->source() . ': ' . implode( ', ', $config->ignore() ) . '. Their outcomes are listed but do not count towards the exit code.';
		}

		return $notices;
	}

	/**
	 * Applies WPD_DB_TABLE_PREFIX when --db-from-env is given and the variable is not empty.
	 *
	 * @since 0.1.0
	 *
	 * @param Site        $site    Located site.
	 * @param Cli_Options $options Parsed options.
	 * @return Site
	 * @throws Usage_Exception When the variable is not a usable prefix.
	 */
	private function with_environment_prefix( Site $site, Cli_Options $options ): Site {
		if ( ! $options->db_from_env() || ! isset( $this->env[ self::ENV_TABLE_PREFIX ] ) || '' === $this->env[ self::ENV_TABLE_PREFIX ] ) {
			return $site;
		}

		try {
			return $site->with_config( $site->config()->with_table_prefix( (string) $this->env[ self::ENV_TABLE_PREFIX ] ) );
		} catch ( \InvalidArgumentException $e ) {
			throw new Usage_Exception( 'WPD_DB_TABLE_PREFIX may contain only letters, digits and underscore' );
		}
	}

	/**
	 * Opens the read-only database connection if the credentials allow it.
	 *
	 * With --db-from-env the host, name, user and password come from the
	 * environment. The table prefix comes from wp-config.php unless
	 * WPD_DB_TABLE_PREFIX is set.
	 *
	 * @since 0.1.0
	 *
	 * @param Site        $site    Located site.
	 * @param Cli_Options $options Parsed options.
	 * @return array{0: Read_Only_Connection|null, 1: string|null} The connection, or null and the reason.
	 */
	private function open_database( Site $site, Cli_Options $options ): array {
		$config = $site->config();
		$needs  = array( Config::TABLE_PREFIX );
		if ( ! $options->db_from_env() ) {
			$needs = array_merge( array( 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ), $needs );
		}
		$reason = $config->skip_reason( ...$needs );
		if ( null !== $reason ) {
			return array( null, $options->db_from_env() ? $reason : $reason . '; if the site takes them from the environment, use --db-from-env' );
		}

		if ( $options->db_from_env() ) {
			$credentials = array();
			foreach ( self::ENV_VARIABLES as $key => $variable ) {
				if ( ! isset( $this->env[ $variable ] ) || ( '' === $this->env[ $variable ] && 'password' !== $key ) ) {
					return array( null, "environment variable $variable is not set (--db-from-env)" );
				}
				$credentials[ $key ] = (string) $this->env[ $variable ];
			}
		} else {
			$credentials = array(
				'host'     => (string) $config->value( 'DB_HOST' ),
				'name'     => (string) $config->value( 'DB_NAME' ),
				'user'     => (string) $config->value( 'DB_USER' ),
				'password' => (string) $config->value( 'DB_PASSWORD' ),
			);
		}

		try {
			$driver = ( $this->connect )( $credentials['host'], $credentials['user'], $credentials['password'], $credentials['name'] );

			return array( new Read_Only_Connection( $driver ), null );
		} catch ( Database_Exception $e ) {
			return array( null, 'database connection failed: ' . $e->getMessage() );
		}
	}

	/**
	 * The formatter for --format.
	 *
	 * @since 0.1.0
	 *
	 * @param Cli_Options $options Parsed options.
	 * @return Formatter
	 */
	private function formatter_for( Cli_Options $options ): Formatter {
		switch ( $options->format() ) {
			case 'json':
				return new Json_Formatter();
			case 'markdown':
				return new Markdown_Formatter();
			default:
				return new Text_Formatter( $this->use_colour( $options ) );
		}
	}

	/**
	 * Whether the text report should use colour.
	 *
	 * @since 0.1.0
	 *
	 * @param Cli_Options $options Parsed options.
	 * @return bool
	 */
	private function use_colour( Cli_Options $options ): bool {
		if ( null !== $options->colour() ) {
			return $options->colour();
		}

		return $this->console->is_tty()
			&& ( ! isset( $this->env['NO_COLOR'] ) || '' === $this->env['NO_COLOR'] )
			&& 'dumb' !== ( $this->env['TERM'] ?? '' );
	}
}
