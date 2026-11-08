<?php
define( 'DB_NAME', 'example_db' );
define( 'DB_USER', 'example_user' );
define( 'DB_PASSWORD', 'fake-db-password-0001' );
define( 'DB_HOST', 'db.example.test' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', true );
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'WP_CONTENT_URL', 'https://example.test/wp-content' );

$table_prefix = 'wptest_';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wp/' );
}

require_once ABSPATH . 'wp-settings.php';
