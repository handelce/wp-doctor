<?php
/**
 * Database driver that answers option lookups from canned values.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use WP_Doctor\Db\Database_Driver;

final class Options_Driver implements Database_Driver {

	/**
	 * Every statement received, in order.
	 *
	 * @var string[]
	 */
	public $statements = array();

	/**
	 * Option values by option name.
	 *
	 * @var array<string, string>
	 */
	public $options = array();

	/**
	 * Network option values by meta key, as a multisite network keeps them in sitemeta.
	 *
	 * @var array<string, string>
	 */
	public $network_options = array();

	/**
	 * Answers a size or value lookup for a known option or network option, and nothing else.
	 *
	 * @param string $sql      SQL sent through the connection.
	 * @param int    $max_rows Row limit the connection passed. Ignored here.
	 * @return array<int, array<string, string|null>>
	 */
	public function execute( string $sql, int $max_rows ): array {
		$this->statements[] = $sql;
		foreach ( $this->options as $name => $value ) {
			if ( false !== strpos( $sql, "option_name = '$name'" ) ) {
				return false !== strpos( $sql, 'LENGTH(option_value)' )
					? array( array( 'size_bytes' => (string) strlen( $value ) ) )
					: array( array( 'option_value' => $value ) );
			}
		}
		foreach ( $this->network_options as $name => $value ) {
			if ( false !== strpos( $sql, "meta_key = '$name'" ) ) {
				return false !== strpos( $sql, 'LENGTH(meta_value)' )
					? array( array( 'size_bytes' => (string) strlen( $value ) ) )
					: array( array( 'meta_value' => $value ) );
			}
		}

		return array();
	}
}
