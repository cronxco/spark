<?php

namespace Tests\Unit\Integrations;

use App\Integrations\ManualLog\ManualLogPlugin;
use App\Jobs\OAuth\ManualLog\BoardGameGeekEnrichmentPull;
use App\Jobs\OAuth\ManualLog\UntappdBeerEnrichmentPull;
use App\Jobs\OAuth\ManualLog\VivinoEnrichmentPull;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualLogPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // played_board_game/drank_wine entries dispatch enrichment jobs
        // that make real outbound calls (BGG's HTTP API, Vivino via
        // Playwright) - fake them so these tests don't depend on network
        // access, the same way UntappdRssDataTest fakes
        // UntappdCheckinDetailPull.
        Queue::fake([BoardGameGeekEnrichmentPull::class, UntappdBeerEnrichmentPull::class, VivinoEnrichmentPull::class]);
    }

    #[Test]
    public function creates_an_event_with_target_and_actor(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $plugin = new ManualLogPlugin;

        $event = $plugin->createManualEvent($integration, 'drank_wine', 'Rioja Reserva', 4.5, 'Lovely with dinner');

        $this->assertSame('manual_log', $event->service);
        $this->assertSame('health', $event->domain);
        $this->assertSame('drank_wine', $event->action);
        $this->assertSame(4.5, (float) $event->formatted_value);
        $this->assertSame('/5', $event->value_unit);
        $this->assertSame('Lovely with dinner', $event->event_metadata['notes']);

        $this->assertSame('Rioja Reserva', $event->target->title);
        $this->assertSame('wine', $event->target->concept);
        $this->assertSame('wine', $event->target->type);

        $this->assertSame('manual_log_user', $event->actor->type);
    }

    #[Test]
    public function reuses_the_same_actor_object_across_multiple_entries(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $plugin = new ManualLogPlugin;

        $first = $plugin->createManualEvent($integration, 'drank_wine', 'Malbec');
        $second = $plugin->createManualEvent($integration, 'played_board_game', 'Catan');

        $this->assertSame((string) $first->actor_id, (string) $second->actor_id);
    }

    #[Test]
    public function rating_and_notes_are_optional(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $plugin = new ManualLogPlugin;

        $event = $plugin->createManualEvent($integration, 'watched_at_cinema', 'Dune: Part Two');

        $this->assertNull($event->value);
        $this->assertNull($event->value_unit);
        $this->assertArrayNotHasKey('notes', $event->event_metadata ?? []);
    }

    #[Test]
    public function rejects_an_unknown_activity_type(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $plugin = new ManualLogPlugin;

        $this->expectException(InvalidArgumentException::class);

        $plugin->createManualEvent($integration, 'went_skydiving', 'YOLO');
    }

    #[Test]
    public function rejects_a_rating_outside_one_to_five(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $plugin = new ManualLogPlugin;

        $this->expectException(InvalidArgumentException::class);

        $plugin->createManualEvent($integration, 'drank_wine', 'Malbec', 6.0);
    }

    #[Test]
    public function maps_each_activity_type_to_its_own_domain_and_target_concept_and_type(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $plugin = new ManualLogPlugin;

        $wine = $plugin->createManualEvent($integration, 'drank_wine', 'Malbec');
        $cinema = $plugin->createManualEvent($integration, 'watched_at_cinema', 'Dune');
        $game = $plugin->createManualEvent($integration, 'played_board_game', 'Catan');

        $this->assertSame(['health', 'wine', 'wine'], [$wine->domain, $wine->target->concept, $wine->target->type]);
        $this->assertSame(['media', 'media', 'cinema_visit'], [$cinema->domain, $cinema->target->concept, $cinema->target->type]);
        $this->assertSame(['media', 'game', 'board_game'], [$game->domain, $game->target->concept, $game->target->type]);
    }

    #[Test]
    public function drank_beer_shares_the_untappd_beer_object_and_queues_the_untappd_lookup(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $plugin = new ManualLogPlugin;

        $first = $plugin->createManualEvent($integration, 'drank_beer', 'Nebulous Sky');
        $second = $plugin->createManualEvent($integration, 'drank_beer', 'Nebulous Sky');

        $this->assertSame('health', $first->domain);
        $this->assertSame('media', $first->target->concept);
        $this->assertSame('untappd_beer', $first->target->type);
        $this->assertSame((string) $first->target_id, (string) $second->target_id);
        Queue::assertPushed(UntappdBeerEnrichmentPull::class, 2);
    }

    #[Test]
    public function options_set_the_time_source_id_and_extra_metadata(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $when = CarbonImmutable::parse('2026-10-18 20:30:00');

        $event = (new ManualLogPlugin)->createManualEvent($integration, 'watched_episode', 'The Diplomat S03E01', null, null, [
            'occurred_at' => $when,
            'source_id' => 'manual_log_custom',
            'event_metadata' => ['quantity' => 1],
            'target_metadata' => ['season' => 3],
            'media_url' => 'https://img/1.jpg',
            'enrich' => false,
        ]);

        $this->assertSame('manual_log_custom', $event->source_id);
        $this->assertTrue($event->time->equalTo($when));
        $this->assertSame(1, $event->event_metadata['quantity']);
        $this->assertSame(3, $event->target->metadata['season']);
        $this->assertSame('https://img/1.jpg', $event->target->media_url);
        $this->assertTrue($event->target->time->equalTo($when));
    }

    #[Test]
    public function enrich_false_skips_the_lookup_jobs(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);

        (new ManualLogPlugin)->createManualEvent($integration, 'drank_wine', 'Malbec', null, null, ['enrich' => false]);
        (new ManualLogPlugin)->createManualEvent($integration, 'drank_beer', 'Pils', null, null, ['enrich' => false]);

        Queue::assertNotPushed(VivinoEnrichmentPull::class);
        Queue::assertNotPushed(UntappdBeerEnrichmentPull::class);
    }

    #[Test]
    public function the_new_activities_are_registered_for_display(): void
    {
        $this->assertArrayHasKey('drank_beer', ManualLogPlugin::getActionTypes());
        $this->assertArrayHasKey('watched_episode', ManualLogPlugin::getActionTypes());
        $this->assertArrayHasKey('beer_details', ManualLogPlugin::getBlockTypes());
        $this->assertArrayHasKey('tv_episode', ManualLogPlugin::getObjectTypes());
        $this->assertArrayHasKey('untappd_beer', ManualLogPlugin::getObjectTypes());
        $this->assertArrayHasKey('drank_beer', ManualLogPlugin::loggableActionTypes());
    }
}
