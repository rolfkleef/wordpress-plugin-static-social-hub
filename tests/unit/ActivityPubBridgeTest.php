<?php
/**
 * Unit tests for ActivityPub_Bridge.
 *
 * @package StaticSocialHub\Tests\Unit
 */

namespace StaticSocialHub\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use StaticSocialHub\ActivityPub_Bridge;

class ActivityPubBridgeTest extends UnitTestCase {

	// -------------------------------------------------------------------------
	// maybe_init
	// -------------------------------------------------------------------------

	public function test_maybe_init_does_nothing_when_activitypub_plugin_absent(): void {
		// \Activitypub\Activitypub and \Activitypub\get_plugin_version are not defined
		// in the unit test environment, so maybe_init returns early — no hooks registered.

		Filters\expectAdded( 'option_activitypub_support_post_types' )->never();
		Actions\expectAdded( 'init' )->never();

		ActivityPub_Bridge::maybe_init();

		// Brain\Monkey's tearDown() enforces the never() expectations above.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_init_registers_hooks_when_activitypub_class_exists(): void {
		// Define the class in this isolated process to simulate the plugin being active.
		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval( 'namespace Activitypub; class Activitypub {}' );

		// Brain\Monkey is set up by setUp() before this method runs.
		Filters\expectAdded( 'option_activitypub_support_post_types' )->once();
		Actions\expectAdded( 'init' )->once();

		ActivityPub_Bridge::maybe_init();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_init_registers_hooks_when_only_function_exists(): void {
		// Define only the function (no class) to exercise the function_exists() branch.
		// phpcs:ignore Squiz.PHP.Eval.Discouraged
		eval( 'namespace Activitypub; function get_plugin_version() { return "1.0.0"; }' );

		Filters\expectAdded( 'option_activitypub_support_post_types' )->once();
		Actions\expectAdded( 'init' )->once();

		ActivityPub_Bridge::maybe_init();

		$this->addToAssertionCount( 1 );
	}

	// -------------------------------------------------------------------------
	// override_ap_url
	// -------------------------------------------------------------------------

	public function test_override_ap_url_substitutes_canonical_url_for_static_pages(): void {
		$post            = new \WP_Post();
		$post->ID        = 42;
		$post->post_type = 'static_pages';

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_activitypub_canonical_url', true )
			->andReturn( 'https://static.example.com/about' );

		$result = ActivityPub_Bridge::override_ap_url( 'https://wp.example.com/?p=42', $post );

		$this->assertSame( 'https://static.example.com/about', $result );
	}

	public function test_override_ap_url_passthrough_for_other_post_types(): void {
		$post            = new \WP_Post();
		$post->ID        = 7;
		$post->post_type = 'post';

		// get_post_meta must NOT be called for unrelated post types.
		Functions\expect( 'get_post_meta' )->never();

		$result = ActivityPub_Bridge::override_ap_url( 'https://wp.example.com/?p=7', $post );

		$this->assertSame( 'https://wp.example.com/?p=7', $result );
	}

	public function test_override_ap_url_falls_back_to_original_when_meta_empty(): void {
		$post            = new \WP_Post();
		$post->ID        = 5;
		$post->post_type = 'static_pages';

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_activitypub_canonical_url', true )
			->andReturn( '' );

		$result = ActivityPub_Bridge::override_ap_url( 'https://wp.example.com/?p=5', $post );

		$this->assertSame( 'https://wp.example.com/?p=5', $result );
	}

	public function test_override_ap_url_passthrough_for_non_post_argument(): void {
		// ActivityPub passes a WP_Post but guard against unexpected types.
		$result = ActivityPub_Bridge::override_ap_url( 'https://wp.example.com/?p=1', null );

		$this->assertSame( 'https://wp.example.com/?p=1', $result );
	}

	// -------------------------------------------------------------------------
	// override_post_type_link
	// -------------------------------------------------------------------------

	public function test_override_post_type_link_substitutes_canonical_url_for_static_pages(): void {
		$post            = new \WP_Post();
		$post->ID        = 42;
		$post->post_type = 'static_pages';

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_activitypub_canonical_url', true )
			->andReturn( 'https://static.example.com/about' );

		$result = ActivityPub_Bridge::override_post_type_link( 'https://wp.example.com/?static_pages=about', $post );

		$this->assertSame( 'https://static.example.com/about', $result );
	}

	public function test_override_post_type_link_passthrough_for_other_post_types(): void {
		$post            = new \WP_Post();
		$post->ID        = 7;
		$post->post_type = 'post';

		Functions\expect( 'get_post_meta' )->never();

		$result = ActivityPub_Bridge::override_post_type_link( 'https://wp.example.com/?p=7', $post );

		$this->assertSame( 'https://wp.example.com/?p=7', $result );
	}

	public function test_override_post_type_link_falls_back_when_meta_empty(): void {
		$post            = new \WP_Post();
		$post->ID        = 5;
		$post->post_type = 'static_pages';

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_activitypub_canonical_url', true )
			->andReturn( '' );

		$result = ActivityPub_Bridge::override_post_type_link( 'https://wp.example.com/?static_pages=slug', $post );

		$this->assertSame( 'https://wp.example.com/?static_pages=slug', $result );
	}

	// -------------------------------------------------------------------------
	// add_static_page_type
	// -------------------------------------------------------------------------

	public function test_add_static_page_type_adds_when_absent(): void {
		$result = ActivityPub_Bridge::add_static_page_type( array() );

		$this->assertContains( 'static_pages', $result );
	}

	public function test_add_static_page_type_preserves_existing_types(): void {
		$result = ActivityPub_Bridge::add_static_page_type( array( 'post', 'page' ) );

		$this->assertContains( 'post', $result );
		$this->assertContains( 'page', $result );
		$this->assertContains( 'static_pages', $result );
		$this->assertCount( 3, $result );
	}

	public function test_add_static_page_type_does_not_add_duplicate(): void {
		$result = ActivityPub_Bridge::add_static_page_type( array( 'static_pages', 'post' ) );

		$this->assertSame(
			1,
			count( array_filter( $result, fn( $t ) => 'static_pages' === $t ) ),
			'static_pages must appear exactly once'
		);
	}

	public function test_add_static_page_type_returns_array(): void {
		$result = ActivityPub_Bridge::add_static_page_type( array( 'post' ) );

		$this->assertIsArray( $result );
	}
}
