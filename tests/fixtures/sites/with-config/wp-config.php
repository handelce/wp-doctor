<?php
define( 'DB_NAME', 'example_db' );
define( 'DB_USER', 'example_user' );
define( 'DB_PASSWORD', 'fake-db-password-0001' );
define( 'DB_HOST', 'db.example.test' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'AUTH_KEY', 'fake-auth-key-0001' );
define( 'SECURE_AUTH_KEY', 'fake-secure-auth-key-0002' );
define( 'AUTH_SALT', 'fake-auth-salt-0003' );
define( 'WP_DEBUG', false );
define( 'WP_MEMORY_LIMIT', '256M' );

$table_prefix = 'wptest_';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
