<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;
use App\Models\Integration;
use App\Traits\MigrationPauser;
use Exception;
use Illuminate\Support\Facades\Log;

class OutlineMigrationPull extends BaseFetchJob
{
    use MigrationPauser;

    private int $offset;
    private int $limit;
    private array $context;

    public function __construct(Integration $integration, int $offset = 0, int $limit = 50, array $context = [])
    {
        parent::__construct($integration);
        $this->offset = $offset;
        $this->limit = $limit;
        $this->context = $context;
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

        // Documents with pagination and optional collection filtering
        $documents = $this->fetchDocumentsWithFiltering($api);

        return [
            'collections' => $collections,
            'documents' => $documents,
            'migration_metadata' => [
                'offset' => $this->offset,
                'limit' => $this->limit,
                'is_last_chunk' => count($documents) < $this->limit,
                'context' => $this->context, // Preserve context for next chunk
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
            $context = $metadata['context'] ?? $this->context;

            if (! $isLastChunk) {
                $nextOffset = $this->offset + $this->limit;

                // Dispatch next chunk with context and a small delay to prevent overwhelming the API
                OutlineMigrationPull::dispatch($this->integration, $nextOffset, $this->limit, $context)
                    ->onQueue('pull')
                    ->delay(now()->addSeconds(2)); // 2-second delay between chunks

                Log::info('Outline migration: dispatched next chunk', [
                    'integration_id' => $this->integration->id,
                    'next_offset' => $nextOffset,
                    'limit' => $this->limit,
                    'context' => $context,
                ]);
            } else {
                // Migration complete - update status and unpause
                $this->integration->update([
                    'configuration->migration_status' => 'completed',
                    'configuration->migration_completed_at' => now()->toIso8601String(),
                ]);

                // Unpause the integration now that migration is complete
                static::unpauseAfterMigration($this->integration);

                Log::info('Outline migration completed', [
                    'integration_id' => $this->integration->id,
                    'total_chunks' => ceil($this->offset / $this->limit) + 1,
                    'context' => $context,
                ]);
            }
        } catch (Exception $e) {
            // Handle migration failure and unpause
            $this->integration->update([
                'configuration->migration_status' => 'failed',
            ]);

            // Unpause the integration even on failure so it's not stuck
            static::unpauseAfterMigration($this->integration);

            Log::error('Outline migration failed', [
                'integration_id' => $this->integration->id,
                'offset' => $this->offset,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fetch documents with collection filtering support
     */
    private function fetchDocumentsWithFiltering(OutlineApi $api): array
    {
        $includeCollectionIds = $this->context['include_collection_ids'] ?? null;
        $excludeCollectionIds = $this->context['exclude_collection_ids'] ?? null;

        // If we have specific collection IDs to include, fetch from each collection
        if ($includeCollectionIds && is_array($includeCollectionIds)) {
            $allDocuments = [];
            foreach ($includeCollectionIds as $collectionId) {
                $collectionDocuments = $api->listDocuments([
                    'collectionId' => $collectionId,
                    'offset' => $this->offset,
                    'limit' => $this->limit,
                    'sort' => 'createdAt',
                    'direction' => 'ASC',
                ]);
                $allDocuments = array_merge($allDocuments, $collectionDocuments);
            }

            // Sort combined results by createdAt to maintain consistency
            usort($allDocuments, function ($a, $b) {
                return strcmp($a['createdAt'] ?? '', $b['createdAt'] ?? '');
            });

            // Apply limit to combined results
            return array_slice($allDocuments, 0, $this->limit);
        }

        // Fetch all documents and apply exclusion filtering if needed
        $documents = $api->listDocuments([
            'offset' => $this->offset,
            'limit' => $this->limit,
            'sort' => 'createdAt', // Use createdAt for consistent ordering
            'direction' => 'ASC', // Start from oldest
        ]);

        // Apply exclude filter if specified
        if ($excludeCollectionIds && is_array($excludeCollectionIds)) {
            $documents = array_filter($documents, function ($doc) use ($excludeCollectionIds) {
                $collectionId = $doc['collectionId'] ?? null;

                return $collectionId === null || ! in_array($collectionId, $excludeCollectionIds);
            });

            // Re-index the array after filtering
            $documents = array_values($documents);
        }

        return $documents;
    }
}
