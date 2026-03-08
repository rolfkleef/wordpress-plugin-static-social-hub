<?php
/**
 * Integration test bootstrap — loads WordPress test suite.
 */

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
    die( 'Run `composer install` before running tests.' . PHP_EOL );
}

require_once $autoload;

// Tell WP test bootstrap where to find PHPUnit Polyfills
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

// Load the WordPress PHPUnit bootstrap
$_tests_dir = getenv( 'WP_TESTS_DIR' )
    ?: dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( $_tests_dir . '/includes/bootstrap.php' ) ) {
    die( 'WordPress test suite not found. Check WP_TESTS_DIR or run `composer install`.' . PHP_EOL );
}

// Register the plugin to be active during tests
tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__, 2 ) . '/plugin/my-plugin.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
