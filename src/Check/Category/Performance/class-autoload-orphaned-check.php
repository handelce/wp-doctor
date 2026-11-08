<?php
/**
 * Large autoloaded options that no installed plugin or theme seems to own.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Guesses an option's owner from the text before its first underscore or
 * hyphen and compares it with the names of installed plugins and themes. Many
 * plugins use a prefix that differs from their folder name, so this is a
 * heuristic and the finding says "likely".
 *
 * @since 0.1.0
 */
final class Autoload_Orphaned_Check implements Check {

	private const EXAMINED   = 50;
	private const MIN_PREFIX = 3;

	/**
	 * Option name prefixes that WordPress core uses.
	 *
	 * @since 0.1.0
	 */
	private const CORE_PREFIXES = array(
		'cron',
		'rewrite',
		'sidebars',
		'widget',
		'theme',
		'wp',
		'active',
		'recently',
		'uninstall',
		'nav',
		'fresh',
		'db',
		'site',
		'blog',
		'home',
		'template',
		'stylesheet',
		'current',
		'admin',
		'category',
		'default',
		'comment',
		'comments',
		'disallowed',
		'moderation',
	);

	/**
	 * Id that keys this check's size threshold.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.autoload.orphaned';
	}

	/**
	 * Performance, with the other autoload checks.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Lists large autoloaded options without a matching plugin or theme.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the options table, the plugins directory and the size threshold.
	 * @return Result
	 * @throws \UnexpectedValueException When a returned row is malformed.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null === $unavailable ) {
			$unavailable = $context->plugins_dir_unavailable();
		}
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$slugs = $this->installed_slugs( $context );
		if ( is_string( $slugs ) ) {
			return Result::skipped( $slugs );
		}

		$larger_than = $context->thresholds()->warning( $this->id() );
		$rows        = $context->database()->query(
			'SELECT option_name, LENGTH(option_value) AS size_bytes FROM ' . $context->table( 'options' )
			. ' WHERE ' . Autoload_Options::CONDITION . ' AND LENGTH(option_value) > ' . $larger_than
			. ' ORDER BY size_bytes DESC, option_name ASC LIMIT ' . self::EXAMINED
		);

		$orphans = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['option_name'], $row['size_bytes'] ) || 1 !== preg_match( '/^\d+$/', $row['size_bytes'] ) ) {
				throw new \UnexpectedValueException( 'The large autoloaded options query returned an unexpected row' );
			}
			if ( ! $this->has_owner( $row['option_name'], $slugs, $context->config()->table_prefix() ) ) {
				$orphans[] = array(
					'name'  => $row['option_name'],
					'bytes' => (int) $row['size_bytes'],
				);
			}
		}
		if ( array() === $orphans ) {
			return Result::pass();
		}

		$evidence            = array(
			'large_options_examined' => count( $rows ) < self::EXAMINED ? count( $rows ) : 'the largest ' . self::EXAMINED . '; any smaller ones above the threshold were not examined',
			'larger_than_bytes'      => $larger_than,
			'threshold_basis'        => $context->thresholds()->basis( $this->id() ),
		);
		$evidence['options'] = array();
		foreach ( array_slice( $orphans, 0, Evidence::MAX_LISTED ) as $orphan ) {
			$evidence['options'][] = array(
				'name'  => $orphan['name'],
				'bytes' => $orphan['bytes'],
			);
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Large autoloaded options likely belong to plugins or themes that are no longer installed',
				$evidence,
				'For each option, search the web for its name to find the plugin that wrote it. If that plugin is gone, delete the row '
				. '(`wp option delete <name>`) after a database backup. If the plugin is still in use under a different folder name, '
				. 'ignore the option.'
			)
		);
	}

	/**
	 * Normalised names of installed plugins, must-use plugins and themes.
	 *
	 * Symlinks that lead outside the root are not followed, but their names count.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the wp-content path and the file scanner.
	 * @return string[]|string The names, or why they could not all be listed.
	 */
	private function installed_slugs( Context $context ) {
		$content = $context->content_dir();
		$slugs   = array();
		foreach ( array( 'plugins', 'mu-plugins', 'themes' ) as $directory ) {
			try {
				$walk  = $context->files()->walk( "$content/$directory", false );
				$names = array();
				foreach ( $walk as $entry ) {
					$names[] = $entry->is_file() ? preg_replace( '/\.php$/i', '', $entry->name() ) : $entry->name();
				}
			} catch ( Path_Unavailable_Exception $e ) {
				if ( 'plugins' !== $directory && File_Scanner::ABSENT === $context->files()->presence( "$content/$directory" ) ) {
					continue;
				}

				return 'Installed plugins and themes could not all be listed: ' . $e->getMessage();
			}
			$gap = $walk->listing_gap();
			if ( null !== $gap ) {
				return "Installed plugins and themes could not all be listed: $gap";
			}
			foreach ( $walk->skipped_links() as $path ) {
				$names[] = basename( $path );
			}
			foreach ( $names as $name ) {
				if ( null !== $name && 'index' !== $name && strlen( $name ) >= self::MIN_PREFIX ) {
					$slugs[] = strtolower( $name );
				}
			}
		}

		return $slugs;
	}

	/**
	 * Whether an option name looks like it belongs to core or an installed extension.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name         Option name.
	 * @param string[] $slugs        Installed extension names, normalised.
	 * @param string   $table_prefix The site's table prefix, which core puts before user_roles.
	 * @return bool True when it cannot be called an orphan.
	 */
	private function has_owner( string $name, array $slugs, string $table_prefix ): bool {
		$name = strtolower( ltrim( $name ) );
		if ( '' === $name || strtolower( $table_prefix ) . 'user_roles' === $name ) {
			return true;
		}
		$prefix = preg_split( '/[_\-]/', $name, 2 )[0];
		if ( in_array( $prefix, self::CORE_PREFIXES, true ) || strlen( $prefix ) < self::MIN_PREFIX ) {
			return true;
		}
		foreach ( $slugs as $slug ) {
			if ( 0 === strpos( $slug, $prefix ) || 0 === strpos( $prefix, $slug ) ) {
				return true;
			}
		}

		return false;
	}
}
