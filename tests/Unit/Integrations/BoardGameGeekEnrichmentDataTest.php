<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\ManualLog\BoardGameGeekEnrichmentData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardGameGeekEnrichmentDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function enriches_the_target_object_and_creates_a_details_block(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);

        $target = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => 'game',
            'type' => 'board_game',
            'title' => 'Catan',
            'time' => now(),
            'metadata' => [],
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'manual_log',
            'action' => 'played_board_game',
            'target_id' => $target->id,
        ]);

        $job = new BoardGameGeekEnrichmentData($integration, [
            'event_id' => $event->id,
            'game' => [
                'bgg_id' => '13',
                'name' => 'Catan',
                'description' => 'In Catan, players try to be the dominant force.',
                'year_published' => '1995',
                'min_players' => '3',
                'max_players' => '4',
                'playing_time' => '120',
                'image' => 'https://cf.geekdo-images.com/full.jpg',
                'rating' => '7.1',
            ],
        ]);

        $job->handle();

        $event->refresh();
        $event->load('target', 'blocks');

        $this->assertSame('In Catan, players try to be the dominant force.', $event->target->content);
        $this->assertSame('https://cf.geekdo-images.com/full.jpg', $event->target->media_url);
        $this->assertSame('13', $event->target->metadata['bgg_id']);
        $this->assertSame('1995', $event->target->metadata['year_published']);

        $block = $event->blocks->firstWhere('block_type', 'board_game_details');
        $this->assertNotNull($block);
        $this->assertSame('3-4', $block->metadata['players']);
        $this->assertSame('120 min', $block->metadata['playing_time']);
        $this->assertSame(7.1, (float) $block->formatted_value);
    }

    #[Test]
    public function does_nothing_when_no_game_data_was_found(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $target = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => 'game',
            'type' => 'board_game',
            'title' => 'Obscure Prototype',
            'time' => now(),
            'metadata' => [],
        ]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $target->id,
        ]);

        $job = new BoardGameGeekEnrichmentData($integration, ['event_id' => $event->id, 'game' => null]);
        $job->handle();

        $event->refresh();
        $this->assertNull($event->target->content);
        $this->assertCount(0, $event->blocks);
    }

    #[Test]
    public function handles_a_game_with_no_player_count_or_rating(): void
    {
        $integration = Integration::factory()->create(['service' => 'manual_log']);
        $target = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => 'game',
            'type' => 'board_game',
            'title' => 'Mystery Game',
            'time' => now(),
            'metadata' => [],
        ]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $target->id,
        ]);

        $job = new BoardGameGeekEnrichmentData($integration, [
            'event_id' => $event->id,
            'game' => [
                'bgg_id' => '999',
                'name' => 'Mystery Game',
                'description' => null,
                'year_published' => null,
                'min_players' => null,
                'max_players' => null,
                'playing_time' => null,
                'image' => null,
                'rating' => null,
            ],
        ]);

        $job->handle();

        $event->refresh();
        $event->load('blocks');

        $block = $event->blocks->firstWhere('block_type', 'board_game_details');
        $this->assertNotNull($block);
        $this->assertArrayNotHasKey('players', $block->metadata);
        $this->assertNull($block->value);
    }
}
