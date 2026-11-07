<?php
/**
 * Reads one row of the options or sitemeta table with a size limit.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

use function WP_Doctor\esc_cli;

/**
 * Looks up an option, or a multisite network option, by name. The size is
 * asked for first, so a value larger than the limit is never fetched into
 * memory.
 *
 * @since 0.1.0
 */
final class Option_Value {

	/**
	 * Fetches an option's value when it is no larger than the limit.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context   Scan context. Its site_tables_unavailable() must be null.
	 * @param string  $name      Option name: letters, digits, underscore, dot and hyphen.
	 * @param int     $max_bytes Largest value, in bytes, that is fetched.
	 * @return array{0: string|null, 1: string|null} The value, or null and the reason it was not read.
	 * @throws \InvalidArgumentException When the name is not a plain option name.
	 * @throws \UnexpectedValueException When the database returns an unexpected result.
	 */
	public static function read( Context $context, string $name, int $max_bytes ): array {
		self::assert_plain_name( $name );
		$table = $context->table( 'options' );

		return self::read_row( $context, $table, "option_name = '$name'", 'option_value', "No $name option in $table; the table prefix or database may be wrong", $name, $max_bytes );
	}

	/**
	 * Fetches a multisite network option from sitemeta when it is no larger
	 * than the limit.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context    Scan context. Its site_tables_unavailable() must be null.
	 * @param string  $name       Meta key: letters, digits, underscore, dot and hyphen.
	 * @param int     $max_bytes  Largest value, in bytes, that is fetched.
	 * @param int     $network_id Network whose option is read; 1 is the main network.
	 * @return array{0: string|null, 1: string|null} The value, or null and the reason it was not read.
	 * @throws \InvalidArgumentException When the name is not a plain option name.
	 * @throws \UnexpectedValueException When the database returns an unexpected result.
	 */
	public static function read_network( Context $context, string $name, int $max_bytes, int $network_id ): array {
		self::assert_plain_name( $name );
		$table = $context->table( 'sitemeta' );

		return self::read_row( $context, $table, "site_id = $network_id AND meta_key = '$name'", 'meta_value', "No $name network option for network $network_id in $table", $name, $max_bytes );
	}

	/**
	 * Refuses a name that could not be put into SQL as it is.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Option name or meta key.
	 * @throws \InvalidArgumentException When the name is not a plain option name.
	 */
	private static function assert_plain_name( string $name ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_.\-]+\z/', $name ) ) {
			throw new \InvalidArgumentException( esc_cli( 'Unsupported option name: ' . $name ) );
		}
	}

	/**
	 * Asks for the size of one row's value, then fetches the value if it fits.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context   Scan context.
	 * @param string  $table     Quoted table name.
	 * @param string  $where     Condition that selects the row, built from checked values only.
	 * @param string  $column    Column that holds the value.
	 * @param string  $absent    Reason given when there is no such row.
	 * @param string  $name      Option name, for messages.
	 * @param int     $max_bytes Largest value, in bytes, that is fetched.
	 * @return array{0: string|null, 1: string|null}
	 * @throws \UnexpectedValueException When the database returns an unexpected result.
	 */
	private static function read_row( Context $context, string $table, string $where, string $column, string $absent, string $name, int $max_bytes ): array {
		$rows = $context->database()->query( "SELECT LENGTH($column) AS size_bytes FROM $table WHERE $where LIMIT 1" );
		if ( array() === $rows ) {
			return array( null, esc_cli( $absent ) );
		}
		if ( ! isset( $rows[0]['size_bytes'] ) || 1 !== preg_match( '/^\d+$/', $rows[0]['size_bytes'] ) ) {
			throw new \UnexpectedValueException( esc_cli( "The $name option size query returned an unexpected result" ) );
		}
		$bytes = (int) $rows[0]['size_bytes'];
		if ( $bytes > $max_bytes ) {
			return array( null, esc_cli( "The $name option is $bytes bytes, above the $max_bytes bytes this check reads" ) );
		}

		$rows = $context->database()->query( "SELECT $column FROM $table WHERE $where LIMIT 1" );
		if ( 1 !== count( $rows ) || ! isset( $rows[0][ $column ] ) ) {
			throw new \UnexpectedValueException( esc_cli( "The $name option query returned an unexpected result" ) );
		}

		return array( $rows[0][ $column ], null );
	}
}
