<?php
/**
 * Runs checks.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

use WP_Doctor\Db\Read_Only_Violation_Exception;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;

/**
 * Runs every registered check. When wp-config.php could not be read, only
 * checks that implement Runs_Without_Config run; the others are skipped with
 * the reason. Anything a check throws becomes an error result,
 * never a pass, and does not stop the other checks. A refused statement is
 * named as a wp-doctor bug, since no check should ever produce one. Messages
 * are kept raw so the Redactor sees secrets before any escaping; formatters
 * escape them for output.
 *
 * @since 0.1.0
 */
final class Runner {

	/**
	 * Runs the checks in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param Check_Registry $registry Checks to run.
	 * @param Context        $context  What the checks may use.
	 * @return Report_Entry[]
	 */
	public function run( Check_Registry $registry, Context $context ): array {
		$entries    = array();
		$unreadable = $context->site()->config()->unreadable_reason();
		foreach ( $registry->all() as $check ) {
			if ( null !== $unreadable && ! $check instanceof Runs_Without_Config ) {
				$entries[] = new Report_Entry( $check->id(), $check->category(), Result::skipped( $unreadable ) );
				continue;
			}
			try {
				$result = $check->run( $context );
			} catch ( Read_Only_Violation_Exception $e ) {
				$result = Result::error( 'Read-only violation, a bug in wp-doctor to report: the statement was refused and nothing was written. ' . $e->getMessage() );
			} catch ( \Throwable $e ) {
				$result = Result::error( get_class( $e ) . ': ' . $e->getMessage() );
			}
			$entries[] = new Report_Entry( $check->id(), $check->category(), $result );
		}

		return $entries;
	}
}
