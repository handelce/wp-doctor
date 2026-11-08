#!/usr/bin/env php
<?php
/**
 * Records a scan of the demo site as docs/demo.cast and renders docs/demo.svg.
 *
 * Usage: php tools/record-demo.php
 *
 * Runs a real scan of tests/fixtures/sites/demo with --offline and --color,
 * and writes the output as an asciicast, with the command typed out before it.
 * The SVG is rendered from the cast with svg-term-cli through npx, which
 * needs Node.js; nothing is uploaded. Run it again whenever the output of the
 * demo scan changes, such as at a release.
 *
 * This is a maintainer tool for the repository. It never runs during a scan.
 *
 * @since 0.3.0
 * @package WP_Doctor
 */

declare(strict_types=1);

const WPD_DEMO_COMMAND = 'wp-doctor scan demo --offline';
const WPD_DEMO_WIDTH   = 110;
const WPD_DEMO_HEIGHT  = 36;
const WPD_DEMO_RENDER  = 'svg-term-cli@2.1.1';

/**
 * Writes a message to standard error and exits with status 1.
 *
 * @since 0.3.0
 *
 * @param string $message What went wrong.
 * @return void
 */
function wpd_demo_fail( string $message ): void {
	fwrite( STDERR, "record-demo: $message\n" );
	exit( 1 );
}

/**
 * Output of the demo scan, as the terminal would show it.
 *
 * @since 0.3.0
 *
 * @param string $root Repository root.
 * @return string
 */
function wpd_demo_output( string $root ): string {
	$command = escapeshellarg( PHP_BINARY ) . ' -d xdebug.mode=off ' . escapeshellarg( "$root/bin/wp-doctor" ) . ' scan demo --offline --color';
	$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs the scan whose output is recorded.
		$command,
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		"$root/tests/fixtures/sites"
	);
	if ( ! is_resource( $process ) ) {
		wpd_demo_fail( 'could not start the scan' );
	}
	$output = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( $exit < 0 || $exit > 2 || '' === $output ) {
		wpd_demo_fail( "the scan ended with exit code $exit and no report" );
	}

	return $output;
}

/**
 * An asciicast v2 recording: the prompt, the command typed out, then the output line by line.
 *
 * @since 0.3.0
 *
 * @param string $output Scan output.
 * @return string
 */
function wpd_demo_cast( string $output ): string {
	$events   = array();
	$time     = 0.5;
	$events[] = array( $time, 'o', "\e[32m\$\e[0m " );
	foreach ( str_split( WPD_DEMO_COMMAND ) as $character ) {
		$time    += 0.06;
		$events[] = array( round( $time, 3 ), 'o', $character );
	}
	$time    += 0.6;
	$events[] = array( round( $time, 3 ), 'o', "\r\n" );
	foreach ( explode( "\n", rtrim( $output, "\n" ) ) as $line ) {
		$time    += 0.04;
		$events[] = array( round( $time, 3 ), 'o', $line . "\r\n" );
	}
	$time    += 4.0;
	$events[] = array( round( $time, 3 ), 'o', '' );

	$header = array(
		'version' => 2,
		'width'   => WPD_DEMO_WIDTH,
		'height'  => WPD_DEMO_HEIGHT,
		'title'   => 'wp-doctor scan of a demo site',
	);
	$lines  = array( json_encode( $header, JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded.
	foreach ( $events as $event ) {
		$lines[] = json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded.
	}

	return implode( "\n", $lines ) . "\n";
}

$root = dirname( __DIR__ );
$cast = "$root/docs/demo.cast";
$svg  = "$root/docs/demo.svg";
if ( false === file_put_contents( $cast, wpd_demo_cast( wpd_demo_output( $root ) ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes into the repository, never into a scanned site.
	wpd_demo_fail( "could not write $cast" );
}
$render = 'npx --yes ' . WPD_DEMO_RENDER . ' --in ' . escapeshellarg( $cast ) . ' --out ' . escapeshellarg( $svg ) . ' --window --no-cursor --padding 12';
passthru( $render, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru -- Renders the recording with Node.js.
if ( 0 !== $status || ! is_file( $svg ) ) {
	wpd_demo_fail( "rendering failed; is Node.js installed? The cast is in $cast" );
}
fwrite( STDOUT, "Wrote $cast and $svg\n" );
