# Testing the Static Social Hub Plugin

This document explains the testing strategy, how to run tests, and how WordPress
function mocking works so you can write new tests with confidence.

---

## Test types

The test suite is split into two distinct layers that serve different purposes.

### Unit tests (`tests/unit/`)

Run in plain PHP — no WordPress installation, no database. WordPress functions
(`add_action`, `get_posts`, `wp_remote_get`, …) are **intercepted by
[Brain\Monkey](https://brain-wp.github.io/BrainMonkey/)** and replaced with
lightweight fakes you control in each test.

- Fast (milliseconds per test)
- No external dependencies
- Ideal for logic, edge cases, and error paths

```bash
composer test:unit
# or directly:
vendor/bin/phpunit --configuration=phpunit.unit.xml
```

### Integration tests (`tests/integration/`)

Load the real WordPress test framework (via `wp-phpunit/wp-phpunit`) and run
against an actual MySQL database. The plugin is loaded exactly as it would be
in a live site.

- Slower (seconds per suite)
- Require a MySQL database
- Validate that the plugin wires up correctly with real WordPress hooks and CPTs

```bash
# Set database credentials first, then:
WP_DB_HOST=127.0.0.1 WP_DB_USER=root WP_DB_PASSWORD='' composer test:integration
# or:
vendor/bin/phpunit --configuration=phpunit.integration.xml
```

For local development with Docker you can set these as `.env` variables or
export them in your shell before running.

---

## How Brain\Monkey mocking works

Brain\Monkey works in two stages:

1. **`Brain\Monkey\setUp()`** — called in each test's `setUp()`. Installs
   [Patchwork](https://github.com/antecedent/patchwork) as a PHP function
   interceptor and prepares Mockery for object mocks.

2. **`Brain\Monkey\tearDown()`** — called in each test's `tearDown()`. Verifies
   that all expected calls were actually made, then cleans up so the next test
   starts fresh.

Both are handled for you automatically by extending `UnitTestCase`:

```php
use StaticSocialHub\Tests\Unit\UnitTestCase;

class MyTest extends UnitTestCase {
    public function test_something(): void {
        // Brain\Monkey is already set up here
    }
}
```

### Mocking a WordPress function

Use `Brain\Monkey\Functions\expect()` to declare that a function will be called
and what it should return:

```php
use Brain\Monkey\Functions;

// Expect exactly one call and return a value
Functions\expect('get_option')
    ->once()
    ->with('ssh_static_site_url', '')
    ->andReturn('https://static.example.com');

// Allow any number of calls
Functions\expect('esc_html')
    ->andReturn('sanitised text');
```

If a function is called but was not set up with `expect()`, Brain\Monkey throws
an exception and the test fails — so unexpected calls are caught automatically.

### Stubbing (passthrough) vs expecting (asserting)

`expect()` is an **assertion** — the test will fail if the function is not
called as specified. For helper functions you just want to work without caring
whether they are called, use `stubs()`:

```php
// Just make these work, don't assert on them
Functions\stubs([
    '__'  => fn($text) => $text,   // i18n passthrough
    'esc_html' => fn($text) => $text,
]);
```

`UnitTestCase` already stubs the common WordPress i18n functions (`__`,
`esc_html__`, `esc_attr__`, `_x`) so you don't need to repeat that.

### Mocking namespaced functions

The plugin defines its own helper functions in the `StaticSocialHub` namespace
(e.g. `ssh_get_static_site_url()`). Brain\Monkey mocks these using the fully
qualified name:

```php
Functions\expect('StaticSocialHub\ssh_get_static_site_url')
    ->andReturn('https://static.example.com');
```

### Mocking WordPress actions and filters

Brain\Monkey also intercepts `add_action()` and `add_filter()` and provides
assertions on them:

```php
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

// Assert a hook is registered
Actions\expectAdded('init')
    ->once()
    ->with(\Mockery::type('array'));

// Assert a filter is registered
Filters\expectAdded('webmention_post_id')
    ->once();
```

### Testing private methods

For private methods that contain non-trivial pure logic (like `extract_title()`),
use PHP's `ReflectionMethod` to make them accessible rather than testing them
only through their public callers:

```php
$method = new \ReflectionMethod(Static_Post::class, 'extract_title');
$method->setAccessible(true);

$result = $method->invoke(null, '<title>Hello</title>');
$this->assertSame('Hello', $result);
```

Use this sparingly — if a private method is complex enough to need its own tests,
it may be worth extracting into a small class or a standalone function.

---

## WP_Error in unit tests

WordPress is not loaded during unit tests, so `WP_Error` would be undefined.
The unit bootstrap (`tests/unit/bootstrap.php`) defines a minimal stub:

```php
$result = Static_Post::find_or_create('https://other.com/page');

$this->assertInstanceOf(\WP_Error::class, $result);
$this->assertSame('ssh_invalid_url', $result->get_error_code());
```

The stub supports `get_error_code()` and `get_error_message()`, which is
sufficient for testing the plugin's error return paths.

---

## Writing a new unit test

1. Create `tests/unit/MyClassTest.php`
2. Extend `UnitTestCase`
3. Name each test method `test_<what_it_tests>()`
4. Use `Functions\expect()` for any WordPress function your code calls
5. Run `composer test:unit` and verify it passes

```php
<?php

namespace StaticSocialHub\Tests\Unit;

use Brain\Monkey\Functions;
use StaticSocialHub\MyClass;

class MyClassTest extends UnitTestCase {

    public function test_does_the_right_thing(): void {
        Functions\expect('get_option')
            ->once()
            ->with('my_option', '')
            ->andReturn('expected-value');

        $instance = new MyClass();
        $this->assertSame('expected-value', $instance->get_setting());
    }
}
```

---

## CI

The CI workflow (`.github/workflows/ci.yml`) runs automatically on every push
and pull request:

- **Unit tests** run on PHP 8.1, 8.2, and 8.3
- **Integration tests** run on PHP 8.2 against WordPress 6.6 and `latest`
- Both include lint (`composer lint`) and coding standards (`composer cs`)

Unit tests are intentionally run across multiple PHP versions to catch
compatibility issues early. Integration tests pin to a single PHP version to
keep CI fast.
