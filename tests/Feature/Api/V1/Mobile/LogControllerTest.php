<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Integrations\ManualLog\ManualLogPlugin;
use App\Jobs\OAuth\ManualLog\BoardGameGeekEnrichmentPull;
use App\Jobs\OAuth\ManualLog\UntappdBeerEnrichmentPull;
use App\Jobs\OAuth\ManualLog\VivinoEnrichmentPull;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Person;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MobileSessionAbilities;
use Tests\TestCase;

class LogControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        Queue::fake();
        Cache::forget('tvdb.token');

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'data:write']));
    }

    #[Test]
    public function requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read']));

        $this->postJson('/api/v1/mobile/log', ['kind' => 'wine', 'idempotency_key' => 'k1', 'title' => 'Malbec'])
            ->assertForbidden();
    }

    #[Test]
    public function validates_the_request(): void
    {
        $this->postJson('/api/v1/mobile/log', ['kind' => 'cheese'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind', 'idempotency_key', 'title']);

        $this->postJson('/api/v1/mobile/log', ['kind' => 'tv', 'idempotency_key' => 'k', 'title' => 'The Diplomat'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['episodes']);

        $this->postJson('/api/v1/mobile/log', ['kind' => 'wine', 'idempotency_key' => 'k', 'title' => 'Malbec', 'episodes' => [1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['episodes']);

        $this->postJson('/api/v1/mobile/log', ['kind' => 'wine', 'idempotency_key' => 'k', 'title' => 'Malbec', 'rating' => 9, 'occurred_at' => now()->addDay()->toIso8601String()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating', 'occurred_at']);
    }

    #[Test]
    public function logs_wine_with_quantity_and_queues_the_vivino_lookup(): void
    {
        $response = $this->postJson('/api/v1/mobile/log', [
            'kind' => 'wine',
            'idempotency_key' => 'log-1',
            'title' => 'Cafe Francois Rouge',
            'quantity' => 0.5,
            'unit' => 'bottle',
            'rating' => 4,
            'notes' => 'With dinner',
            'occurred_at' => now()->subHour()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('status', 'logged')
            ->assertJsonPath('events.0.action', 'drank_wine')
            ->assertJsonPath('events.0.title', 'Cafe Francois Rouge');

        $event = Event::with('target')->findOrFail($response->json('events.0.id'));
        $this->assertSame('manual_log', $event->service);
        $this->assertSame('manual_log_log-1', $event->source_id);
        $this->assertSame(4.0, (float) $event->formatted_value);
        $this->assertSame(0.5, (float) $event->event_metadata['quantity']);
        $this->assertSame('bottle', $event->event_metadata['unit']);
        $this->assertSame('With dinner', $event->event_metadata['notes']);
        $this->assertEqualsWithDelta(now()->subHour()->timestamp, $event->time->timestamp, 5);
        $this->assertSame($this->user->id, $event->target->user_id);

        Queue::assertPushed(VivinoEnrichmentPull::class, fn ($job) => $job->wineName === 'Cafe Francois Rouge' && $job->eventId === $event->id);
    }

    #[Test]
    public function a_retried_request_returns_the_same_event_without_logging_again(): void
    {
        $payload = ['kind' => 'wine', 'idempotency_key' => 'log-retry', 'title' => 'Malbec'];

        $first = $this->postJson('/api/v1/mobile/log', $payload)->assertCreated();
        $second = $this->postJson('/api/v1/mobile/log', $payload)->assertOk();

        $this->assertSame($first->json('events.0.id'), $second->json('events.0.id'));
        $this->assertSame(1, Event::where('action', 'drank_wine')->count());
        Queue::assertPushed(VivinoEnrichmentPull::class, 1);
    }

    #[Test]
    public function logs_beer_on_the_shared_untappd_beer_object_with_serving_details(): void
    {
        $existing = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'media',
            'type' => 'untappd_beer',
            'title' => 'Nebulous Sky',
            'time' => now(),
            'metadata' => ['abv' => 8.4],
        ]);

        $response = $this->postJson('/api/v1/mobile/log', [
            'kind' => 'beer',
            'idempotency_key' => 'beer-1',
            'title' => 'Nebulous Sky',
            'serving' => 'can',
            'quantity' => 1,
        ])->assertCreated()->assertJsonPath('events.0.action', 'drank_beer');

        $event = Event::findOrFail($response->json('events.0.id'));
        $this->assertSame((string) $existing->id, (string) $event->target_id);
        $this->assertSame('health', $event->domain);
        $this->assertSame('can', $event->event_metadata['serving_style']);
        $this->assertSame(1, EventObject::where('type', 'untappd_beer')->count());

        Queue::assertPushed(UntappdBeerEnrichmentPull::class, fn ($job) => $job->beerName === 'Nebulous Sky');
    }

    #[Test]
    public function logs_cinema_and_board_games_through_the_same_endpoint(): void
    {
        $this->postJson('/api/v1/mobile/log', ['kind' => 'cinema', 'idempotency_key' => 'c1', 'title' => 'Dune'])
            ->assertCreated()->assertJsonPath('events.0.action', 'watched_at_cinema');
        $this->postJson('/api/v1/mobile/log', ['kind' => 'board_game', 'idempotency_key' => 'b1', 'title' => 'Catan'])
            ->assertCreated()->assertJsonPath('events.0.action', 'played_board_game');

        Queue::assertPushed(BoardGameGeekEnrichmentPull::class, 1);
    }

    #[Test]
    public function logs_each_episode_with_tvdb_detail_when_the_series_and_season_are_clear(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([$this->diplomat()], seasons: [3]);

        $response = $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv',
            'idempotency_key' => 'tv-1',
            'title' => 'The Diplomat',
            'episodes' => [2, 1],
        ])->assertCreated()
            ->assertJsonPath('status', 'logged')
            ->assertJsonPath('missing_episodes', [])
            ->assertJsonPath('events.0.title', 'The Diplomat S03E01')
            ->assertJsonPath('events.1.title', 'The Diplomat S03E02');

        $first = Event::with('target')->findOrFail($response->json('events.0.id'));
        $this->assertSame('watched_episode', $first->action);
        $this->assertSame('media', $first->domain);
        $this->assertSame('manual_log_tv-1_e1', $first->source_id);
        $this->assertSame('The Last Straw', $first->target->metadata['episode_name']);
        $this->assertSame(371572, $first->target->metadata['tvdb_series_id']);
        $this->assertSame(3, $first->target->metadata['season']);
        $this->assertSame('https://img/501.jpg', $first->target->media_url);
    }

    #[Test]
    public function an_explicit_season_skips_the_season_question(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([$this->diplomat()], seasons: [1, 2, 3]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-2', 'title' => 'The Diplomat', 'season' => 3, 'episodes' => [1],
        ])->assertCreated()->assertJsonPath('events.0.title', 'The Diplomat S03E01');
    }

    #[Test]
    public function asks_which_season_when_the_series_has_several_and_none_was_given(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([$this->diplomat()], seasons: [1, 2, 3]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-3', 'title' => 'The Diplomat', 'episodes' => [1, 2],
        ])->assertOk()
            ->assertJsonPath('status', 'needs_clarification')
            ->assertJsonPath('field', 'season')
            ->assertJsonPath('suggested', 3)
            ->assertJsonPath('options.0', ['label' => 'Season 3', 'value' => 3])
            ->assertJsonPath('options.2', ['label' => 'Season 1', 'value' => 1]);

        $this->assertSame(0, Event::where('action', 'watched_episode')->count());
    }

    #[Test]
    public function asks_which_series_when_two_share_a_name(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([
            $this->diplomat(),
            ['tvdb_id' => '99', 'name' => 'The Diplomat', 'year' => '1990'],
        ]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-4', 'title' => 'The Diplomat', 'season' => 1, 'episodes' => [1],
        ])->assertOk()
            ->assertJsonPath('field', 'series')
            ->assertJsonPath('options.0.label', 'The Diplomat (2023)')
            ->assertJsonPath('options.0.value', 371572)
            ->assertJsonPath('options.1.label', 'The Diplomat (1990)');
    }

    #[Test]
    public function offers_partial_matches_when_no_series_covers_the_title(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([['tvdb_id' => '5', 'name' => 'The Diplomat Chronicles', 'year' => '2001']]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-5', 'title' => 'Diplomat Wars', 'episodes' => [1],
        ])->assertOk()->assertJsonPath('field', 'series')->assertJsonCount(1, 'options');
    }

    #[Test]
    public function a_chosen_series_id_is_used_directly(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([], seasons: [3]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-6', 'title' => 'The Diplomat', 'tvdb_id' => 371572, 'episodes' => [1],
        ])->assertCreated()->assertJsonPath('events.0.title', 'The Diplomat S03E01');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/search'));
    }

    #[Test]
    public function reports_episodes_that_do_not_exist_and_logs_the_rest(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([$this->diplomat()], seasons: [3]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-7', 'title' => 'The Diplomat', 'episodes' => [1, 9],
        ])->assertCreated()->assertJsonCount(1, 'events')->assertJsonPath('missing_episodes', [9]);
    }

    #[Test]
    public function retrying_a_partial_episode_request_replays_the_events_and_the_missing_list(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([$this->diplomat()], seasons: [3]);
        $payload = ['kind' => 'tv', 'idempotency_key' => 'tv-13', 'title' => 'The Diplomat', 'episodes' => [1, 9]];

        $first = $this->postJson('/api/v1/mobile/log', $payload)
            ->assertCreated()
            ->assertJsonPath('missing_episodes', [9]);

        $this->postJson('/api/v1/mobile/log', $payload)
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.id', $first->json('events.0.id'))
            ->assertJsonPath('missing_episodes', [9]);

        $this->assertSame(1, Event::where('action', 'watched_episode')->count());
    }

    #[Test]
    public function rejects_a_title_thetvdb_cannot_match_at_all(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-14', 'title' => 'Zzzz Qqqq', 'episodes' => [1],
        ])->assertUnprocessable();

        $this->assertSame(0, Event::where('action', 'watched_episode')->count());
    }

    #[Test]
    public function rejects_a_request_where_no_episode_exists(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([$this->diplomat()], seasons: [3]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-8', 'title' => 'The Diplomat', 'episodes' => [9],
        ])->assertUnprocessable()->assertJsonValidationErrors(['title']);
    }

    #[Test]
    public function falls_back_to_a_plain_entry_when_tvdb_is_not_configured(): void
    {
        config(['services.tvdb.api_key' => null]);
        Http::fake();

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-9', 'title' => 'The Diplomat', 'season' => 2, 'episodes' => [1, 2],
        ])->assertCreated()
            ->assertJsonPath('events.0.title', 'The Diplomat S02E01')
            ->assertJsonPath('events.1.title', 'The Diplomat S02E02');

        Http::assertNothingSent();
    }

    #[Test]
    public function falls_back_to_a_plain_entry_when_tvdb_is_down(): void
    {
        $this->configureTvdb();
        Http::fake(['tvdb.test/*' => Http::response([], 500)]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-10', 'title' => 'The Diplomat', 'episodes' => [1],
        ])->assertCreated()->assertJsonPath('events.0.title', 'The Diplomat E1');
    }

    #[Test]
    public function falls_back_to_a_plain_entry_when_the_episode_lookup_fails(): void
    {
        $this->configureTvdb();
        Http::fake([
            'tvdb.test/v4/login' => Http::response(['data' => ['token' => 'abc']]),
            'tvdb.test/v4/search*' => Http::response(['data' => [$this->diplomat()]]),
            'tvdb.test/v4/series/*/extended*' => Http::response(['data' => ['seasons' => [['number' => 3, 'type' => ['type' => 'official']]]]]),
            'tvdb.test/v4/series/*/episodes/default*' => Http::response([], 500),
        ]);

        $this->postJson('/api/v1/mobile/log', [
            'kind' => 'tv', 'idempotency_key' => 'tv-12', 'title' => 'The Diplomat', 'episodes' => [1],
        ])->assertCreated()->assertJsonPath('events.0.title', 'The Diplomat S03E01');
    }

    #[Test]
    public function a_retried_tv_request_does_not_log_the_episodes_again(): void
    {
        $this->configureTvdb();
        $this->fakeTvdb([$this->diplomat()], seasons: [3]);
        $payload = ['kind' => 'tv', 'idempotency_key' => 'tv-11', 'title' => 'The Diplomat', 'episodes' => [1, 2]];

        $this->postJson('/api/v1/mobile/log', $payload)->assertCreated();
        $this->postJson('/api/v1/mobile/log', $payload)->assertOk()->assertJsonCount(2, 'events');

        $this->assertSame(2, Event::where('action', 'watched_episode')->count());
    }

    #[Test]
    public function logged_events_belong_to_the_users_manual_log_integration(): void
    {
        $response = $this->postJson('/api/v1/mobile/log', ['kind' => 'wine', 'idempotency_key' => 'i1', 'title' => 'Malbec'])->assertCreated();

        $integration = ManualLogPlugin::resolveIntegration($this->user->id);
        $this->assertSame($integration->id, Event::findOrFail($response->json('events.0.id'))->integration_id);
    }

    private function configureTvdb(): void
    {
        config(['services.tvdb.api_key' => 'test-key', 'services.tvdb.base_url' => 'https://tvdb.test/v4']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $series
     * @param  array<int, int>  $seasons
     */
    private function fakeTvdb(array $series, array $seasons = [1, 2, 3], ?array $episodes = null): void
    {
        $episodes ??= [
            ['id' => 501, 'number' => 1, 'seasonNumber' => 3, 'name' => 'The Last Straw', 'aired' => '2026-01-01', 'runtime' => 50, 'image' => 'https://img/501.jpg'],
            ['id' => 502, 'number' => 2, 'seasonNumber' => 3, 'name' => 'Crossing', 'aired' => '2026-01-01', 'runtime' => 52],
        ];

        Http::fake([
            'tvdb.test/v4/login' => Http::response(['data' => ['token' => 'abc']]),
            'tvdb.test/v4/search*' => Http::response(['data' => $series]),
            'tvdb.test/v4/series/*/extended*' => Http::response(['data' => ['seasons' => array_map(
                fn (int $number) => ['number' => $number, 'type' => ['type' => 'official']],
                $seasons,
            )]]),
            'tvdb.test/v4/series/*/episodes/default*' => Http::response(['data' => ['episodes' => $episodes]]),
        ]);
    }

    #[Test]
    public function tags_people_with_the_event_creating_new_ones_and_reusing_known_ones(): void
    {
        $dan = Person::create(['user_id' => $this->user->id, 'type' => 'person', 'title' => 'Dan', 'time' => now(), 'metadata' => []]);

        $response = $this->postJson('/api/v1/mobile/log', [
            'kind' => 'wine',
            'idempotency_key' => 'with-1',
            'title' => 'Malbec',
            'with' => ['dan', ' Sam ', 'DAN'],
        ])->assertCreated()
            ->assertJsonCount(2, 'people')
            ->assertJsonPath('people.0.id', (string) $dan->id)
            ->assertJsonPath('people.0.created', false)
            ->assertJsonPath('people.1.name', 'Sam')
            ->assertJsonPath('people.1.created', true);

        $eventId = $response->json('events.0.id');
        $this->assertSame(2, Relationship::where('type', 'with')->where('to_type', Event::class)->where('to_id', $eventId)->count());
        $this->assertSame(2, Person::where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function a_retried_request_does_not_tag_people_again(): void
    {
        $payload = ['kind' => 'wine', 'idempotency_key' => 'with-retry', 'title' => 'Malbec', 'with' => ['Dan']];

        $this->postJson('/api/v1/mobile/log', $payload)->assertCreated();
        $this->postJson('/api/v1/mobile/log', $payload)->assertOk()->assertJsonCount(0, 'people');

        $this->assertSame(1, Relationship::where('type', 'with')->count());
    }

    #[Test]
    public function rejects_too_many_or_blank_names(): void
    {
        $base = ['kind' => 'wine', 'idempotency_key' => 'with-bad', 'title' => 'Malbec'];

        $this->postJson('/api/v1/mobile/log', $base + ['with' => array_fill(0, 11, 'Dan')])->assertUnprocessable();
        $this->postJson('/api/v1/mobile/log', $base + ['with' => ['']])->assertUnprocessable();
    }

    private function diplomat(): array
    {
        return ['tvdb_id' => '371572', 'name' => 'The Diplomat', 'year' => '2023'];
    }
}
