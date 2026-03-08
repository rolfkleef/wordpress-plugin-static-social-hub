<?php
/**
 * Registers the static_pages custom post type and hooks into the Webmention plugin's
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
			'static_pages',
			array(
				'label'           => __( 'Static Site Pages', 'static-social-hub' ),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 5,
				'menu_icon'       => 'dashicons-admin-site-alt3',
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'editor', 'comments', 'custom-fields' ),
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

		$existing = self::query_by_static_url( $target );
		if ( $existing ) {
			return $existing;
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
				'post_type'      => 'static_pages',
				'post_title'     => $target_path,
				'post_status'    => 'draft',
				'post_name'      => self::path_to_slug( $target_path ),
				'comment_status' => 'open',
				'meta_input'     => array(
					'_activitypub_canonical_url' => $static_url,
				),
			)
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return false;
		}

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

		return self::query_by_static_url( $static_url );
	}

	/**
	 * Looks up a static_pages post by its _activitypub_canonical_url meta value.
	 *
	 * @param string $static_url Full static page URL.
	 * @return int|false Post ID or false.
	 */
	private static function query_by_static_url( $static_url ) {
		$results = get_posts( array(
			'post_type'      => 'static_pages',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'meta_key'       => '_activitypub_canonical_url',
			'meta_value'     => $static_url,
		) );
		return $results ? $results[0] : false;
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
