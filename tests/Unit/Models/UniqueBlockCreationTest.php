<?php

namespace Tests\Unit\Models;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UniqueBlockCreationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prevents_duplicate_blocks_with_same_title_and_block_type(): void
    {
        // Create test data
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $target = EventObject::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        // Create the first block
        $firstBlock = $event->createBlock([
            'title' => 'Heart Rate',
            'block_type' => 'heart_rate',
            'value' => 75,
            'value_multiplier' => 1,
            'value_unit' => 'bpm',
            'metadata' => ['type' => 'average'],
        ]);

        $this->assertInstanceOf(Block::class, $firstBlock);
        $this->assertEquals('Heart Rate', $firstBlock->title);
        $this->assertEquals('heart_rate', $firstBlock->block_type);
        $this->assertEquals(75, $firstBlock->value);

        // Try to create a duplicate block with same title and block_type
        $secondBlock = $event->createBlock([
            'title' => 'Heart Rate',
            'block_type' => 'heart_rate',
            'value' => 80, // Different value
            'value_multiplier' => 1,
            'value_unit' => 'bpm',
            'metadata' => ['type' => 'updated_average'],
        ]);

        // Should return the same block, updated with new values
        $this->assertEquals($firstBlock->id, $secondBlock->id);

        // Refresh to get updated values
        $firstBlock->refresh();
        $this->assertEquals(80, $firstBlock->value);
        $this->assertEquals(['type' => 'updated_average'], $firstBlock->metadata);

        // Verify only one block exists for this event with this title/type combination
        $blockCount = Block::where('event_id', $event->id)
            ->where('title', 'Heart Rate')
            ->where('block_type', 'heart_rate')
            ->count();

        $this->assertEquals(1, $blockCount);
    }

    #[Test]
    public function it_allows_different_blocks_with_different_titles(): void
    {
        // Create test data
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $target = EventObject::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        // Create blocks with different titles
        $heartRateBlock = $event->createBlock([
            'title' => 'Heart Rate',
            'block_type' => 'heart_rate',
            'value' => 75,
            'value_unit' => 'bpm',
        ]);

        $caloriesBlock = $event->createBlock([
            'title' => 'Calories',
            'block_type' => 'workout_metric',
            'value' => 250,
            'value_unit' => 'kcal',
        ]);

        $this->assertNotEquals($heartRateBlock->id, $caloriesBlock->id);

        // Both blocks should exist
        $this->assertEquals(2, $event->blocks()->count());
    }

    #[Test]
    public function it_allows_same_title_with_different_block_types(): void
    {
        // Create test data
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $target = EventObject::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        // Create blocks with same title but different block_type
        $avgHeartRateBlock = $event->createBlock([
            'title' => 'Average Heart Rate',
            'block_type' => 'heart_rate',
            'value' => 75,
            'value_unit' => 'bpm',
        ]);

        $avgWorkoutMetricBlock = $event->createBlock([
            'title' => 'Average Heart Rate',
            'block_type' => 'workout_metric', // Different block_type
            'value' => 75,
            'value_unit' => 'bpm',
        ]);

        $this->assertNotEquals($avgHeartRateBlock->id, $avgWorkoutMetricBlock->id);

        // Both blocks should exist
        $this->assertEquals(2, $event->blocks()->count());
    }

    #[Test]
    public function update_or_create_for_event_creates_new_block_when_none_exists(): void
    {
        // Create test data
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
        ]);

        $target = EventObject::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $block = Block::updateOrCreateForEvent($event->id, [
            'title' => 'Test Block',
            'block_type' => 'test_type',
            'value' => 100,
            'value_unit' => 'units',
        ]);

        $this->assertInstanceOf(Block::class, $block);
        $this->assertEquals('Test Block', $block->title);
        $this->assertEquals('test_type', $block->block_type);
        $this->assertEquals(100, $block->value);
        $this->assertEquals($event->id, $block->event_id);
    }
}
