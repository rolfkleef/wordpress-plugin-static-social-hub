<?php

namespace StaticSocialHub\Tests\Integration;

class PluginActivationTest extends \WP_UnitTestCase {

	/**
	 * Verify the plugin is actually loaded and active.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue(
			defined( 'SSH_VERSION' ),
			'SSH_VERSION constant should be defined after plugin loads.'
		);
	}

	/**
	 * Verify the plugin constants have sensible values.
	 */
	public function test_plugin_constants(): void {
		$this->assertNotEmpty( SSH_VERSION );
		$this->assertDirectoryExists( SSH_PLUGIN_DIR );
		$this->assertStringStartsWith( 'http', SSH_PLUGIN_URL );
		$this->assertNotEmpty( SSH_REST_NAMESPACE );
	}

	/**
	 * Verify the text domain is declared in the plugin header.
	 */
	public function test_text_domain_declared(): void {
		$plugin_file = SSH_PLUGIN_DIR . 'static-social-hub.php';
		$plugin_data = get_plugin_data( $plugin_file, false, false );
		$this->assertSame(
			'static-social-hub',
			$plugin_data['TextDomain'],
			'Plugin header must declare TextDomain as static-social-hub.'
		);
	}

	/**
	 * Verify the plugins_loaded hook was triggered.
	 */
	public function test_plugins_loaded_hook_ran(): void {
		$this->assertGreaterThan(
			0,
			did_action( 'plugins_loaded' ),
			'plugins_loaded action should have fired.'
		);
	}

	/**
	 * Verify the static_pages custom post type is registered.
	 */
	public function test_static_pages_post_type_registered(): void {
		$this->assertTrue(
			post_type_exists( 'static_pages' ),
			'static_pages custom post type should be registered.'
		);
	}
}
