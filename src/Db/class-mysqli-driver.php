<?php
/**
 * Database driver backed by mysqli.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Db;

use function WP_Doctor\esc_cli;

/**
 * The one class that talks to mysqli.
 *
 * @since 0.1.0
 */
final class Mysqli_Driver implements Versioned_Driver {

	private const CONNECT_TIMEOUT_SECONDS = 10;
	private const READ_TIMEOUT_SECONDS    = 60;
	private const DEFAULT_PORT            = 3306;

	/**
	 * Connection opened by connect().
	 *
	 * @since 0.1.0
	 *
	 * @var \mysqli
	 */
	private $mysqli;

	/**
	 * Callers use connect().
	 *
	 * @since 0.1.0
	 *
	 * @param \mysqli $mysqli Connection with the utf8mb4 charset set.
	 */
	private function __construct( \mysqli $mysqli ) {
		$this->mysqli = $mysqli;
	}

	/**
	 * Opens a connection with the credentials from wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @param string $host     DB_HOST, possibly with a port or socket.
	 * @param string $user     DB_USER.
	 * @param string $password DB_PASSWORD.
	 * @param string $name     DB_NAME.
	 * @return self
	 * @throws Database_Exception When the connection fails.
	 */
	public static function connect( string $host, string $user, string $password, string $name ): self {
		if ( ! extension_loaded( 'mysqli' ) ) {
			throw new Database_Exception( 'The mysqli extension is not loaded' );
		}
		mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
		$parts = self::split_host( $host );

		// An unresolvable host raises a PHP warning as well as the exception; the warning would print into the report.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped to the connection attempt.
			static function (): bool {
				return true;
			},
			E_WARNING
		);

		try {
			$mysqli = mysqli_init();
			if ( false === $mysqli ) {
				throw new Database_Exception( 'mysqli could not be initialised' );
			}
			foreach ( self::client_options() as $option => $value ) {
				$mysqli->options( $option, $value );
			}
			$mysqli->real_connect( $parts['host'], $user, $password, $name, $parts['port'], $parts['socket'] );
			$mysqli->set_charset( 'utf8mb4' );
		} catch ( \mysqli_sql_exception $e ) {
			throw new Database_Exception( self::connection_error( (int) $e->getCode(), $e->getMessage() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- connection_error() passes the message through esc_cli().
		} finally {
			restore_error_handler();
		}

		return new self( $mysqli );
	}

	/**
	 * Client options set before connecting. The read timeout ends a query the
	 * server never answers, so one hung statement cannot hang the scan; builds
	 * of mysqli without the option get the connect timeout only.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, int> Seconds by MYSQLI_OPT_* constant.
	 */
	public static function client_options(): array {
		$options = array( MYSQLI_OPT_CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS );
		if ( defined( 'MYSQLI_OPT_READ_TIMEOUT' ) ) {
			$options[ (int) constant( 'MYSQLI_OPT_READ_TIMEOUT' ) ] = self::READ_TIMEOUT_SECONDS;
		}

		return $options;
	}

	/**
	 * The message for a failed connection. The server's "Access denied for
	 * user 'name'@'host'" names the address wp-doctor connected from, which is
	 * not in wp-config.php and so cannot be redacted by value; it is masked here.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $code    MySQL error code.
	 * @param string $message Server or client message.
	 * @return string
	 */
	public static function connection_error( int $code, string $message ): string {
		$message = (string) preg_replace( "/('[^']*')@'[^']*'/", "$1@'[redacted]'", $message );

		return esc_cli( sprintf( 'Could not connect to the database (error %d): %s', $code, $message ) );
	}

	/**
	 * Splits a WordPress DB_HOST value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $host Host, host:port, [ipv6]:port or host:/path/to.sock.
	 * @return array{host: string, port: int, socket: string}
	 */
	public static function split_host( string $host ): array {
		$result = array(
			'host'   => $host,
			'port'   => self::DEFAULT_PORT,
			'socket' => '',
		);

		if ( 1 === preg_match( '/^\[([^\]]+)\](?::(\d+))?$/', $host, $m ) ) {
			$result['host'] = $m[1];
			$result['port'] = isset( $m[2] ) ? (int) $m[2] : self::DEFAULT_PORT;
		} elseif ( 1 === preg_match( '/^([^:]*):(\d+)$/', $host, $m ) ) {
			$result['host'] = $m[1];
			$result['port'] = (int) $m[2];
		} elseif ( 1 === preg_match( '/^([^:]*):(\/.+)$/', $host, $m ) ) {
			$result['host']   = $m[1];
			$result['socket'] = $m[2];
		}

		if ( '' === $result['host'] ) {
			$result['host'] = 'localhost';
		}

		return $result;
	}

	/**
	 * The version string the server announced when the connection opened.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example 8.0.36 or 10.11.6-MariaDB.
	 */
	public function server_version(): string {
		return (string) $this->mysqli->server_info;
	}

	/**
	 * Runs one statement and returns its rows.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql      Statement already accepted by the gate.
	 * @param int    $max_rows Most rows accepted. 0 for a statement that returns none.
	 * @return array<int, array<string, string|null>>
	 * @throws Database_Exception When the statement fails or the result is larger than the limit.
	 */
	public function execute( string $sql, int $max_rows ): array {
		try {
			$result = $this->mysqli->query( $sql );
		} catch ( \mysqli_sql_exception $e ) {
			throw new Database_Exception( esc_cli( sprintf( 'Query failed (error %d): %s', $e->getCode(), $e->getMessage() ) ) );
		}

		if ( ! $result instanceof \mysqli_result ) {
			return array();
		}

		$rows = array();
		foreach ( $result as $row ) {
			if ( count( $rows ) >= $max_rows ) {
				$result->free();
				throw new Database_Exception( esc_cli( sprintf( 'Result larger than %d rows', $max_rows ) ) );
			}
			$rows[] = $row;
		}
		$result->free();

		return $rows;
	}
}
