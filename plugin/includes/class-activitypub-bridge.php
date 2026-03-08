<?php
/**
 * Integrates the static_pages post type with the ActivityPub plugin.
 *
 * Registers static_pages as an ActivityPub-supported post type so the plugin
 * federates these posts using _activitypub_canonical_url as the object id/url.
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

		// Register static_pages as an ActivityPub-supported post type by hooking into
		// the option filter. ActivityPub iterates this option during its own init hook (priority 10)
		// to call add_post_type_support(), so we inject our CPT before that lookup.
		add_filter( 'option_activitypub_support_post_types', array( self::class, 'add_static_page_type' ) );
		// Also call add_post_type_support directly at a later init priority as a belt-and-suspenders.
		add_action( 'init', array( self::class, 'add_post_type_support_direct' ), 20 );
	}

	/**
	 * Directly registers ActivityPub support on the static_pages post type.
	 * This runs on init priority 20, after both WP and ActivityPub have initialised.
	 */
	public static function add_post_type_support_direct() {
		add_post_type_support( 'static_pages', 'activitypub' );
	}

	/**
	 * Adds static_pages to the list of post types the ActivityPub plugin federates.
	 *
	 * @param string[] $post_types
	 * @return string[]
	 */
	public static function add_static_page_type( $post_types ) {
		if ( ! in_array( 'static_pages', $post_types, true ) ) {
			$post_types[] = 'static_pages';
		}
		return $post_types;
	}
}
