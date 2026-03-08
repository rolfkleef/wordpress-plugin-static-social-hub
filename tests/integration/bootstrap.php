<?php
/**
 * Integration test bootstrap — loads WordPress test suite.
 */

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
    die( 'Run `composer install` before running tests.' . PHP_EOL );
}

require_once $autoload;

// Tell WP test bootstrap where to find PHPUnit Polyfills.
// NOTE: do not define WP_TESTS_PHPUNIT_POLYFILLS_PATH here — it is already set
// via the <const> element in phpunit.integration.xml and would cause a warning.

// Load the WordPress PHPUnit bootstrap
$_tests_dir = getenv( 'WP_TESTS_DIR' )
    ?: dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( $_tests_dir . '/includes/bootstrap.php' ) ) {
    die( 'WordPress test suite not found. Check WP_TESTS_DIR or run `composer install`.' . PHP_EOL );
}

// Load functions.php first — this is where tests_add_filter() is defined.
require_once $_tests_dir . '/includes/functions.php';

// Register the plugin to be active during tests.
// WordPress (and therefore ABSPATH) is not yet loaded here, but the plugin file
// is only required when the muplugins_loaded hook fires, by which point
// WordPress has already defined ABSPATH.
tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__, 2 ) . '/plugin/static-social-hub.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
