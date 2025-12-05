<?php

namespace App\Jobs\OAuth\Untappd;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Untappd\UntappdCheckinDetailData;
use App\Models\Integration;
use Exception;

class UntappdCheckinDetailPull extends BaseFetchJob
{
    public function __construct(
        public Integration $integration,
        public string $eventId,
        public string $checkinUrl
    ) {
        parent::__construct($integration);
    }

    protected function getServiceName(): string
    {
        return 'untappd';
    }

    protected function getJobType(): string
    {
        return 'checkin_detail';
    }

    protected function getUniqueId(): string
    {
        // Daily idempotency per event
        return sprintf(
            'untappd_checkin_detail_%d_%s',
            $this->eventId,
            now()->format('Y-m-d')
        );
    }

    protected function fetchData(): array
    {
        $client = app(PlaywrightFetchClient::class);
        $result = $client->fetch($this->checkinUrl, $this->integration->group);

        if (! $result['success']) {
            throw new Exception('Playwright fetch failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        return [
            'html' => $result['html'],
            'screenshot' => $result['screenshot'] ?? null,
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        UntappdCheckinDetailData::dispatch(
            $this->integration,
            [
                'event_id' => $this->eventId,
                'html' => $rawData['html'],
            ]
        );
    }
}
