<?php
/**
 * Admin settings pages for the Static Social Hub plugin.
 *
 * Pages:
 *   Settings  → Static Social Hub    (tabs: General | Widget | Demo)
 *   Appearance → Social Hub Widget              (theme setting + CSS reference)
 *
 * Options:
 *   ssh_static_site_url  – Base URL of the static website.
 *   ssh_cors_origin      – CORS allowed origin (defaults to ssh_static_site_url).
 *   ssh_widget_theme     – JS widget colour theme: light | dark | auto.
 *
 * @package StaticSocialHub
 */

namespace StaticSocialHub;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings pages for the Static Social Hub plugin.
 */
class Admin_Settings {

	/** Admin hook suffix for the main Settings page (General + Demo tabs). */
	const HOOK_GENERAL = 'settings_page_static-social-hub';
	/** Admin hook suffix for the Appearance page under WP Appearance menu. */
	const HOOK_APPEARANCE = 'appearance_page_static-social-hub-appearance';

	/**
	 * Registers all admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( self::class, 'add_menu_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_notices', array( self::class, 'maybe_show_default_notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_preview_assets' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add_static_url_meta_box' ) );
		add_filter( 'post_row_actions', array( self::class, 'add_fediverse_preview_row_action' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Meta box
	// -------------------------------------------------------------------------

	/**
	 * Registers a read-only sidebar meta box on static_pages posts showing the
	 * static site URL as a clickable link.
	 */
	public static function add_static_url_meta_box() {
		add_meta_box(
			'ssh_static_url',
			__( 'Static Site URL', 'static-social-hub' ),
			array( self::class, 'render_static_url_meta_box' ),
			'static_pages',
			'side',
			'high'
		);
	}

	/**
	 * Renders the static URL meta box content.
	 *
	 * @param \WP_Post $post The current static_pages post object.
	 */
	public static function render_static_url_meta_box( $post ) {
		$url = get_post_meta( $post->ID, '_activitypub_canonical_url', true );
		if ( $url ) {
			echo '<p style="word-break:break-all"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></p>';
		} else {
			echo '<p><em>' . esc_html__( 'No static URL set.', 'static-social-hub' ) . '</em></p>';
		}
	}

	/**
	 * Adds a "Fediverse Preview ⁂" row action to static_pages posts in the list table.
	 *
	 * The ActivityPub plugin hides its own preview action for posts with local content
	 * visibility. Since static_pages posts are often kept local (they federate via our
	 * bridge rather than normal ActivityPub scheduling), we add the link ourselves so the
	 * JSON preview is always accessible.
	 *
	 * Hooked into post_row_actions.
	 *
	 * @param string[] $actions Existing row actions.
	 * @param \WP_Post $post    The current post object.
	 * @return string[]
	 */
	public static function add_fediverse_preview_row_action( $actions, $post ) {
		if (
			'static_pages' !== $post->post_type ||
			! in_array( $post->post_status, array( 'pending', 'draft', 'future', 'publish' ), true ) ||
			! current_user_can( 'edit_post', $post->ID )
		) {
			return $actions;
		}

		$preview_url               = add_query_arg( 'activitypub', 'true', get_preview_post_link( $post ) );
		$actions['ssh_ap_preview'] = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( $preview_url ),
			esc_html__( 'Fediverse Preview ⁂', 'static-social-hub' )
		);

		return $actions;
	}

	// -------------------------------------------------------------------------
	// Asset enqueuing
	// -------------------------------------------------------------------------

	/**
	 * Enqueues the widget JS and inline scripts on our settings pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_preview_assets( $hook ) {
		$our_hooks = array( self::HOOK_GENERAL, self::HOOK_APPEARANCE );
		if ( ! in_array( $hook, $our_hooks, true ) ) {
			return;
		}

		// Enqueue the widget JS without data-api so it skips auto-embed and only
		// registers window.SSH.mount() for use by the preview / demo scripts.
		wp_enqueue_script(
			'ssh-widget',
			SSH_PLUGIN_URL . 'assets/static-social-hub.js',
			array(),
			SSH_VERSION,
			true
		);

		wp_enqueue_style(
			'ssh-widget',
			SSH_PLUGIN_URL . 'assets/static-social-hub.css',
			array(),
			SSH_VERSION
		);

		// Build static site page list for the General page preview dropdown.
		$static_pages = get_posts(
			array(
				'post_type'      => 'static_pages',
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$post_options = array();
		foreach ( $static_pages as $pid ) {
			$static_url = get_post_meta( $pid, '_activitypub_canonical_url', true );
			if ( ! $static_url ) {
				$static_url = ssh_get_static_site_url() . get_post_field( 'post_title', $pid );
			}
			$post_options[] = array(
				'id'    => $pid,
				'url'   => $static_url,
				'title' => get_post_field( 'post_title', $pid ),
			);
		}

		wp_add_inline_script(
			'ssh-widget',
			'window.SSH_ADMIN = ' . wp_json_encode(
				array(
					'apiBase'           => rest_url( SSH_REST_NAMESPACE ),
					'theme'             => get_option( 'ssh_widget_theme', 'auto' ),
					'staticPages'       => $post_options,
					'staticBase'        => ssh_get_static_site_url(),
					'demoUrl'           => ssh_get_static_site_url() . '/demo-page',
					'demoData'          => self::get_demo_reactions(),
					'mastodonShareText' => ssh_get_mastodon_share_text(),
					'i18n'              => array(
						'loadPreview'    => __( 'Load Preview', 'static-social-hub' ),
						'loading'        => __( 'Loading…', 'static-social-hub' ),
						'noStaticPages'  => __( 'No static site pages found.', 'static-social-hub' ),
						'customUrlLabel' => __( 'Or enter a static page URL:', 'static-social-hub' ),
						'selectPage'     => __( '— select a static site page —', 'static-social-hub' ),
					),
				)
			) . ';',
			'before'
		);

		// Preview controller (Widget tab) and demo mount (Demo tab) both live on
		// HOOK_GENERAL (same page, different ?tab= params) — load both eagerly.
		if ( self::HOOK_GENERAL === $hook ) {
			wp_add_inline_script( 'ssh-widget', self::get_preview_controller_js(), 'after' );
			wp_add_inline_script( 'ssh-widget', self::get_demo_controller_js(), 'after' );
		}
	}

	/**
	 * Returns the inline JS that powers the admin preview panel.
	 *
	 * @return string
	 */
	private static function get_preview_controller_js() {
		return <<<'JS'
(function () {
  var cfg   = window.SSH_ADMIN || {};
  var i18n  = cfg.i18n || {};
  var panel = document.getElementById('ssh-preview-panel');
  if (!panel) { return; }

  var selectEl  = document.getElementById('ssh-preview-select');
  var customEl  = document.getElementById('ssh-preview-custom-url');
  var btnEl     = document.getElementById('ssh-preview-btn');
  var outputEl  = document.getElementById('ssh-preview-output');
  var themeEl   = document.getElementById('ssh-preview-theme');

  if (!btnEl || !outputEl) { return; }

  btnEl.addEventListener('click', function () {
    var url = '';
    if (selectEl && selectEl.value) {
      url = selectEl.value;
    }
    if (customEl && customEl.value.trim()) {
      url = customEl.value.trim();
    }
    if (!url) {
      outputEl.innerHTML = '<p style="color:#c62828;margin:0">' +
        (i18n.selectPage || 'Please select a page or enter a URL.') + '</p>';
      return;
    }

    var theme = (themeEl && themeEl.value) ? themeEl.value : (cfg.theme || 'auto');

    outputEl.id = 'ssh-preview-output';
    if (window.SSH && window.SSH.mount) {
      window.SSH.mount(outputEl, url, cfg.apiBase, theme, true);
    } else {
      outputEl.innerHTML = '<p>SDB widget not loaded.</p>';
    }

    // Show the URL being previewed.
    var urlInfo = document.getElementById('ssh-preview-url-info');
    if (urlInfo) { urlInfo.textContent = url; }
    panel.style.display = 'block';
  });

  // Allow pressing Enter in the custom URL field to trigger preview.
  if (customEl) {
    customEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); btnEl.click(); }
    });
  }

  // When select changes, clear the custom URL field (and vice versa).
  if (selectEl) {
    selectEl.addEventListener('change', function () {
      if (selectEl.value && customEl) { customEl.value = ''; }
    });
  }
  if (customEl) {
    customEl.addEventListener('input', function () {
      if (customEl.value.trim() && selectEl) { selectEl.value = ''; }
    });
  }
})();
JS;
	}

	// -------------------------------------------------------------------------
	// Menu & page
	// -------------------------------------------------------------------------

	/**
	 * Registers the plugin options page under WordPress Settings menu.
	 */
	public static function add_menu_page() {
		add_options_page(
			__( 'Static Social Hub', 'static-social-hub' ),
			__( 'Static Social Hub', 'static-social-hub' ),
			'manage_options',
			'static-social-hub',
			array( self::class, 'render_settings_page' )
		);
	}

	/**
	 * Registers all plugin settings, sections, and fields.
	 */
	public static function register_settings() {
		// ---- General settings -----------------------------------------------
		register_setting(
			'ssh_settings',
			'ssh_static_site_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_url_option' ),
				'default'           => '',
			)
		);

		register_setting(
			'ssh_settings',
			'ssh_cors_origin',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_url_option' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'ssh_main_section',
			__( 'General Settings', 'static-social-hub' ),
			array( self::class, 'render_section_description' ),
			'static-social-hub'
		);

		add_settings_field(
			'ssh_static_site_url',
			__( 'Static site URL', 'static-social-hub' ),
			array( self::class, 'render_static_site_url_field' ),
			'static-social-hub',
			'ssh_main_section'
		);

		add_settings_field(
			'ssh_cors_origin',
			__( 'CORS allowed origin', 'static-social-hub' ),
			array( self::class, 'render_cors_origin_field' ),
			'static-social-hub',
			'ssh_main_section'
		);

		register_setting(
			'ssh_settings',
			'ssh_mastodon_share_text',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_field(
			'ssh_mastodon_share_text',
			__( 'Mastodon share text', 'static-social-hub' ),
			array( self::class, 'render_mastodon_share_text_field' ),
			'static-social-hub',
			'ssh_main_section'
		);

		// ---- New static page settings ---------------------------------------
		register_setting(
			'ssh_settings',
			'ssh_title_first_segment',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( self::class, 'sanitize_checkbox_option' ),
				'default'           => false,
			)
		);

		register_setting(
			'ssh_settings',
			'ssh_default_fediverse_visibility',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_fediverse_visibility_option' ),
				'default'           => 'local',
			)
		);

		add_settings_section(
			'ssh_new_page_section',
			__( 'New Static Page Defaults', 'static-social-hub' ),
			array( self::class, 'render_new_page_section_description' ),
			'static-social-hub'
		);

		add_settings_field(
			'ssh_title_first_segment',
			__( 'Trim page titles', 'static-social-hub' ),
			array( self::class, 'render_title_first_segment_field' ),
			'static-social-hub',
			'ssh_new_page_section'
		);

		add_settings_field(
			'ssh_default_fediverse_visibility',
			__( 'Fediverse visibility', 'static-social-hub' ),
			array( self::class, 'render_default_fediverse_visibility_field' ),
			'static-social-hub',
			'ssh_new_page_section'
		);

		// ---- Appearance settings --------------------------------------------
		register_setting(
			'ssh_appearance_settings',
			'ssh_widget_theme',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_theme_option' ),
				'default'           => 'auto',
			)
		);

		add_settings_section(
			'ssh_appearance_section',
			__( 'Styling', 'static-social-hub' ),
			array( self::class, 'render_appearance_section_description' ),
			'static-social-hub-appearance'
		);

		add_settings_field(
			'ssh_widget_theme',
			__( 'Default theme', 'static-social-hub' ),
			array( self::class, 'render_widget_theme_field' ),
			'static-social-hub-appearance',
			'ssh_appearance_section'
		);
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the main settings page (General, Widget, Demo tabs).
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Determine active tab (default: general).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab_input  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$active_tab = in_array( $tab_input, array( 'general', 'widget', 'demo' ), true ) ? $tab_input : 'general';

		$base_url   = admin_url( 'options-general.php?page=static-social-hub' );
		$widget_url = esc_url( SSH_PLUGIN_URL . 'assets/static-social-hub.js' );
		$api_base   = esc_url( rest_url( SSH_REST_NAMESPACE ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Static Social Hub', 'static-social-hub' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings tabs', 'static-social-hub' ); ?>">
				<a href="<?php echo esc_url( $base_url . '&tab=general' ); ?>"
					class="nav-tab<?php echo 'general' === $active_tab ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General', 'static-social-hub' ); ?>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=widget' ); ?>"
					class="nav-tab<?php echo 'widget' === $active_tab ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Widget', 'static-social-hub' ); ?>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=demo' ); ?>"
					class="nav-tab<?php echo 'demo' === $active_tab ? ' nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Demo', 'static-social-hub' ); ?>
				</a>
			</nav>

			<?php if ( 'general' === $active_tab ) : ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'ssh_settings' );
				do_settings_sections( 'static-social-hub' );
				submit_button();
				?>
			</form>

			<?php elseif ( 'widget' === $active_tab ) : ?>
				<?php $css_url = esc_url( SSH_PLUGIN_URL . 'assets/static-social-hub.css' ); ?>

			<h2><?php esc_html_e( 'Embed Code', 'static-social-hub' ); ?></h2>
			<p><?php esc_html_e( 'Add the following snippet to any static page where you want comments and reactions to appear:', 'static-social-hub' ); ?></p>
			<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- display-only embed example ?>
			<pre style="background:#f6f7f7;padding:12px;overflow:auto;"><code>&lt;link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>"&gt;
&lt;div id="ssh-comments"&gt;&lt;/div&gt;
&lt;script src="<?php echo esc_url( $widget_url ); ?>"
		data-api="<?php echo esc_url( $api_base ); ?>"&gt;&lt;/script&gt;</code></pre>
			<p class="description">
				<?php
				printf(
					/* translators: %s: REST API base URL */
					esc_html__( 'The widget auto-detects the current page URL. The REST API is available at %s.', 'static-social-hub' ),
					'<code>' . esc_url( $api_base ) . '</code>'
				);
				?>
			</p>

			<hr>
				<?php self::render_preview_section(); ?>

			<hr>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'ssh_appearance_settings' );
				do_settings_sections( 'static-social-hub-appearance' );
				submit_button();
				?>
			</form>

			<?php else : // Demo tab. ?>

				<?php self::render_demo_tab(); ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the Appearance settings page.
	 */
	public static function render_appearance_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Social Hub Widget', 'static-social-hub' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'ssh_appearance_settings' );
				do_settings_sections( 'static-social-hub-appearance' );
				submit_button();
				?>
			</form>

			</div>
		<?php
	}

	/**
	 * Renders the Demo tab content: a mock static article with the full widget
	 * pre-populated with sample reactions. The comment form submits real comments.
	 */
	private static function render_demo_tab() {
		$demo_url = ssh_get_static_site_url() . '/demo-page';
		?>
		<div class="notice notice-info inline" style="margin:1.5em 0 1.5em;padding:.75em 1em;">
			<p>
				<strong><?php esc_html_e( 'About this demo:', 'static-social-hub' ); ?></strong>
				<?php
				printf(
					wp_kses(
						/* translators: %s: demo URL */
						__( 'The reactions (likes, boosts, replies, webmentions, and comments) shown below are sample data so you can see how the widget looks. The comment form is live — submissions create real pending comments in WordPress for the demo URL %s.', 'static-social-hub' ),
						array( 'code' => array() )
					),
					'<code>' . esc_html( $demo_url ) . '</code>'
				);
				?>
			</p>
		</div>

		<?php self::render_mock_article( $demo_url ); ?>
		<?php
	}

	/**
	 * Renders a simulated static article page with the embedded widget.
	 *
	 * @param string $demo_url Static page URL used for the comment form submission target.
	 */
	private static function render_mock_article( $demo_url ) {
		?>
		<div id="ssh-demo-page" style="
			max-width: 720px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 6px;
			overflow: hidden;
			box-shadow: 0 2px 8px rgba(0,0,0,.07);
			margin-bottom: 2em;
		">
			<!-- Simulated browser chrome -->
			<div style="background:#f0f0f0;padding:.55em .9em;display:flex;align-items:center;gap:.6em;border-bottom:1px solid #ddd;">
				<span style="width:12px;height:12px;border-radius:50%;background:#ff5f57;display:inline-block;"></span>
				<span style="width:12px;height:12px;border-radius:50%;background:#febc2e;display:inline-block;"></span>
				<span style="width:12px;height:12px;border-radius:50%;background:#28c840;display:inline-block;"></span>
				<span style="flex:1;background:#fff;border:1px solid #ccc;border-radius:4px;padding:.2em .6em;font-size:.82em;color:#555;margin-left:.4em;">
					<?php echo esc_html( $demo_url ); ?>
				</span>
			</div>

			<!-- Simulated article content -->
			<article style="padding:2em 2.25em 0;font-family:Georgia,serif;line-height:1.7;color:#222;">
				<header style="margin-bottom:1.5em;">
					<h1 style="font-size:1.9em;margin:0 0 .3em;"><?php esc_html_e( 'A Quiet Morning Walk', 'static-social-hub' ); ?></h1>
					<p style="color:#888;font-size:.9em;margin:0;">
						<?php esc_html_e( 'Published', 'static-social-hub' ); ?> &middot;
						<time datetime="2025-03-01">March 1, 2025</time>
					</p>
				</header>

				<p><?php esc_html_e( 'There is something uniquely restorative about stepping outside before the rest of the world has fully woken. The streets are empty, the air carries a cool freshness that disappears by mid-morning, and the only sounds are birdsong and the distant rumble of an early bus.', 'static-social-hub' ); ?></p>

				<p><?php esc_html_e( 'I have been taking this walk for three years now — roughly the same route, loosely the same time — and each day I notice something new. A garden that has slowly turned its front lawn into a wildflower meadow. A chalk drawing left by a child that lasted, somehow, an entire month before the rain took it.', 'static-social-hub' ); ?></p>

				<p><?php esc_html_e( 'This blog exists as an extension of those walks: a place to notice things, write them down, and see what others make of them. Scroll down to read what people have said, or leave your own thought.', 'static-social-hub' ); ?></p>
			</article>

			<!-- Widget area (injected by JS) -->
			<div id="ssh-demo-widget" style="padding:1.5em 2.25em 2em;"></div>
		</div>

		<script>
		(function () {
			function mount() {
				// Read SSH_ADMIN here (not at IIFE time) so footer scripts have already run.
				var cfg = window.SSH_ADMIN || {};
				var el = document.getElementById('ssh-demo-widget');
				if (!el || !window.SSH || !window.SSH.mount) { return; }
				el.id = 'ssh-demo-widget'; // keep id stable
				window.SSH.mount(
					el,
					cfg.demoUrl || '<?php echo esc_js( $demo_url ); ?>',
					cfg.apiBase || '',
					cfg.theme || 'auto',
					false,          // preview = false → comment form submits for real
					cfg.demoData    // skip fetchReactions; render this sample data instead
				);
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', mount);
			} else {
				mount();
			}
		})();
		</script>
		<?php
	}


	/**
	 * Outputs the description for the General settings section.
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'Configure how the Static Social Hub connects your WordPress backend to your static website.', 'static-social-hub' ) . '</p>';
	}

	/**
	 * Outputs the description for the Appearance settings section.
	 */
	public static function render_appearance_section_description() {
		echo '<p>' . esc_html__( 'Control the default visual theme for the embedded widget.', 'static-social-hub' ) . '</p>';
	}

	/**
	 * Renders the static site URL input field.
	 */
	public static function render_static_site_url_field() {
		$value   = get_option( 'ssh_static_site_url', '' );
		$default = ssh_get_static_site_url();
		?>
		<input type="url" name="ssh_static_site_url" id="ssh_static_site_url"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="<?php echo esc_attr( $default ); ?>"
				class="regular-text">
		<p class="description">
			<?php
			printf(
				/* translators: %s: auto-detected URL */
				esc_html__( 'Base URL of your static website (scheme + host only, no trailing slash). Leave blank to auto-detect: %s', 'static-social-hub' ),
				'<code>' . esc_html( $default ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the CORS allowed origin input field.
	 */
	public static function render_cors_origin_field() {
		$value   = get_option( 'ssh_cors_origin', '' );
		$default = ssh_get_static_site_url();
		?>
		<input type="url" name="ssh_cors_origin" id="ssh_cors_origin"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="<?php echo esc_attr( $default ); ?>"
				class="regular-text">
		<p class="description">
			<?php esc_html_e( 'The origin that is allowed to make cross-origin requests to the REST API. Defaults to the static site URL above.', 'static-social-hub' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the Mastodon share text input field.
	 */
	public static function render_mastodon_share_text_field() {
		$value = get_option( 'ssh_mastodon_share_text', '' );
		?>
		<input type="text" name="ssh_mastodon_share_text" id="ssh_mastodon_share_text"
				value="<?php echo esc_attr( $value ); ?>"
				class="regular-text"
				placeholder="<?php esc_attr_e( 'e.g. via @you@example.social', 'static-social-hub' ); ?>">
		<p class="description">
			<?php esc_html_e( 'Optional text appended to the page URL in Mastodon share links (e.g. a handle or hashtag). Leave blank to share the URL only.', 'static-social-hub' ); ?>
		</p>
		<?php
	}

	/**
	 * Outputs the description for the New Static Page Defaults settings section.
	 */
	public static function render_new_page_section_description() {
		echo '<p>' . esc_html__( 'Settings applied whenever a new static page post is created automatically (via webmention or the widget).', 'static-social-hub' ) . '</p>';
	}

	/**
	 * Renders the "trim page titles" checkbox field.
	 */
	public static function render_title_first_segment_field() {
		$value = get_option( 'ssh_title_first_segment', false );
		?>
		<input type="checkbox" name="ssh_title_first_segment" id="ssh_title_first_segment"
				value="1" <?php checked( $value, 1 ); ?>>
		<label for="ssh_title_first_segment">
			<?php esc_html_e( 'Use only the first segment of the HTML &lt;title&gt; when creating static pages from existing URLs', 'static-social-hub' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Many page titles include the site name (e.g. "My Post – My Site"). Enable this to strip everything after the first separator (–, —, -, |, :) and keep only the page-specific part.', 'static-social-hub' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the default fediverse visibility select field.
	 */
	public static function render_default_fediverse_visibility_field() {
		$value   = get_option( 'ssh_default_fediverse_visibility', '' );
		$options = array(
			''             => __( 'Public', 'static-social-hub' ),
			'quiet_public' => __( 'Quiet public (federated but not boosted)', 'static-social-hub' ),
			'local'        => __( 'Do not federate', 'static-social-hub' ),
		);
		?>
		<select name="ssh_default_fediverse_visibility" id="ssh_default_fediverse_visibility">
			<?php foreach ( $options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Fediverse visibility set on newly created static page posts. Can be changed per-post in the Fediverse ⁂ sidebar panel.', 'static-social-hub' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the widget theme select field.
	 */
	public static function render_widget_theme_field() {
		$value   = get_option( 'ssh_widget_theme', 'auto' );
		$options = array(
			'auto'  => __( 'Auto (follows system preference)', 'static-social-hub' ),
			'light' => __( 'Light', 'static-social-hub' ),
			'dark'  => __( 'Dark', 'static-social-hub' ),
		);
		?>
		<select name="ssh_widget_theme" id="ssh_widget_theme">
			<?php foreach ( $options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	// -------------------------------------------------------------------------
	// Preview section
	// -------------------------------------------------------------------------

	/**
	 * Renders the widget preview section on the settings page.
	 * The actual mounting is handled by the inline JS from get_preview_controller_js().
	 */
	public static function render_preview_section() {
		$static_pages = get_posts(
			array(
				'post_type'      => 'static_pages',
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$theme_value   = get_option( 'ssh_widget_theme', 'auto' );
		$theme_options = array(
			'auto'  => __( 'Auto', 'static-social-hub' ),
			'light' => __( 'Light', 'static-social-hub' ),
			'dark'  => __( 'Dark', 'static-social-hub' ),
		);
		?>
		<h2><?php esc_html_e( 'Widget Preview', 'static-social-hub' ); ?></h2>
		<p><?php esc_html_e( 'Select a static site page post or enter a URL to see how the widget would look for that page.', 'static-social-hub' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="ssh-preview-select"><?php esc_html_e( 'Static site page', 'static-social-hub' ); ?></label>
				</th>
				<td>
					<?php if ( empty( $static_pages ) ) : ?>
						<p class="description">
							<?php esc_html_e( 'No static site pages found yet. They are created automatically when a webmention or comment targeting a static URL is received.', 'static-social-hub' ); ?>
						</p>
					<?php else : ?>
						<select id="ssh-preview-select" style="max-width:100%;min-width:300px;">
							<option value=""><?php esc_html_e( '— select a static site page —', 'static-social-hub' ); ?></option>
							<?php
							foreach ( $static_pages as $pid ) :
								$static_url = get_post_meta( $pid, '_activitypub_canonical_url', true );
								if ( ! $static_url ) {
									$static_url = ssh_get_static_site_url() . get_post_field( 'post_title', $pid );
								}
								?>
								<option value="<?php echo esc_attr( $static_url ); ?>">
									<?php echo esc_html( get_post_field( 'post_title', $pid ) ); ?>
									&nbsp;(<?php echo esc_html( $static_url ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Choosing a static site page will pre-fill the URL below.', 'static-social-hub' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ssh-preview-custom-url"><?php esc_html_e( 'URL to preview', 'static-social-hub' ); ?></label>
				</th>
				<td>
					<input type="url" id="ssh-preview-custom-url"
							placeholder="<?php echo esc_attr( ssh_get_static_site_url() . '/example-post' ); ?>"
							class="regular-text">
					<p class="description"><?php esc_html_e( 'Enter any static page URL, or select a static site page above.', 'static-social-hub' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ssh-preview-theme"><?php esc_html_e( 'Theme', 'static-social-hub' ); ?></label>
				</th>
				<td>
					<select id="ssh-preview-theme">
						<?php foreach ( $theme_options as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $theme_value, $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<p>
			<button id="ssh-preview-btn" class="button button-primary">
				<?php esc_html_e( 'Load Preview', 'static-social-hub' ); ?>
			</button>
		</p>

		<div id="ssh-preview-panel" style="display:none;margin-top:1em;">
			<div style="background:#f0f0f0;padding:.5em .75em;border-radius:4px 4px 0 0;font-size:.85em;color:#555;border:1px solid #ddd;border-bottom:0;">
				<?php esc_html_e( 'Previewing:', 'static-social-hub' ); ?>
				<strong id="ssh-preview-url-info" style="word-break:break-all;"></strong>
			</div>
			<div id="ssh-preview-output"
				style="border:1px solid #ddd;border-radius:0 0 4px 4px;padding:1.25em;background:#fff;min-height:80px;">
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// CSS documentation section
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Demo data & JS controller
	// -------------------------------------------------------------------------

	/**
	 * Returns the inline JS that mounts the widget on the Demo page.
	 * (The actual mount call is inlined directly in render_mock_article(); this
	 * hook is kept for any future demo-page-specific scripting.)
	 *
	 * @return string
	 */
	private static function get_demo_controller_js() {
		return '/* Demo page controller is inlined in render_mock_article(). */';
	}

	/**
	 * Returns a realistic set of fake reactions to pre-populate the Demo page widget.
	 *
	 * Shape mirrors the REST API response from GET /reactions so the JS widget
	 * renders it identically to real data.
	 *
	 * @return array
	 */
	public static function get_demo_reactions() {
		$av = function ( $seed ) {
			return 'https://www.gravatar.com/avatar/' . md5( $seed ) . '?d=identicon&s=48&r=g';
		};

		return array(
			'url'                 => '',
			'post_id'             => null,
			'mastodon_share_text' => ssh_get_mastodon_share_text(),

			'likes'               => array(
				array(
					'id'            => 'demo-l1',
					'author'        => 'Alice',
					'author_url'    => 'https://mastodon.social/@alice',
					'author_avatar' => $av( 'alice@mastodon.social' ),
					'date'          => '2025-02-10T09:14:00',
				),
				array(
					'id'            => 'demo-l2',
					'author'        => 'Bob',
					'author_url'    => 'https://fosstodon.org/@bob',
					'author_avatar' => $av( 'bob@fosstodon.org' ),
					'date'          => '2025-02-10T09:31:00',
				),
				array(
					'id'            => 'demo-l3',
					'author'        => 'Charlie',
					'author_url'    => 'https://social.coop/@charlie',
					'author_avatar' => $av( 'charlie@social.coop' ),
					'date'          => '2025-02-10T10:02:00',
				),
				array(
					'id'            => 'demo-l4',
					'author'        => 'Diana',
					'author_url'    => 'https://hachyderm.io/@diana',
					'author_avatar' => $av( 'diana@hachyderm.io' ),
					'date'          => '2025-02-11T08:45:00',
				),
				array(
					'id'            => 'demo-l5',
					'author'        => 'Eve',
					'author_url'    => 'https://mastodon.online/@eve',
					'author_avatar' => $av( 'eve@mastodon.online' ),
					'date'          => '2025-02-11T11:20:00',
				),
				array(
					'id'            => 'demo-l6',
					'author'        => 'Frank',
					'author_url'    => 'https://infosec.exchange/@frank',
					'author_avatar' => $av( 'frank@infosec.exchange' ),
					'date'          => '2025-02-12T07:05:00',
				),
				array(
					'id'            => 'demo-l7',
					'author'        => 'Grace',
					'author_url'    => 'https://techhub.social/@grace',
					'author_avatar' => $av( 'grace@techhub.social' ),
					'date'          => '2025-02-12T14:33:00',
				),
				array(
					'id'            => 'demo-l8',
					'author'        => 'Hank',
					'author_url'    => 'https://kolektiva.social/@hank',
					'author_avatar' => $av( 'hank@kolektiva.social' ),
					'date'          => '2025-02-13T10:10:00',
				),
				array(
					'id'            => 'demo-l9',
					'author'        => 'Iris',
					'author_url'    => 'https://mastodon.social/@iris',
					'author_avatar' => $av( 'iris@mastodon.social' ),
					'date'          => '2025-02-14T16:50:00',
				),
				array(
					'id'            => 'demo-l10',
					'author'        => 'Jack',
					'author_url'    => 'https://fosstodon.org/@jack',
					'author_avatar' => $av( 'jack@fosstodon.org' ),
					'date'          => '2025-02-15T09:22:00',
				),
			),

			'boosts'              => array(
				array(
					'id'            => 'demo-b1',
					'author'        => 'Kate',
					'author_url'    => 'https://mastodon.social/@kate',
					'author_avatar' => $av( 'kate@mastodon.social' ),
					'date'          => '2025-02-10T09:45:00',
				),
				array(
					'id'            => 'demo-b2',
					'author'        => 'Liam',
					'author_url'    => 'https://social.coop/@liam',
					'author_avatar' => $av( 'liam@social.coop' ),
					'date'          => '2025-02-11T13:00:00',
				),
				array(
					'id'            => 'demo-b3',
					'author'        => 'Mia',
					'author_url'    => 'https://hachyderm.io/@mia',
					'author_avatar' => $av( 'mia@hachyderm.io' ),
					'date'          => '2025-02-12T08:17:00',
				),
				array(
					'id'            => 'demo-b4',
					'author'        => 'Noah',
					'author_url'    => 'https://fosstodon.org/@noah',
					'author_avatar' => $av( 'noah@fosstodon.org' ),
					'date'          => '2025-02-13T17:30:00',
				),
				array(
					'id'            => 'demo-b5',
					'author'        => 'Olivia',
					'author_url'    => 'https://mastodon.online/@olivia',
					'author_avatar' => $av( 'olivia@mastodon.online' ),
					'date'          => '2025-02-14T11:55:00',
				),
				array(
					'id'            => 'demo-b6',
					'author'        => 'Pete',
					'author_url'    => 'https://infosec.exchange/@pete',
					'author_avatar' => $av( 'pete@infosec.exchange' ),
					'date'          => '2025-02-15T14:08:00',
				),
			),

			'replies'             => array(
				array(
					'id'            => 'demo-r1',
					'author'        => 'Quinn',
					'author_url'    => 'https://mastodon.social/@quinn',
					'author_avatar' => $av( 'quinn@mastodon.social' ),
					'date'          => '2025-02-10T10:30:00',
					'source'        => 'https://mastodon.social/@quinn/113982741000000001',
					'content'       => 'Really appreciated this post. The observation about the chalk drawing lasting a whole month despite the rain was unexpectedly moving.',
				),
				array(
					'id'            => 'demo-r2',
					'author'        => 'Rose',
					'author_url'    => 'https://social.coop/@rose',
					'author_avatar' => $av( 'rose@social.coop' ),
					'date'          => '2025-02-12T15:44:00',
					'source'        => 'https://social.coop/@rose/113997830000000002',
					'content'       => 'This reminded me of something Robert Macfarlane wrote about attention and slowness. Have you read The Wild Places?',
				),
				array(
					'id'            => 'demo-r3',
					'author'        => 'Sam',
					'author_url'    => 'https://hachyderm.io/@sam',
					'author_avatar' => $av( 'sam@hachyderm.io' ),
					'date'          => '2025-02-15T08:12:00',
					'source'        => 'https://hachyderm.io/@sam/114013200000000003',
					'content'       => 'I started doing this after reading your post — early morning walk, same route each day. It changes how you notice things.',
				),
			),

			'webmentions'         => array(
				array(
					'id'            => 'demo-w1',
					'author'        => "Taylor's Notes",
					'author_url'    => 'https://example.blog/taylor',
					'author_avatar' => $av( 'taylor@example.blog' ),
					'date'          => '2025-02-13T09:00:00',
					'content'       => 'I found this post very useful and wrote a short follow-up about maintaining a walking habit through winter.',
					'source'        => 'https://example.blog/taylor/winter-walks',
				),
				array(
					'id'            => 'demo-w2',
					'author'        => 'Dev Notes',
					'author_url'    => 'https://devnotes.example.com',
					'author_avatar' => $av( 'editor@devnotes.example.com' ),
					'date'          => '2025-02-16T11:30:00',
					'content'       => 'Mentioned in our weekly linklog. A lovely example of writing that is also quietly about attention.',
					'source'        => 'https://devnotes.example.com/linklog/2025-02-16',
				),
			),

			'comments'            => array(
				array(
					'id'            => 'demo-c1',
					'author'        => 'Jordan',
					'author_url'    => 'https://jordan.example.net',
					'author_avatar' => $av( 'jordan@example.net' ),
					'date'          => '2025-02-11T14:22:00',
					'content'       => 'This is beautifully written. The wildflower meadow detail — a garden turning itself over slowly — really stuck with me.',
				),
				array(
					'id'            => 'demo-c2',
					'author'        => 'Morgan',
					'author_url'    => '',
					'author_avatar' => $av( 'morgan@example.com' ),
					'date'          => '2025-02-14T19:08:00',
					'content'       => "I tried this same route idea and you're right — it completely changes the experience once you stop trying to cover new ground and just pay attention.",
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Sanitizers
	// -------------------------------------------------------------------------

	/**
	 * Sanitizes a URL option value, keeping only scheme + host.
	 *
	 * @param string $value Raw option value to sanitize.
	 * @return string Sanitized URL or empty string.
	 */
	public static function sanitize_url_option( $value ) {
		$value = esc_url_raw( trim( $value ) );
		// Strip path — we only want scheme + host.
		if ( $value ) {
			$parsed = wp_parse_url( $value );
			if ( ! empty( $parsed['host'] ) ) {
				$clean = $parsed['scheme'] . '://' . $parsed['host'];
				if ( ! empty( $parsed['port'] ) ) {
					$clean .= ':' . $parsed['port'];
				}
				return $clean;
			}
		}
		return '';
	}

	/**
	 * Sanitizes a checkbox option to 1 or 0.
	 *
	 * @param mixed $value Raw option value to sanitize.
	 * @return int 1 if truthy, 0 otherwise.
	 */
	public static function sanitize_checkbox_option( $value ) {
		return $value ? 1 : 0;
	}

	/**
	 * Sanitizes the fediverse visibility option to an allowed value.
	 *
	 * @param string $value Raw option value to sanitize.
	 * @return string One of: '', 'quiet_public', 'local'.
	 */
	public static function sanitize_fediverse_visibility_option( $value ) {
		return in_array( $value, array( '', 'quiet_public', 'local' ), true ) ? $value : 'local';
	}

	/**
	 * Sanitizes the widget theme option to an allowed value.
	 *
	 * @param string $value Raw option value to sanitize.
	 * @return string One of: 'light', 'dark', 'auto'.
	 */
	public static function sanitize_theme_option( $value ) {
		return in_array( $value, array( 'light', 'dark', 'auto' ), true ) ? $value : 'auto';
	}

	// -------------------------------------------------------------------------
	// Notices
	// -------------------------------------------------------------------------

	/**
	 * Shows an admin notice if the static site URL is using the auto-detected default.
	 */
	public static function maybe_show_default_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$our_screens = array( self::HOOK_GENERAL, self::HOOK_APPEARANCE );
		if ( ! in_array( $screen->id, $our_screens, true ) ) {
			return;
		}
		if ( get_option( 'ssh_static_site_url', '' ) ) {
			return;
		}
		$default = ssh_get_static_site_url();
		echo '<div class="notice notice-info is-dismissible"><p>';
		printf(
			/* translators: %s: auto-detected URL */
			esc_html__( 'Static Social Hub is using auto-detected static site URL: %s. You can override this below.', 'static-social-hub' ),
			'<strong>' . esc_html( $default ) . '</strong>'
		);
		echo '</p></div>';
	}
}
