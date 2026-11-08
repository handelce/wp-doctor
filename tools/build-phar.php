#!/usr/bin/env php
<?php
/**
 * Builds wp-doctor.phar from a checkout of this repository.
 *
 * Usage: php -d phar.readonly=0 tools/build-phar.php [--source=DIR] [--output=FILE] [--expect-version=X.Y.Z]
 *
 * --source is a checkout in which `composer install` or `composer dump-autoload`
 * has run (default: this repository). --output defaults to build/wp-doctor.phar.
 * --expect-version refuses the build unless Application::VERSION matches.
 *
 * The build is reproducible: every file in the phar gets the time in
 * SOURCE_DATE_EPOCH, or else the time of the source's last commit, and the
 * same permissions, so the same source gives the same bytes. Setting the times
 * needs seld/phar-utils from this repository's development install.
 *
 * This is a maintainer tool for the repository. It never runs during a scan
 * and never touches a scanned site. The phar gets its own class map
 * autoloader for src/, so no Composer files or development packages go in.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

/**
 * Writes a message to standard error and exits with status 1.
 *
 * @since 0.1.0
 *
 * @param string $message What went wrong.
 * @return void
 */
function wpd_build_fail( string $message ): void {
	fwrite( STDERR, "build-phar: $message\n" );
	exit( 1 );
}

/**
 * A path with backslashes turned into slashes, the separator inside a phar
 * and in the class map.
 *
 * @since 0.1.0
 *
 * @param string $path Path as the platform writes it.
 * @return string
 */
function wpd_build_slashes( string $path ): string {
	return str_replace( '\\', '/', $path );
}

/**
 * Reads the --name=value options this tool accepts.
 *
 * @since 0.1.0
 *
 * @param array<int, string> $args Command-line arguments after the script name.
 * @return array<string, string> Option values by name.
 */
function wpd_build_options( array $args ): array {
	$options = array(
		'source'         => dirname( __DIR__ ),
		'output'         => dirname( __DIR__ ) . '/build/wp-doctor.phar',
		'expect-version' => '',
	);
	foreach ( $args as $arg ) {
		if ( 1 !== preg_match( '/^--(source|output|expect-version)=(.+)$/', $arg, $match ) ) {
			wpd_build_fail( "unknown argument $arg" );
		}
		$options[ $match[1] ] = $match[2];
	}

	return $options;
}

/**
 * Reads Application::VERSION from the source without loading it.
 *
 * @since 0.1.0
 *
 * @param string $source Checkout root.
 * @return string The version.
 */
function wpd_build_version( string $source ): string {
	$code = (string) file_get_contents( "$source/src/class-application.php" );
	if ( 1 !== preg_match( "/public const VERSION = '([^']+)';/", $code, $match ) ) {
		wpd_build_fail( "no VERSION constant in $source/src/class-application.php" );
	}

	return $match[1];
}

/**
 * The time every file in the phar is given: SOURCE_DATE_EPOCH when it is set,
 * else the commit time of the source's HEAD.
 *
 * @since 0.3.0
 *
 * @param string $source Checkout root.
 * @return int Unix time.
 */
function wpd_build_epoch( string $source ): int {
	$epoch = getenv( 'SOURCE_DATE_EPOCH' );
	if ( false !== $epoch && '' !== $epoch ) {
		if ( 1 !== preg_match( '/^\d{1,10}$/', $epoch ) ) {
			wpd_build_fail( "SOURCE_DATE_EPOCH must be a Unix time in seconds, not $epoch" );
		}

		return (int) $epoch;
	}
	$commit = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $source ) . ' log -1 --format=%ct 2>' . ( '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- A maintainer tool asking git for the commit time; it never runs during a scan.
	if ( 1 !== preg_match( '/^\d+$/', $commit ) ) {
		wpd_build_fail( "$source has no git history to date the build; set SOURCE_DATE_EPOCH" );
	}

	return (int) $commit;
}

/**
 * Lists every file under a directory, relative to the checkout root.
 *
 * @since 0.1.0
 *
 * @param string $source    Checkout root.
 * @param string $directory Directory under the root.
 * @return array<int, string> Relative paths, sorted.
 */
function wpd_build_files( string $source, string $directory ): array {
	$files    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$source/$directory", FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$files[] = substr( wpd_build_slashes( $file->getPathname() ), strlen( "$source/" ) );
	}
	sort( $files );

	return $files;
}

/**
 * Maps each class in src/ to its file, from the class map Composer generated.
 * Every PHP file in src/ apart from functions.php must declare a mapped class.
 *
 * @since 0.1.0
 *
 * @param string $source Checkout root.
 * @return array<string, string> Relative file path by class name.
 */
function wpd_build_class_map( string $source ): array {
	$classmap = "$source/vendor/composer/autoload_classmap.php";
	if ( ! is_file( $classmap ) ) {
		wpd_build_fail( "$classmap is missing; run composer install in $source first" );
	}
	$all = require $classmap;
	$map = array();
	foreach ( $all as $class => $path ) {
		$path = wpd_build_slashes( $path );
		if ( 0 === strpos( $path, "$source/src/" ) ) {
			$map[ $class ] = substr( $path, strlen( "$source/" ) );
		}
	}

	$unmapped = array_diff( wpd_build_files( $source, 'src' ), array_values( $map ), array( 'src/functions.php' ) );
	if ( array() !== $unmapped ) {
		wpd_build_fail( 'the Composer class map does not list ' . implode( ', ', $unmapped ) . "; run composer dump-autoload in $source" );
	}
	ksort( $map );

	return $map;
}

/**
 * The autoloader placed at vendor/autoload.php inside the phar, where
 * bin/wp-doctor looks for it.
 *
 * @since 0.1.0
 *
 * @param array<string, string> $map Relative file path by class name.
 * @return string PHP source.
 */
function wpd_build_autoloader( array $map ): string {
	return "<?php\n"
		. "spl_autoload_register(\n"
		. "\tstatic function ( \$class ) {\n"
		. "\t\tstatic \$map = " . var_export( $map, true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes the class map as PHP source.
		. "\t\tif ( isset( \$map[ \$class ] ) ) {\n"
		. "\t\t\trequire __DIR__ . '/../' . \$map[ \$class ];\n"
		. "\t\t}\n"
		. "\t}\n"
		. ");\n"
		. "require __DIR__ . '/../src/functions.php';\n";
}

if ( ! class_exists( 'Phar' ) ) {
	wpd_build_fail( 'the phar extension is not loaded' );
}
if ( filter_var( ini_get( 'phar.readonly' ), FILTER_VALIDATE_BOOLEAN ) ) {
	wpd_build_fail( 'phar.readonly is on; run php -d phar.readonly=0 tools/build-phar.php' );
}

$options = wpd_build_options( array_slice( $argv, 1 ) );
$source  = realpath( $options['source'] );
$source  = false === $source ? false : wpd_build_slashes( $source );
if ( false === $source || ! is_file( "$source/bin/wp-doctor" ) ) {
	wpd_build_fail( "{$options['source']} is not a wp-doctor checkout" );
}
$version = wpd_build_version( $source );
if ( '' !== $options['expect-version'] && $options['expect-version'] !== $version ) {
	wpd_build_fail( "expected version {$options['expect-version']}, but src/class-application.php says $version" );
}
$class_map = wpd_build_class_map( $source );
$epoch     = wpd_build_epoch( $source );
$tooling   = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! is_file( $tooling ) ) {
	wpd_build_fail( 'seld/phar-utils is needed to set the file times; run composer install in ' . dirname( __DIR__ ) );
}
require $tooling;
if ( ! class_exists( 'Seld\\PharUtils\\Timestamps' ) ) {
	wpd_build_fail( 'seld/phar-utils is not installed; run composer install in ' . dirname( __DIR__ ) );
}

$output = $options['output'];
if ( ! is_dir( dirname( $output ) ) && ! mkdir( dirname( $output ), 0755, true ) ) {
	wpd_build_fail( 'could not create ' . dirname( $output ) );
}
$partial = dirname( $output ) . '/.' . basename( $output, '.phar' ) . '-partial.phar';
if ( is_file( $partial ) ) {
	unlink( $partial );
}

try {
	$phar = new Phar( $partial );
	$phar->startBuffering();
	$contents = array();
	foreach ( array_merge( wpd_build_files( $source, 'src' ), wpd_build_files( $source, 'data' ), array( 'LICENSE' ) ) as $file ) {
		$contents[ $file ] = (string) file_get_contents( "$source/$file" );
	}
	$contents['bin/wp-doctor']       = (string) preg_replace( '/\A#![^\n]*\n/', '', (string) file_get_contents( "$source/bin/wp-doctor" ) );
	$contents['vendor/autoload.php'] = wpd_build_autoloader( $class_map );
	foreach ( $contents as $file => $content ) {
		$phar->addFromString( $file, $content );
		$phar[ $file ]->chmod( 0644 );
	}
	$phar->setStub( "#!/usr/bin/env php\n<?php\nPhar::mapPhar( 'wp-doctor.phar' );\nrequire 'phar://wp-doctor.phar/bin/wp-doctor';\n__HALT_COMPILER();\n" );
	$phar->setSignatureAlgorithm( Phar::SHA256 );
	$phar->stopBuffering();
	unset( $phar );
	$times = new Seld\PharUtils\Timestamps( $partial );
	$times->updateTimestamps( $epoch );
	if ( ! $times->save( $partial, Phar::SHA256 ) ) {
		throw new RuntimeException( "could not save $partial with the file times set" );
	}
} catch ( Exception $e ) {
	if ( is_file( $partial ) ) {
		unlink( $partial );
	}
	wpd_build_fail( 'could not write the phar: ' . $e->getMessage() );
}

chmod( $partial, 0755 );
if ( ! rename( $partial, $output ) ) {
	unlink( $partial );
	wpd_build_fail( "could not move the phar to $output" );
}
fwrite( STDOUT, "Built $output (wp-doctor $version, " . count( $class_map ) . ' classes, ' . filesize( $output ) . ' bytes, files dated ' . gmdate( 'Y-m-d H:i:s', $epoch ) . " UTC)\n" );
