<?php
/**
 * PHP past its end of life.
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
 * Compares a PHP version with a table of end-of-security-support dates. The
 * version comes from a handler line in the site's .htaccess when there is one,
 * which likely selects the web server's PHP, and otherwise from the PHP that
 * runs wp-doctor. A command-line scan cannot see the web server's PHP itself,
 * so without a hint a version still in support is a skip, not a pass.
 *
 * @since 0.1.0
 */
final class Php_Version_Check implements Runs_Without_Config {

	private const HTACCESS_READ_BYTES = 65536;
	private const HEADER_READ_BYTES   = 8192;

	/**
	 * End of security support by minor version, as a UTC date.
	 *
	 * @since 0.1.0
	 */
	private const END_OF_LIFE = array(
		'5.6' => '2018-12-31',
		'7.0' => '2019-01-10',
		'7.1' => '2019-12-01',
		'7.2' => '2020-11-30',
		'7.3' => '2021-12-06',
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	);

	/**
	 * Version of the PHP running wp-doctor.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $php_version;

	/**
	 * Unix time to compare against, or null for the current time.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private $now;

	/**
	 * Takes the PHP version and the time to judge against.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $php_version Version string of the PHP running wp-doctor, such as 8.2.10.
	 * @param int|null $now         Unix time to treat as now, or null for the clock.
	 */
	public function __construct( string $php_version = PHP_VERSION, ?int $now = null ) {
		$this->php_version = $php_version;
		$this->now         = $now;
	}

	/**
	 * Id for both the end-of-life and the plugin requirement findings.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.php.version';
	}

	/**
	 * Grouped with the configuration checks.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Judges the hinted or running PHP version.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives .htaccess in the site root and the plugins directory.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$hint = $this->htaccess_hint( $context );
		if ( is_string( $hint ) ) {
			return Result::skipped( $hint );
		}

		if ( null !== $hint ) {
			$findings = array();
			$verdict  = $this->judge( $hint['version'], '.htaccess handler line, which likely selects the web server\'s PHP', array( 'htaccess_line' => $hint['line'] ) );
			if ( is_string( $verdict ) ) {
				return Result::skipped( $verdict );
			}
			if ( null !== $verdict ) {
				$findings[] = $verdict;
			}
			list( $mismatch, $gap ) = $this->plugin_requirement_mismatch( $context, $hint['version'], $hint['line'] );
			if ( null !== $mismatch ) {
				$findings[] = $mismatch;
			}
			if ( array() === $findings && null !== $gap ) {
				return Result::skipped( 'The PHP version from .htaccess is in security support, but plugin PHP requirements were not all read: ' . $gap );
			}

			return array() === $findings ? Result::pass() : Result::findings( ...$findings );
		}

		if ( 1 !== preg_match( '/^(\d+)\.(\d+)/', $this->php_version, $match ) ) {
			return Result::skipped( 'The PHP version string could not be read' );
		}
		$minor   = $match[1] . '.' . $match[2];
		$verdict = $this->judge( $minor, 'the PHP that runs wp-doctor, which may differ from the web server\'s PHP', array( 'php_version' => $this->php_version ) );
		if ( is_string( $verdict ) ) {
			return Result::skipped( $verdict );
		}
		if ( null === $verdict ) {
			return Result::skipped( "PHP $minor, which runs wp-doctor, is in security support; the web server's PHP was not examined" );
		}

		return Result::findings( $verdict );
	}

	/**
	 * Compares a major.minor version with its end-of-life date.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $minor       Version such as 7.4.
	 * @param string                $measured_on Which PHP the version belongs to, shown in the evidence.
	 * @param array<string, string> $extra       Evidence merged into the finding, such as the .htaccess line.
	 * @return Finding|string|null A warning, a skip reason, or null when the version is in support.
	 */
	private function judge( string $minor, string $measured_on, array $extra ) {
		$now = $this->now ?? time();

		if ( isset( self::END_OF_LIFE[ $minor ] ) ) {
			$end = self::END_OF_LIFE[ $minor ];
		} elseif ( version_compare( $minor, '5.6', '<' ) ) {
			$end = 'before 2019';
		} else {
			return "PHP $minor is newer than the end-of-life table in this version of wp-doctor";
		}

		if ( 'before 2019' !== $end && strtotime( $end . ' 23:59:59 UTC' ) >= $now ) {
			return null;
		}

		return new Finding(
			$this->id(),
			Severity::warning(),
			"PHP $minor likely no longer receives security fixes",
			array_merge(
				array(
					'php_minor'              => $minor,
					'security_support_ended' => $end,
					'measured_on'            => $measured_on,
					'table_source'           => 'end-of-life dates built into wp-doctor; check php.net/supported-versions.php',
				),
				$extra
			),
			'Ask the host to move the site to a PHP version that still receives security fixes (see php.net/supported-versions.php), '
			. 'and test the site on a copy first. If the web server already runs a newer PHP, this finding does not apply.'
		);
	}

	/**
	 * The PHP version an .htaccess handler line selects.
	 *
	 * Recognises handler names such as application/x-httpd-ea-php74,
	 * application/x-httpd-php8.1 and application/x-lsphp81. A single digit
	 * such as php8 names no minor version and is not used.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives .htaccess in the site root.
	 * @return array{version: string, line: string}|string|null The hint, a skip reason for conflicting hints, or null.
	 */
	private function htaccess_hint( Context $context ) {
		$content = $context->files()->read( '.htaccess', self::HTACCESS_READ_BYTES );
		if ( null === $content ) {
			return null;
		}

		$found = array();
		foreach ( preg_split( '/\R/', $content ) as $line ) {
			if ( 1 !== preg_match( '/^\s*(?:AddHandler|SetHandler|AddType)\s+(\S+)/i', $line, $handler ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/(?:php|lsphp)-?(\d)\.?(\d{1,2})(?!\d)/i', $handler[1], $version ) ) {
				continue;
			}
			$found[ $version[1] . '.' . $version[2] ] = trim( $line );
		}
		if ( array() === $found ) {
			return null;
		}
		if ( count( $found ) > 1 ) {
			return '.htaccess names more than one PHP version in handler lines (' . implode( ', ', array_keys( $found ) ) . '), so the web server\'s PHP could not be told';
		}

		return array(
			'version' => (string) key( $found ),
			'line'    => (string) current( $found ),
		);
	}

	/**
	 * Plugins whose Requires PHP header is above the hinted version.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the plugins directory and the files in it.
	 * @param string  $hinted  Hinted major.minor version.
	 * @param string  $line    The .htaccess line the hint came from.
	 * @return array{0: Finding|null, 1: string|null} The finding, and why some plugins were not read.
	 */
	private function plugin_requirement_mismatch( Context $context, string $hinted, string $line ): array {
		$unavailable = $context->plugins_dir_unavailable();
		if ( null !== $unavailable ) {
			return array( null, $unavailable );
		}
		$files   = $context->files();
		$plugins = $context->plugins_dir();
		$above   = array();
		$dir     = $files->stat( $plugins );
		if ( null === $dir || ! $dir->is_dir() ) {
			return array( null, null );
		}

		$gap  = null;
		$walk = $files->walk( $plugins, false );
		foreach ( $walk as $entry ) {
			$required = null;
			if ( $entry->is_file() && 'php' === $entry->extension() ) {
				$required = $this->requires_php( $files->read( $entry->path(), self::HEADER_READ_BYTES ) );
			} elseif ( $entry->is_dir() ) {
				$found  = false;
				$folder = $files->walk( $entry->path(), false );
				foreach ( $folder as $file ) {
					if ( ! $file->is_file() || 'php' !== $file->extension() ) {
						continue;
					}
					$content = $files->read( $file->path(), self::HEADER_READ_BYTES );
					if ( null !== $content && 1 === preg_match( '/^[ \t\/*#@]*Plugin Name:/mi', $content ) ) {
						$required = $this->requires_php( $content );
						$found    = true;
						break;
					}
				}
				if ( ! $found && null === $gap ) {
					$gap = $folder->entries_seen() > 0 ? $folder->incomplete_reason() : $folder->listing_gap();
				}
			}
			if ( null !== $required && version_compare( $required, $hinted . '.9999', '>' ) ) {
				$above[ $entry->name() ] = $required;
			}
		}
		if ( null === $gap ) {
			$gap = $walk->incomplete_reason();
		}
		if ( array() === $above ) {
			return array( null, $gap );
		}

		ksort( $above );
		$evidence = array(
			'htaccess_php'                => $hinted,
			'htaccess_line'               => $line,
			'plugins_requiring_newer_php' => count( $above ),
		);
		if ( null !== $gap ) {
			$evidence['plugins_not_all_read'] = $gap;
		}
		$evidence['plugins'] = array();
		foreach ( array_slice( $above, 0, 20, true ) as $plugin => $required ) {
			$evidence['plugins'][] = array(
				'plugin'       => (string) $plugin,
				'requires_php' => $required,
			);
		}

		$finding = new Finding(
			$this->id(),
			Severity::info(),
			'Plugins likely require a newer PHP than the .htaccess handler selects',
			$evidence,
			'Either the handler line is stale or these plugins cannot run. Check the PHP version in the hosting panel, and raise it '
			. 'or remove the plugins that need more.'
		);

		return array( $finding, $gap );
	}

	/**
	 * The Requires PHP header of a plugin file.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $content First 8 KB of the file, or null when it could not be read.
	 * @return string|null Version, or null when there is no header.
	 */
	private function requires_php( ?string $content ): ?string {
		if ( null === $content || 1 !== preg_match( '/^[ \t\/*#@]*Requires PHP:\s*(\d+(?:\.\d+){0,2})/mi', $content, $match ) ) {
			return null;
		}

		return $match[1];
	}
}
