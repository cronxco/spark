<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;

class OutlinePullRecentDocuments extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'outline';
    }

    protected function getJobType(): string
    {
        return 'pull_recent_documents';
    }

    protected function fetchData(): array
    {
        $api = new OutlineApi($this->integration);
        $limit = (int) ($this->integration->configuration['document_limit'] ?? 10);

        $documents = $api->listDocuments([
            'limit' => $limit,
            'sort' => 'updatedAt',
            'direction' => 'DESC',
        ]);

        // Exclude documents from the daynotes collection to avoid duplicate "had_day_note" events
        $daynotesCollectionId = $this->getDayNotesCollectionId();
        if (!empty($daynotesCollectionId)) {
            $documents = array_filter($documents, function ($doc) use ($daynotesCollectionId) {
                return ($doc['collectionId'] ?? '') !== $daynotesCollectionId;
            });
        }

        return [
            'collections' => [],
            'documents' => $documents,
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        OutlineData::dispatch($this->integration, $rawData)
            ->onQueue('pull');
    }

    private function getDayNotesCollectionId(): string
    {
        return (string) (($this->integration->configuration['daynotes_collection_id'] ?? null)
            ?: config('services.outline.daynotes_collection_id'));
    }
}
