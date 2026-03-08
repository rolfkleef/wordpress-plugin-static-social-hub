<?php
/**
 * Integrates the webmention_static site page type with the ActivityPub plugin.
 *
 * - Registers webmention_shell as an ActivityPub-supported post type.
 * - Ensures the _activitypub_canonical_url meta is set to the static URL whenever a static site page
 *   post is saved, so ActivityPub federates with the static page URL as the object id/url.
 *
 * @package StaticSocialHub
 */

namespace StaticSocialHub;

defined( 'ABSPATH' ) || exit;

class ActivityPub_Bridge {

	public static function init() {
		// Only hook if the ActivityPub plugin is active.
		add_action( 'plugins_loaded', array( self::class, 'maybe_init' ) );
	}

	public static function maybe_init() {
		if ( ! class_exists( '\Activitypub\Activitypub' ) && ! function_exists( '\Activitypub\get_plugin_version' ) ) {
			return;
		}

		// Register webmention_shell as an ActivityPub-supported post type by hooking into
		// the option filter. ActivityPub iterates this option during its own init hook (priority 10)
		// to call add_post_type_support(), so we inject our CPT before that lookup.
		add_filter( 'option_activitypub_support_post_types', array( self::class, 'add_static_page_type' ) );
		// Also call add_post_type_support directly at a later init priority as a belt-and-suspenders.
		add_action( 'init', array( self::class, 'add_post_type_support_direct' ), 20 );

		// Ensure _activitypub_canonical_url is always in sync with _static_url.
		add_action( 'save_post_webmention_shell', array( self::class, 'sync_canonical_url' ), 10, 2 );
		add_action( 'updated_post_meta', array( self::class, 'on_static_url_meta_updated' ), 10, 4 );
		add_action( 'added_post_meta', array( self::class, 'on_static_url_meta_updated' ), 10, 4 );
	}

	/**
	 * Directly registers ActivityPub support on the webmention_static site page type.
	 * This runs on init priority 20, after both WP and ActivityPub have initialised.
	 */
	public static function add_post_type_support_direct() {
		add_post_type_support( 'webmention_shell', 'activitypub' );
	}

	/**
	 * Adds webmention_shell to the list of post types the ActivityPub plugin federates.
	 *
	 * @param string[] $post_types
	 * @return string[]
	 */
	public static function add_static_page_type( $post_types ) {
		if ( ! in_array( 'webmention_shell', $post_types, true ) ) {
			$post_types[] = 'webmention_shell';
		}
		return $post_types;
	}

	/**
	 * Syncs _activitypub_canonical_url with _static_url on post save.
	 *
	 * @param int      $post_id
	 * @param \WP_Post $post
	 */
	public static function sync_canonical_url( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$static_url = get_post_meta( $post_id, '_static_url', true );
		if ( $static_url ) {
			update_post_meta( $post_id, '_activitypub_canonical_url', $static_url );
		}
	}

	/**
	 * Fires when _static_url post meta is added or updated, keeping the AP canonical URL in sync.
	 *
	 * @param int    $meta_id
	 * @param int    $post_id
	 * @param string $meta_key
	 * @param mixed  $meta_value
	 */
	public static function on_static_url_meta_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_static_url' !== $meta_key ) {
			return;
		}
		if ( 'webmention_shell' !== get_post_type( $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_activitypub_canonical_url', $meta_value );
	}
}
