<?php

namespace Tests\Unit\Integrations;

use App\Integrations\ManualLog\ManualLogPlugin;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualLogPluginTest extends TestCase
{
    use RefreshDatabase;

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
}
