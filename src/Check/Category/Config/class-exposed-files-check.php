<?php
/**
 * Sensitive files in the web root.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Config;

use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Looks at the top level of the web root, and of the core directory when core
 * is in a subdirectory, for version-control data, environment files and
 * phpinfo pages, which reveal secrets or server details when the web
 * server serves them. Reachability is not tested, so findings say "likely".
 * Copies of wp-config.php are reported by disk.leftovers.web.
 *
 * @since 0.1.0
 */
final class Exposed_Files_Check implements Runs_Without_Config {

	private const READ_BYTES = 65536;

	/**
	 * PHP files WordPress core puts in the site root, plus wp-config.php.
	 *
	 * @since 0.1.0
	 */
	private const CORE_ROOT_FILES = array(
		'index.php',
		'wp-activate.php',
		'wp-blog-header.php',
		'wp-comments-post.php',
		'wp-config.php',
		'wp-config-sample.php',
		'wp-cron.php',
		'wp-links-opml.php',
		'wp-load.php',
		'wp-login.php',
		'wp-mail.php',
		'wp-settings.php',
		'wp-signup.php',
		'wp-trackback.php',
		'xmlrpc.php',
	);

	/**
	 * Id shared by both exposed-file findings.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.exposed.files';
	}

	/**
	 * Grouped under config in the report.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Lists sensitive files at the top of the web root and the core directory.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the file scanner, which lists and reads the top level of the site root and the core directory.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$files   = $context->files();
		$core    = $context->site()->core_dir();
		$serious = array();
		$version = array();
		$gaps    = array();
		foreach ( '' === $core ? array( '' ) : array( '', $core ) as $top ) {
			$walk = $files->walk( $top, false );
			foreach ( $walk as $entry ) {
				$name = strtolower( $entry->name() );
				if ( ! $this->sort_by_name( $entry->path(), $name, $serious, $version )
					&& $entry->is_file() && 'php' === $entry->extension() && $this->is_info_page( $name, $files->read( $entry->path(), self::READ_BYTES ) ) ) {
					$serious[ $entry->path() ] = 'phpinfo page';
				}
			}
			$gaps = array_merge( $gaps, array_filter( array( $walk->listing_gap() ) ) );
			foreach ( $walk->skipped_links() as $path ) {
				$name = strtolower( basename( $path ) );
				if ( ! $this->sort_by_name( $path, $name, $serious, $version ) && $this->may_be_info_page( $name ) ) {
					$gaps[] = "$path is a symlink that was not followed, so it was not checked for phpinfo()";
				}
			}
		}
		$incomplete = array() !== $gaps;

		if ( array() === $serious && array() === $version ) {
			return $incomplete
				? Result::skipped( 'No sensitive files were found, but not all of the top level was examined: ' . implode( '; ', $gaps ) )
				: Result::pass();
		}

		$findings = array();
		if ( array() !== $serious ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::warning(),
				'Sensitive files are likely reachable from the web',
				$this->evidence( $serious ),
				'Delete each file from the web root, or block it in the web server configuration. A .git directory exposes the full '
				. 'source history; a .env file or phpinfo page can expose passwords and server details, so change any secrets they held.'
			);
		}
		if ( array() !== $version ) {
			$findings[] = new Finding(
				$this->id(),
				Severity::info(),
				'The WordPress readme shows the installed version',
				$this->evidence( $version ),
				'Delete readme.html from the web root. WordPress puts it back on each core update.'
			);
		}

		return Result::findings( ...$findings );
	}

	/**
	 * Numbered path and reason pairs.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $found Reason by path.
	 * @return array<string, string>
	 */
	private function evidence( array $found ): array {
		$evidence = array();
		$rank     = 0;
		ksort( $found );
		foreach ( $found as $path => $reason ) {
			++$rank;
			$evidence[ "path_$rank" ]          = (string) $path;
			$evidence[ "path_{$rank}_reason" ] = $reason;
		}

		return $evidence;
	}

	/**
	 * Records a root-level name that is sensitive whatever its content.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $path    Path relative to the root.
	 * @param string                $name    Lowercased name.
	 * @param array<string, string> $serious Serious findings by path, updated.
	 * @param array<string, string> $version Version disclosures by path, updated.
	 * @return bool Whether the name matched.
	 */
	private function sort_by_name( string $path, string $name, array &$serious, array &$version ): bool {
		if ( in_array( $name, array( '.git', '.svn', '.hg' ), true ) ) {
			$serious[ $path ] = 'version control data';
		} elseif ( '.env' === $name || 0 === strpos( $name, '.env.' ) ) {
			$serious[ $path ] = 'environment file that usually holds secrets';
		} elseif ( 'readme.html' === $name ) {
			$version[ $path ] = 'WordPress readme that shows the installed version';
		} else {
			return false;
		}

		return true;
	}

	/**
	 * Whether a root-level PHP file name could be a phpinfo page rather than a WordPress file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Lowercased name.
	 * @return bool
	 */
	private function may_be_info_page( string $name ): bool {
		return '.php' === substr( $name, -4 ) && ! in_array( $name, self::CORE_ROOT_FILES, true );
	}

	/**
	 * Whether a root-level PHP file is a phpinfo page.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $name    Lowercase file name.
	 * @param string|null $content First 64 KB of the file, or null when it could not be read.
	 * @return bool
	 */
	private function is_info_page( string $name, ?string $content ): bool {
		if ( ! $this->may_be_info_page( $name ) || null === $content ) {
			return false;
		}

		return 1 === preg_match( '/\bphpinfo\s*\(/i', $content );
	}
}
