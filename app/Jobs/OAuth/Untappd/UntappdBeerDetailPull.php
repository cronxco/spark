<?php

namespace App\Jobs\OAuth\Untappd;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Untappd\UntappdBeerDetailData;
use App\Models\Integration;
use Exception;

class UntappdBeerDetailPull extends BaseFetchJob
{
    public function __construct(
        public Integration $integration,
        public int $beerId,
        public string $beerUrl
    ) {
        parent::__construct($integration);
    }

    protected function getServiceName(): string
    {
        return 'untappd';
    }

    protected function getJobType(): string
    {
        return 'beer_detail';
    }

    protected function getUniqueId(): string
    {
        // Weekly idempotency per beer
        $weekStart = now()->startOfWeek()->format('Y-m-d');

        return sprintf(
            'untappd_beer_detail_%d_%s',
            $this->beerId,
            $weekStart
        );
    }

    protected function fetchData(): array
    {
        $client = app(PlaywrightFetchClient::class);
        $result = $client->fetch($this->beerUrl, $this->integration->group);

        if (! $result['success']) {
            throw new Exception('Playwright fetch failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        return [
            'html' => $result['html'],
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        UntappdBeerDetailData::dispatch(
            $this->integration,
            $this->beerId,
            $rawData['html']
        );
    }
}
