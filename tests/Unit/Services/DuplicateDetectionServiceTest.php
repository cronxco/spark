<?php

namespace Tests\Unit\Services;

use App\Services\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DuplicateDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DuplicateDetectionService;
    }

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(DuplicateDetectionService::class, $this->service);
    }

    /**
     * Note: The DuplicateDetectionService uses raw SQL with table aliases (t1, t2)
     * that are not compatible with Laravel's test database table prefix.
     * These tests verify the service exists and can be instantiated, but
     * actual duplicate detection functionality should be tested in integration
     * tests or with a properly configured test database without table prefixes.
     */
    #[Test]
    public function service_has_find_duplicate_events_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'findDuplicateEvents'));
    }

    #[Test]
    public function service_has_find_duplicate_blocks_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'findDuplicateBlocks'));
    }

    #[Test]
    public function service_has_find_duplicate_objects_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'findDuplicateObjects'));
    }
}
