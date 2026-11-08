<?php
/**
 * Size of the uploads directory.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Disk;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Uploads are usually the largest part of a site on disk and in its backups.
 * The listing shows where the space goes: the year folders WordPress files
 * media under, and folders plugins write there.
 *
 * @since 0.2.0
 */
final class Uploads_Size_Check implements Check {

	private const LISTED = 10;

	/**
	 * Id of the uploads size listing.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'disk.uploads.size';
	}

	/**
	 * Grouped under disk.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'disk';
	}

	/**
	 * Adds up the uploads directory and the largest folders in it.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the uploads directory and the scanner.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->uploads_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		try {
			$size = new Directory_Size( $context->files(), $context->uploads_dir() );
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( $e->getMessage() );
		}

		$summary  = $size->summary();
		$evidence = array(
			'scan_incomplete' => null !== $size->gap(),
			'directory'       => $summary['path'],
			'bytes'           => $summary['bytes'],
			'files'           => $summary['files'],
		);
		if ( null !== $size->gap() ) {
			$evidence['not_counted'] = $size->gap();
		}
		$evidence['largest'] = $size->largest( self::LISTED );
		$location            = $context->uploads_dir_unconfirmed();
		if ( null !== $location ) {
			$evidence['location_unconfirmed'] = $location;
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'Size of the uploads directory',
				$evidence,
				'Look at the largest folders first when the disk or the backups are too large. Media that no post uses can be '
				. 'found with a media cleanup plugin; folders that plugins left behind can be removed after a backup.'
			)
		);
	}
}
