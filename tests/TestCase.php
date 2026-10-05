<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends FrameworkTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep model broadcasts enabled without connecting to Redis for every fixture.
        // Throttle tests can override this default with explicit expectations.
        Redis::shouldReceive('set')
            ->withArgs(fn ($key) => str_starts_with($key, 'broadcast:newevent:'))
            ->byDefault()
            ->andReturn(true);
    }
}
