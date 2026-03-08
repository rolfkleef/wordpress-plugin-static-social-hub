<?php

use Brain\Monkey\Functions;
use StaticSocialHub\YourClass;

class ExampleTest extends \PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        parent::setUp();
        \Brain\Monkey\setUp();
    }

    protected function tearDown(): void {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function test_something_without_wordpress(): void {
        Functions\expect( 'esc_html' )
            ->once()
            ->with( 'hello' )
            ->andReturn( 'hello' );

        // Test your class logic here
        $this->assertTrue( true );
    }
}
