<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;

class OutlinePullRecentDayNotes extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'outline';
    }

    protected function getJobType(): string
    {
        return 'pull_recent_daynotes';
    }

    protected function fetchData(): array
    {
        $api = new OutlineApi($this->integration);
        $collectionId = $this->getDayNotesCollectionId();

        if (empty($collectionId)) {
            return [
                'collections' => [],
                'documents' => [],
            ];
        }

        $limit = (int) ($this->integration->configuration['document_limit'] ?? 5);

        $documents = $api->listDocuments([
            'collectionId' => $collectionId,
            'limit' => $limit,
            'sort' => 'updatedAt',
            'direction' => 'DESC',
        ]);

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
