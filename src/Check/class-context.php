<?php
/**
 * What a check is given to work with.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Http\Checksum_Source;
use WP_Doctor\Site\Config;
use WP_Doctor\Site\Site;

use function WP_Doctor\esc_cli;
use function WP_Doctor\real_path;

/**
 * The collaborators a check may use. A check gets nothing else: no globals and
 * no direct database or filesystem access.
 *
 * @since 0.1.0
 */
final class Context {

	private const MAX_UPLOAD_PATH_BYTES = 4096;

	/**
	 * Site being scanned.
	 *
	 * @since 0.1.0
	 *
	 * @var Site
	 */
	private $site;

	/**
	 * Read-only file access.
	 *
	 * @since 0.1.0
	 *
	 * @var File_Scanner
	 */
	private $files;

	/**
	 * Connection to the site database. Null until a lazy opener has run, or when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @var Read_Only_Connection|null
	 */
	private $database;

	/**
	 * Reason database checks skip. Null once a connection is held.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $database_unavailable;

	/**
	 * Opener that runs on first use. Null once it has run, or when the context was built without one.
	 *
	 * @since 0.1.0
	 *
	 * @var callable|null
	 */
	private $open_database;

	/**
	 * Source of official checksums. Null when lookups are not allowed.
	 *
	 * @since 0.1.0
	 *
	 * @var Checksum_Source|null
	 */
	private $checksums;

	/**
	 * Reason checksum checks skip. Null when a source is set.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $checksums_unavailable = 'Checksum lookups are not configured';

	/**
	 * Statements given with --queries. Null when none were given.
	 *
	 * @since 0.3.0
	 *
	 * @var Supplied_Queries|null
	 */
	private $queries;

	/**
	 * Limits set by with_thresholds(). Null means the defaults apply.
	 *
	 * @since 0.1.0
	 *
	 * @var Thresholds|null
	 */
	private $thresholds;

	/**
	 * The upload_path option and why it could not be read, once looked up.
	 *
	 * @since 0.1.0
	 *
	 * @var array{0: string|null, 1: string|null}|null
	 */
	private $upload_path = null;

	/**
	 * Callers use with_database(), with_lazy_database() or without_database().
	 *
	 * @since 0.1.0
	 *
	 * @param Site                      $site                 Site being scanned.
	 * @param File_Scanner              $files                Read-only access to the site's files.
	 * @param Read_Only_Connection|null $database             Open connection, or null when there is none yet.
	 * @param string|null               $database_unavailable Why there is no connection. Null when there is one.
	 */
	private function __construct( Site $site, File_Scanner $files, ?Read_Only_Connection $database, ?string $database_unavailable ) {
		$this->site                 = $site;
		$this->files                = $files;
		$this->database             = $database;
		$this->database_unavailable = $database_unavailable;
	}

	/**
	 * A context around a connection that is already open.
	 *
	 * @since 0.1.0
	 *
	 * @param Site                 $site     Site being scanned.
	 * @param File_Scanner         $files    Read-only access to the site's files.
	 * @param Read_Only_Connection $database Open connection to the site database.
	 * @return self
	 */
	public static function with_database( Site $site, File_Scanner $files, Read_Only_Connection $database ): self {
		return new self( $site, $files, $database, null );
	}

	/**
	 * A context that opens the database the first time a check asks for it.
	 *
	 * Checks that never ask leave the connection closed. The opener runs once,
	 * and its connection or failure reason is kept for every later check.
	 *
	 * @since 0.1.0
	 *
	 * @param Site         $site  Site being scanned.
	 * @param File_Scanner $files Read-only access to the site's files.
	 * @param callable     $open  Returns array{0: Read_Only_Connection|null, 1: string|null}: the connection, or null and the reason.
	 * @return self
	 */
	public static function with_lazy_database( Site $site, File_Scanner $files, callable $open ): self {
		$context                = new self( $site, $files, null, 'the database has not been opened yet' );
		$context->open_database = $open;

		return $context;
	}

	/**
	 * A context where database checks must skip.
	 *
	 * @since 0.1.0
	 *
	 * @param Site         $site   Site being scanned.
	 * @param File_Scanner $files  Read-only access to the site's files.
	 * @param string       $reason Why there is no database access, given to every check that skips.
	 * @return self
	 * @throws \InvalidArgumentException When the reason is blank.
	 */
	public static function without_database( Site $site, File_Scanner $files, string $reason ): self {
		if ( '' === trim( $reason ) ) {
			throw new \InvalidArgumentException( 'A context without a database needs a reason' );
		}

		return new self( $site, $files, null, $reason );
	}

	/**
	 * A copy of this context with checksum lookups allowed or ruled out.
	 *
	 * @since 0.1.0
	 *
	 * @param Checksum_Source|null $source Source of checksums, or null when lookups are not allowed.
	 * @param string               $reason Why lookups are not allowed; required when there is no source.
	 * @return self
	 * @throws \InvalidArgumentException When there is neither a source nor a reason.
	 */
	public function with_checksums( ?Checksum_Source $source, string $reason = '' ): self {
		if ( null === $source && '' === trim( $reason ) ) {
			throw new \InvalidArgumentException( 'A context without a checksum source needs a reason' );
		}
		$copy                        = clone $this;
		$copy->checksums             = $source;
		$copy->checksums_unavailable = null === $source ? $reason : null;

		return $copy;
	}

	/**
	 * A copy of this context with limits from a config file.
	 *
	 * @since 0.1.0
	 *
	 * @param Thresholds $thresholds Limits read from the config file.
	 * @return self
	 */
	public function with_thresholds( Thresholds $thresholds ): self {
		$copy             = clone $this;
		$copy->thresholds = $thresholds;

		return $copy;
	}

	/**
	 * A copy of this context that carries the statements given with --queries.
	 *
	 * @since 0.3.0
	 *
	 * @param Supplied_Queries $queries Statements from the file.
	 * @return self
	 */
	public function with_queries( Supplied_Queries $queries ): self {
		$copy          = clone $this;
		$copy->queries = $queries;

		return $copy;
	}

	/**
	 * Statements given with --queries. Null when none were given.
	 *
	 * @since 0.3.0
	 *
	 * @return Supplied_Queries|null
	 */
	public function queries(): ?Supplied_Queries {
		return $this->queries;
	}

	/**
	 * Limits the checks compare against: those given to with_thresholds(), or the defaults.
	 *
	 * @since 0.1.0
	 *
	 * @return Thresholds
	 */
	public function thresholds(): Thresholds {
		return $this->thresholds ?? Thresholds::defaults();
	}

	/**
	 * Reason a checksum check must skip, or null when checksums() can be called.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function checksums_unavailable(): ?string {
		return $this->checksums_unavailable;
	}

	/**
	 * Source of official checksums. Call checksums_unavailable() first.
	 *
	 * @since 0.1.0
	 *
	 * @return Checksum_Source
	 * @throws \LogicException When lookups are not available.
	 */
	public function checksums(): Checksum_Source {
		if ( null === $this->checksums ) {
			throw new \LogicException( esc_cli( 'No checksum source: ' . $this->checksums_unavailable ) );
		}

		return $this->checksums;
	}

	/**
	 * The site under scan: its root, config path and WordPress version.
	 *
	 * @since 0.1.0
	 *
	 * @return Site
	 */
	public function site(): Site {
		return $this->site;
	}

	/**
	 * Constants parsed from wp-config.php. Shorthand for site()->config().
	 *
	 * @since 0.1.0
	 *
	 * @return Config
	 */
	public function config(): Config {
		return $this->site->config();
	}

	/**
	 * The only route a check has to the site's files.
	 *
	 * @since 0.1.0
	 *
	 * @return File_Scanner
	 */
	public function files(): File_Scanner {
		return $this->files;
	}

	/**
	 * Reason a database check must skip, or null when database() can be called.
	 *
	 * The first call on a lazy context opens the connection.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function database_unavailable(): ?string {
		$this->open_if_pending();

		return $this->database_unavailable;
	}

	/**
	 * The site database, behind the read-only statement gate. Call database_unavailable() first.
	 *
	 * @since 0.1.0
	 *
	 * @return Read_Only_Connection
	 * @throws \LogicException When there is no database access.
	 */
	public function database(): Read_Only_Connection {
		$this->open_if_pending();
		if ( null === $this->database ) {
			throw new \LogicException( esc_cli( 'No database access: ' . $this->database_unavailable ) );
		}

		return $this->database;
	}

	/**
	 * Runs the opener once, the first time a check asks about the database. When
	 * the opener throws or returns something malformed, the reason is recorded
	 * before the exception goes on, so later checks skip with the true cause.
	 * The reason is kept raw so redaction sees it before any escaping.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable What the opener threw, or \UnexpectedValueException when it returned neither a connection nor a reason.
	 */
	private function open_if_pending(): void {
		if ( null === $this->open_database ) {
			return;
		}
		$open                = $this->open_database;
		$this->open_database = null;
		try {
			list( $this->database, $this->database_unavailable ) = self::opened( $open() );
		} catch ( \Throwable $e ) {
			$this->database_unavailable = 'the database could not be opened: ' . $e->getMessage();
			throw $e;
		}
	}

	/**
	 * Checks what a database opener returned.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $result What the opener returned.
	 * @return array{0: Read_Only_Connection|null, 1: string|null} The connection and no reason, or no connection and the reason.
	 * @throws \UnexpectedValueException When the result is neither a connection nor a reason.
	 */
	private static function opened( $result ): array {
		if ( is_array( $result ) && 2 === count( $result ) ) {
			list( $database, $reason ) = $result;
			if ( $database instanceof Read_Only_Connection ) {
				return array( $database, null );
			}
			if ( is_string( $reason ) && '' !== trim( $reason ) ) {
				return array( null, $reason );
			}
		}

		throw new \UnexpectedValueException( 'The database opener must return a connection or a reason' );
	}

	/**
	 * Reason a check that queries the site's tables must skip: no database, or a
	 * table prefix that could not be read. Null when table() can be called.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function site_tables_unavailable(): ?string {
		$database = $this->database_unavailable();
		if ( null !== $database ) {
			return $database;
		}

		return $this->site->config()->skip_reason( Config::TABLE_PREFIX );
	}

	/**
	 * A quoted table name carrying the site's table prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Table name without the prefix, such as options.
	 * @return string For example `wp_options`, backticks included.
	 * @throws \InvalidArgumentException When the name is not lowercase letters and underscores.
	 * @throws \LogicException           When site_tables_unavailable() is not null.
	 */
	public function table( string $name ): string {
		if ( 1 !== preg_match( '/^[a-z_]+\z/', $name ) ) {
			throw new \InvalidArgumentException( esc_cli( 'Unsupported table name: ' . $name ) );
		}
		$reason = $this->site_tables_unavailable();
		if ( null !== $reason ) {
			throw new \LogicException( esc_cli( 'Site tables unavailable: ' . $reason ) );
		}

		return '`' . $this->site->config()->table_prefix() . $name . '`';
	}

	/**
	 * Reason the content directory cannot be located, or null when content_dir() can be called.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function content_dir_unavailable(): ?string {
		return $this->resolve_directory( 'WP_CONTENT_DIR', $this->site->core_path( 'wp-content' ) )[1];
	}

	/**
	 * The content directory relative to the site root.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 * @throws \LogicException When content_dir_unavailable() is not null.
	 */
	public function content_dir(): string {
		list( $directory, $reason ) = $this->resolve_directory( 'WP_CONTENT_DIR', $this->site->core_path( 'wp-content' ) );
		if ( null === $directory ) {
			throw new \LogicException( esc_cli( 'Content directory unavailable: ' . $reason ) );
		}

		return $directory;
	}

	/**
	 * Why the uploads directory from uploads_dir() may not be the one WordPress
	 * uses: the upload_path option, which moves it when UPLOADS is not set, could
	 * not be read. Null when the location is confirmed.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function uploads_dir_unconfirmed(): ?string {
		if ( $this->site->config()->is_defined( 'UPLOADS' ) ) {
			return null;
		}

		return $this->upload_path_option()[1];
	}

	/**
	 * Reason the uploads directory cannot be located. Null when uploads_dir() can be called.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function uploads_dir_unavailable(): ?string {
		return $this->resolve_uploads()[1];
	}

	/**
	 * The uploads directory relative to the site root.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 * @throws \LogicException When uploads_dir_unavailable() is not null.
	 */
	public function uploads_dir(): string {
		list( $directory, $reason ) = $this->resolve_uploads();
		if ( null === $directory ) {
			throw new \LogicException( esc_cli( 'Uploads directory unavailable: ' . $reason ) );
		}

		return $directory;
	}

	/**
	 * Reason the plugin directories cannot be located. Null when plugins_dir() can be called.
	 *
	 * WP_PLUGIN_DIR and WPMU_PLUGIN_DIR are not followed, so either being set
	 * makes the plugin directories unavailable rather than silently wrong.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function plugins_dir_unavailable(): ?string {
		$config = $this->site->config();
		foreach ( array( 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $name ) {
			if ( $config->is_defined( $name ) ) {
				return "$name is set in wp-config.php; only the default plugin directories are supported";
			}
		}

		return $this->content_dir_unavailable();
	}

	/**
	 * The plugins directory relative to the site root, always content_dir()/plugins.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 * @throws \LogicException When plugins_dir_unavailable() is not null.
	 */
	public function plugins_dir(): string {
		$reason = $this->plugins_dir_unavailable();
		if ( null !== $reason ) {
			throw new \LogicException( esc_cli( 'Plugin directories unavailable: ' . $reason ) );
		}

		return $this->content_dir() . '/plugins';
	}

	/**
	 * Reason the debug log cannot be located, or null when debug_log_path() can be called.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function debug_log_unavailable(): ?string {
		return $this->resolve_debug_log()[1];
	}

	/**
	 * The debug log file relative to the site root: WP_DEBUG_LOG when it names a
	 * file inside the root, else debug.log in the content directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 * @throws \LogicException When debug_log_unavailable() is not null.
	 */
	public function debug_log_path(): string {
		list( $path, $reason ) = $this->resolve_debug_log();
		if ( null === $path ) {
			throw new \LogicException( esc_cli( 'Debug log path unavailable: ' . $reason ) );
		}

		return $path;
	}

	/**
	 * Resolves the debug log. A boolean WP_DEBUG_LOG keeps the default file.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: string|null, 1: string|null} The file relative to the root, or null and the reason.
	 */
	private function resolve_debug_log(): array {
		$config = $this->site->config();
		if ( $config->is_defined( 'WP_DEBUG_LOG' ) && ! ( $config->has( 'WP_DEBUG_LOG' ) && is_bool( $config->value( 'WP_DEBUG_LOG' ) ) ) ) {
			return $this->resolve_path( 'WP_DEBUG_LOG', null );
		}
		list( $content, $reason ) = $this->resolve_directory( 'WP_CONTENT_DIR', $this->site->core_path( 'wp-content' ) );

		return null === $content ? array( null, $reason ) : array( $content . '/debug.log', null );
	}

	/**
	 * The uploads directory: UPLOADS relative to the core directory when set,
	 * then the upload_path option, else content_dir()/uploads.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: string|null, 1: string|null} The directory relative to the root, or null and the reason.
	 */
	private function resolve_uploads(): array {
		$config = $this->site->config();
		if ( ! $config->is_defined( 'UPLOADS' ) ) {
			$option = $this->upload_path_option()[0];
			if ( null !== $option && '' !== $option ) {
				return $this->option_directory( 'upload_path', $option );
			}
			list( $content, $reason ) = $this->resolve_directory( 'WP_CONTENT_DIR', $this->site->core_path( 'wp-content' ) );

			return null === $content ? array( null, $reason ) : array( $content . '/uploads', null );
		}
		if ( ! $config->has( 'UPLOADS' ) ) {
			return array( null, 'UPLOADS is set in wp-config.php but could not be read (' . ( $config->unresolved()['UPLOADS'] ?? 'built from a path' ) . ')' );
		}
		$value = $config->value( 'UPLOADS' );
		if ( ! is_string( $value ) ) {
			return array( null, 'UPLOADS in wp-config.php is not a string' );
		}

		return $this->core_relative_directory( 'UPLOADS in wp-config.php', trim( $value, '/' ) );
	}

	/**
	 * A directory constant resolved to a path inside the site root.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     Constant that may override the directory, such as WP_CONTENT_DIR.
	 * @param string $fallback Directory relative to the root when the constant is not defined.
	 * @return array{0: string|null, 1: string|null} The directory relative to the root, or null and the reason.
	 */
	private function resolve_directory( string $name, string $fallback ): array {
		return $this->resolve_path( $name, $fallback );
	}

	/**
	 * The upload_path option, read once. Empty when it is unset or blank.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: string|null, 1: string|null} The trimmed value, or null and why it could not be read.
	 * @throws \UnexpectedValueException When the database returns an unexpected row.
	 */
	private function upload_path_option(): array {
		if ( null !== $this->upload_path ) {
			return $this->upload_path;
		}
		$unavailable = $this->site_tables_unavailable();
		if ( null !== $unavailable ) {
			$this->upload_path = array( null, 'the upload_path option, which can move the uploads directory, could not be read: ' . $unavailable );

			return $this->upload_path;
		}
		$rows = $this->database()->query(
			'SELECT LEFT(option_value, ' . ( self::MAX_UPLOAD_PATH_BYTES + 1 ) . ') AS option_value FROM ' . $this->table( 'options' )
			. " WHERE option_name = 'upload_path' LIMIT 1"
		);
		if ( array() !== $rows && ! array_key_exists( 'option_value', $rows[0] ) ) {
			throw new \UnexpectedValueException( 'The upload_path query returned an unexpected row' );
		}
		$value = array() === $rows ? '' : trim( (string) $rows[0]['option_value'] );
		if ( strlen( $value ) > self::MAX_UPLOAD_PATH_BYTES ) {
			$this->upload_path = array( null, 'the upload_path option is longer than ' . self::MAX_UPLOAD_PATH_BYTES . ' bytes and was not used' );

			return $this->upload_path;
		}
		$this->upload_path = array( $value, null );

		return $this->upload_path;
	}

	/**
	 * A directory named by an option: absolute, or relative to the core
	 * directory, as WordPress reads upload_path relative to ABSPATH.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  Option name, for the reason.
	 * @param string $value Option value, not empty.
	 * @return array{0: string|null, 1: string|null} The directory relative to the root, or null and the reason.
	 */
	private function option_directory( string $name, string $value ): array {
		$value = rtrim( str_replace( '\\', '/', $value ), '/' );
		if ( '/' !== $value[0] && 1 !== preg_match( '#^[A-Za-z]:/#', $value ) ) {
			return $this->core_relative_directory( "The $name option", ltrim( $value, '/' ) );
		}
		$root     = rtrim( $this->site->root(), '/' );
		$absolute = $this->inside_root( $value );
		if ( null === $absolute ) {
			return array( null, "The $name option points outside the site root; only directories inside it are supported" );
		}

		return $this->relative_directory( "The $name option", ltrim( substr( $absolute, strlen( $root ) ), '/' ) );
	}

	/**
	 * An absolute path rewritten under the site root, or null when it lies outside.
	 * A path that exists is resolved first, so a root reached through a symlink
	 * still matches.
	 *
	 * @since 0.1.0
	 *
	 * @param string $absolute Absolute path with forward slashes and no trailing slash.
	 * @return string|null
	 */
	private function inside_root( string $absolute ): ?string {
		$root = rtrim( $this->site->root(), '/' );
		$real = real_path( $absolute );
		if ( false !== $real ) {
			$absolute = rtrim( str_replace( '\\', '/', $real ), '/' );
		}

		return 0 === strpos( $absolute . '/', $root . '/' ) ? $absolute : null;
	}

	/**
	 * A path constant resolved to a location inside the site root.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $name     Constant that may hold the path, such as WP_DEBUG_LOG.
	 * @param string|null $fallback Relative path used when the constant is not defined. Null makes an undefined constant a reason to skip.
	 * @return array{0: string|null, 1: string|null} The path relative to the root, or null and the reason.
	 */
	private function resolve_path( string $name, ?string $fallback ): array {
		$config = $this->site->config();
		$root   = rtrim( $this->site->root(), '/' );
		if ( null !== $config->unreadable_reason() ) {
			return array( null, "$name could not be looked up: " . $config->unreadable_reason() );
		}
		if ( ! $config->is_defined( $name ) ) {
			return null === $fallback ? array( null, "$name is not set in wp-config.php" ) : array( $fallback, null );
		}
		$path = $config->path_constant( $name );
		if ( null !== $path ) {
			$core     = '' === $this->site->core_dir() ? $root : $root . '/' . $this->site->core_dir();
			$base     = '__DIR__' === $path['base'] ? rtrim( dirname( $this->site->config_path() ), '/' ) : $core;
			$absolute = $base . '/' . ltrim( $path['suffix'], '/' );
		} elseif ( $config->has( $name ) && is_string( $config->value( $name ) ) ) {
			$absolute = $config->value( $name );
		} else {
			return array( null, "$name is set in wp-config.php but could not be read (" . ( $config->unresolved()[ $name ] ?? 'not a string' ) . ')' );
		}
		$absolute = $this->inside_root( rtrim( str_replace( '\\', '/', $absolute ), '/' ) );
		if ( null === $absolute ) {
			return array( null, "$name in wp-config.php points outside the site root; only directories inside it are supported" );
		}

		return $this->relative_directory( "$name in wp-config.php", ltrim( substr( $absolute, strlen( $root ) ), '/' ) );
	}

	/**
	 * Rejects a relative path that is empty or climbs out of the root.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     Constant the path came from, named in the reason.
	 * @param string $relative Path relative to the root, without surrounding slashes.
	 * @return array{0: string|null, 1: string|null} The path, or null and the reason.
	 */
	private function relative_directory( string $name, string $relative ): array {
		if ( '' === $relative ) {
			return array( null, "$name names the site root itself, which is not supported" );
		}
		if ( in_array( '..', explode( '/', $relative ), true ) || false !== strpos( $relative, "\0" ) ) {
			return array( null, "$name contains a parent-directory segment, which is not supported" );
		}

		return array( $relative, null );
	}

	/**
	 * Turns a path that WordPress reads relative to ABSPATH, the core
	 * directory, into one relative to the root.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name     Setting the path came from, named in the reason.
	 * @param string $relative Path relative to the core directory, without surrounding slashes.
	 * @return array{0: string|null, 1: string|null} The path relative to the root, or null and the reason.
	 */
	private function core_relative_directory( string $name, string $relative ): array {
		list( $path, $reason ) = $this->relative_directory( $name, $relative );

		return null === $path ? array( null, $reason ) : array( $this->site->core_path( $path ), null );
	}
}
