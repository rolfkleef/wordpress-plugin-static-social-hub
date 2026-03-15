# Static Social Hub – Copilot Instructions

## What this plugin does

Static Social Hub bridges a static website with a WordPress backend. WordPress acts as the social hub: it stores comments, webmentions, and ActivityPub reactions (likes, boosts, replies) for static pages via a JavaScript widget. It requires the [ActivityPub](https://wordpress.org/plugins/activitypub/) and [Webmention](https://wordpress.org/plugins/webmention/) plugins to be active.

## Repository layout

The deployable plugin lives under `static-social-hub/` (the subdirectory that gets zipped for release). Everything else is dev tooling.

```
static-social-hub/           # Plugin root (deployed)
  static-social-hub.php      # Main file: constants, helper functions, bootstraps classes
  includes/
    class-static-post.php    # CPT `static_pages` + Webmention URL resolution
    class-activitypub-bridge.php  # Wires CPT into ActivityPub federation
    class-rest-api.php       # Three public REST endpoints
    class-admin-settings.php # Settings page UI and option storage
  assets/
    static-social-hub.js     # Frontend widget (vanilla JS, no build step)
    static-social-hub.css    # Widget styles (light/dark themes)
tests/
  unit/                      # Brain\Monkey mocks; no DB required
  integration/               # Real WP test framework; requires MySQL
docs/testing.md              # Full testing guide including mocking patterns
```

## Build, lint, test commands

All commands run from the repo root (`plugin-static-social-hub/`).

```bash
composer install             # Install PHP deps (required first)
composer lint                # PHP syntax check (parallel-lint)
composer cs                  # PHPCS with WordPress coding standards
composer cs:fix              # Auto-fix PHPCS violations
composer test                # Unit tests only (alias for test:unit)
composer test:unit           # Unit tests – no DB, milliseconds
composer test:coverage       # Unit tests with HTML coverage report → tmp/
composer check-all           # lint + cs + test:unit in sequence
```

Run a single test file or test method:
```bash
vendor/bin/phpunit --configuration=phpunit.unit.xml tests/unit/StaticPostTest.php
vendor/bin/phpunit --configuration=phpunit.unit.xml --filter test_is_static_url_matches_url_under_static_base
```

Integration tests require MySQL; set env vars before running:
```bash
WORDPRESS_DB_HOST=127.0.0.1 WORDPRESS_DB_USER=root WORDPRESS_DB_PASSWORD='' \
  WORDPRESS_DB_NAME=wordpress_test WP_ABSPATH=/workspace/wordpress/wp/ \
  composer test:integration
```

## Architecture: static class pattern

Every class uses a **static-only** pattern. There are no instantiated objects in normal plugin flow:

```php
class Static_Post {
    public static function init() {
        add_action('init', [self::class, 'register_post_type']);
        add_filter('webmention_post_id', [self::class, 'resolve_post_id'], 10, 2);
    }
    // All methods are public static
}
```

Bootstrap order in `static-social-hub.php`:
1. Constants defined (`SSH_VERSION`, `SSH_PLUGIN_DIR`, `SSH_PLUGIN_URL`, `SSH_REST_NAMESPACE`)
2. Helper functions defined in the `StaticSocialHub` namespace (`ssh_get_static_site_url()`, `ssh_get_cors_origin()`, etc.)
3. Classes `require_once`'d
4. `::init()` called on each class

## REST endpoints

Namespace: `static-social-hub/v1` (constant `SSH_REST_NAMESPACE`)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/reactions?url=<static-url>` | Returns comments/webmentions/ActivityPub reactions for a URL |
| `POST` | `/comments` | Submit a comment for a static page URL (pending moderation) |
| `OPTIONS` | `/reactions`, `/comments` | CORS preflight handler |

All endpoints are public (`permission_callback => '__return_true'`). Security is URL-based: requests must target the configured static site domain.

## WordPress options

| Option key | Default | Description |
|---|---|---|
| `ssh_static_site_url` | derived from `home_url()` | Base URL of the static site |
| `ssh_cors_origin` | same as static site URL | CORS `Access-Control-Allow-Origin` header |
| `ssh_title_first_segment` | `false` | Trim page titles to first path segment |
| `ssh_default_fediverse_visibility` | `'local'` | ActivityPub visibility for new static page posts |

## Custom post type: `static_pages`

Represents a static site page within WordPress so it can receive comments and be federated. Key behaviour:
- Uses `_activitypub_canonical_url` post meta to store the real static URL; ActivityPub uses this as the object `id`/`url` when federating.
- `rewrite => false` — WordPress URLs for these posts are not intended for public browsing.
- `Static_Post::find_or_create($url)` — looks up or creates a `static_pages` post for a given URL; returns post ID or `WP_Error`.

## Writing unit tests

Extend `UnitTestCase` (not `TestCase` directly). It handles Brain\Monkey lifecycle and stubs common i18n functions:

```php
use StaticSocialHub\Tests\Unit\UnitTestCase;
use Brain\Monkey\Functions;

class MyTest extends UnitTestCase {
    public function test_something(): void {
        Functions\expect('get_option')
            ->once()->with('ssh_static_site_url', '')->andReturn('https://static.example.com');

        // ...
    }
}
```

- Use `Functions\expect()` (assertion) when a WP function **must** be called.
- Use `Functions\stubs()` (passthrough) when you don't care whether it's called.
- Mock namespaced helpers with their fully qualified name: `'StaticSocialHub\ssh_get_static_site_url'`.
- Test private methods via `ReflectionMethod::setAccessible(true)` when the logic warrants it.
- `WP_Error` is stubbed in `tests/unit/bootstrap.php`; supports `get_error_code()` and `get_error_message()`.

See `docs/testing.md` for the full mocking guide.

## Coding standards

- WordPress Coding Standards (`WordPress` ruleset via `phpcs.xml.dist`)
- Text domain must be `static-social-hub` on all i18n calls
- PHP ≥ 8.1 required; all classes live in the `StaticSocialHub` namespace
- No build step for JS/CSS — edit `assets/` files directly

## Releases

Releases are automated via `semantic-release` on push to `main` (conventional commits). CI runs on PHP 8.3, 8.4, and 8.5. The release workflow zips `static-social-hub/` (prod deps only, no tests or dev files).
