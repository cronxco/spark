<?php

namespace App\Jobs\OAuth\ManualLog;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Integrations\ManualLog\UntappdSearchParser;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\ManualLog\UntappdBeerEnrichmentData;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Looks up style, ABV, IBU, rating and label for a manually-logged beer via a
 * Playwright search of untappd.com, the same "ingest thin, enrich after"
 * pattern as VivinoEnrichmentPull. Rides on the manual_log integration's
 * IntegrationGroup only for PlaywrightFetchClient's per-domain cookie replay;
 * a public search needs no Untappd login.
 *
 * Only a result whose brewery and name cover the logged name is used.
 */
class UntappdBeerEnrichmentPull extends BaseFetchJob
{
    public function __construct(
        Integration $integration,
        public string $eventId,
        public string $beerName,
    ) {
        parent::__construct($integration);
    }

    protected function getServiceName(): string
    {
        return 'manual_log';
    }

    protected function getJobType(): string
    {
        return 'beer_enrichment';
    }

    protected function fetchData(): array
    {
        $group = $this->integration->group;

        if (! $group) {
            return ['event_id' => $this->eventId, 'beer' => null];
        }

        $searchUrl = config('services.untappd.search_url').'?'.http_build_query(['q' => $this->beerName]);

        try {
            $result = app(PlaywrightFetchClient::class)->fetch($searchUrl, $group);
        } catch (Throwable $e) {
            Log::warning('Beer enrichment: Untappd search failed', [
                'event_id' => $this->eventId,
                'error' => $e->getMessage(),
            ]);

            return ['event_id' => $this->eventId, 'beer' => null];
        }

        if (! ($result['success'] ?? false) || empty($result['html'])) {
            return ['event_id' => $this->eventId, 'beer' => null];
        }

        return [
            'event_id' => $this->eventId,
            'beer' => app(UntappdSearchParser::class)->parseBestMatch($result['html'], $this->beerName),
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['beer'])) {
            return;
        }

        UntappdBeerEnrichmentData::dispatch($this->integration, $rawData);
    }
}
