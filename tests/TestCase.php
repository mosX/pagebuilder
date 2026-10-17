<?php

namespace Tests;

use App\Support\Site\Scheduling;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Behave like the SPA: Sanctum treats requests from a stateful domain as session-authenticated.
        $this->withHeader('Origin', 'http://localhost');

        // Company ids repeat between tests: remembered time zones must not leak.
        Scheduling::forget();
    }
}
