<?php
// Mirror of wp-config.php but for the test database — keep it separate!
define( 'DB_NAME',     getenv( 'WP_DB_NAME'     ) ?: 'wordpress_test' );
define( 'DB_USER',     getenv( 'WP_DB_USER'     ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST',     getenv( 'WP_DB_HOST'     ) ?: 'localhost' );
define( 'DB_CHARSET',  'utf8' );
define( 'DB_COLLATE',  '' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/src/' );
