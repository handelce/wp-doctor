<?php
/**
 * Scripts and defacement text injected into posts.
 *
 * @since 0.3.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Check\Option_Value;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * WordPress removes script tags from posts written by anyone without the
 * unfiltered_html capability, and embeds are stored as URLs, so a script in a
 * published post is unusual. Injected spam and redirect code usually arrives
 * as a script loaded from another host, or as an inline script that hides
 * what it does. A script is reported when its src names a host other than
 * the site's, or a subdomain of it, and when it is inline and uses eval(),
 * atob(), String.fromCharCode(), document.write(), unescape() or a long
 * encoded string. The site's hosts come from WP_HOME and WP_SITEURL, which
 * WordPress puts in place of the options, and from the home and siteurl
 * options. Separately, recently modified posts are searched for the phrases
 * defacers sign their work with.
 *
 * @since 0.3.0
 */
final class Injected_Content_Check implements Check {

	private const MAX_POSTS          = 1000;
	private const MAX_CONTENT_BYTES  = 1048576;
	private const MAX_OPTION_BYTES   = 4096;
	private const RECENT_DAYS        = 30;
	private const MIN_ENCODED_LENGTH = 200;
	private const HIDING_CALLS       = array( 'eval', 'atob', 'String.fromCharCode', 'document.write', 'unescape' );
	private const DEFACEMENT_MARKERS = array( 'hacked by', 'h4cked by', 'h4ck3d by', 'defaced by', 'greetz' );
	private const SKIPPED_TYPES      = "'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request'";

	/**
	 * Unix time to compare against. Null for the clock.
	 *
	 * @since 0.3.0
	 *
	 * @var int|null
	 */
	private $now;

	/**
	 * Takes the time that decides which posts are recent.
	 *
	 * @since 0.3.0
	 *
	 * @param int|null $now Unix time to treat as now, or null for the clock.
	 */
	public function __construct( ?int $now = null ) {
		$this->now = $now;
	}

	/**
	 * Id of the injected content findings.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.content.injected';
	}

	/**
	 * Grouped under security.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Reads published posts holding scripts, and recent posts holding defacement phrases.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the posts table, the site's addresses and the database.
	 * @return Result
	 * @throws \UnexpectedValueException When the database returns an unexpected row.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		$hosts = $this->site_hosts( $context );
		if ( array() === $hosts ) {
			return Result::skipped( 'The site\'s own address could not be read from WP_HOME, WP_SITEURL or the home and siteurl options, so no script host can be judged' );
		}

		$posts = $context->table( 'posts' );
		$rows  = $context->database()->query(
			'SELECT ID AS id, post_type AS type, post_title AS title, LENGTH(post_content) AS bytes, SUBSTRING(post_content, 1, ' . self::MAX_CONTENT_BYTES . ') AS content'
			. ' FROM ' . $posts . " WHERE post_status = 'publish' AND post_type NOT IN (" . self::SKIPPED_TYPES . ')'
			. " AND LOWER(post_content) LIKE '%<script%' ORDER BY ID ASC LIMIT " . ( self::MAX_POSTS + 1 )
		);
		$gaps  = array();
		if ( count( $rows ) > self::MAX_POSTS ) {
			$rows   = array_slice( $rows, 0, self::MAX_POSTS );
			$gaps[] = 'more than ' . self::MAX_POSTS . ' published posts hold a script tag, and only the first ' . self::MAX_POSTS . ' were read';
		}
		$scripts   = array();
		$truncated = 0;
		foreach ( $rows as $row ) {
			if ( ! isset( $row['id'], $row['type'], $row['bytes'], $row['content'] ) || 1 !== preg_match( '/^\d+$/', $row['bytes'] ) ) {
				throw new \UnexpectedValueException( 'The posts query returned an unexpected row' );
			}
			if ( (int) $row['bytes'] > self::MAX_CONTENT_BYTES ) {
				++$truncated;
			}
			$found = self::suspicious_scripts( $row['content'], $hosts );
			if ( array() !== $found ) {
				$scripts[] = array( $row, $found );
			}
		}
		if ( $truncated > 0 ) {
			$gaps[] = "$truncated post(s) longer than " . self::MAX_CONTENT_BYTES . ' bytes were read only in part';
		}

		$findings = array();
		if ( array() !== $scripts ) {
			$findings[] = $this->script_finding( $scripts, count( $rows ), $hosts, $gaps );
		}
		$defaced = $this->defaced_posts( $context, $posts );
		if ( array() !== $defaced ) {
			$findings[] = $this->defacement_finding( $defaced );
		}
		if ( array() === $findings ) {
			return array() === $gaps ? Result::pass() : Result::skipped( 'No injected script was found, but ' . implode( '; ', $gaps ) );
		}

		return Result::findings( ...$findings );
	}

	/**
	 * Hosts the site is served from, lowercase and without a leading www.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives wp-config.php and the options.
	 * @return string[]
	 */
	private function site_hosts( Context $context ): array {
		$urls   = array();
		$config = $context->config();
		foreach ( array( 'WP_HOME', 'WP_SITEURL' ) as $name ) {
			if ( $config->has( $name ) && is_string( $config->value( $name ) ) ) {
				$urls[] = $config->value( $name );
			}
		}
		foreach ( array( 'home', 'siteurl' ) as $name ) {
			$urls[] = (string) Option_Value::read( $context, $name, self::MAX_OPTION_BYTES )[0];
		}
		$hosts = array();
		foreach ( $urls as $url ) {
			$host = self::host_of( $url );
			if ( null !== $host ) {
				$hosts[ $host ] = true;
			}
		}

		return array_keys( $hosts );
	}

	/**
	 * Host of a URL, lowercase and without a leading www. Null for a URL without one.
	 *
	 * @since 0.3.0
	 *
	 * @param string $url Absolute or protocol-relative URL.
	 * @return string|null
	 */
	private static function host_of( string $url ): ?string {
		$host = parse_url( trim( $url ), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded, so wp_parse_url() is not available.
		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}

		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/**
	 * Descriptions of the scripts in a post that are likely injected.
	 *
	 * @since 0.3.0
	 *
	 * @param string   $content Post content.
	 * @param string[] $hosts   The site's hosts.
	 * @return string[]
	 */
	public static function suspicious_scripts( string $content, array $hosts ): array {
		if ( false === preg_match_all( '#<script\b([^>]*)>(.*?)(?:</script\s*>|\z)#is', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		$found = array();
		foreach ( $matches as $match ) {
			if ( 1 === preg_match( '#\bsrc\s*=\s*(["\']?)([^"\'\s>]+)\1#i', $match[1], $src ) ) {
				$host = self::host_of( html_entity_decode( $src[2], ENT_QUOTES ) );
				if ( null !== $host && ! self::is_site_host( $host, $hosts ) ) {
					$found[] = "script from $host";
				}
				continue;
			}
			foreach ( self::HIDING_CALLS as $call ) {
				if ( 1 === preg_match( '/\b' . preg_quote( $call, '/' ) . '\s*\(/', $match[2] ) ) {
					$found[] = "inline script using $call()";
					continue 2;
				}
			}
			if ( 1 === preg_match( '/[A-Za-z0-9+\/=]{' . self::MIN_ENCODED_LENGTH . ',}|(?:\\\\x[0-9A-Fa-f]{2}){50,}/', $match[2] ) ) {
				$found[] = 'inline script holding a long encoded string';
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Whether a host is one of the site's, or a subdomain of one.
	 *
	 * @since 0.3.0
	 *
	 * @param string   $host  Host of a script, normalised as host_of() does.
	 * @param string[] $hosts The site's hosts.
	 * @return bool
	 */
	private static function is_site_host( string $host, array $hosts ): bool {
		foreach ( $hosts as $site ) {
			if ( $host === $site || substr( $host, -strlen( '.' . $site ) ) === '.' . $site ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Published posts modified in the last RECENT_DAYS days whose title or content holds a defacement phrase.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the database.
	 * @param string  $posts   Quoted posts table name.
	 * @return array<int, array<string, string|null>>
	 * @throws \UnexpectedValueException When the database returns an unexpected row.
	 */
	private function defaced_posts( Context $context, string $posts ): array {
		$since   = gmdate( 'Y-m-d H:i:s', ( $this->now ?? time() ) - self::RECENT_DAYS * 86400 );
		$markers = array();
		foreach ( self::DEFACEMENT_MARKERS as $marker ) {
			$markers[] = "LOWER(post_title) LIKE '%$marker%' OR LOWER(post_content) LIKE '%$marker%'";
		}
		$rows = $context->database()->query(
			'SELECT ID AS id, post_type AS type, post_title AS title, post_modified_gmt AS modified FROM ' . $posts
			. " WHERE post_status = 'publish' AND post_type NOT IN (" . self::SKIPPED_TYPES . ") AND post_modified_gmt >= '$since'"
			. ' AND (' . implode( ' OR ', $markers ) . ') ORDER BY post_modified_gmt DESC, ID DESC LIMIT ' . Evidence::MAX_LISTED
		);
		foreach ( $rows as $row ) {
			if ( ! isset( $row['id'], $row['type'], $row['modified'] ) ) {
				throw new \UnexpectedValueException( 'The recent posts query returned an unexpected row' );
			}
		}

		return $rows;
	}

	/**
	 * The warning for posts holding likely injected scripts.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, array{0: array<string, string|null>, 1: string[]}> $scripts  Each post row with what was found in it.
	 * @param int                                                           $examined Posts with a script tag that were read.
	 * @param string[]                                                      $hosts    The site's hosts.
	 * @param string[]                                                      $gaps     What could not be examined.
	 * @return Finding
	 */
	private function script_finding( array $scripts, int $examined, array $hosts, array $gaps ): Finding {
		$evidence = array(
			'posts_with_scripts' => $examined,
			'posts_flagged'      => count( $scripts ),
			'site_hosts'         => implode( ', ', $hosts ),
			'scan_incomplete'    => array() !== $gaps,
		);
		if ( array() !== $gaps ) {
			$evidence['not_examined'] = implode( '; ', $gaps );
		}
		$evidence['posts'] = array();
		foreach ( array_slice( $scripts, 0, Evidence::MAX_LISTED ) as $post ) {
			$evidence['posts'][] = array(
				'id'      => (int) $post[0]['id'],
				'type'    => (string) $post[0]['type'],
				'title'   => (string) $post[0]['title'],
				'scripts' => implode( '; ', $post[1] ),
			);
		}

		return new Finding(
			$this->id(),
			Severity::warning(),
			'Published posts likely hold injected scripts',
			$evidence,
			'Open each post in the block editor\'s code view and remove scripts nobody on your side added. Then look for the same '
			. 'host elsewhere with `wp db search <host> --all-tables`. Injected posts usually mean an account or a plugin was '
			. 'compromised: change the passwords, review the administrators, and update or remove vulnerable plugins.'
		);
	}

	/**
	 * The notice for recently modified posts holding defacement phrases.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, array<string, string|null>> $defaced Post rows.
	 * @return Finding
	 */
	private function defacement_finding( array $defaced ): Finding {
		$evidence          = array(
			'recent_days'   => self::RECENT_DAYS,
			'posts_flagged' => count( $defaced ),
			'phrases'       => implode( ', ', self::DEFACEMENT_MARKERS ),
		);
		$evidence['posts'] = array();
		foreach ( $defaced as $row ) {
			$evidence['posts'][] = array(
				'id'       => (int) $row['id'],
				'type'     => (string) $row['type'],
				'title'    => (string) $row['title'],
				'modified' => $row['modified'] . ' UTC',
			);
		}

		return new Finding(
			$this->id(),
			Severity::info(),
			'Recently changed posts likely hold defacement text',
			$evidence,
			'Read each post listed. If it was changed by someone you do not know, restore it from a revision or a backup, '
			. 'and treat the site as compromised: change the passwords and review the administrators.'
		);
	}
}
