#!/usr/bin/env php
<?php
/**
 * Refreshes data/disposable-email-domains.txt from its upstream list.
 *
 * Usage: php tools/update-disposable-domains.php
 *
 * This is a maintainer tool for the repository. It never runs during a scan
 * and never touches a scanned site.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

const WPD_DISPOSABLE_REPOSITORY = 'disposable-email-domains/disposable-email-domains';
const WPD_DISPOSABLE_LIST       = 'disposable_email_blocklist.conf';
const WPD_DISPOSABLE_LICENCE    = 'CC0 1.0 Universal (public domain dedication)';

/**
 * Fetches a URL over HTTPS and exits with status 1 on an error or an empty body.
 *
 * @since 0.1.0
 *
 * @param string $url URL to fetch.
 * @return string The response body, never empty.
 */
function wpd_fetch( string $url ): string {
	$context = stream_context_create(
		array(
			'http' => array(
				'method'        => 'GET',
				'header'        => "User-Agent: wp-doctor-tools\r\nAccept: */*\r\n",
				'timeout'       => 60,
				'ignore_errors' => false,
			),
		)
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Maintainer tool, not a scan.
	$body = file_get_contents( $url, false, $context );
	if ( false === $body || '' === $body ) {
		fwrite( STDERR, "Could not fetch $url\n" );
		exit( 1 );
	}

	return $body;
}

$commit_json = wpd_fetch( 'https://api.github.com/repos/' . WPD_DISPOSABLE_REPOSITORY . '/commits?path=' . WPD_DISPOSABLE_LIST . '&per_page=1' );
$commits     = json_decode( $commit_json, true );
if ( ! is_array( $commits ) || ! isset( $commits[0]['sha'], $commits[0]['commit']['committer']['date'] ) ) {
	fwrite( STDERR, "The commit lookup returned an unexpected shape\n" );
	exit( 1 );
}
$sha  = (string) $commits[0]['sha'];
$date = (string) $commits[0]['commit']['committer']['date'];
if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $sha ) ) {
	fwrite( STDERR, "The commit SHA is not a SHA\n" );
	exit( 1 );
}

$list    = wpd_fetch( 'https://raw.githubusercontent.com/' . WPD_DISPOSABLE_REPOSITORY . '/' . $sha . '/' . WPD_DISPOSABLE_LIST );
$domains = array();
foreach ( preg_split( '/\R/', $list ) as $line ) {
	$line = strtolower( trim( $line ) );
	if ( '' === $line ) {
		continue;
	}
	if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $line ) ) {
		fwrite( STDERR, "Refusing a line that is not a domain name: $line\n" );
		exit( 1 );
	}
	$domains[ $line ] = true;
}
if ( count( $domains ) < 1000 ) {
	fwrite( STDERR, 'The list has only ' . count( $domains ) . " domains; refusing to write it\n" );
	exit( 1 );
}
ksort( $domains, SORT_STRING );

$header = array(
	'# Disposable email domains used by the sec.users.admins check.',
	'# source: https://github.com/' . WPD_DISPOSABLE_REPOSITORY . '/blob/' . $sha . '/' . WPD_DISPOSABLE_LIST,
	'# licence: ' . WPD_DISPOSABLE_LICENCE,
	'# upstream_commit: ' . $sha,
	'# upstream_commit_date: ' . $date,
	'# domains: ' . count( $domains ),
	'# Regenerate with: php tools/update-disposable-domains.php',
);

$target = dirname( __DIR__ ) . '/data/disposable-email-domains.txt';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Maintainer tool writing into this repository.
$written = file_put_contents( $target, implode( "\n", $header ) . "\n" . implode( "\n", array_keys( $domains ) ) . "\n" );
if ( false === $written ) {
	fwrite( STDERR, "Could not write $target\n" );
	exit( 1 );
}
fwrite( STDOUT, 'Wrote ' . count( $domains ) . " domains to $target (upstream $sha)\n" );
