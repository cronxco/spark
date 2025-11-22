<?php

namespace Tests\Unit;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_has_uuid_as_primary_key(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $block = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);

        $this->assertTrue(Str::isUuid($block->id));
    }

    public function test_block_id_is_not_auto_incrementing(): void
    {
        $block = new Block();

        $this->assertFalse($block->incrementing);
        $this->assertEquals('string', $block->getKeyType());
    }

    public function test_block_uuid_is_generated_on_creation(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $block = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);

        $this->assertNotNull($block->id);
        $this->assertTrue(Str::isUuid($block->id));
    }

    public function test_block_does_not_override_provided_id(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $customId = Str::uuid()->toString();

        $block = Block::factory()->create([
            'id' => $customId,
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);

        $this->assertEquals($customId, $block->id);
    }

    public function test_block_has_fillable_attributes(): void
    {
        $block = new Block();
        $fillable = $block->getFillable();

        $expectedFillable = [
            'event_id', 'time', 'integration_id', 'title', 'content',
            'url', 'media_url', 'value', 'value_multiplier', 'value_unit', 'embeddings',
        ];

        foreach ($expectedFillable as $field) {
            $this->assertContains($field, $fillable);
        }
    }

    public function test_block_casts_time_to_datetime(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $block = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $block->time);
    }

    public function test_block_belongs_to_event(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $block = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);

        $this->assertInstanceOf(Event::class, $block->event);
        $this->assertEquals($event->id, $block->event->id);
    }

    public function test_block_belongs_to_integration(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);
        $block = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);

        $this->assertInstanceOf(Integration::class, $block->integration);
        $this->assertEquals($integration->id, $block->integration->id);
    }

    public function test_block_uses_blocks_table(): void
    {
        $block = new Block();

        $this->assertEquals('blocks', $block->getTable());
    }

    public function test_multiple_blocks_have_unique_uuids(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $block1 = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);
        $block2 = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);

        $this->assertNotEquals($block1->id, $block2->id);
    }

    public function test_block_can_store_content(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $block = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
            'title' => 'Test Title',
            'content' => 'Test content here',
            'url' => 'https://example.com',
        ]);

        $this->assertEquals('Test Title', $block->title);
        $this->assertEquals('Test content here', $block->content);
        $this->assertEquals('https://example.com', $block->url);
    }
}
