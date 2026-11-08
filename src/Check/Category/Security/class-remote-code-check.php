<?php
/**
 * PHP that downloads code from another server and runs it.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Context;
use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Reports eval(), assert(), create_function(), include or require whose
 * argument is fetched from another server: by curl, the WordPress HTTP API,
 * a socket, or a file function given a URL or a decoded string. An include
 * of a URL counts as well, since PHP fetches it. Whoever controls that server
 * can then run any code on the site. Every PHP file in the site is read, and
 * wp-config.php wherever it is. Findings say "likely", since a URL built at
 * runtime cannot always be told apart from a local path.
 *
 * @since 0.2.0
 */
final class Remote_Code_Check implements Runs_Without_Config {

	private const RUNNERS = array( 'eval', 'assert', 'create_function', 'include', 'require' );

	/**
	 * Id of the remote code finding.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.code.remotefetch';
	}

	/**
	 * Grouped under security.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Looks for fetched content passed to code-running constructs.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the scanner that walks and reads the PHP files.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$signs   = array_merge( array( 'http', 'ftp', 'curl_', 'wp_remote_', 'wp_safe_remote_', 'fsockopen', 'stream_socket_client', 'download_url' ), Code_Sinks::DECODERS );
		$sources = new Php_Sources( $context, array( self::RUNNERS, $signs ) );
		try {
			$found = $sources->hits(
				static function ( array $hit ): bool {
					return in_array( Code_Sinks::REMOTE, $hit['traits'], true );
				}
			);
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( 'The site root could not be walked: ' . $e->getMessage() );
		}

		if ( array() === $found ) {
			if ( 0 === $sources->examined() ) {
				return Result::skipped( 'No PHP files were found, so there was nothing to examine' );
			}
			$gaps = $sources->gaps();

			return array() === $gaps ? Result::pass() : Result::skipped( 'No code that runs downloaded content was found, but ' . implode( '; ', $gaps ) );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::critical(),
				'PHP files likely download code from another server and run it',
				$sources->evidence( $found ),
				'Open each file at the line given. Code that runs what it downloads lets whoever controls that server run anything '
				. 'on this site, and is a common backdoor. If you cannot account for it, treat the site as compromised: replace the '
				. 'file with a clean copy from where it came from, change the passwords and the keys and salts, and look for how it '
				. 'was added.'
			)
		);
	}
}
