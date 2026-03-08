<?php
/**
 * Unit test bootstrap — no WordPress loaded here.
 */

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
    die( 'Run `composer install` before running tests.' . PHP_EOL );
}

require_once $autoload;

// Brain\Monkey lets us mock WP functions (add_action, esc_html, etc.)
// without loading WordPress at all.
\Brain\Monkey\setUp();
