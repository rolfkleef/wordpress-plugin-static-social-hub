<?php
/**
 * Registers the webmention_shell custom post type and hooks into the Webmention plugin's
 * URL-to-post-ID resolution to map static page URLs to static site pages.
 *
 * @package StaticSocialHub
 */

namespace StaticSocialHub;

defined( 'ABSPATH' ) || exit;

class Static_Post {

	public static function init() {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_filter( 'webmention_post_id', array( self::class, 'resolve_post_id' ), 10, 2 );
	}

	/**
	 * Registers the webmention_static site page type.
	 * Posts are published so that ActivityPub can federate them, but they use a synthetic
	 * permalink that points back to the static site URL.
	 */
	public static function register_post_type() {
		register_post_type(
			'webmention_shell',
			array(
				'label'           => __( 'Static Site Pages', 'static-social-hub' ),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 5,
				'menu_icon'       => 'dashicons-admin-site-alt3',
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'comments', 'custom-fields' ),
				'capability_type' => 'post',
				'has_archive'     => false,
				'rewrite'         => false,
			)
		);
	}

	/**
	 * Resolves a static page URL to a static site page ID, creating the static site page if needed.
	 * Hooked into the Webmention plugin's `webmention_post_id` filter.
	 *
	 * @param int|false $post_id  Post ID found by Webmention (null/false if not found).
	 * @param string    $target   The target URL from the incoming webmention.
	 * @return int|false
	 */
	public static function resolve_post_id( $post_id, $target ) {
		// If Webmention already resolved a real WordPress post, leave it alone.
		if ( $post_id ) {
			return $post_id;
		}

		// Only intercept URLs that belong to the configured static site.
		if ( ! self::is_static_url( $target ) ) {
			return $post_id;
		}

		$target_path = self::normalise_path( $target );

		// Look for an existing static site page.
		$existing = get_posts(
			array(
				'post_type'      => 'webmention_shell',
				'title'          => $target_path,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_status'    => array( 'publish', 'pending', 'draft' ),
			)
		);

		if ( $existing ) {
			return $existing[0];
		}

		return self::create_static_page( $target );
	}

	/**
	 * Creates a new static site page for a static URL.
	 *
	 * @param string $static_url Full static page URL.
	 * @return int|false New post ID or false on failure.
	 */
	public static function create_static_page( $static_url ) {
		$target_path = self::normalise_path( $static_url );

		$new_id = wp_insert_post(
			array(
				'post_type'      => 'webmention_shell',
				'post_title'     => $target_path,
				'post_status'    => 'publish',
				'post_name'      => self::path_to_slug( $target_path ),
				'comment_status' => 'open',
				'meta_input'     => array(
					'_static_url' => $static_url,
				),
			)
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return false;
		}

		// Immediately set the ActivityPub canonical URL so the AP plugin federates
		// this post with the static URL as its object id/url.
		update_post_meta( $new_id, '_activitypub_canonical_url', $static_url );

		return $new_id;
	}

	/**
	 * Finds an existing static site page ID for a static URL without creating one.
	 *
	 * @param string $static_url Full static page URL.
	 * @return int|false Post ID or false.
	 */
	public static function find_static_page( $static_url ) {
		if ( ! self::is_static_url( $static_url ) ) {
			return false;
		}

		$target_path = self::normalise_path( $static_url );

		$existing = get_posts(
			array(
				'post_type'      => 'webmention_shell',
				'title'          => $target_path,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_status'    => array( 'publish', 'pending', 'draft' ),
			)
		);

		return $existing ? $existing[0] : false;
	}

	/**
	 * Checks whether a URL belongs to the configured static site.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function is_static_url( $url ) {
		$static_base = ssh_get_static_site_url();
		// Compare scheme + host (+ optional port).
		$parsed_url    = wp_parse_url( $url );
		$parsed_static = wp_parse_url( $static_base );

		if ( empty( $parsed_url['host'] ) || empty( $parsed_static['host'] ) ) {
			return false;
		}

		$url_host    = strtolower( $parsed_url['host'] );
		$static_host = strtolower( $parsed_static['host'] );

		$url_scheme    = strtolower( $parsed_url['scheme'] ?? 'https' );
		$static_scheme = strtolower( $parsed_static['scheme'] ?? 'https' );

		$url_port    = $parsed_url['port'] ?? null;
		$static_port = $parsed_static['port'] ?? null;

		return $url_host === $static_host
			&& $url_scheme === $static_scheme
			&& $url_port === $static_port;
	}

	/**
	 * Returns a normalised path string (no trailing slash except root "/").
	 *
	 * @param string $url
	 * @return string
	 */
	private static function normalise_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH ) ?? '/';
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}

	/**
	 * Converts a path to a URL-safe slug (max 190 chars to stay within DB limits).
	 *
	 * @param string $path
	 * @return string
	 */
	private static function path_to_slug( $path ) {
		$slug = trim( $path, '/' );
		$slug = str_replace( '/', '--', $slug );
		$slug = sanitize_title( $slug );
		if ( strlen( $slug ) > 190 ) {
			$slug = substr( $slug, 0, 150 ) . '-' . md5( $path );
		}
		return $slug ?: 'root';
	}
}
