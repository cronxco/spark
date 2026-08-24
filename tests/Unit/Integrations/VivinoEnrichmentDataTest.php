<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\ManualLog\VivinoEnrichmentData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VivinoEnrichmentDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function enriches_the_target_object_and_creates_a_details_block(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);

        $target = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => 'wine',
            'type' => 'wine',
            'title' => 'Tierras Altas Insignia Malbec',
            'time' => now(),
            'metadata' => ['rating' => 5],
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'manual_log',
            'action' => 'drank_wine',
            'target_id' => $target->id,
        ]);

        $job = new VivinoEnrichmentData($integration, [
            'event_id' => $event->id,
            'wine' => [
                'title' => 'Tierras Altas Insignia Malbec 2021',
                'winery' => 'Tierras Altas',
                'vintage' => '2021',
                'region' => 'Mendoza',
                'rating' => 4.2,
                'url' => 'https://www.vivino.com/wines/1234',
                'image' => 'https://images.vivino.com/labels/1234.jpg',
            ],
        ]);

        $job->handle();

        $event->refresh();
        $event->load('target', 'blocks');

        $this->assertSame('https://images.vivino.com/labels/1234.jpg', $event->target->media_url);
        $this->assertSame('Tierras Altas', $event->target->metadata['winery']);
        $this->assertSame('2021', $event->target->metadata['vintage']);
        $this->assertSame('Mendoza', $event->target->metadata['region']);
        $this->assertSame('https://www.vivino.com/wines/1234', $event->target->metadata['vivino_url']);
        $this->assertSame(4.2, $event->target->metadata['vivino_rating']);
        // The user's own rating, set at manual-log time, must survive the merge.
        $this->assertSame(5, $event->target->metadata['rating']);

        $block = $event->blocks->firstWhere('block_type', 'wine_details');
        $this->assertNotNull($block);
        $this->assertSame('Tierras Altas Insignia Malbec 2021', $block->title);
        $this->assertSame('Tierras Altas', $block->metadata['winery']);
        $this->assertSame(4.2, (float) $block->formatted_value);
    }

    #[Test]
    public function does_not_null_out_existing_content_when_the_match_has_no_image(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $target = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => 'wine',
            'type' => 'wine',
            'title' => 'House Red',
            'time' => now(),
            'media_url' => 'https://example.com/existing.jpg',
            'metadata' => [],
        ]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $target->id,
        ]);

        $job = new VivinoEnrichmentData($integration, [
            'event_id' => $event->id,
            'wine' => [
                'title' => 'House Red',
                'winery' => null,
                'vintage' => null,
                'region' => null,
                'rating' => null,
                'url' => null,
                'image' => null,
            ],
        ]);

        $job->handle();

        $event->refresh();
        $this->assertSame('https://example.com/existing.jpg', $event->target->media_url);
    }

    #[Test]
    public function does_nothing_when_no_wine_data_was_found(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $target = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => 'wine',
            'type' => 'wine',
            'title' => 'Obscure Bottle',
            'time' => now(),
            'metadata' => [],
        ]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $target->id,
        ]);

        $job = new VivinoEnrichmentData($integration, ['event_id' => $event->id, 'wine' => null]);
        $job->handle();

        $event->refresh();
        $this->assertNull($event->target->media_url);
        $this->assertCount(0, $event->blocks);
    }
}
