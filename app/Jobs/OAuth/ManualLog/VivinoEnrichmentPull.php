<?php

namespace App\Jobs\OAuth\ManualLog;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Integrations\ManualLog\VivinoSearchParser;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\ManualLog\VivinoEnrichmentData;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * CAVEAT: VivinoSearchParser's selectors are a best-effort scaffold, not
 * verified against Vivino's real markup - see its class docblock.
 */
class VivinoEnrichmentPull extends BaseFetchJob
{
    public function __construct(
        Integration $integration,
        public string $eventId,
        public string $wineName,
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

        $searchUrl = config('services.vivino.search_url') . '?' . http_build_query(['q' => $this->wineName]);

        try {
            $result = app(PlaywrightFetchClient::class)->fetch($searchUrl, $group);
        } catch (Throwable $e) {
            Log::warning('Wine enrichment: Vivino search failed', [
                'event_id' => $this->eventId,
                'error' => $e->getMessage(),
            ]);

            return ['event_id' => $this->eventId, 'wine' => null];
        }

        if (! ($result['success'] ?? false) || empty($result['html'])) {
            return ['event_id' => $this->eventId, 'wine' => null];
        }

        $wine = app(VivinoSearchParser::class)->parseFirstResult($result['html']);

        return ['event_id' => $this->eventId, 'wine' => $wine];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['wine'])) {
            return;
        }

        VivinoEnrichmentData::dispatch($this->integration, $rawData);
    }
}
