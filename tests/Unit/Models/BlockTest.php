<?php

namespace Tests\Unit\Models;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->event = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);
    }

    #[Test]
    public function it_generates_uuid_on_creation(): void
    {
        $block = Block::factory()->create(['event_id' => $this->event->id]);

        $this->assertNotNull($block->id);
        $this->assertTrue(Str::isUuid($block->id));
    }

    #[Test]
    public function it_belongs_to_an_event(): void
    {
        $block = Block::factory()->create(['event_id' => $this->event->id]);

        $this->assertInstanceOf(Event::class, $block->event);
        $this->assertEquals($this->event->id, $block->event->id);
    }

    #[Test]
    public function it_calculates_formatted_value_with_multiplier(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'value' => 10000,
            'value_multiplier' => 100,
        ]);

        $this->assertEquals(100, $block->formatted_value);
    }

    #[Test]
    public function it_returns_raw_value_when_multiplier_is_one(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'value' => 100,
            'value_multiplier' => 1,
        ]);

        $this->assertEquals(100, $block->formatted_value);
    }

    #[Test]
    public function it_returns_value_when_multiplier_is_null(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'value' => 100,
            'value_multiplier' => null,
        ]);

        $this->assertEquals(100, $block->formatted_value);
    }

    #[Test]
    public function it_returns_null_when_value_is_null(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'value' => null,
            'value_multiplier' => 100,
        ]);

        $this->assertNull($block->formatted_value);
    }

    #[Test]
    public function update_or_create_for_event_creates_new_block(): void
    {
        $block = Block::updateOrCreateForEvent($this->event->id, [
            'title' => 'Test Block',
            'block_type' => 'test_type',
        ], [
            'value' => 100,
        ]);

        $this->assertNotNull($block->id);
        $this->assertEquals('Test Block', $block->title);
        $this->assertEquals('test_type', $block->block_type);
        $this->assertEquals(100, $block->value);
    }

    #[Test]
    public function update_or_create_for_event_updates_existing_block(): void
    {
        $block1 = Block::updateOrCreateForEvent($this->event->id, [
            'title' => 'Test Block',
            'block_type' => 'test_type',
        ], [
            'value' => 100,
        ]);

        $block2 = Block::updateOrCreateForEvent($this->event->id, [
            'title' => 'Test Block',
            'block_type' => 'test_type',
        ], [
            'value' => 200,
        ]);

        $this->assertEquals($block1->id, $block2->id);
        $this->assertEquals(200, $block2->value);
    }

    #[Test]
    public function update_or_create_creates_separate_blocks_for_different_types(): void
    {
        $block1 = Block::updateOrCreateForEvent($this->event->id, [
            'title' => 'Test Block',
            'block_type' => 'type_a',
        ]);

        $block2 = Block::updateOrCreateForEvent($this->event->id, [
            'title' => 'Test Block',
            'block_type' => 'type_b',
        ]);

        $this->assertNotEquals($block1->id, $block2->id);
    }

    #[Test]
    public function it_uses_soft_deletes(): void
    {
        $block = Block::factory()->create(['event_id' => $this->event->id]);

        $block->delete();

        $this->assertSoftDeleted('blocks', ['id' => $block->id]);
        $this->assertNotNull(Block::withTrashed()->find($block->id));
    }

    #[Test]
    public function it_casts_metadata_to_array(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'metadata' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $this->assertIsArray($block->metadata);
        $this->assertEquals('value', $block->metadata['key']);
        $this->assertEquals(1, $block->metadata['nested']['a']);
    }

    #[Test]
    public function it_has_relationships_from(): void
    {
        $block = Block::factory()->create(['event_id' => $this->event->id]);
        $otherBlock = Block::factory()->create(['event_id' => $this->event->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Block::class,
            'from_id' => $block->id,
            'to_type' => Block::class,
            'to_id' => $otherBlock->id,
            'type' => 'linked_to',
        ]);

        $this->assertCount(1, $block->relationshipsFrom);
    }

    #[Test]
    public function it_has_relationships_to(): void
    {
        $block = Block::factory()->create(['event_id' => $this->event->id]);
        $otherBlock = Block::factory()->create(['event_id' => $this->event->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Block::class,
            'from_id' => $otherBlock->id,
            'to_type' => Block::class,
            'to_id' => $block->id,
            'type' => 'linked_to',
        ]);

        $this->assertCount(1, $block->relationshipsTo);
    }

    #[Test]
    public function it_gets_all_relationships(): void
    {
        $block = Block::factory()->create(['event_id' => $this->event->id]);
        $block2 = Block::factory()->create(['event_id' => $this->event->id]);
        $block3 = Block::factory()->create(['event_id' => $this->event->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Block::class,
            'from_id' => $block->id,
            'to_type' => Block::class,
            'to_id' => $block2->id,
            'type' => 'linked_to',
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Block::class,
            'from_id' => $block3->id,
            'to_type' => Block::class,
            'to_id' => $block->id,
            'type' => 'related_to',
        ]);

        $allRelationships = $block->allRelationships()->get();
        $this->assertCount(2, $allRelationships);
    }

    #[Test]
    public function it_gets_related_objects(): void
    {
        $block = Block::factory()->create(['event_id' => $this->event->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Block::class,
            'from_id' => $block->id,
            'to_type' => EventObject::class,
            'to_id' => $object->id,
            'type' => 'linked_to',
        ]);

        $related = $block->relatedObjects()->get();
        $this->assertCount(1, $related);
        $this->assertEquals($object->id, $related->first()->id);
    }

    #[Test]
    public function it_gets_related_events(): void
    {
        $block = Block::factory()->create(['event_id' => $this->event->id]);
        $relatedEvent = Event::factory()->create([
            'integration_id' => $this->integration->id,
        ]);

        Relationship::create([
            'user_id' => $this->user->id,
            'from_type' => Block::class,
            'from_id' => $block->id,
            'to_type' => Event::class,
            'to_id' => $relatedEvent->id,
            'type' => 'linked_to',
        ]);

        $related = $block->relatedEvents()->get();
        $this->assertCount(1, $related);
        $this->assertEquals($relatedEvent->id, $related->first()->id);
    }

    #[Test]
    public function it_gets_content_from_metadata(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'metadata' => ['content' => 'This is test content'],
        ]);

        $this->assertEquals('This is test content', $block->getContent());
    }

    #[Test]
    public function it_returns_null_when_no_content(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'metadata' => [],
        ]);

        $this->assertNull($block->getContent());
    }

    #[Test]
    public function it_sets_content_in_metadata(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'metadata' => [],
        ]);

        $block->setContent('New content');

        $this->assertEquals('New content', $block->getContent());
        $this->assertEquals('New content', $block->metadata['content']);
    }

    #[Test]
    public function has_content_returns_true_when_content_exists(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'metadata' => ['content' => 'Some content'],
        ]);

        $this->assertTrue($block->hasContent());
    }

    #[Test]
    public function has_content_returns_false_when_no_content(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'metadata' => [],
        ]);

        $this->assertFalse($block->hasContent());
    }

    #[Test]
    public function it_generates_searchable_text(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'title' => 'Test Title',
            'metadata' => ['content' => 'Test content here'],
            'url' => 'https://example.com',
            'value' => 100,
            'value_unit' => 'GBP',
        ]);

        $searchableText = $block->getSearchableText();

        $this->assertStringContainsString('Test Title', $searchableText);
        $this->assertStringContainsString('Test content here', $searchableText);
        $this->assertStringContainsString('https://example.com', $searchableText);
        $this->assertStringContainsString('100 GBP', $searchableText);
    }

    #[Test]
    public function it_converts_content_as_html_from_markdown(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'metadata' => ['content' => '**Bold text**'],
        ]);

        $html = $block->getContentAsHtml();

        $this->assertStringContainsString('<strong>Bold text</strong>', $html);
    }

    #[Test]
    public function validation_rules_include_unique_title_per_event(): void
    {
        $rules = Block::validationRules($this->event->id);

        $this->assertArrayHasKey('title', $rules);
        $this->assertArrayHasKey('event_id', $rules);
        $this->assertArrayHasKey('block_type', $rules);
    }

    #[Test]
    public function it_casts_time_to_datetime(): void
    {
        $block = Block::factory()->create([
            'event_id' => $this->event->id,
            'time' => '2024-06-15 10:30:00',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $block->time);
        $this->assertEquals('2024-06-15', $block->time->format('Y-m-d'));
    }
}
