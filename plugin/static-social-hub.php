<?php
/**
 * Plugin Name:  Static Social Hub
 * Plugin URI:   https://drostan.org
 * Description:  Bridge between a static website and a WordPress backend as Social Hub.
 *               Enables comment submission and display of comments, webmentions, and
 *               ActivityPub reactions  (likes, boosts, replies) for static pages via
 *               a JavaScript widget.
 *               Requires the ActivityPub and the Webmention plugins to be installed and activated.
 * Version:      0.1.0
 * Author:       Rolf Kleef
 * Author URI:   https://drostan.org
 * License:      AGPL-3.0-or-later
 * License URI:  https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain:  static-social-hub
 * Domain Path:  /languages
 * Requires PHP: 7.4
 *
 * @package StaticSocialHub
 */

namespace StaticSocialHub;

defined( 'ABSPATH' ) || exit;

define( 'SSH_VERSION', '0.1.0' );
define( 'SSH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SSH_REST_NAMESPACE', 'static-social-hub/v1' );

require_once SSH_PLUGIN_DIR . 'includes/class-static-post.php';
require_once SSH_PLUGIN_DIR . 'includes/class-activitypub-bridge.php';
require_once SSH_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once SSH_PLUGIN_DIR . 'includes/class-admin-settings.php';

/**
 * Returns the configured static site base URL (scheme + host, no trailing slash).
 * Falls back to the root domain of the WordPress installation.
 *
 * @return string
 */
function ssh_get_static_site_url() {
	$saved = get_option( 'ssh_static_site_url', '' );
	if ( $saved ) {
		return rtrim( $saved, '/' );
	}
	// Derive root domain from home_url() by keeping only scheme + host.
	$parsed = wp_parse_url( home_url() );
	$url    = $parsed['scheme'] . '://' . $parsed['host'];
	if ( ! empty( $parsed['port'] ) ) {
		$url .= ':' . $parsed['port'];
	}
	return $url;
}

/**
 * Returns the CORS allowed origin (defaults to the static site URL).
 *
 * @return string
 */
function ssh_get_cors_origin() {
	$saved = get_option( 'ssh_cors_origin', '' );
	return $saved ? rtrim( $saved, '/' ) : ssh_get_static_site_url();
}

/**
 * Returns true when static page titles should be trimmed to their first segment.
 *
 * @return bool
 */
function ssh_title_first_segment() {
	return (bool) get_option( 'ssh_title_first_segment', false );
}

/**
 * Returns the default Fediverse visibility for newly created static page posts.
 * Values mirror ActivityPub's activitypub_content_visibility meta:
 *   ''            = public
 *   'quiet_public' = federated but not boosted
 *   'local'        = do not federate
 *
 * @return string
 */
function ssh_get_default_fediverse_visibility() {
	return get_option( 'ssh_default_fediverse_visibility', 'local' );
}

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'static-social-hub', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// Bootstrap all components.
Static_Post::init();
ActivityPub_Bridge::init();
REST_API::init();
Admin_Settings::init();
