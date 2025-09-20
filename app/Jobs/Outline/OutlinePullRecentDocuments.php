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
}
