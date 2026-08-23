<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\HomeAssistant\HomeAssistantMediaEnrichmentData;
use App\Jobs\OAuth\HomeAssistant\HomeAssistantMediaEnrichmentPull;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomeAssistantMediaEnrichmentPullTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function skips_gracefully_when_no_tmdb_api_key_is_configured(): void
    {
        config(['services.tmdb.api_key' => null]);
        Queue::fake([HomeAssistantMediaEnrichmentData::class]);

        $integration = Integration::factory()->create(['service' => 'home_assistant']);

        HomeAssistantMediaEnrichmentPull::dispatchSync($integration, 'event-id', 'Loki');

        Queue::assertNotPushed(HomeAssistantMediaEnrichmentData::class);
    }

    #[Test]
    public function searches_tmdb_and_filters_to_movie_and_tv_results_only(): void
    {
        config(['services.tmdb.api_key' => 'test-key']);
        Queue::fake([HomeAssistantMediaEnrichmentData::class]);

        Http::fake([
            'api.themoviedb.org/*' => Http::response([
                'results' => [
                    ['id' => 1, 'media_type' => 'movie', 'title' => 'Loki: The Movie'],
                    ['id' => 2, 'media_type' => 'person', 'name' => 'Tom Hiddleston'],
                    ['id' => 3, 'media_type' => 'tv', 'name' => 'Loki'],
                ],
            ], 200),
        ]);

        $integration = Integration::factory()->create(['service' => 'home_assistant']);

        HomeAssistantMediaEnrichmentPull::dispatchSync($integration, 'event-id', 'Loki');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'search/multi')
            && $request['query'] === 'Loki'
            && $request['api_key'] === 'test-key');

        Queue::assertPushed(HomeAssistantMediaEnrichmentData::class, 1);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_when_tmdb_returns_no_media_results(): void
    {
        config(['services.tmdb.api_key' => 'test-key']);
        Queue::fake([HomeAssistantMediaEnrichmentData::class]);

        Http::fake([
            'api.themoviedb.org/*' => Http::response(['results' => []], 200),
        ]);

        $integration = Integration::factory()->create(['service' => 'home_assistant']);

        HomeAssistantMediaEnrichmentPull::dispatchSync($integration, 'event-id', 'Sky Sports Formula 1');

        Queue::assertNotPushed(HomeAssistantMediaEnrichmentData::class);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_when_the_tmdb_request_fails(): void
    {
        config(['services.tmdb.api_key' => 'test-key']);
        Queue::fake([HomeAssistantMediaEnrichmentData::class]);

        Http::fake([
            'api.themoviedb.org/*' => Http::response([], 500),
        ]);

        $integration = Integration::factory()->create(['service' => 'home_assistant']);

        HomeAssistantMediaEnrichmentPull::dispatchSync($integration, 'event-id', 'Loki');

        Queue::assertNotPushed(HomeAssistantMediaEnrichmentData::class);
    }
}
