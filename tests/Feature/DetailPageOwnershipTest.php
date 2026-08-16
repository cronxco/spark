<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetailPageOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $intruder;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    #[Test]
    public function event_detail_is_forbidden_for_non_owner(): void
    {
        $event = $this->ownedEvent();

        $this->actingAs($this->intruder)
            ->get(route('events.show', $event))
            ->assertForbidden();
    }

    #[Test]
    public function event_detail_is_visible_to_owner(): void
    {
        $event = $this->ownedEvent();

        $this->actingAs($this->owner)
            ->get(route('events.show', $event))
            ->assertOk();
    }

    #[Test]
    public function object_detail_is_forbidden_for_non_owner(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->intruder)
            ->get(route('objects.show', $object))
            ->assertForbidden();
    }

    #[Test]
    public function object_detail_is_visible_to_owner(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->get(route('objects.show', $object))
            ->assertOk();
    }

    #[Test]
    public function detail_pages_render_stored_markdown_safely(): void
    {
        $content = "**Trusted markdown** [safe](https://example.com) [unsafe](javascript:alert('xss'))\n\n<script>alert('xss')</script>";
        $object = EventObject::factory()->create([
            'user_id' => $this->owner->id,
            'content' => $content,
        ]);
        $event = $this->ownedEvent();
        $event->update(['target_id' => $object->id]);

        foreach ([route('objects.show', $object), route('events.show', $event)] as $route) {
            $this->actingAs($this->owner)
                ->get($route)
                ->assertOk()
                ->assertSee('<strong>Trusted markdown</strong>', false)
                ->assertSee('<a href="https://example.com">safe</a>', false)
                ->assertDontSee('<script>', false)
                ->assertDontSee('javascript:', false);
        }
    }

    #[Test]
    public function card_fetch_newsletter_outline_and_editorial_views_render_stored_markdown_safely(): void
    {
        $content = "**Trusted markdown** [safe](https://example.com) [unsafe](javascript:alert('xss'))\n\n<img src=x onerror=alert('xss')>";
        $event = $this->ownedEvent();
        $event->load(['integration', 'blocks', 'target']);

        $this->view('components.bookmark-card', [
            'event' => $event,
            'title' => 'Bookmark',
            'summary' => $content,
            'url' => 'https://example.com',
            'image' => null,
        ])->assertSee('<strong>Trusted markdown</strong>', false)
            ->assertDontSee('javascript:', false)
            ->assertDontSee('<img', false);

        $cardBlock = Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'note',
            'metadata' => ['content' => $content],
            'value' => null,
        ]);
        $cardBlock->load('event');

        $this->view('components.block-card', ['block' => $cardBlock])
            ->assertSee('<strong>Trusted markdown</strong>', false)
            ->assertDontSee('javascript:', false)
            ->assertDontSee('<img', false);

        foreach (['fetch_summary_short', 'newsletter_summary_short', 'doc_task', 'day_task', 'flint_editorial_note'] as $blockType) {
            $block = Block::factory()->create([
                'event_id' => $event->id,
                'block_type' => $blockType,
                'title' => $content,
                'metadata' => ['content' => $content],
            ]);
            $block->load('event');

            $this->view("blocks.types.{$blockType}", ['block' => $block])
                ->assertSee('<strong>Trusted markdown</strong>', false)
                ->assertDontSee('javascript:', false)
                ->assertDontSee('<img', false);
        }
    }

    #[Test]
    public function block_detail_is_forbidden_for_non_owner(): void
    {
        $block = Block::factory()->create(['event_id' => $this->ownedEvent()->id]);

        $this->actingAs($this->intruder)
            ->get(route('blocks.show', $block))
            ->assertForbidden();
    }

    #[Test]
    public function block_detail_is_visible_to_owner(): void
    {
        $block = Block::factory()->create(['event_id' => $this->ownedEvent()->id]);

        $this->actingAs($this->owner)
            ->get(route('blocks.show', $block))
            ->assertOk();
    }

    protected function ownedEvent(): Event
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->owner->id,
            'service' => 'monzo',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->owner->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
        ]);
    }
}
