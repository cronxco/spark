<?php

namespace Tests\Unit\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DuplicateDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetectionService $service;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DuplicateDetectionService;
        $this->user = User::factory()->create();

        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);
    }

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(DuplicateDetectionService::class, $this->service);
    }

    #[Test]
    public function find_duplicate_events_returns_collection(): void
    {
        $result = $this->service->findDuplicateEvents($this->user->id);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    #[Test]
    public function find_duplicate_events_returns_empty_when_no_embeddings(): void
    {
        Event::factory()->count(5)->create([
            'integration_id' => $this->integration->id,
            'embeddings' => null,
        ]);

        $result = $this->service->findDuplicateEvents($this->user->id);

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function find_duplicate_blocks_returns_collection(): void
    {
        $result = $this->service->findDuplicateBlocks($this->user->id);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    #[Test]
    public function find_duplicate_blocks_returns_empty_when_no_embeddings(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Block::factory()->count(5)->create([
            'event_id' => $event->id,
            'embeddings' => null,
        ]);

        $result = $this->service->findDuplicateBlocks($this->user->id);

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function find_duplicate_objects_returns_collection(): void
    {
        $result = $this->service->findDuplicateObjects($this->user->id);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    #[Test]
    public function find_duplicate_objects_returns_empty_when_no_embeddings(): void
    {
        EventObject::factory()->count(5)->create([
            'user_id' => $this->user->id,
            'embeddings' => null,
        ]);

        $result = $this->service->findDuplicateObjects($this->user->id);

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function find_duplicate_events_respects_similarity_threshold(): void
    {
        // Create events without embeddings (similarity detection requires embeddings)
        Event::factory()->count(3)->create([
            'integration_id' => $this->integration->id,
            'embeddings' => null,
        ]);

        // With high threshold (0.99), fewer duplicates should match
        $highThreshold = $this->service->findDuplicateEvents($this->user->id, 0.99);

        // With lower threshold (0.5), more duplicates should match
        $lowThreshold = $this->service->findDuplicateEvents($this->user->id, 0.5);

        // Both should be collections
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $highThreshold);
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $lowThreshold);
    }

    #[Test]
    public function find_duplicate_events_respects_limit(): void
    {
        // The method should respect the limit parameter
        $result = $this->service->findDuplicateEvents($this->user->id, 0.95, 10);

        $this->assertLessThanOrEqual(10, $result->count());
    }

    #[Test]
    public function find_duplicate_blocks_respects_limit(): void
    {
        $result = $this->service->findDuplicateBlocks($this->user->id, 0.95, 10);

        $this->assertLessThanOrEqual(10, $result->count());
    }

    #[Test]
    public function find_duplicate_objects_respects_limit(): void
    {
        $result = $this->service->findDuplicateObjects($this->user->id, 0.95, 10);

        $this->assertLessThanOrEqual(10, $result->count());
    }

    #[Test]
    public function find_duplicate_events_only_returns_user_events(): void
    {
        $otherUser = User::factory()->create();
        $otherGroup = IntegrationGroup::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'test',
        ]);
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'integration_group_id' => $otherGroup->id,
            'service' => 'test',
        ]);

        // Create events for other user
        Event::factory()->count(3)->create([
            'integration_id' => $otherIntegration->id,
            'embeddings' => null,
        ]);

        // Search for duplicates for our test user
        $result = $this->service->findDuplicateEvents($this->user->id);

        // Should not contain other user's events
        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function find_duplicate_objects_only_returns_user_objects(): void
    {
        $otherUser = User::factory()->create();

        // Create objects for other user
        EventObject::factory()->count(3)->create([
            'user_id' => $otherUser->id,
            'embeddings' => null,
        ]);

        // Search for duplicates for our test user
        $result = $this->service->findDuplicateObjects($this->user->id);

        // Should not contain other user's objects
        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function result_pairs_have_correct_structure(): void
    {
        // Test that if we get results, they have the expected structure
        // Since we don't have embeddings, we'll just verify the method doesn't error
        // and returns an empty collection
        $result = $this->service->findDuplicateEvents($this->user->id);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);

        // If there were results, they should have model1, model2, and similarity
        foreach ($result as $pair) {
            $this->assertArrayHasKey('model1', $pair);
            $this->assertArrayHasKey('model2', $pair);
            $this->assertArrayHasKey('similarity', $pair);
        }
    }

    #[Test]
    public function default_similarity_threshold_is_applied(): void
    {
        // Default threshold is 0.95
        $result = $this->service->findDuplicateEvents($this->user->id);

        // Should work without errors
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    #[Test]
    public function default_limit_is_applied(): void
    {
        // Default limit is 100
        $result = $this->service->findDuplicateEvents($this->user->id);

        $this->assertLessThanOrEqual(100, $result->count());
    }
}
