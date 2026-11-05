<?php

namespace App\Jobs\OAuth\ManualLog;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\ManualLog\VivinoEnrichmentData;
use App\Models\Integration;
use App\Services\ManualLog\ManualLogLookup;

/**
 * Looks up richer detail (winery, vintage, region, label image, Vivino's
 * own community rating) for a manually-logged wine, via a Playwright
 * search of vivino.com - the same "ingest thin, enrich after" pattern
 * BoardGameGeekEnrichmentPull uses for board games, dispatched once per
 * manual "drank_wine" entry by ManualLogPlugin.
 *
 * Playwright (not a plain HTTP search) because Vivino is a client-rendered
 * page. No login/cookies are required for a public wine search, so this
 * rides on the manual_log integration's own IntegrationGroup purely for
 * PlaywrightFetchClient's per-domain cookie-replay signature, not because
 * any Vivino auth is actually needed.
 *
 * Only a card whose name covers the logged name is used: Vivino's search is
 * fuzzy and its first card is often an unrelated wine. A card the user picked
 * in the preview is used as chosen.
 */
class VivinoEnrichmentPull extends BaseFetchJob
{
    public function __construct(
        Integration $integration,
        public string $eventId,
        public string $wineName,
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
        return 'wine_enrichment';
    }

    protected function fetchData(): array
    {
        $group = $this->integration->group;

        if (! $group) {
            return ['event_id' => $this->eventId, 'wine' => null];
        }

        $lookup = app(ManualLogLookup::class);
        $cards = $lookup->results('wine', $this->wineName, $group);

        return ['event_id' => $this->eventId, 'wine' => $cards === null ? null : $lookup->pick($cards, $this->wineName, $this->matchUrl)];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['wine'])) {
            return;
        }

        VivinoEnrichmentData::dispatch($this->integration, $rawData);
    }
}
