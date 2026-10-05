<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\EventResource;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventResourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exposes_message_id_when_present_in_event_metadata()
    {
        $event = Event::factory()->create([
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'had_receipt_from',
            'event_metadata' => [
                'email_message_id' => '<invoice-abc123@digitalocean.com>',
            ],
        ]);

        $data = (new EventResource($event))->toArray(Request::create('/'));

        $this->assertSame('<invoice-abc123@digitalocean.com>', $data['message_id']);
    }

    #[Test]
    public function it_omits_message_id_when_not_present_in_event_metadata()
    {
        $event = Event::factory()->create([
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'event_metadata' => [],
        ]);

        $data = (new EventResource($event))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('message_id', $data);
    }

    #[Test]
    public function fetch_resources_present_the_immutable_revision_snapshot(): void
    {
        $webpage = EventObject::factory()->create([
            'title' => 'Live mutable title',
            'content' => 'Live mutable content',
            'url' => 'https://example.com/live',
            'media_url' => 'https://example.com/live.jpg',
        ]);
        $event = Event::factory()->create([
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'updated',
            'target_id' => $webpage->id,
            'target_metadata' => [
                'title' => 'Revision title',
                'url' => 'https://example.com/revision',
                'media_url' => 'https://example.com/revision.jpg',
                'excerpt' => 'Revision excerpt',
            ],
        ]);
        Block::factory()->create([
            'event_id' => $event->id,
            'title' => 'Raw Content',
            'block_type' => 'fetch_content',
            'metadata' => ['article_text' => 'Revision article text'],
        ]);
        $event->load(['target', 'blocks']);
        $request = Request::create('/');

        $full = (new EventResource($event))->toArray($request);
        $compact = (new CompactEventResource($event))->toArray($request);

        $this->assertSame('Revision title', $full['target']['title']);
        $this->assertSame('Revision article text', $full['target']['content']);
        $this->assertSame('https://example.com/revision', $full['target']['url']);
        $this->assertSame('Revision title', $compact['target']['title']);
        $this->assertSame('https://example.com/revision.jpg', $compact['target']['media_url']);
    }
}
