<?php
/**
 * Unit test bootstrap — no WordPress loaded here.
 *
 * Brain\Monkey intercepts WordPress function calls so tests can run in plain PHP.
 * Do NOT call Brain\Monkey\setUp() here; each test does that in its own setUp() method.
 */

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! file_exists( $autoload ) ) {
	die( 'Run `composer install` before running tests.' . PHP_EOL );
}

require_once $autoload;

// Plugin files guard themselves with `defined( 'ABSPATH' ) || exit`.
// Define a dummy value so the autoloader can load classes without bailing out.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

// Minimal WP_Error stub so unit tests can assert on error returns without loading WordPress.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
