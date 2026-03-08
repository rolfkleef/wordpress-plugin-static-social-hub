<?php
/**
 * Base class for all unit tests.
 *
 * Handles Brain\Monkey lifecycle and stubs common WordPress functions so tests
 * do not need to repeat boilerplate. Extend this instead of TestCase directly.
 *
 * @package StaticSocialHub\Tests\Unit
 */

namespace StaticSocialHub\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

abstract class UnitTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub WordPress i18n functions so string-returning calls work without WP.
		Functions\stubs(
			array(
				'__'         => fn( $text ) => $text,
				'esc_html__' => fn( $text ) => $text,
				'esc_attr__' => fn( $text ) => $text,
				'_x'         => fn( $text ) => $text,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
