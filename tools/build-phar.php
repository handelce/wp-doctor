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
	foreach ( array_merge( wpd_build_files( $source, 'src' ), wpd_build_files( $source, 'data' ), array( 'LICENSE' ) ) as $file ) {
		$phar->addFile( "$source/$file", $file );
	}
	$entry = (string) file_get_contents( "$source/bin/wp-doctor" );
	$phar->addFromString( 'bin/wp-doctor', (string) preg_replace( '/\A#![^\n]*\n/', '', $entry ) );
	$phar->addFromString( 'vendor/autoload.php', wpd_build_autoloader( $class_map ) );
	$phar->setStub( "#!/usr/bin/env php\n<?php\nPhar::mapPhar( 'wp-doctor.phar' );\nrequire 'phar://wp-doctor.phar/bin/wp-doctor';\n__HALT_COMPILER();\n" );
	$phar->setSignatureAlgorithm( Phar::SHA256 );
	$phar->stopBuffering();
	unset( $phar );
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
fwrite( STDOUT, "Built $output (wp-doctor $version, " . count( $class_map ) . ' classes, ' . filesize( $output ) . " bytes)\n" );
