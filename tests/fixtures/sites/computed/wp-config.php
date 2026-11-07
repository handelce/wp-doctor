<?php
$GLOBALS['wpd_canary_executed'] = true;

define( 'DB_NAME', getenv( 'EXAMPLE_DB_NAME' ) );
define( 'DB_USER', 'example_user' );
define( 'DB_PASSWORD', $_SERVER['EXAMPLE_PASSWORD'] );
define( 'DB_HOST', 'db.' . 'example.test' );
define( 'WP_CONTENT_DIR', dirname( __FILE__ ) . '/content' );

if ( 'production' === getenv( 'EXAMPLE_ENV' ) ) {
	define( 'WP_DEBUG', false );
}

define( $computed_name, 'x' );

require_once __DIR__ . '/wp-config-local.php';

$table_prefix = getenv( 'EXAMPLE_PREFIX' );

require_once ABSPATH . 'wp-settings.php';
