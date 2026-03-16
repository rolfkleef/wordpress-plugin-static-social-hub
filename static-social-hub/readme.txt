=== Static Social Hub ===
Contributors: rolfkleef
Tags: static, activitypub, webmention, comments, widget
Stable tag: 0.0.0
Tested up to: 6.9
Requires PHP: 7.4
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Bridge between a static website and a WordPress backend as a Social Hub.
Enables comments, webmentions, and ActivityPub reactions for static pages.

== Description ==

**Static Social Hub** transforms your WordPress installation into a social backend for
your static website (e.g., Jekyll, Hugo, 11ty, Gatsby). It allows you to collect and
display social interactions on your static pages without needing a dynamic server-side
component on the static site itself.

The plugin provides a JavaScript widget that you embed on your static pages. This widget:
1.  **Displays Comments & Reactions:** Fetches and displays comments, webmentions, and
	ActivityPub reactions (likes, boosts, replies) stored in WordPress.
2.  **Enables Submission:** Provides a comment form for visitors to submit new comments
	directly to your WordPress backend.
3.  **Handles Webmentions:** Works with the Webmention plugin to receive mentions from
	across the web for your static URLs.
4.  **Connects to the Fediverse:** Works with the ActivityPub plugin to federate your
	static content and collect reactions from Mastodon and other ActivityPub platforms.

**Key Features:**
*   **JavaScript Widget:** Easily embeddable on any static site.
*   **Centralized Social Hub:** Manage all your comments and interactions in one place.
*   **Moderate Reactions:** Use Wordpress to moderate reactions and filter spam.
*   **ActivityPub Integration:** Bridge the gap between static sites and the Fediverse.
*   **Webmention Support:** Receive and display webmentions for your static articles.
*   **Customizable Appearance:** Configure widget themes (light/dark/auto) and custom
	CSS.
*   **CORS Support:** Securely allows cross-origin requests from your static site domain.

**Requirements:**
This plugin acts as a bridge and requires the following plugins to be installed and active
to function fully:
*   [ActivityPub](https://wordpress.org/plugins/activitypub/)
*   [Webmention](https://wordpress.org/plugins/webmention/)

== Installation ==

1.  Upload the `static-social-hub` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  **Important:** Ensure the ActivityPub and Webmention plugins are also installed and
	active.
4.  Go to **Settings > Static Social Hub** to configure your static site URL.
5.  Go to **Appearance > Social Hub Widget** to customize the widget appearance.
6.  Go to **Settings >Webmention** to enablewebmention support for Static Site Pages

== Configuration ==

1.  **Static Site URL:** In *Settings > Static Social Hub*, enter the base URL of your
	static website (e.g., `https://mysite.com`). This is used for CORS validation.
2.  **CORS Origin:** (Optional) If your static site is served from a different origin
	than the base URL, you can specify it here.
3.  **Widget Theme:** In *Appearance > Social Hub Widget*, choose between Light, Dark,
	or Auto (system preference) themes for the comment widget.

== Frequently Asked Questions ==

= How do I add the widget to my static site? =
After configuring the plugin, go to **Settings > Static Social Hub** and click on the
**Demo** tab. You will find the HTML snippet to include on your static site pages.

= Does this work with any static site generator? =
Yes! The plugin provides a JavaScript-based widget that is platform-agnostic. It works
with Jekyll, Hugo, Eleventy, Next.js, Gatsby, or plain HTML.

= Where are the comments stored? =
All comments and reactions are stored in your WordPress database as standard WordPress
comments, associated with a custom post type "Static Pages".

== Changelog ==

= 0.2.0 =
First release as a Zip file. This should make it possible to install the plugin on your own Wordpress website by uploading the Zip file.

- The changelog is not automatically generated yet.
- The plugin does the primary work of offering a styled widget that you can include in your static pages.
- It builds on the Webmention plugin and extends it, to register existing pages on a static site.
- These pages can receive Webmentions and ActivityPub reactions as Wordpress comments.
- Newly accepted URLs will become drafts of the custom post type Static Pages. You can use this to send out announcements about a static page: when you publish the static page, the ActivityPub plugin will take care of that.
- The WP-Admin settings page offers a few options and can show a demo and a preview of an existing page.
- The first work on testing is there.

= 0.1.0 =
*   Initial release.
*   Basic comment widget implementation.
*   Integration with ActivityPub and Webmention plugins.
*   REST API endpoints for widget communication.
