<?php

namespace Tests\Unit\Services;

use App\Services\AgentWorkingMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentWorkingMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AgentWorkingMemoryService $service;

    protected string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AgentWorkingMemoryService;
        $this->userId = 'test-user-123';

        // Clear cache before each test
        Cache::flush();
    }

    #[Test]
    public function it_reports_empty_statistics_before_any_feedback(): void
    {
        $stats = $this->service->getFeedbackStatistics($this->userId);

        $this->assertEquals(0, $stats['total_feedback_count']);
        $this->assertEquals(0, $stats['rating_average']);
        $this->assertEquals(0, $stats['dismissed_count']);
        $this->assertEquals(0, $stats['acted_count']);
        $this->assertSame([], $stats['rating_distribution']);
    }

    #[Test]
    public function it_can_store_user_feedback(): void
    {
        $this->service->recordFeedback(
            $this->userId,
            'insight-123',
            'rating',
            5,
            'useful',
        );

        $stats = $this->service->getFeedbackStatistics($this->userId);

        $this->assertEquals(1, $stats['total_feedback_count']);
        $this->assertEquals(5, $stats['rating_average']);
        $this->assertEquals([5 => 1], $stats['rating_distribution']);
    }

    #[Test]
    public function it_calculates_feedback_statistics(): void
    {
        $this->service->recordFeedback($this->userId, '1', 'rating', 5);
        $this->service->recordFeedback($this->userId, '2', 'rating', 3);
        $this->service->recordFeedback($this->userId, '3', 'dismissed', true);
        $this->service->recordFeedback($this->userId, '4', 'acted', true);

        $stats = $this->service->getFeedbackStatistics($this->userId);

        $this->assertEquals(4, $stats['rating_average']); // (5 + 3) / 2
        $this->assertEquals(4, $stats['total_feedback_count']);
        $this->assertEquals(1, $stats['dismissed_count']);
        $this->assertEquals(1, $stats['acted_count']);
    }

    #[Test]
    public function it_keeps_only_the_last_hundred_feedback_items(): void
    {
        foreach (range(1, 105) as $i) {
            $this->service->recordFeedback($this->userId, (string) $i, 'acted', true);
        }

        $this->assertEquals(100, $this->service->getFeedbackStatistics($this->userId)['total_feedback_count']);
    }

    #[Test]
    public function it_keeps_feedback_isolated_per_user(): void
    {
        $this->service->recordFeedback($this->userId, '1', 'rating', 5);

        $this->assertEquals(0, $this->service->getFeedbackStatistics('someone-else')['total_feedback_count']);
    }

    #[Test]
    public function it_can_clear_all_working_memory(): void
    {
        $this->service->recordFeedback($this->userId, '1', 'rating', 5);

        $this->service->clearWorkingMemory($this->userId);

        $this->assertEquals(0, $this->service->getFeedbackStatistics($this->userId)['total_feedback_count']);
    }
}
