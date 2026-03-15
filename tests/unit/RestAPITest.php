<?php
/**
 * Unit tests for REST_API.
 *
 * Tests the classify/shape logic, CORS header behaviour, preflight handling,
 * and the get_reactions / submit_comment endpoint callbacks.
 *
 * @package StaticSocialHub\Tests\Unit
 */

namespace StaticSocialHub\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionMethod;
use StaticSocialHub\REST_API;

class RestAPITest extends UnitTestCase {

	// -------------------------------------------------------------------------
	// classify_comment (private — tested via ReflectionMethod)
	// -------------------------------------------------------------------------

	/** @return ReflectionMethod */
	private function get_classify(): ReflectionMethod {
		$m = new ReflectionMethod( REST_API::class, 'classify_comment' );
		$m->setAccessible( true );
		return $m;
	}

	private function make_comment( string $type, int $id = 1 ): \WP_Comment {
		$c                = new \WP_Comment();
		$c->comment_ID   = $id;
		$c->comment_type = $type;
		return $c;
	}

	public function test_classify_like_maps_to_likes(): void {
		$this->assertSame( 'likes', $this->get_classify()->invoke( null, $this->make_comment( 'like' ) ) );
	}

	public function test_classify_repost_maps_to_boosts(): void {
		$this->assertSame( 'boosts', $this->get_classify()->invoke( null, $this->make_comment( 'repost' ) ) );
	}

	public function test_classify_quote_maps_to_replies(): void {
		$this->assertSame( 'replies', $this->get_classify()->invoke( null, $this->make_comment( 'quote' ) ) );
	}

	public function test_classify_webmention_maps_to_webmentions(): void {
		$this->assertSame( 'webmentions', $this->get_classify()->invoke( null, $this->make_comment( 'webmention' ) ) );
	}

	public function test_classify_pingback_maps_to_webmentions(): void {
		$this->assertSame( 'webmentions', $this->get_classify()->invoke( null, $this->make_comment( 'pingback' ) ) );
	}

	public function test_classify_comment_with_activitypub_protocol_maps_to_replies(): void {
		$comment = $this->make_comment( 'comment' );

		Functions\expect( 'get_comment_meta' )
			->once()
			->with( 1, 'protocol', true )
			->andReturn( 'activitypub' );

		$this->assertSame( 'replies', $this->get_classify()->invoke( null, $comment ) );
	}

	public function test_classify_regular_comment_maps_to_comments(): void {
		$comment = $this->make_comment( 'comment' );

		Functions\expect( 'get_comment_meta' )
			->once()
			->with( 1, 'protocol', true )
			->andReturn( '' );

		$this->assertSame( 'comments', $this->get_classify()->invoke( null, $comment ) );
	}

	public function test_classify_empty_type_defaults_to_comments(): void {
		$comment = $this->make_comment( '' );

		Functions\expect( 'get_comment_meta' )
			->once()
			->with( 1, 'protocol', true )
			->andReturn( '' );

		$this->assertSame( 'comments', $this->get_classify()->invoke( null, $comment ) );
	}

	// -------------------------------------------------------------------------
	// add_cors_headers
	// -------------------------------------------------------------------------

	public function test_add_cors_headers_ignores_non_plugin_route(): void {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_route' )->andReturn( '/wp/v2/posts' );

		// ssh_get_cors_origin must NOT be called for unrelated routes.
		Functions\expect( 'StaticSocialHub\ssh_get_cors_origin' )->never();

		$result = REST_API::add_cors_headers( false, new \WP_HTTP_Response(), $request, null );

		$this->assertFalse( $result );
	}

	public function test_add_cors_headers_applies_to_plugin_route(): void {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_route' )->andReturn( '/static-social-hub/v1/reactions' );

		Functions\expect( 'StaticSocialHub\ssh_get_cors_origin' )
			->once()
			->andReturn( 'https://static.example.com' );
		// Stub header() so PHPUnit does not treat the "headers already sent" warning as a test error.
		Functions\stubs( array( 'header' => null ) );

		$result = REST_API::add_cors_headers( true, new \WP_HTTP_Response(), $request, null );

		// $served must be returned unchanged; CORS headers are set as a side effect.
		$this->assertTrue( $result );
	}

	public function test_add_cors_headers_passes_through_served_value(): void {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_route' )->andReturn( '/static-social-hub/v1/comments' );

		Functions\stubs( array( 'StaticSocialHub\ssh_get_cors_origin' => fn() => 'https://static.example.com' ) );
		Functions\stubs( array( 'header' => null ) );

		// $served = false must be returned as-is even when headers are added.
		$result = REST_API::add_cors_headers( false, new \WP_HTTP_Response(), $request, null );
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// handle_preflight
	// -------------------------------------------------------------------------

	public function test_handle_preflight_returns_200_for_options(): void {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_method' )->andReturn( 'OPTIONS' );

		$result = REST_API::handle_preflight( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->status );
	}

	public function test_handle_preflight_returns_405_for_non_options(): void {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_method' )->andReturn( 'DELETE' );

		$result = REST_API::handle_preflight( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 405, $result->status );
	}

	// -------------------------------------------------------------------------
	// get_reactions
	// -------------------------------------------------------------------------

	public function test_get_reactions_rejects_non_static_url(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->once()
			->andReturn( 'https://static.example.com' );

		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_param' )->with( 'url' )->andReturn( 'https://other.com/page' );

		$result = REST_API::get_reactions( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_invalid_url', $result->get_error_code() );
	}

	public function test_get_reactions_returns_empty_buckets_when_no_post_exists(): void {
		// ssh_get_static_site_url is called twice: once in get_reactions, once inside find_static_page.
		Functions\stubs( array( 'StaticSocialHub\ssh_get_static_site_url' => fn() => 'https://static.example.com' ) );
		Functions\expect( 'get_posts' )->once()->andReturn( array() );
		Functions\stubs( array( 'rest_ensure_response' => fn( $data ) => $data ) );

		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_param' )->with( 'url' )->andReturn( 'https://static.example.com/about' );

		$result = REST_API::get_reactions( $request );

		$this->assertSame( 'https://static.example.com/about', $result['url'] );
		$this->assertFalse( $result['post_id'] );
		$this->assertSame( array(), $result['comments'] );
		$this->assertSame( array(), $result['likes'] );
		$this->assertSame( array(), $result['boosts'] );
		$this->assertSame( array(), $result['replies'] );
		$this->assertSame( array(), $result['webmentions'] );
	}

	public function test_get_reactions_buckets_a_like_into_likes(): void {
		Functions\stubs( array( 'StaticSocialHub\ssh_get_static_site_url' => fn() => 'https://static.example.com' ) );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 99 ) );

		$comment                       = new \WP_Comment();
		$comment->comment_ID           = 1;
		$comment->comment_author       = 'Alice';
		$comment->comment_author_email = 'alice@example.com';
		$comment->comment_author_url   = 'https://alice.social/@alice';
		$comment->comment_content      = '';
		$comment->comment_type         = 'like';
		$comment->comment_date_gmt     = '2024-01-15 12:00:00';

		Functions\expect( 'get_comments' )->once()->andReturn( array( $comment ) );

		// shape_comment calls get_avatar_url internally.
		Functions\expect( 'get_comment_meta' )
			->once()
			->with( 1, 'avatar_url', true )
			->andReturn( '' );
		Functions\expect( 'get_avatar_url' )
			->once()
			->andReturn( 'https://gravatar.com/avatar/abc123' );

		Functions\expect( 'admin_url' )
			->once()
			->andReturn( 'https://wp.example.com/wp-admin/post.php?post=99&action=edit' );
		Functions\stubs( array( 'rest_ensure_response' => fn( $data ) => $data ) );

		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_param' )->with( 'url' )->andReturn( 'https://static.example.com/about' );

		$result = REST_API::get_reactions( $request );

		$this->assertSame( 99, $result['post_id'] );
		$this->assertCount( 1, $result['likes'] );
		$this->assertSame( array(), $result['comments'] );
		$this->assertSame( 'Alice', $result['likes'][0]['author'] );
		$this->assertSame( 'https://gravatar.com/avatar/abc123', $result['likes'][0]['author_avatar'] );
		// Likes bucket must NOT contain a 'content' key.
		$this->assertArrayNotHasKey( 'content', $result['likes'][0] );
	}

	public function test_get_reactions_buckets_a_regular_comment(): void {
		Functions\stubs( array( 'StaticSocialHub\ssh_get_static_site_url' => fn() => 'https://static.example.com' ) );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 99 ) );

		$comment                       = new \WP_Comment();
		$comment->comment_ID           = 2;
		$comment->comment_author       = 'Bob';
		$comment->comment_author_email = 'bob@example.com';
		$comment->comment_author_url   = '';
		$comment->comment_content      = 'Great post!';
		$comment->comment_type         = 'comment';
		$comment->comment_date_gmt     = '2024-01-16 09:00:00';

		Functions\expect( 'get_comments' )->once()->andReturn( array( $comment ) );

		// classify_comment checks protocol meta for 'comment' type.
		Functions\expect( 'get_comment_meta' )
			->with( 2, 'protocol', true )
			->once()
			->andReturn( '' );
		// shape_comment: check avatar meta, then fall back to gravatar.
		Functions\expect( 'get_comment_meta' )
			->with( 2, 'avatar_url', true )
			->once()
			->andReturn( '' );
		Functions\expect( 'get_avatar_url' )->once()->andReturn( 'https://gravatar.com/avatar/bob' );
		Functions\expect( 'wp_strip_all_tags' )->once()->andReturn( 'Great post!' );

		Functions\expect( 'admin_url' )->once()->andReturn( 'https://wp.example.com/wp-admin/post.php?post=99&action=edit' );
		Functions\stubs( array( 'rest_ensure_response' => fn( $data ) => $data ) );

		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_param' )->with( 'url' )->andReturn( 'https://static.example.com/about' );

		$result = REST_API::get_reactions( $request );

		$this->assertCount( 1, $result['comments'] );
		$this->assertSame( array(), $result['likes'] );
		$this->assertSame( 'Bob', $result['comments'][0]['author'] );
		$this->assertSame( 'Great post!', $result['comments'][0]['content'] );
	}

	public function test_get_reactions_includes_admin_url_when_post_found(): void {
		Functions\stubs( array( 'StaticSocialHub\ssh_get_static_site_url' => fn() => 'https://static.example.com' ) );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 42 ) );
		Functions\expect( 'get_comments' )->once()->andReturn( array() );
		Functions\expect( 'admin_url' )
			->once()
			->andReturn( 'https://wp.example.com/wp-admin/post.php?post=42&action=edit' );
		Functions\stubs( array( 'rest_ensure_response' => fn( $data ) => $data ) );

		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_param' )->with( 'url' )->andReturn( 'https://static.example.com/page' );

		$result = REST_API::get_reactions( $request );

		$this->assertArrayHasKey( 'admin', $result );
		$this->assertStringContainsString( 'post=42', $result['admin']['edit_url'] );
	}

	// -------------------------------------------------------------------------
	// submit_comment
	// -------------------------------------------------------------------------

	public function test_submit_comment_rejects_non_static_url(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->once()
			->andReturn( 'https://static.example.com' );

		$result = REST_API::submit_comment( $this->make_request( 'https://other.com/page' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_invalid_url', $result->get_error_code() );
	}

	public function test_submit_comment_rejects_invalid_email(): void {
		Functions\stubs( array( 'StaticSocialHub\ssh_get_static_site_url' => fn() => 'https://static.example.com' ) );
		Functions\expect( 'is_email' )->once()->andReturn( false );

		$result = REST_API::submit_comment(
			$this->make_request( 'https://static.example.com/page', author_email: 'not-an-email' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_invalid_email', $result->get_error_code() );
	}

	public function test_submit_comment_rejects_content_too_short(): void {
		Functions\stubs( array( 'StaticSocialHub\ssh_get_static_site_url' => fn() => 'https://static.example.com' ) );
		Functions\expect( 'is_email' )->once()->andReturn( true );

		$result = REST_API::submit_comment(
			$this->make_request( 'https://static.example.com/page', content: 'Hi' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_empty_content', $result->get_error_code() );
	}

	public function test_submit_comment_rejects_when_comments_are_closed(): void {
		$this->setup_until_allow_comment();
		Functions\expect( 'comments_open' )->once()->with( 99 )->andReturn( false );

		$result = REST_API::submit_comment( $this->make_request( 'https://static.example.com/page' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_comments_closed', $result->get_error_code() );
	}

	public function test_submit_comment_rejects_duplicate(): void {
		$this->setup_until_allow_comment();
		Functions\expect( 'comments_open' )->once()->andReturn( true );
		Functions\stubs( array( 'current_time' => fn() => '2024-01-15 12:00:00' ) );
		Functions\expect( 'wp_allow_comment' )
			->once()
			->andReturn( new \WP_Error( 'comment_duplicate', 'Duplicate comment' ) );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );

		$result = REST_API::submit_comment( $this->make_request( 'https://static.example.com/page' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_duplicate_comment', $result->get_error_code() );
	}

	public function test_submit_comment_rejects_comment_flood(): void {
		$this->setup_until_allow_comment();
		Functions\expect( 'comments_open' )->once()->andReturn( true );
		Functions\stubs( array( 'current_time' => fn() => '2024-01-15 12:00:00' ) );
		Functions\expect( 'wp_allow_comment' )
			->once()
			->andReturn( new \WP_Error( 'comment_flood', 'Slow down' ) );
		Functions\expect( 'is_wp_error' )->once()->andReturn( true );

		$result = REST_API::submit_comment( $this->make_request( 'https://static.example.com/page' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_comment_flood', $result->get_error_code() );
	}

	public function test_submit_comment_returns_pending_on_success(): void {
		$this->setup_until_allow_comment();
		Functions\expect( 'comments_open' )->once()->andReturn( true );
		Functions\stubs( array( 'current_time' => fn() => '2024-01-15 12:00:00' ) );
		Functions\expect( 'wp_allow_comment' )->once()->andReturn( '0' );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_insert_comment' )->once()->andReturn( 55 );
		Functions\stubs( array( 'rest_ensure_response' => fn( $data ) => $data ) );

		$result = REST_API::submit_comment( $this->make_request( 'https://static.example.com/page' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 55, $result['id'] );
		$this->assertTrue( $result['comment']['pending'] );
	}

	public function test_submit_comment_returns_approved_on_success(): void {
		$this->setup_until_allow_comment();
		Functions\expect( 'comments_open' )->once()->andReturn( true );
		Functions\stubs( array( 'current_time' => fn() => '2024-01-15 12:00:00' ) );
		Functions\expect( 'wp_allow_comment' )->once()->andReturn( '1' );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_insert_comment' )->once()->andReturn( 55 );
		Functions\stubs( array( 'rest_ensure_response' => fn( $data ) => $data ) );

		$result = REST_API::submit_comment( $this->make_request( 'https://static.example.com/page' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'approved', $result['status'] );
		$this->assertFalse( $result['comment']['pending'] );
	}

	public function test_submit_comment_returns_error_when_insert_fails(): void {
		$this->setup_until_allow_comment();
		Functions\expect( 'comments_open' )->once()->andReturn( true );
		Functions\stubs( array( 'current_time' => fn() => '2024-01-15 12:00:00' ) );
		Functions\expect( 'wp_allow_comment' )->once()->andReturn( '0' );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		Functions\expect( 'wp_insert_comment' )->once()->andReturn( false );

		$result = REST_API::submit_comment( $this->make_request( 'https://static.example.com/page' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_insert_failed', $result->get_error_code() );
	}

	public function test_submit_comment_marks_spam_after_insert(): void {
		$this->setup_until_allow_comment();
		Functions\expect( 'comments_open' )->once()->andReturn( true );
		Functions\stubs( array( 'current_time' => fn() => '2024-01-15 12:00:00' ) );
		Functions\expect( 'wp_allow_comment' )->once()->andReturn( 'spam' );
		Functions\expect( 'is_wp_error' )->once()->andReturn( false );
		// Spam comments are still inserted; the error is returned afterwards.
		Functions\expect( 'wp_insert_comment' )->once()->andReturn( 77 );

		$result = REST_API::submit_comment( $this->make_request( 'https://static.example.com/page' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_spam', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Sets up stubs/expectations for the submit_comment path up to (but NOT
	 * including) the comments_open / wp_allow_comment call.
	 */
	private function setup_until_allow_comment(): void {
		Functions\stubs( array( 'StaticSocialHub\ssh_get_static_site_url' => fn() => 'https://static.example.com' ) );
		Functions\expect( 'is_email' )->once()->andReturn( true );
		// Simulate an existing static_pages post so create_static_page isn't called.
		Functions\expect( 'get_posts' )->once()->andReturn( array( 99 ) );
	}

	/**
	 * Creates a Mockery WP_REST_Request mock pre-loaded with comment fields.
	 */
	private function make_request(
		string $url,
		string $author_email = 'alice@example.com',
		string $content = 'This is a great post!'
	): \WP_REST_Request {
		$request = \Mockery::mock( \WP_REST_Request::class );
		$request->allows( 'get_param' )->with( 'url' )->andReturn( $url );
		$request->allows( 'get_param' )->with( 'author_name' )->andReturn( 'Alice' );
		$request->allows( 'get_param' )->with( 'author_email' )->andReturn( $author_email );
		$request->allows( 'get_param' )->with( 'author_url' )->andReturn( '' );
		$request->allows( 'get_param' )->with( 'content' )->andReturn( $content );
		return $request;
	}
}
