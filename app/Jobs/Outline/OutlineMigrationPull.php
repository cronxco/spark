<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;
use App\Models\Integration;
use Exception;
use Illuminate\Support\Facades\Log;

class OutlineMigrationPull extends BaseFetchJob
{
    private int $offset;
    private int $limit;

    public function __construct(Integration $integration, int $offset = 0, int $limit = 50)
    {
        parent::__construct($integration);
        $this->offset = $offset;
        $this->limit = $limit;
    }

    public function uniqueId(): string
    {
        return $this->getServiceName() . '_migration_' . $this->integration->id . '_' . $this->offset;
    }

    protected function getServiceName(): string
    {
        return 'outline';
    }

    protected function getJobType(): string
    {
        return 'migration';
    }

    protected function fetchData(): array
    {
        $api = new OutlineApi($this->integration);

        // Collections (only fetch once, on first chunk)
        $collections = [];
        if ($this->offset === 0) {
            $collections = $api->listCollections();
        }

        // Documents with pagination
        $documents = $api->listDocuments([
            'limit' => $this->limit,
            'sort' => 'createdAt', // Use createdAt for consistent ordering
            'direction' => 'ASC', // Start from oldest
        ]);

        return [
            'collections' => $collections,
            'documents' => $documents,
            'migration_metadata' => [
                'offset' => $this->offset,
                'limit' => $this->limit,
                'is_last_chunk' => count($documents) < $this->limit,
            ],
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        try {
            // Process this chunk
            OutlineData::dispatch($this->integration, $rawData)
                ->onQueue('pull');

            // Check if we need to dispatch the next chunk
            $metadata = $rawData['migration_metadata'] ?? [];
            $isLastChunk = $metadata['is_last_chunk'] ?? false;

            if (! $isLastChunk) {
                $nextOffset = $this->offset + $this->limit;

                // Dispatch next chunk with a small delay to prevent overwhelming the API
                OutlineMigrationPull::dispatch($this->integration, $nextOffset, $this->limit)
                    ->onQueue('pull')
                    ->delay(now()->addSeconds(2)); // 2-second delay between chunks

                Log::info('Outline migration: dispatched next chunk', [
                    'integration_id' => $this->integration->id,
                    'next_offset' => $nextOffset,
                    'limit' => $this->limit,
                ]);
            } else {
                // Migration complete - update status
                $this->integration->update([
                    'configuration->migration_status' => 'completed',
                    'configuration->migration_completed_at' => now()->toIso8601String(),
                ]);

                Log::info('Outline migration completed', [
                    'integration_id' => $this->integration->id,
                    'total_chunks' => ceil($this->offset / $this->limit) + 1,
                ]);
            }
        } catch (Exception $e) {
            // Handle migration failure
            $this->integration->update([
                'configuration->migration_status' => 'failed',
            ]);

            Log::error('Outline migration failed', [
                'integration_id' => $this->integration->id,
                'offset' => $this->offset,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
