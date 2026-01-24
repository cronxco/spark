<?php

namespace App\Jobs\OAuth\Untappd;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Untappd\UntappdBreweryDetailData;
use App\Models\Integration;
use Exception;

class UntappdBreweryDetailPull extends BaseFetchJob
{
    public function __construct(
        public Integration $integration,
        public string $breweryId,
        public string $breweryUrl
    ) {
        parent::__construct($integration);
    }

    protected function getServiceName(): string
    {
        return 'untappd';
    }

    protected function getJobType(): string
    {
        return 'brewery_detail';
    }

    protected function getUniqueId(): string
    {
        // Weekly idempotency per brewery
        $weekStart = now()->startOfWeek()->format('Y-m-d');

        return sprintf(
            'untappd_brewery_detail_%d_%s',
            $this->breweryId,
            $weekStart
        );
    }

    protected function fetchData(): array
    {
        $client = app(PlaywrightFetchClient::class);
        $result = $client->fetch($this->breweryUrl, $this->integration->group);

        if (! $result['success']) {
            throw new Exception('Playwright fetch failed: '.($result['error'] ?? 'Unknown error'));
        }

        return [
            'html' => $result['html'],
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        UntappdBreweryDetailData::dispatch(
            $this->integration,
            [
                'brewery_id' => $this->breweryId,
                'html' => $rawData['html'],
            ]
        );
    }
}
