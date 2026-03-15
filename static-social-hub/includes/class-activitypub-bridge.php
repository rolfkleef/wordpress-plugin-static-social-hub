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

/**
 * Integrates the static_pages post type with the ActivityPub plugin.
 */
class ActivityPub_Bridge {

	/**
	 * Registers hooks to conditionally enable ActivityPub integration.
	 */
	public static function init() {
		// Only hook if the ActivityPub plugin is active.
		add_action( 'plugins_loaded', array( self::class, 'maybe_init' ) );
	}

	/**
	 * Hooks into ActivityPub if the plugin is active.
	 */
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

		// Override the URL and ID used in the ActivityPub object for static_pages posts.
		// ActivityPub's Post Transformer calls get_permalink() for live posts, which produces
		// the WordPress URL. We substitute the static site URL stored in post meta instead.
		add_filter( 'activitypub_transform_set_url', array( self::class, 'override_ap_url' ), 10, 2 );
		add_filter( 'activitypub_transform_set_id', array( self::class, 'override_ap_url' ), 10, 2 );
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
	 * @param string[] $post_types Post types currently supported by ActivityPub.
	 * @return string[]
	 */
	public static function add_static_page_type( $post_types ) {
		if ( ! in_array( 'static_pages', $post_types, true ) ) {
			$post_types[] = 'static_pages';
		}
		return $post_types;
	}

	/**
	 * Replaces the ActivityPub object URL/ID with the static site canonical URL.
	 *
	 * ActivityPub's Post Transformer calls get_permalink() for live posts, producing
	 * the WordPress URL (e.g. /?p=123). For static_pages posts we store the real static
	 * site URL in the _activitypub_canonical_url meta, so we substitute that here.
	 * The same value is used for both the `url` and `id` properties so Fediverse servers
	 * treat the static page URL as the authoritative identity of the object.
	 *
	 * Hooked into activitypub_transform_set_url and activitypub_transform_set_id.
	 *
	 * @param string   $value The URL/ID value proposed by ActivityPub.
	 * @param \WP_Post $post  The post being transformed.
	 * @return string
	 */
	public static function override_ap_url( $value, $post ) {
		if ( ! $post instanceof \WP_Post || 'static_pages' !== $post->post_type ) {
			return $value;
		}

		$canonical = get_post_meta( $post->ID, '_activitypub_canonical_url', true );
		return $canonical ? $canonical : $value;
	}
}
