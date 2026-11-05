<?php

namespace App\Jobs\OAuth\ManualLog;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\ManualLog\UntappdBeerEnrichmentData;
use App\Models\Integration;
use App\Services\ManualLog\ManualLogLookup;

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
        public ?string $matchUrl = null,
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

        $lookup = app(ManualLogLookup::class);
        $cards = $lookup->results('beer', $this->beerName, $group);

        return ['event_id' => $this->eventId, 'beer' => $cards === null ? null : $lookup->pick($cards, $this->beerName, $this->matchUrl)];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['beer'])) {
            return;
        }

        UntappdBeerEnrichmentData::dispatch($this->integration, $rawData);
    }
}
