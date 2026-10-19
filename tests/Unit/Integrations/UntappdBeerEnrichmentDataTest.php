<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\ManualLog\UntappdBeerEnrichmentData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UntappdBeerEnrichmentDataTest extends TestCase
{
    use RefreshDatabase;

    private const BEER = [
        'beer_id' => '5063519',
        'title' => 'Verdant Brewing Co Nebulous Sky',
        'brewery' => 'Verdant Brewing Co',
        'style' => 'IPA - Imperial / Double New England / Hazy',
        'abv' => 8.4,
        'ibu' => null,
        'rating' => 4.24,
        'url' => 'https://untappd.com/b/verdant-brewing-co-nebulous-sky/5063519',
        'image' => 'https://assets.untappd.com/site/beer_logos/beer-5063519_8e78d_sm.jpeg',
    ];

    private function eventFor(Integration $integration, array $eventMetadata = []): Event
    {
        $target = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => 'media',
            'type' => 'untappd_beer',
            'title' => 'Nebulous Sky',
            'time' => now(),
            'metadata' => ['rating' => 5],
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'manual_log',
            'action' => 'drank_beer',
            'target_id' => $target->id,
            'event_metadata' => $eventMetadata,
        ]);
    }

    #[Test]
    public function enriches_the_beer_adds_a_details_block_and_works_out_units(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $event = $this->eventFor($integration, ['serving_style' => 'can', 'quantity' => 2]);

        (new UntappdBeerEnrichmentData($integration, ['event_id' => $event->id, 'beer' => self::BEER]))->handle();

        $event->refresh()->load('target', 'blocks');

        $this->assertSame(self::BEER['image'], $event->target->media_url);
        $this->assertSame(8.4, $event->target->metadata['abv']);
        $this->assertSame('Verdant Brewing Co', $event->target->metadata['brewery']);
        $this->assertSame(4.24, $event->target->metadata['aggregate_rating']);
        $this->assertSame('5063519', $event->target->metadata['untappd_beer_id']);
        $this->assertArrayNotHasKey('ibu', $event->target->metadata);
        $this->assertSame(5, $event->target->metadata['rating']);

        // 8.4% x 440ml x 2 cans
        $this->assertSame(7.4, $event->event_metadata['alcohol_units']);

        $block = $event->blocks->firstWhere('block_type', 'beer_details');
        $this->assertNotNull($block);
        $this->assertSame(4.24, (float) $block->formatted_value);
    }

    #[Test]
    public function does_nothing_without_a_beer_or_for_a_missing_event(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $event = $this->eventFor($integration);

        (new UntappdBeerEnrichmentData($integration, ['event_id' => $event->id, 'beer' => null]))->handle();
        (new UntappdBeerEnrichmentData($integration, ['event_id' => '00000000-0000-0000-0000-000000000000', 'beer' => self::BEER]))->handle();

        $this->assertSame(0, $event->refresh()->blocks()->count());
        $this->assertArrayNotHasKey('abv', $event->target->metadata);
    }
}
