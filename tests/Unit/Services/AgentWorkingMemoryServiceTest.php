<?php

namespace Tests\Unit\Services;

use App\Services\AgentWorkingMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /** @test */
    public function it_initializes_working_memory_with_default_structure()
    {
        $memory = $this->service->getWorkingMemory($this->userId);

        $this->assertIsArray($memory);
        $this->assertArrayHasKey('domain_insights', $memory);
        $this->assertArrayHasKey('cross_domain_observations', $memory);
        $this->assertArrayHasKey('urgent_flags', $memory);
        $this->assertArrayHasKey('agent_queries', $memory);
        $this->assertArrayHasKey('user_feedback', $memory);
        $this->assertArrayHasKey('prioritized_actions', $memory);
        $this->assertArrayHasKey('last_execution', $memory);
    }

    /** @test */
    public function it_can_store_and_retrieve_domain_insights()
    {
        $insight = [
            'insights' => [
                ['title' => 'Test Insight', 'confidence' => 0.8],
            ],
            'suggestions' => [
                ['title' => 'Test Suggestion'],
            ],
        ];

        $this->service->storeDomainInsight($this->userId, 'health', $insight);

        $memory = $this->service->getWorkingMemory($this->userId);

        $this->assertArrayHasKey('health', $memory['domain_insights']);
        $this->assertEquals($insight['insights'], $memory['domain_insights']['health']['insights']);
    }

    /** @test */
    public function it_can_retrieve_all_domain_insights()
    {
        $this->service->storeDomainInsight($this->userId, 'health', [
            'insights' => [['title' => 'Health Insight']],
        ]);

        $this->service->storeDomainInsight($this->userId, 'money', [
            'insights' => [['title' => 'Money Insight']],
        ]);

        $insights = $this->service->getAllDomainInsights($this->userId);

        $this->assertArrayHasKey('health', $insights);
        $this->assertArrayHasKey('money', $insights);
    }

    /** @test */
    public function it_can_store_and_retrieve_cross_domain_observations()
    {
        $observation = [
            'domains' => ['health', 'money'],
            'observation' => 'Poor sleep affecting spending decisions',
            'confidence' => 0.75,
        ];

        $this->service->addCrossDomainObservation($this->userId, $observation);

        $retrieved = $this->service->getCrossDomainObservations($this->userId);

        $this->assertCount(1, $retrieved);
        $this->assertEquals('Poor sleep affecting spending decisions', $retrieved[0]['observation']);
    }

    /** @test */
    public function it_limits_cross_domain_observations_by_count()
    {
        // Store 15 observations
        for ($i = 0; $i < 15; $i++) {
            $this->service->addCrossDomainObservation($this->userId, [
                'domains' => ['health', 'money'],
                'observation' => "Observation {$i}",
                'confidence' => 0.7,
            ]);
        }

        // Request only 10
        $retrieved = $this->service->getCrossDomainObservations($this->userId, 10);

        $this->assertCount(10, $retrieved);
    }

    /** @test */
    public function it_can_store_and_retrieve_urgent_flags()
    {
        $this->service->raiseUrgentFlag(
            $this->userId,
            'money',
            'Unusual spending spike detected',
            ['amount' => 500, 'average' => 200]
        );

        $flags = $this->service->getUnresolvedUrgentFlags($this->userId);

        $this->assertCount(1, $flags);
        $this->assertEquals('Unusual spending spike detected', $flags[0]['reason']);
    }

    /** @test */
    public function it_can_clear_urgent_flags()
    {
        $this->service->raiseUrgentFlag(
            $this->userId,
            'health',
            'Test flag',
            []
        );

        $this->service->clearWorkingMemory($this->userId);

        $flags = $this->service->getUnresolvedUrgentFlags($this->userId);

        $this->assertEmpty($flags);
    }

    /** @test */
    public function it_can_store_and_retrieve_agent_queries()
    {
        $this->service->postAgentQuery(
            $this->userId,
            'health',
            'money',
            'Did spending increase on days with poor sleep?',
            []
        );

        $queries = $this->service->getUnansweredQueriesForDomain($this->userId, 'money');

        $this->assertCount(1, $queries);
        $this->assertEquals('health', array_values($queries)[0]['from_domain']);
    }

    /** @test */
    public function it_can_store_user_feedback()
    {
        $this->service->recordFeedback(
            $this->userId,
            'insight-123',
            'rating',
            5,
            null
        );

        $memory = $this->service->getWorkingMemory($this->userId);

        $this->assertCount(1, $memory['user_feedback']);
        $this->assertEquals(5, $memory['user_feedback'][0]['value']);
    }

    /** @test */
    public function it_calculates_feedback_statistics()
    {
        // Store various feedback
        $this->service->recordFeedback($this->userId, '1', 'rating', 5);
        $this->service->recordFeedback($this->userId, '2', 'rating', 3);
        $this->service->recordFeedback($this->userId, '3', 'dismissed', true);

        $stats = $this->service->getFeedbackStatistics($this->userId);

        $this->assertEquals(4, $stats['rating_average']); // (5 + 3) / 2
        $this->assertEquals(3, $stats['total_feedback_count']);
        $this->assertEquals(1, $stats['dismissed_count']);
    }

    /** @test */
    public function it_can_store_and_retrieve_prioritized_actions()
    {
        $actions = [
            [
                'title' => 'Schedule doctor appointment',
                'priority' => 'high',
                'source_domains' => ['health'],
            ],
            [
                'title' => 'Review budget',
                'priority' => 'medium',
                'source_domains' => ['money'],
            ],
        ];

        $this->service->storePrioritizedActions($this->userId, $actions);

        $retrieved = $this->service->getPrioritizedActions($this->userId);

        $this->assertCount(2, $retrieved);
        $this->assertEquals('Schedule doctor appointment', $retrieved[0]['title']);
    }

    /** @test */
    public function it_tracks_last_execution_times()
    {
        $this->service->setLastExecutionTime($this->userId, 'continuous_background');

        $memory = $this->service->getWorkingMemory($this->userId);

        $this->assertArrayHasKey('continuous_background', $memory['last_execution']);
        $this->assertNotNull($memory['last_execution']['continuous_background']);
    }

    /** @test */
    public function it_can_clear_all_working_memory()
    {
        // Store some data
        $this->service->storeDomainInsight($this->userId, 'health', [
            'insights' => [['title' => 'Test']],
        ]);

        // Clear memory
        $this->service->clearWorkingMemory($this->userId);

        // Verify it's cleared - should return default structure
        $memory = $this->service->getWorkingMemory($this->userId);

        $this->assertEquals([], $memory['domain_insights']['health']);
    }

    /** @test */
    public function it_respects_ttl_for_cache()
    {
        $this->service->storeDomainInsight($this->userId, 'health', [
            'insights' => [['title' => 'Test']],
        ]);

        // Verify data exists
        $this->assertTrue(Cache::has("flint:working_memory:{$this->userId}"));

        // Clear cache to simulate TTL expiration
        Cache::forget("flint:working_memory:{$this->userId}");

        // Should return default structure with empty domain insights
        $memory = $this->service->getWorkingMemory($this->userId);
        $this->assertEquals([], $memory['domain_insights']['health']);
    }
}
