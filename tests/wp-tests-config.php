<?php
// Mirror of wp-config.php but for the test database — keep it separate!
define( 'DB_NAME',     getenv( 'WORDPRESS_DB_NAME'     ) ?: 'wordpress_test' );
define( 'DB_USER',     getenv( 'WORDPRESS_DB_USER'     ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST',     getenv( 'WORDPRESS_DB_HOST'     ) ?: 'localhost' );
define( 'DB_CHARSET',  'utf8' );
define( 'DB_COLLATE',  '' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );

// WordPress test suite requirements.
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL',  'admin@example.org' );
define( 'WP_TESTS_TITLE',  'Test Blog' );
define( 'WP_PHP_BINARY',   PHP_BINARY );

define( 'ABSPATH', rtrim( getenv( 'WP_ABSPATH' ) ?: '/workspace/wordpress/wp/', '/' ) . '/' );
