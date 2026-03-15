<?php
/**
 * Unit test bootstrap — no WordPress loaded here.
 *
 * Brain\Monkey intercepts WordPress function calls so tests can run in plain PHP.
 * Do NOT call Brain\Monkey\setUp() here; each test does that in its own setUp() method.
 */

// Ensure subprocesses spawned by @runInSeparateProcess inherit xdebug's off mode so
// xdebug step-debug noise doesn't pollute PHPUnit's subprocess communication channel.
// Only set the env var when coverage (or any other non-off mode) isn't already active
// via either the environment variable or the -d xdebug.mode ini override.
if ( false === getenv( 'XDEBUG_MODE' ) && in_array( ini_get( 'xdebug.mode' ), array( false, '', 'off' ), true ) ) {
	putenv( 'XDEBUG_MODE=off' );
}

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

		// Third $data param accepted but not stored – matches WP's real signature.
		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function add_data( mixed $data, string $code = '' ): void {}
	}
}

// Minimal WP REST stubs for unit tests that exercise REST_API methods directly.
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function get_param( string $key ): mixed { return null; }
		public function get_method(): string { return ''; }
		public function get_route(): string { return ''; }
	}
}

if ( ! class_exists( 'WP_HTTP_Response' ) ) {
	class WP_HTTP_Response {
		public mixed $data;
		public int   $status;
		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response extends WP_HTTP_Response {}
}

// Minimal WP_Comment stub with the public properties accessed by REST_API.
if ( ! class_exists( 'WP_Comment' ) ) {
	class WP_Comment {
		public int    $comment_ID           = 0;
		public string $comment_author       = '';
		public string $comment_author_email = '';
		public string $comment_author_url   = '';
		public string $comment_content      = '';
		public string $comment_type         = '';
		public string $comment_date_gmt     = '';
	}
}

// Plugin constants needed by REST_API (normally defined in static-social-hub.php).
if ( ! defined( 'SSH_REST_NAMESPACE' ) ) {
	define( 'SSH_REST_NAMESPACE', 'static-social-hub/v1' );
}

// Minimal WP_Post stub with the public properties accessed by ActivityPub_Bridge.
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int    $ID        = 0;
		public string $post_type = '';
	}
}
