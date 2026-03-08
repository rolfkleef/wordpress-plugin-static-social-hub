<?php
/**
 * Unit tests for Static_Post.
 *
 * Uses Brain\Monkey to intercept WordPress function calls so no database or
 * WordPress installation is required. See docs/testing.md for an explanation
 * of the mocking patterns used here.
 *
 * @package StaticSocialHub\Tests\Unit
 */

namespace StaticSocialHub\Tests\Unit;

use Brain\Monkey\Functions;
use ReflectionMethod;
use StaticSocialHub\Static_Post;

class StaticPostTest extends UnitTestCase {

	// -------------------------------------------------------------------------
	// resolve_post_id
	// -------------------------------------------------------------------------

	public function test_resolve_post_id_passes_through_when_already_found(): void {
		// If Webmention already resolved a post, Static_Post must not touch it.
		$this->assertSame( 42, Static_Post::resolve_post_id( 42, 'https://example.com/page' ) );
	}

	public function test_resolve_post_id_returns_false_on_non_static_url(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->once()
			->andReturn( 'https://static.example.com' );

		$result = Static_Post::resolve_post_id( false, 'https://other.com/page' );

		// find_or_create returns WP_Error; resolve_post_id falls back to original $post_id.
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// is_static_url
	// -------------------------------------------------------------------------

	public function test_is_static_url_matches_url_under_static_base(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );

		$this->assertTrue( Static_Post::is_static_url( 'https://static.example.com/about' ) );
	}

	public function test_is_static_url_rejects_different_domain(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );

		$this->assertFalse( Static_Post::is_static_url( 'https://other.com/about' ) );
	}

	public function test_is_static_url_rejects_partial_domain_match(): void {
		// "static.example.com.evil.com" must not match "static.example.com".
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );

		$this->assertFalse( Static_Post::is_static_url( 'https://static.example.com.evil.com/about' ) );
	}

	public function test_is_static_url_matches_root(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );

		$this->assertTrue( Static_Post::is_static_url( 'https://static.example.com/' ) );
	}

	// -------------------------------------------------------------------------
	// is_wordpress_url
	// -------------------------------------------------------------------------

	public function test_is_wordpress_url_matches_wp_installation(): void {
		Functions\expect( 'site_url' )->andReturn( 'https://wp.example.com' );

		$this->assertTrue( Static_Post::is_wordpress_url( 'https://wp.example.com/wp-admin/' ) );
	}

	public function test_is_wordpress_url_rejects_static_site(): void {
		Functions\expect( 'site_url' )->andReturn( 'https://wp.example.com' );

		$this->assertFalse( Static_Post::is_wordpress_url( 'https://static.example.com/about' ) );
	}

	// -------------------------------------------------------------------------
	// extract_title (private — tested via ReflectionMethod)
	// -------------------------------------------------------------------------

	/** @return ReflectionMethod */
	private function get_extract_title(): ReflectionMethod {
		$method = new ReflectionMethod( Static_Post::class, 'extract_title' );
		$method->setAccessible( true );
		return $method;
	}

	public function test_extract_title_returns_title_text(): void {
		Functions\expect( 'StaticSocialHub\ssh_title_first_segment' )->andReturn( false );

		$html  = '<html><head><title>My Page Title</title></head></html>';
		$title = $this->get_extract_title()->invoke( null, $html );

		$this->assertSame( 'My Page Title', $title );
	}

	public function test_extract_title_trims_whitespace(): void {
		Functions\expect( 'StaticSocialHub\ssh_title_first_segment' )->andReturn( false );

		$html  = '<html><head><title>  Padded Title  </title></head></html>';
		$title = $this->get_extract_title()->invoke( null, $html );

		$this->assertSame( 'Padded Title', $title );
	}

	public function test_extract_title_decodes_html_entities(): void {
		Functions\expect( 'StaticSocialHub\ssh_title_first_segment' )->andReturn( false );

		$html  = '<html><head><title>Caf&eacute; &amp; Bar</title></head></html>';
		$title = $this->get_extract_title()->invoke( null, $html );

		$this->assertSame( 'Café & Bar', $title );
	}

	public function test_extract_title_returns_false_when_no_title_tag(): void {
		$html  = '<html><head><meta charset="utf-8"></head></html>';
		$title = $this->get_extract_title()->invoke( null, $html );

		$this->assertFalse( $title );
	}

	public function test_extract_title_returns_false_for_empty_title(): void {
		Functions\expect( 'StaticSocialHub\ssh_title_first_segment' )->andReturn( false );

		$html  = '<html><head><title>   </title></head></html>';
		$title = $this->get_extract_title()->invoke( null, $html );

		$this->assertFalse( $title );
	}

	/** @dataProvider first_segment_provider */
	public function test_extract_title_first_segment( string $raw, string $expected ): void {
		Functions\expect( 'StaticSocialHub\ssh_title_first_segment' )->andReturn( true );

		$html  = "<html><head><title>{$raw}</title></head></html>";
		$title = $this->get_extract_title()->invoke( null, $html );

		$this->assertSame( $expected, $title );
	}

	public static function first_segment_provider(): array {
		return array(
			'pipe separator'         => array( 'My Page | Site Name', 'My Page' ),
			'dash separator'         => array( 'My Page - Site Name', 'My Page' ),
			'em dash separator'      => array( 'My Page – Site Name', 'My Page' ),
			'colon separator'        => array( 'My Page: Site Name', 'My Page' ),
			'no separator, kept as-is' => array( 'Just A Title', 'Just A Title' ),
		);
	}

	// -------------------------------------------------------------------------
	// fetch_page_title
	// -------------------------------------------------------------------------

	public function test_fetch_page_title_returns_null_on_404(): void {
		Functions\expect( 'wp_remote_get' )->andReturn( array() );
		Functions\expect( 'is_wp_error' )->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 404 );

		$this->assertNull( Static_Post::fetch_page_title( 'https://static.example.com/missing' ) );
	}

	public function test_fetch_page_title_returns_false_on_wp_error(): void {
		Functions\expect( 'wp_remote_get' )->andReturn( new \WP_Error( 'http_request_failed', 'cURL error' ) );
		Functions\expect( 'is_wp_error' )->andReturn( true );

		$this->assertFalse( Static_Post::fetch_page_title( 'https://static.example.com/page' ) );
	}

	public function test_fetch_page_title_returns_false_on_non_200_non_404(): void {
		Functions\expect( 'wp_remote_get' )->andReturn( array() );
		Functions\expect( 'is_wp_error' )->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 500 );

		$this->assertFalse( Static_Post::fetch_page_title( 'https://static.example.com/error' ) );
	}

	public function test_fetch_page_title_extracts_title_from_200_response(): void {
		Functions\expect( 'wp_remote_get' )->andReturn( array() );
		Functions\expect( 'is_wp_error' )->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->andReturn( '<html><head><title>About Us</title></head></html>' );
		Functions\expect( 'StaticSocialHub\ssh_title_first_segment' )->andReturn( false );

		$this->assertSame( 'About Us', Static_Post::fetch_page_title( 'https://static.example.com/about' ) );
	}

	// -------------------------------------------------------------------------
	// find_or_create
	// -------------------------------------------------------------------------

	public function test_find_or_create_rejects_non_static_url(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );

		$result = Static_Post::find_or_create( 'https://other.com/page' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_invalid_url', $result->get_error_code() );
	}

	public function test_find_or_create_rejects_wordpress_url(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://wp.example.com' );
		Functions\expect( 'site_url' )->andReturn( 'https://wp.example.com' );

		$result = Static_Post::find_or_create( 'https://wp.example.com/wp-admin/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_wordpress_url', $result->get_error_code() );
	}

	public function test_find_or_create_returns_existing_post_id(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );
		Functions\expect( 'site_url' )->andReturn( 'https://wp.example.com' );
		Functions\expect( 'get_posts' )->andReturn( array( 99 ) );

		$result = Static_Post::find_or_create( 'https://static.example.com/about' );

		$this->assertSame( 99, $result );
	}

	public function test_find_or_create_returns_error_on_404(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );
		Functions\expect( 'site_url' )->andReturn( 'https://wp.example.com' );
		Functions\expect( 'get_posts' )->andReturn( array() );

		// fetch_page_title returns null → 404.
		Functions\expect( 'wp_remote_get' )->andReturn( array() );
		Functions\expect( 'is_wp_error' )->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 404 );

		$result = Static_Post::find_or_create( 'https://static.example.com/missing' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_not_found', $result->get_error_code() );
	}

	public function test_find_or_create_creates_new_post_when_page_exists(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );
		Functions\expect( 'site_url' )->andReturn( 'https://wp.example.com' );
		Functions\expect( 'get_posts' )->andReturn( array() );

		// Successful HTTP fetch with a title.
		Functions\expect( 'wp_remote_get' )->andReturn( array() );
		Functions\expect( 'is_wp_error' )->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->andReturn( '<html><head><title>About Us</title></head></html>' );
		Functions\expect( 'StaticSocialHub\ssh_title_first_segment' )->andReturn( false );

		Functions\expect( 'StaticSocialHub\ssh_get_default_fediverse_visibility' )
			->andReturn( 'local' );
		Functions\expect( 'wp_insert_post' )->andReturn( 77 );
		Functions\expect( 'is_wp_error' )->andReturn( false );

		$result = Static_Post::find_or_create( 'https://static.example.com/about' );

		$this->assertSame( 77, $result );
	}

	public function test_find_or_create_returns_error_when_insert_fails(): void {
		Functions\expect( 'StaticSocialHub\ssh_get_static_site_url' )
			->andReturn( 'https://static.example.com' );
		Functions\expect( 'site_url' )->andReturn( 'https://wp.example.com' );
		Functions\expect( 'get_posts' )->andReturn( array() );

		Functions\expect( 'wp_remote_get' )->andReturn( array() );
		Functions\expect( 'is_wp_error' )->andReturn( false );
		Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )
			->andReturn( '<html><head><title>About</title></head></html>' );
		Functions\expect( 'StaticSocialHub\ssh_title_first_segment' )->andReturn( false );

		Functions\expect( 'StaticSocialHub\ssh_get_default_fediverse_visibility' )
			->andReturn( 'local' );
		Functions\expect( 'wp_insert_post' )->andReturn( 0 ); // 0 = failure
		Functions\expect( 'is_wp_error' )->andReturn( false );

		$result = Static_Post::find_or_create( 'https://static.example.com/about' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ssh_create_failed', $result->get_error_code() );
	}
}
