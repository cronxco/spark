<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;
use App\Models\Integration;
use Carbon\CarbonImmutable;

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

        $daynotesCollectionId = (string) (($this->integration->configuration['daynotes_collection_id'] ?? null)
            ?: config('services.outline.daynotes_collection_id'));

        if ($daynotesCollectionId === '') {
            return [
                'collections' => [],
                'documents' => [],
            ];
        }

        // Title format used by processing logic: 'YYYY-MM-DD: DayName' in UTC
        $title = CarbonImmutable::createFromFormat('Y-m-d', $this->forDate, 'UTC')->format('Y-m-d: l');

        // Try to locate today's document within the Day Notes collection
        $docsPage = $api->listDocuments([
            'collectionId' => $daynotesCollectionId,
            'limit' => 100,
            'sort' => 'updatedAt',
            'direction' => 'DESC',
            'query' => $title,
        ]);

        $documents = [];
        foreach (($docsPage['data'] ?? $docsPage ?? []) as $doc) {
            if (($doc['title'] ?? '') === $title) {
                // Enrich with full content
                $info = $api->getDocument((string) ($doc['id'] ?? ''));
                $full = $info['data'] ?? [];
                if (! isset($full['text']) && isset($full['id'])) {
                    // Some Outline responses may nest text differently; prefer 'text' when available
                }
                $documents[] = array_merge($doc, $full);
                break;
            }
        }

        return [
            'collections' => [],
            'documents' => $documents,
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['documents'])) {
            return;
        }

        OutlineData::dispatch($this->integration, $rawData)
            ->onQueue('pull');
    }
}
