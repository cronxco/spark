<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\HomeAssistant\HomeAssistantMediaEnrichmentData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Ai\ChatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HomeAssistantMediaEnrichmentDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function applies_a_single_candidate_without_calling_the_llm(): void
    {
        $this->mock(ChatClient::class)->shouldNotReceive('text');

        [$integration, $event] = $this->makeWatchEvent('Loki');

        $job = new HomeAssistantMediaEnrichmentData($integration, [
            'event_id' => $event->id,
            'title' => 'Loki',
            'candidates' => [
                [
                    'id' => 84958,
                    'media_type' => 'tv',
                    'name' => 'Loki',
                    'overview' => 'Loki gets sent through the multiverse.',
                    'first_air_date' => '2021-06-09',
                    'poster_path' => '/poster.jpg',
                    'vote_average' => 8.2,
                ],
            ],
        ]);

        $job->handle();

        $event->refresh();
        $event->load('target', 'blocks');

        $this->assertSame('tv_episode', $event->target->type);
        $this->assertSame('Loki gets sent through the multiverse.', $event->target->content);
        $this->assertStringContainsString('/poster.jpg', $event->target->media_url);
        $this->assertSame(84958, $event->target->metadata['tmdb_id']);

        $block = $event->blocks->firstWhere('block_type', 'media_details');
        $this->assertNotNull($block);
        $this->assertSame(8.2, (float) $block->formatted_value);
    }

    #[Test]
    public function asks_the_llm_to_disambiguate_multiple_candidates_and_applies_its_choice(): void
    {
        $this->mock(ChatClient::class)
            ->shouldReceive('text')
            ->once()
            ->andReturn('{"tmdb_id": 2}');

        [$integration, $event] = $this->makeWatchEvent('Loki');

        $job = new HomeAssistantMediaEnrichmentData($integration, [
            'event_id' => $event->id,
            'title' => 'Loki',
            'candidates' => [
                ['id' => 1, 'media_type' => 'movie', 'title' => 'Some Other Loki Thing'],
                ['id' => 2, 'media_type' => 'tv', 'name' => 'Loki', 'vote_average' => 8.2],
            ],
        ]);

        $job->handle();

        $event->refresh();
        $this->assertSame(2, $event->target->metadata['tmdb_id']);
    }

    #[Test]
    public function lets_the_llm_retry_via_the_search_tmdb_tool_when_the_initial_search_found_nothing(): void
    {
        config(['services.tmdb.api_key' => 'test-key']);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_tmdb',
                                'arguments' => json_encode(['query' => 'Loki']),
                            ],
                        ]],
                    ],
                ]],
            ]),
            CreateResponse::fake([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '{"tmdb_id": 555}'],
                ]],
            ]),
        ]);

        Http::fake([
            'api.themoviedb.org/*' => Http::response([
                'results' => [
                    ['id' => 555, 'media_type' => 'tv', 'name' => 'Loki', 'vote_average' => 8.2],
                ],
            ], 200),
        ]);

        [$integration, $event] = $this->makeWatchEvent('Live: Loki S2');

        $job = new HomeAssistantMediaEnrichmentData($integration, [
            'event_id' => $event->id,
            'title' => 'Live: Loki S2',
            'candidates' => [],
        ]);

        $job->handle();

        $event->refresh();
        $this->assertSame('tv_episode', $event->target->type);
        $this->assertSame(555, $event->target->metadata['tmdb_id']);
    }

    #[Test]
    public function leaves_the_event_unchanged_when_the_llm_rejects_all_candidates(): void
    {
        $this->mock(ChatClient::class)
            ->shouldReceive('text')
            ->once()
            ->andReturn('{"tmdb_id": null}');

        [$integration, $event] = $this->makeWatchEvent('Sky Sports Formula 1');

        $job = new HomeAssistantMediaEnrichmentData($integration, [
            'event_id' => $event->id,
            'title' => 'Sky Sports Formula 1',
            'candidates' => [
                ['id' => 1, 'media_type' => 'movie', 'title' => 'Loosely Similar Title'],
                ['id' => 2, 'media_type' => 'tv', 'name' => 'Another Loose Match'],
            ],
        ]);

        $job->handle();

        $event->refresh();
        $this->assertSame('tv_watch', $event->target->type);
        $this->assertArrayNotHasKey('tmdb_id', $event->target->metadata ?? []);
    }

    #[Test]
    public function leaves_the_event_unchanged_when_the_llm_call_throws(): void
    {
        $this->mock(ChatClient::class)
            ->shouldReceive('text')
            ->once()
            ->andThrow(new RuntimeException('rate limited'));

        [$integration, $event] = $this->makeWatchEvent('Loki');

        $job = new HomeAssistantMediaEnrichmentData($integration, [
            'event_id' => $event->id,
            'title' => 'Loki',
            'candidates' => [
                ['id' => 1, 'media_type' => 'movie', 'title' => 'A'],
                ['id' => 2, 'media_type' => 'tv', 'name' => 'B'],
            ],
        ]);

        $job->handle();

        $event->refresh();
        $this->assertSame('tv_watch', $event->target->type);
    }

    #[Test]
    public function does_nothing_when_the_related_event_no_longer_exists(): void
    {
        $this->mock(ChatClient::class)->shouldNotReceive('text');

        $integration = Integration::factory()->create(['service' => 'home_assistant']);

        $job = new HomeAssistantMediaEnrichmentData($integration, [
            'event_id' => (string) Str::uuid(),
            'title' => 'Loki',
            'candidates' => [['id' => 1, 'media_type' => 'tv', 'name' => 'Loki']],
        ]);

        // Should not throw.
        $job->handle();
        $this->assertTrue(true);
    }

    /**
     * @return array{0: Integration, 1: Event}
     */
    private function makeWatchEvent(string $title): array
    {
        $integration = Integration::factory()->create(['service' => 'home_assistant']);

        $target = EventObject::create([
            'user_id' => $integration->user_id,
            'concept' => 'media',
            'type' => 'tv_watch',
            'title' => $title,
            'time' => now(),
            'metadata' => [],
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'home_assistant',
            'action' => 'watched',
            'target_id' => $target->id,
        ]);

        return [$integration, $event];
    }
}
