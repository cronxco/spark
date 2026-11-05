<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;

class OutlinePull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'outline';
    }

    protected function getJobType(): string
    {
        return 'pull';
    }

    protected function fetchData(): array
    {
        $api = new OutlineApi($this->integration);

        // Collections
        $collections = $api->listCollections();

        // Documents: fetch recent by updatedAt DESC; Outline API doesn't filter by time
        // We will rely on processing job idempotency and per-document updatedAt comparisons
        $documents = $api->listDocuments([
            'sort' => 'updatedAt',
            'direction' => 'DESC',
        ]);

        return [
            'collections' => $collections,
            'documents' => $documents,
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        OutlineData::dispatch($this->integration, $rawData)
            ->onQueue('pull');
    }
}
