<?php

namespace App\Jobs\OAuth\HomeAssistant;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\HomeAssistant\HomeAssistantMediaEnrichmentData;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Looks up richer metadata (poster, overview, movie-vs-episode, genre) for
 * a Home Assistant "watched" event, since the media_player entity only
 * gives a bare title. Dispatched once per newly-created watch event by
 * HomeAssistantPlugin - the same two-stage "ingest thin, enrich after"
 * pattern UntappdCheckinDetailPull uses for Untappd checkins.
 */
class HomeAssistantMediaEnrichmentPull extends BaseFetchJob
{
    public function __construct(
        Integration $integration,
        public string $eventId,
        public string $title,
    ) {
        parent::__construct($integration);
    }

    protected function getServiceName(): string
    {
        return 'home_assistant';
    }

    protected function getJobType(): string
    {
        return 'media_enrichment';
    }

    protected function fetchData(): array
    {
        $apiKey = config('services.tmdb.api_key');

        if (empty($apiKey)) {
            Log::info('Home Assistant media enrichment: no TMDB_API_KEY configured, skipping', [
                'event_id' => $this->eventId,
            ]);

            return ['event_id' => $this->eventId, 'title' => $this->title, 'candidates' => [], 'skip' => true];
        }

        $response = Http::get(config('services.tmdb.base_url') . '/search/multi', [
            'api_key' => $apiKey,
            'query' => $this->title,
            'include_adult' => false,
        ]);

        if (! $response->successful()) {
            Log::warning('Home Assistant media enrichment: TMDB search failed', [
                'event_id' => $this->eventId,
                'status' => $response->status(),
            ]);

            return ['event_id' => $this->eventId, 'title' => $this->title, 'candidates' => [], 'skip' => true];
        }

        $candidates = collect($response->json('results') ?? [])
            ->filter(fn (array $result) => in_array($result['media_type'] ?? null, ['movie', 'tv'], true))
            ->values()
            ->all();

        // Zero results here isn't a dead end: the Data job can still ask the
        // LLM to retry the search itself with a cleaned-up title via the
        // search_tmdb tool, so it's dispatched the same as an ambiguous
        // (2+) result set rather than being dropped.
        return ['event_id' => $this->eventId, 'title' => $this->title, 'candidates' => $candidates, 'skip' => false];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if ($rawData['skip'] ?? false) {
            return;
        }

        HomeAssistantMediaEnrichmentData::dispatch($this->integration, $rawData);
    }
}
