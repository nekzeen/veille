<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_runs_in_testing_environment(): void
    {
        $this->assertSame('testing', app()->environment());
    }
}
