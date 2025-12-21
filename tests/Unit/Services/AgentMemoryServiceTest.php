<?php

namespace Tests\Unit\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\AgentMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AgentMemoryService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AgentMemoryService;
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_store_pattern_in_long_term_memory()
    {
        $patternData = [
            'title' => 'Exercise improves sleep quality',
            'pattern_type' => 'correlation',
            'description' => 'Days with exercise show 20% better sleep scores',
            'confidence' => 0.85,
            'domains' => ['health'],
            'supporting_evidence' => ['12 days analyzed', 'p-value < 0.05'],
        ];

        $eventObject = $this->service->storePattern($this->user->id, $patternData);

        $this->assertInstanceOf(EventObject::class, $eventObject);
        $this->assertEquals('flint', $eventObject->concept);
        $this->assertEquals('pattern', $eventObject->type);
        $this->assertEquals('Exercise improves sleep quality', $eventObject->title);
        $this->assertEquals('correlation', $eventObject->metadata['pattern_type']);
        $this->assertEquals(0.85, $eventObject->metadata['confidence']);
    }

    /** @test */
    public function it_can_retrieve_patterns_within_days_limit()
    {
        // Create patterns at different times
        $pattern1 = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'pattern',
            'title' => 'Recent Pattern',
            'time' => now()->subDays(5),
            'metadata' => [
                'pattern_type' => 'correlation',
                'confidence' => 0.8,
            ],
        ]);

        $pattern2 = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'pattern',
            'title' => 'Old Pattern',
            'time' => now()->subDays(40),
            'metadata' => [
                'pattern_type' => 'trend',
                'confidence' => 0.75,
            ],
        ]);

        $patterns = $this->service->getPatterns($this->user->id, 30);

        $this->assertCount(1, $patterns);
        $this->assertEquals('Recent Pattern', $patterns[0]['title']);
    }

    /** @test */
    public function it_can_store_agent_learning_data()
    {
        $learningData = [
            'successful_insights' => [
                ['type' => 'observation', 'rating' => 5],
            ],
            'failed_insights' => [
                ['type' => 'anomaly', 'reason' => 'too speculative'],
            ],
            'user_preferences' => [
                'preferred_insight_length' => 'concise',
                'prefers_numeric_data' => true,
            ],
        ];

        $eventObject = $this->service->storeAgentLearning(
            $this->user->id,
            'health',
            $learningData
        );

        $this->assertInstanceOf(EventObject::class, $eventObject);
        $this->assertEquals('agent_learning', $eventObject->type);
        $this->assertArrayHasKey('successful_insights', $eventObject->metadata);
    }

    /** @test */
    public function it_can_retrieve_agent_learning_for_domain()
    {
        // Store learning data
        $this->service->storeAgentLearning($this->user->id, 'health', [
            'successful_insights' => [['type' => 'observation']],
        ]);

        $learning = $this->service->getAgentLearning($this->user->id, 'health');

        $this->assertNotNull($learning);
        $this->assertArrayHasKey('successful_insights', $learning);
    }

    /** @test */
    public function it_returns_null_when_no_learning_data_exists()
    {
        $learning = $this->service->getAgentLearning($this->user->id, 'health');

        $this->assertNull($learning);
    }

    /** @test */
    public function it_can_query_domain_insight_blocks()
    {
        // Create test data
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $eventObject = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'test',
            'type' => 'test',
            'title' => 'Test Object',
        ]);

        $event = Event::factory()->create([
            'source_id' => $eventObject->id,
            'integration_id' => $integration->id,
            'domain' => 'health',
            'service' => 'flint',
            'action' => 'had_insight',
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'flint_health_insight',
            'time' => now()->subDays(2),
        ]);

        $blocks = $this->service->getDomainInsightBlocks(
            $this->user->id,
            'health',
            7
        );

        $this->assertCount(1, $blocks);
        $this->assertEquals('flint_health_insight', $blocks[0]['block_type']);
    }

    /** @test */
    public function it_can_query_all_insight_blocks()
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $eventObject1 = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'test',
            'type' => 'test',
            'title' => 'Test Object 1',
        ]);

        $eventObject2 = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'test',
            'type' => 'test',
            'title' => 'Test Object 2',
        ]);

        $event1 = Event::factory()->create([
            'source_id' => $eventObject1->id,
            'integration_id' => $integration->id,
            'domain' => 'health',
        ]);

        $event2 = Event::factory()->create([
            'source_id' => $eventObject2->id,
            'integration_id' => $integration->id,
            'domain' => 'money',
        ]);

        Block::factory()->create([
            'event_id' => $event1->id,
            'block_type' => 'flint_health_insight',
            'time' => now()->subDays(2),
        ]);

        Block::factory()->create([
            'event_id' => $event2->id,
            'block_type' => 'flint_money_insight',
            'time' => now()->subDays(3),
        ]);

        $blocks = $this->service->getAllInsightBlocks(
            $this->user->id,
            7
        );

        $this->assertCount(2, $blocks);
    }

    /** @test */
    public function it_updates_existing_pattern_instead_of_creating_duplicate()
    {
        $patternData = [
            'title' => 'Exercise Pattern',
            'pattern_type' => 'correlation',
            'confidence' => 0.8,
        ];

        $pattern1 = $this->service->storePattern($this->user->id, $patternData);

        // Store again with updated confidence
        $patternData['confidence'] = 0.9;
        $pattern2 = $this->service->storePattern($this->user->id, $patternData);

        $this->assertEquals($pattern1->id, $pattern2->id);
        $this->assertEquals(0.9, $pattern2->fresh()->metadata['confidence']);
    }

    /** @test */
    public function it_updates_existing_learning_data_instead_of_creating_duplicate()
    {
        $learningData = [
            'successful_insights' => [['type' => 'observation']],
        ];

        $learning1 = $this->service->storeAgentLearning(
            $this->user->id,
            'health',
            $learningData
        );

        // Update with new data
        $learningData['successful_insights'][] = ['type' => 'pattern'];
        $learning2 = $this->service->storeAgentLearning(
            $this->user->id,
            'health',
            $learningData
        );

        $this->assertEquals($learning1->id, $learning2->id);
        $this->assertCount(2, $learning2->fresh()->metadata['successful_insights']);
    }
}
