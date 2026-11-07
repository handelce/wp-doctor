<?php
/**
 * PHP code hidden in files that look like images.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Looks for image files in uploads that carry PHP code near the start of the
 * file, or whose name has a PHP extension in front of the image extension.
 * The content test is a heuristic: it reads the first kilobyte only.
 *
 * @since 0.1.0
 */
final class Uploads_Disguised_Check implements Check {

	private const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'tif', 'tiff', 'avif' );
	private const PHP_BEFORE_IMAGE = '/\.(?:php\d*|phtml|pht|phar|phps)[._\-]*\z/';
	private const PHP_OPEN_TAG     = '/<\?(?:php|=)/i';
	private const HEAD_BYTES       = 1024;

	/**
	 * Id of the disguised-image finding.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.uploads.disguised';
	}

	/**
	 * Security, with the other uploads check.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Lists image files that hide PHP.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the uploads directory and the file scanner.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->uploads_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$files    = $context->files();
		$uploads  = $context->uploads_dir();
		$examined = 0;
		$found    = array();
		$reasons  = array();
		$links    = array();
		try {
			$walk = $files->walk( $uploads );
			foreach ( $walk as $entry ) {
				if ( $entry->is_link() ) {
					$links[] = $entry->path();
					continue;
				}
				if ( ! $entry->is_file() || ! in_array( $entry->extension(), self::IMAGE_EXTENSIONS, true ) ) {
					continue;
				}
				++$examined;
				$why = array();
				if ( 1 === preg_match( self::PHP_BEFORE_IMAGE, substr( strtolower( $entry->name() ), 0, -( strlen( $entry->extension() ) + 1 ) ) ) ) {
					$why[] = 'a PHP extension comes before the image extension';
				}
				if ( 1 === preg_match( self::PHP_OPEN_TAG, (string) $files->read( $entry->path(), self::HEAD_BYTES ) ) ) {
					$why[] = 'a PHP open tag appears in the first ' . self::HEAD_BYTES . ' bytes';
				}
				if ( array() !== $why ) {
					$found[]                   = $entry;
					$reasons[ $entry->path() ] = implode( '; ', $why );
				}
			}
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( $e->getMessage() );
		}
		$gap         = $walk->incomplete_reason();
		$unconfirmed = $context->uploads_dir_unconfirmed();
		sort( $links, SORT_STRING );

		if ( array() === $found ) {
			if ( null !== $gap ) {
				return Result::skipped( "No disguised files were found, but not all of $uploads was examined: $gap" );
			}
			if ( array() !== $links ) {
				return Result::skipped( "No disguised files were found, but symlinks in $uploads point elsewhere in the site and were not followed: " . implode( ', ', array_slice( $links, 0, Evidence::MAX_LISTED ) ) );
			}
			if ( 0 === $examined ) {
				return Result::skipped( "No image files in $uploads, so there was nothing to examine" );
			}

			return null === $unconfirmed ? Result::pass() : Result::skipped( "No disguised files were found in $uploads, but it may not be the uploads directory WordPress uses: $unconfirmed" );
		}

		$evidence = array(
			'image_files_examined' => $examined,
			'suspicious_files'     => count( $found ),
			'scan_incomplete'      => null !== $gap,
		);
		if ( array() !== $links ) {
			$evidence['symlinks_not_followed'] = implode( ', ', array_slice( $links, 0, Evidence::MAX_LISTED ) );
		}
		if ( null !== $unconfirmed ) {
			$evidence['uploads_location_unconfirmed'] = $unconfirmed;
		}
		foreach ( array_slice( Evidence::by_path( $found ), 0, Evidence::MAX_LISTED ) as $index => $entry ) {
			$rank                             = $index + 1;
			$evidence[ "file_$rank" ]         = $entry->path();
			$evidence[ "file_{$rank}_bytes" ] = $entry->size();
			$evidence[ "file_{$rank}_why" ]   = $reasons[ $entry->path() ];
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::critical(),
				'Files that look like images likely contain PHP code',
				$evidence,
				'Open each file in a text editor. A real image never holds PHP; if you did not put the file there, treat the site '
				. 'as compromised: remove it, work out how it arrived from the access logs, and change the passwords and the keys and salts.'
			)
		);
	}
}
