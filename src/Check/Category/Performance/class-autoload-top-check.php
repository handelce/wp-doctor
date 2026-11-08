<?php
/**
 * The largest autoloaded options.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

use function WP_Doctor\esc_cli;

/**
 * Lists the options that contribute most to the autoloaded total, so the
 * owner can see which plugin or theme to look at.
 *
 * @since 0.1.0
 */
final class Autoload_Top_Check implements Check {

	private const LIMIT = 10;

	/**
	 * Id that the perf.autoload.size fix points readers to.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.autoload.top';
	}

	/**
	 * Performance, alongside perf.autoload.size.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Reports the ten largest autoloaded options.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the options table.
	 * @return Result
	 * @throws \UnexpectedValueException When a returned row is not a name and a size.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$table = $context->table( 'options' );
		$rows  = $context->database()->query(
			'SELECT option_name, LENGTH(option_value) AS size_bytes FROM ' . $table
			. ' WHERE ' . Autoload_Options::CONDITION
			. ' ORDER BY size_bytes DESC, option_name ASC LIMIT ' . self::LIMIT
		);

		if ( array() === $rows ) {
			return Result::skipped( esc_cli( "No autoloaded rows in $table; the table prefix or database may be wrong" ) );
		}
		if ( count( $rows ) > self::LIMIT ) {
			throw new \UnexpectedValueException( 'The largest-options query returned more rows than it asked for' );
		}

		$evidence = array( 'options' => array() );
		foreach ( $rows as $row ) {
			if ( ! isset( $row['option_name'], $row['size_bytes'] ) || 1 !== preg_match( '/^\d+$/', $row['size_bytes'] ) ) {
				throw new \UnexpectedValueException( 'The largest-options query returned an unexpected row' );
			}
			$evidence['options'][] = array(
				'name'  => $row['option_name'],
				'bytes' => (int) $row['size_bytes'],
			);
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'Largest autoloaded options',
				$evidence,
				'Look up which plugin or theme owns each large option. Delete rows left by plugins that are no longer '
				. 'installed, and set autoload to no for options that are not needed on every request. Take a database backup first.'
			)
		);
	}
}
