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
		if ( $post_id ) {
			return $post_id;
		}

		$result = self::find_or_create( $target );
		return is_wp_error( $result ) ? $post_id : $result;
	}

	/**
	 * Finds an existing static page for a URL, or creates one if it passes all
	 * validation checks. Returns a WP_Error describing the first failing check.
	 *
	 * @param string $url
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function find_or_create( $url ) {
		if ( ! self::is_static_url( $url ) ) {
			return new \WP_Error( 'ssh_invalid_url', __( 'URL does not belong to the configured static site.', 'static-social-hub' ) );
		}

		if ( self::is_wordpress_url( $url ) ) {
			return new \WP_Error( 'ssh_wordpress_url', __( 'URL belongs to the WordPress site.', 'static-social-hub' ) );
		}

		$existing = self::query_by_static_url( $url );
		if ( $existing ) {
			return $existing;
		}

		$title = self::fetch_page_title( $url );
		if ( null === $title ) {
			return new \WP_Error( 'ssh_not_found', __( 'Static page returned 404.', 'static-social-hub' ) );
		}

		$post_id = self::create_static_page( $url, $title ?: sprintf( '[Title unavailable: %s]', self::normalise_path( $url ) ) );
		if ( ! $post_id ) {
			return new \WP_Error( 'ssh_create_failed', __( 'Could not create the static page.', 'static-social-hub' ) );
		}

		return $post_id;
	}

	/**
	 * Creates a new static site page for a static URL.
	 *
	 * @param string $static_url Full static page URL.
	 * @param string $title      Post title to use.
	 * @return int|false New post ID or false on failure.
	 */
	public static function create_static_page( $static_url, $title ) {
		$new_id = wp_insert_post(
			array(
				'post_type'      => 'static_pages',
				'post_title'     => $title,
				'post_status'    => 'draft',
				'comment_status' => 'open',
				'meta_input'     => array(
					'_activitypub_canonical_url'     => $static_url,
					'activitypub_content_visibility' => ssh_get_default_fediverse_visibility(),
				),
			)
		);

		return ( is_wp_error( $new_id ) || ! $new_id ) ? false : $new_id;
	}

	/**
	 * Fetches a URL and returns its page title.
	 *
	 * Return values:
	 *   string  – title found and extracted
	 *   false   – fetch succeeded but no <title> tag present
	 *   null    – page returned 404 (caller should not create a post)
	 *
	 * Any other HTTP error or timeout returns false (fetch failed, page may be
	 * temporarily unavailable — still worth creating the post).
	 *
	 * @param string $url
	 * @return string|false|null
	 */
	public static function fetch_page_title( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $status ) {
			return null;
		}

		if ( 200 !== $status ) {
			return false;
		}

		return self::extract_title( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Extracts and returns the trimmed <title> from an HTML string, or false if absent.
	 *
	 * @param string $html
	 * @return string|false
	 */
	private static function extract_title( $html ) {
		if ( ! preg_match( '/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $matches ) ) {
			return false;
		}

		$title = html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ssh_title_first_segment() ) {
			$parts = preg_split( '/\s*[–—\-|:]\s*/', $title, 2 );
			$title = trim( $parts[0] );
		}

		return $title ?: false;
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
		$static_base = rtrim( ssh_get_static_site_url(), '/' ) . '/';
		return 0 === strpos( $url, $static_base );
	}

	/**
	 * Checks whether a URL belongs to the WordPress site itself by testing
	 * whether it starts with the WordPress installation URL (site_url()).
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function is_wordpress_url( $url ) {
		$wp_base = rtrim( site_url(), '/' ) . '/';
		return 0 === strpos( $url, $wp_base );
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

}
