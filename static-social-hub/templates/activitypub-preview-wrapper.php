<?php
/**
 * Wrapper for the ActivityPub Fediverse Preview page.
 *
 * Captures the original ActivityPub preview template output, then injects a
 * collapsible raw-JSON section at the bottom so developers can inspect the
 * exact ActivityPub object that would be federated.
 *
 * Loaded via the `activitypub_preview_template` filter.
 *
 * @package StaticSocialHub
 */

defined( 'ABSPATH' ) || exit;

// During preview we want to inspect the transformer output even for posts with
// 'local' content visibility (which would normally cause Factory::get_transformer()
// to return WP_Error). Temporarily override the disabled-check for static_pages.
$ssh_preview_override = function ( $disabled, $post ) {
	if ( $post instanceof \WP_Post && 'static_pages' === $post->post_type ) {
		return false;
	}
	return $disabled;
};
add_filter( 'activitypub_is_post_disabled', $ssh_preview_override, 10, 2 );

$ssh_post        = get_post();
$ssh_transformer = \Activitypub\Transformer\Factory::get_transformer( $ssh_post );

remove_filter( 'activitypub_is_post_disabled', $ssh_preview_override, 10 );

// If the transformer still failed (non-static_pages post, or other reason),
// delegate entirely to the original template which will call wp_die().
if ( is_wp_error( $ssh_transformer ) ) {
	include \ACTIVITYPUB_PLUGIN_DIR . '/templates/post-preview.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
	return;
}

$ssh_object = $ssh_transformer->to_object();
$ssh_json   = wp_json_encode(
	$ssh_object->to_array(),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

// The original template also calls Factory::get_transformer() internally.
// Add the override again for that call, then remove it after the include.
add_filter( 'activitypub_is_post_disabled', $ssh_preview_override, 10, 2 );
ob_start();
require \ACTIVITYPUB_PLUGIN_DIR . '/templates/post-preview.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
$ssh_html = ob_get_clean();
remove_filter( 'activitypub_is_post_disabled', $ssh_preview_override, 10 );

// Build the JSON panel to inject before </body>.
$ssh_json_panel = '
<style>
  .ssh-ap-json details summary { list-style: none; }
  .ssh-ap-json details summary::-webkit-details-marker { display: none; }
  .ssh-ap-json details summary::before {
    content: "\25B6";
    display: inline-block;
    margin-right: 0.5rem;
    transition: transform 0.2s ease;
  }
  .ssh-ap-json details[open] summary::before {
    transform: rotate(90deg);
  }
</style>
<section class="ssh-ap-json" style="background:#0f1621;border-top:3px solid #2b5797;font-family:monospace;">
  <details>
    <summary style="cursor:pointer;padding:0.75rem 1rem;background:#1d2945;color:#9baec8;font-size:14px;user-select:none;">
      ActivityPub JSON Object
    </summary>
    <pre style="margin:0;padding:1rem;color:#9baec8;overflow-x:auto;font-size:13px;line-height:1.5;white-space:pre;">'
	. esc_html( $ssh_json )
	. '</pre>
  </details>
</section>';

// Inject the panel immediately before </body>.
echo str_replace( '</body>', $ssh_json_panel . '</body>', $ssh_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
