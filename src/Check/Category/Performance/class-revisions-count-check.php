<?php
/**
 * Post revisions piling up in the posts table.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * WordPress keeps every revision of a post unless WP_POST_REVISIONS limits
 * them. They live in the posts table as rows of type `revision`, so a large
 * number makes that table and its backups larger and its queries slower.
 *
 * @since 0.2.0
 */
final class Revisions_Count_Check implements Check {

	private const LISTED = 5;

	/**
	 * Id that keys the revision threshold.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.revisions.count';
	}

	/**
	 * Grouped under performance.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Counts revision rows and the posts that have the most.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the posts table, the threshold and WP_POST_REVISIONS from wp-config.php.
	 * @return Result
	 * @throws \UnexpectedValueException When the database returns an unexpected result.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$posts  = $context->table( 'posts' );
		$totals = $context->database()->query(
			'SELECT COUNT(*) AS revisions, COUNT(DISTINCT post_parent) AS parents FROM ' . $posts . " WHERE post_type = 'revision'"
		);
		if ( 1 !== count( $totals ) || ! self::is_count( $totals[0]['revisions'] ?? null ) || ! self::is_count( $totals[0]['parents'] ?? null ) ) {
			throw new \UnexpectedValueException( 'The revision count query returned an unexpected result' );
		}
		$revisions     = (int) $totals[0]['revisions'];
		$warning_above = $context->thresholds()->warning( $this->id() );
		if ( $revisions <= $warning_above ) {
			return Result::pass();
		}

		$evidence          = array(
			'revisions'            => $revisions,
			'posts_with_revisions' => (int) $totals[0]['parents'],
			'warning_above_rows'   => $warning_above,
			'threshold_basis'      => $context->thresholds()->basis( $this->id() ),
			'WP_POST_REVISIONS'    => $this->limit_setting( $context ),
		);
		$top               = $context->database()->query(
			'SELECT post_parent AS parent, COUNT(*) AS revisions FROM ' . $posts . " WHERE post_type = 'revision'"
			. ' GROUP BY post_parent ORDER BY revisions DESC, parent ASC LIMIT ' . self::LISTED
		);
		$evidence['posts'] = array();
		foreach ( $top as $row ) {
			if ( ! self::is_count( $row['parent'] ?? null ) || ! self::is_count( $row['revisions'] ?? null ) ) {
				throw new \UnexpectedValueException( 'The revisions by post query returned an unexpected row' );
			}
			$evidence['posts'][] = array(
				'id'        => (int) $row['parent'],
				'revisions' => (int) $row['revisions'],
			);
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'The posts table holds many post revisions',
				$evidence,
				'Limit how many revisions WordPress keeps with `define( \'WP_POST_REVISIONS\', 10 );` in wp-config.php. '
				. 'The limit applies as posts are saved again, so remove the old ones once: take a database backup, then run '
				. '`wp post list --post_type=revision --format=ids | xargs wp post delete --force`.'
			)
		);
	}

	/**
	 * What WP_POST_REVISIONS tells WordPress to keep, read as wp_revisions_to_keep() reads it.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the parsed wp-config.php.
	 * @return string
	 */
	private function limit_setting( Context $context ): string {
		$config = $context->config();
		if ( array_key_exists( 'WP_POST_REVISIONS', $config->unresolved() ) ) {
			return 'not read: ' . $config->unresolved()['WP_POST_REVISIONS'];
		}
		if ( ! $config->has( 'WP_POST_REVISIONS' ) ) {
			return $config->unread_code() > 0 ? 'not set in wp-config.php, which loads code that was not read' : 'not set, so every revision is kept';
		}
		$value = $config->value( 'WP_POST_REVISIONS' );
		$keep  = true === $value ? -1 : (int) $value;
		if ( $keep < 0 ) {
			return 'every revision is kept';
		}

		return 0 === $keep ? 'no revisions are kept' : "$keep per post";
	}

	/**
	 * Whether a value from the database is a non-negative whole number.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $value Column value.
	 * @return bool
	 */
	private static function is_count( ?string $value ): bool {
		return null !== $value && 1 === preg_match( '/^\d+$/', $value );
	}
}
