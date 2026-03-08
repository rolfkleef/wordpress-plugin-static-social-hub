<?php

namespace YourVendor\MyPlugin\Tests\Integration;

class PluginActivationTest extends \WP_UnitTestCase {

    /**
     * Verify the plugin is actually loaded and active.
     */
    public function test_plugin_is_loaded(): void {
        $this->assertTrue(
            defined( 'MY_PLUGIN_VERSION' ),
            'MY_PLUGIN_VERSION constant should be defined after plugin loads.'
        );
    }

    /**
     * Verify the plugin constants have sensible values.
     */
    public function test_plugin_constants(): void {
        $this->assertNotEmpty( MY_PLUGIN_VERSION );
        $this->assertFileExists( MY_PLUGIN_FILE );
        $this->assertDirectoryExists( MY_PLUGIN_DIR );
        $this->assertStringStartsWith( 'http', MY_PLUGIN_URL );
    }

    /**
     * Verify the text domain is registered.
     */
    public function test_text_domain_loaded(): void {
        $this->assertTrue(
            is_textdomain_loaded( 'my-plugin' ),
            'Text domain my-plugin should be loaded.'
        );
    }

    /**
     * Verify the plugins_loaded hook was triggered.
     * Useful baseline — add more specific hook checks as your plugin grows.
     */
    public function test_plugins_loaded_hook_ran(): void {
        $this->assertGreaterThan(
            0,
            did_action( 'plugins_loaded' ),
            'plugins_loaded action should have fired.'
        );
    }
}
