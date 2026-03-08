<?php
/**
 * REST API endpoints for the Static Social Hub plugin.
 *
 * GET  /wp-json/static-social-hub/v1/reactions?url=<static-url>
 *   Returns all comments, webmentions, and ActivityPub reactions for a static page URL.
 *
 * POST /wp-json/static-social-hub/v1/comments
 *   Submits a comment for a static page URL (pending moderation).
 *
 * @package StaticSocialHub
 */

namespace StaticSocialHub;

defined( 'ABSPATH' ) || exit;

/**
 * REST API endpoints for the Static Social Hub plugin.
 */
class REST_API {

	/**
	 * Registers REST routes and CORS filter hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		// Add CORS headers for the static site origin on all our REST responses.
		add_filter( 'rest_pre_serve_request', array( self::class, 'add_cors_headers' ), 10, 4 );
	}

	/**
	 * Registers all plugin REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			SSH_REST_NAMESPACE,
			'/reactions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_reactions' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'url' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
							'description'       => __( 'The static page URL to fetch reactions for.', 'static-social-hub' ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::ALLMETHODS,
					'callback'            => array( self::class, 'handle_preflight' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			SSH_REST_NAMESPACE,
			'/comments',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'submit_comment' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'url'          => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'author_name'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'author_email' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'author_url'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
							'default'           => '',
						),
						'content'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'wp_kses_post',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::ALLMETHODS,
					'callback'            => array( self::class, 'handle_preflight' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			SSH_REST_NAMESPACE,
			'/static-pages',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_static_page_post' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'url' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::ALLMETHODS,
					'callback'            => array( self::class, 'handle_preflight' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// POST /static-pages
	// -------------------------------------------------------------------------

	/**
	 * Finds or creates a static_pages post for the given URL and returns its
	 * post_id and edit_url. The URL must belong to the configured static site.
	 * No authentication required — URL validation is the guard.
	 *
	 * @param \WP_REST_Request $request Incoming REST request with a 'url' parameter.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_static_page_post( \WP_REST_Request $request ) {
		$result = Static_Post::find_or_create( $request->get_param( 'url' ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response(
			array(
				'post_id'  => $result,
				'edit_url' => admin_url( 'post.php?post=' . $result . '&action=edit' ),
			)
		);
	}

	/**
	 * Returns all reactions (comments, webmentions, AP likes/boosts/replies) for a static URL.
	 *
	 * @param \WP_REST_Request $request Incoming REST request with a 'url' parameter.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_reactions( \WP_REST_Request $request ) {
		$url = $request->get_param( 'url' );

		if ( ! Static_Post::is_static_url( $url ) ) {
			return new \WP_Error(
				'ssh_invalid_url',
				__( 'The URL does not belong to the configured static site.', 'static-social-hub' ),
				array( 'status' => 400 )
			);
		}

		$post_id = Static_Post::find_static_page( $url );

		$response_data = array(
			'url'         => $url,
			'post_id'     => $post_id,
			'comments'    => array(),
			'webmentions' => array(),
			'likes'       => array(),
			'boosts'      => array(),
			'replies'     => array(),
		);

		if ( ! $post_id ) {
			$response_data['admin'] = array();
			return rest_ensure_response( $response_data );
		}

		$raw_comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'approve',
				'number'  => 200,
				'orderby' => 'comment_date',
				'order'   => 'ASC',
			)
		);

		foreach ( $raw_comments as $comment ) {
			$type                       = strtolower( trim( $comment->comment_type ) );
			$bucket                     = self::classify_comment( $comment );
			$shaped                     = self::shape_comment( $comment, $bucket );
			$response_data[ $bucket ][] = $shaped;
		}

		// Always include admin URLs so the JS widget can show an edit button when
		// it detects a WP session cookie. WP enforces auth itself when the link is opened.
		$response_data['admin'] = array(
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);

		return rest_ensure_response( $response_data );
	}

	/**
	 * Classifies a comment into the correct response bucket.
	 *
	 * ActivityPub comment types:
	 *   - 'like'   → AP Like activity
	 *   - 'repost' → AP Announce (boost) activity
	 *   - 'quote'  → AP Quote activity (shown alongside replies)
	 *   - 'comment' with meta protocol='activitypub' → AP Create (reply from fediverse)
	 *   - 'comment' without AP protocol → regular comment
	 *   - 'webmention' / 'pingback' → webmention
	 *
	 * @param \WP_Comment $comment The comment to classify.
	 * @return string  One of: likes|boosts|replies|webmentions|comments
	 */
	private static function classify_comment( \WP_Comment $comment ) {
		$type = strtolower( trim( $comment->comment_type ) );

		switch ( $type ) {
			case 'like':
				return 'likes';
			case 'repost':
				return 'boosts';
			case 'quote':
				// Quote posts have content; show alongside fediverse replies.
				return 'replies';
			case 'webmention':
			case 'pingback':
				return 'webmentions';
			case 'comment':
			default:
				// Check if this is a fediverse reply (ActivityPub Create activity stored as comment).
				$protocol = get_comment_meta( $comment->comment_ID, 'protocol', true );
				if ( 'activitypub' === $protocol ) {
					return 'replies';
				}
				return 'comments';
		}
	}

	/**
	 * Shapes a WP_Comment into a clean array for the API response.
	 *
	 * @param \WP_Comment $comment The comment to shape into an array.
	 * @param string      $bucket  The reaction bucket the comment belongs to.
	 * @return array
	 */
	private static function shape_comment( \WP_Comment $comment, $bucket ) {
		$shaped = array(
			'id'            => (int) $comment->comment_ID,
			'author'        => $comment->comment_author,
			'author_url'    => $comment->comment_author_url,
			'author_avatar' => self::get_avatar_url( $comment ),
			'date'          => $comment->comment_date_gmt,
		);

		// Add content for everything except bare likes/boosts.
		if ( ! in_array( $bucket, array( 'likes', 'boosts' ), true ) ) {
			$shaped['content'] = wp_strip_all_tags( $comment->comment_content );
		}

		// Webmention source URL (the page that sent the webmention).
		if ( 'webmentions' === $bucket ) {
			$source = get_comment_meta( $comment->comment_ID, 'webmention_source_url', true );
			if ( ! $source ) {
				$source = get_comment_meta( $comment->comment_ID, 'semantic_linkbacks_source', true );
			}
			$shaped['source'] = $source ? $source : $comment->comment_author_url;
		}

		// Fediverse reply: include the ActivityPub post URL so the date can link to it.
		if ( 'replies' === $bucket ) {
			$source = get_comment_meta( $comment->comment_ID, 'source_url', true );
			if ( ! $source ) {
				$source = get_comment_meta( $comment->comment_ID, 'source_id', true );
			}
			if ( $source ) {
				$shaped['source'] = $source;
			}
		}

		return $shaped;
	}

	/**
	 * Returns the avatar URL for a comment author.
	 *
	 * @param \WP_Comment $comment The comment to retrieve the avatar for.
	 * @return string
	 */
	private static function get_avatar_url( \WP_Comment $comment ) {
		// Check ActivityPub actor avatar stored in meta.
		$ap_avatar = get_comment_meta( $comment->comment_ID, 'avatar_url', true );
		if ( $ap_avatar ) {
			return esc_url( $ap_avatar );
		}

		// Fall back to Gravatar.
		$avatar_url = get_avatar_url( $comment->comment_author_email, array( 'default' => 'identicon' ) );
		return $avatar_url ? $avatar_url : '';
	}

	// -------------------------------------------------------------------------
	// POST /comments
	// -------------------------------------------------------------------------

	/**
	 * Handles comment submission from the static site widget.
	 *
	 * @param \WP_REST_Request $request Incoming REST request with comment fields.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit_comment( \WP_REST_Request $request ) {
		$url          = $request->get_param( 'url' );
		$author_name  = $request->get_param( 'author_name' );
		$author_email = $request->get_param( 'author_email' );
		$author_url   = $request->get_param( 'author_url' );
		$content      = $request->get_param( 'content' );

		if ( ! Static_Post::is_static_url( $url ) ) {
			return new \WP_Error(
				'ssh_invalid_url',
				__( 'The URL does not belong to the configured static site.', 'static-social-hub' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_email( $author_email ) ) {
			return new \WP_Error(
				'ssh_invalid_email',
				__( 'Please provide a valid email address.', 'static-social-hub' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( trim( $content ) ) < 3 ) {
			return new \WP_Error(
				'ssh_empty_content',
				__( 'Comment content is too short.', 'static-social-hub' ),
				array( 'status' => 400 )
			);
		}

		// Resolve or create a static site page for this URL.
		$post_id = Static_Post::find_static_page( $url );
		if ( ! $post_id ) {
			$post_id = Static_Post::create_static_page( $url );
		}
		if ( ! $post_id ) {
			return new \WP_Error(
				'ssh_static_page_failed',
				__( 'Could not find or create a post for this URL.', 'static-social-hub' ),
				array( 'status' => 500 )
			);
		}

		// Check if comments are open for this post.
		if ( ! comments_open( $post_id ) ) {
			return new \WP_Error(
				'ssh_comments_closed',
				__( 'Comments are closed for this page.', 'static-social-hub' ),
				array( 'status' => 403 )
			);
		}

		// Build the comment data array.
		// comment_approved starts at 0 (pending); wp_allow_comment() may upgrade it.
		// comment_date_gmt must be set explicitly — wp_allow_comment() passes it to the
		// flood check, and without it $time_newcomment evaluates to 0, making every
		// subsequent submission appear to be a flood forever.
		$comment_date     = current_time( 'mysql' );
		$comment_date_gmt = current_time( 'mysql', true );

		$comment_data = array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => $author_name,
			'comment_author_email' => $author_email,
			'comment_author_url'   => $author_url,
			'comment_content'      => $content,
			'comment_type'         => '',
			'comment_parent'       => 0,
			'comment_approved'     => 0,
			'comment_date'         => $comment_date,
			'comment_date_gmt'     => $comment_date_gmt,
			'comment_author_IP'    => self::get_client_ip(),
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		// Allow Akismet and other plugins to set approval status.
		// wp_allow_comment() also handles duplicate and flood checks internally.
		$allowed = wp_allow_comment( $comment_data, true );
		if ( is_wp_error( $allowed ) ) {
			$code = $allowed->get_error_code();
			if ( 'comment_duplicate' === $code ) {
				return new \WP_Error(
					'ssh_duplicate_comment',
					__( 'It looks like you already said that!', 'static-social-hub' ),
					array( 'status' => 409 )
				);
			}
			if ( 'comment_flood' === $code ) {
				return new \WP_Error(
					'ssh_comment_flood',
					__( 'You are posting comments too quickly. Please slow down.', 'static-social-hub' ),
					array( 'status' => 429 )
				);
			}
			return $allowed;
		}
		$comment_data['comment_approved'] = $allowed;

		$comment_id = wp_insert_comment( $comment_data );

		if ( ! $comment_id ) {
			return new \WP_Error(
				'ssh_insert_failed',
				__( 'Could not save your comment. Please try again.', 'static-social-hub' ),
				array( 'status' => 500 )
			);
		}

		$status = $comment_data['comment_approved'];

		if ( '1' === (string) $status || 1 === $status ) {
			$message = __( 'Your comment has been posted.', 'static-social-hub' );
			$state   = 'approved';
		} elseif ( 'spam' === $status ) {
			return new \WP_Error(
				'ssh_spam',
				__( 'Your comment has been marked as spam.', 'static-social-hub' ),
				array( 'status' => 400 )
			);
		} else {
			$message = __( 'Your comment is awaiting moderation.', 'static-social-hub' );
			$state   = 'pending';
		}

		return rest_ensure_response(
			array(
				'status'  => $state,
				'message' => $message,
				'id'      => $comment_id,
				'comment' => array(
					'author'        => $author_name,
					'author_url'    => $author_url,
					'author_avatar' => 'https://www.gravatar.com/avatar/' . md5( strtolower( trim( $author_email ) ) ) . '?s=40&d=identicon',
					'content'       => $content,
					'date'          => $comment_date_gmt,
					'pending'       => ( 'pending' === $state ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// CORS & preflight
	// -------------------------------------------------------------------------

	/**
	 * Adds CORS headers to all Drostan REST API responses.
	 *
	 * @param bool              $served   Whether the request has already been served.
	 * @param \WP_HTTP_Response $result   The response object.
	 * @param \WP_REST_Request  $request  The current REST request.
	 * @param \WP_REST_Server   $_server  Server instance (unused).
	 * @return bool
	 */
	public static function add_cors_headers( $served, $result, $request, $_server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . SSH_REST_NAMESPACE . '/' ) ) {
			return $served;
		}

		$origin = ssh_get_cors_origin();
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce' );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin' );

		return $served;
	}

	/**
	 * Handles CORS preflight (OPTIONS) requests by returning 200 immediately.
	 *
	 * @param \WP_REST_Request $request Incoming OPTIONS REST request.
	 * @return \WP_REST_Response
	 */
	public static function handle_preflight( \WP_REST_Request $request ) {
		if ( 'OPTIONS' === $request->get_method() ) {
			$response = new \WP_REST_Response( null, 200 );
			return $response;
		}
		return new \WP_REST_Response( null, 405 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the client IP address, respecting trusted reverse-proxy headers.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		// WordPress sets this via is_proxied_request / pre_option_siteurl already, but
		// we also respect X-Forwarded-For for comment IP logging.
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );
		}
		return ! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
