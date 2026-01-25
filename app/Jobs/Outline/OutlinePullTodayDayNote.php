<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class OutlinePullTodayDayNote extends BaseFetchJob
{
    private string $forDate; // Y-m-d in UTC

    public function __construct(Integration $integration, ?string $forDate = null)
    {
        parent::__construct($integration);
        if ($forDate) {
            $this->forDate = CarbonImmutable::parse($forDate, 'UTC')->format('Y-m-d');
        } else {
            $this->forDate = CarbonImmutable::now('UTC')->format('Y-m-d');
        }
    }

    public function uniqueId(): string
    {
        return $this->getServiceName() . '_' . $this->getJobType() . '_' . $this->integration->id . '_' . $this->forDate;
    }

    protected function getServiceName(): string
    {
        return 'outline';
    }

    protected function getJobType(): string
    {
        return 'pull_today';
    }

    protected function fetchData(): array
    {
        $api = new OutlineApi($this->integration);

        $daynotesCollectionId = $api->daynotesCollectionId();

        if ($daynotesCollectionId === '') {
            return [
                'collections' => [],
                'documents' => [],
            ];
        }

        // Title format used by processing logic: 'YYYY-MM-DD: DayName' in UTC
        $title = CarbonImmutable::createFromFormat('Y-m-d', $this->forDate, 'UTC')->format('Y-m-d: l');

        // Try to locate today's document within the Day Notes collection
        // Use Outline's dedicated search endpoint with date-only query for efficiency
        $searchResult = $api->searchSingleDocument([
            'collectionId' => $daynotesCollectionId,
            'query' => $this->forDate, // Search by date (Y-m-d) instead of full title
        ]);

        $foundDocuments = [];
        if ($searchResult) {
            // Search results have a different structure - the document is nested under 'document'
            $doc = $searchResult['document'] ?? $searchResult;

            if (($doc['title'] ?? '') === $title) {
                // Enrich with full content
                $info = $api->getDocument((string) ($doc['id'] ?? ''));
                $full = $info['data'] ?? [];
                if (! isset($full['text']) && isset($full['id'])) {
                    // Some Outline responses may nest text differently; prefer 'text' when available
                }
                $foundDocuments[] = array_merge($doc, $full);
            }
        }

        return [
            'collections' => [],
            'documents' => $foundDocuments,
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['documents'])) {
            // Log warning when no day note is found for the requested date
            Log::warning('OutlinePullTodayDayNote: No day note found for date', [
                'integration_id' => $this->integration->id,
                'requested_date' => $this->forDate,
                'title_searched' => CarbonImmutable::createFromFormat('Y-m-d', $this->forDate, 'UTC')->format('Y-m-d: l'),
            ]);

            return;
        }

        OutlineData::dispatch($this->integration, $rawData)
            ->onQueue('pull');
    }
}
