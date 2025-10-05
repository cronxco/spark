<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;
use App\Models\ActionProgress;
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
    private ?ActionProgress $progressRecord = null;

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
        // Get the progress record to update during migration
        if ($this->progressRecord === null) {
            $this->progressRecord = ActionProgress::getLatestProgress(
                $this->integration->user_id,
                'migration',
                "integration_{$this->integration->id}"
            );
        }

        $api = new OutlineApi($this->integration);

        // Collections (only fetch once, on first chunk)
        $collections = [];
        if ($this->offset === 0) {
            $collections = $api->listCollections();
            $this->updateProgress('fetching_collections', 'Fetching Outline collections...', 50);
        } else {
            // Calculate progress: Start at 50% (after initial setup) and progress toward 95%
            // Assume we'll fetch roughly 1000 documents max for progress calculation
            $maxEstimatedDocs = 1000;
            $progressRange = 45; // From 50% to 95%
            $docProgress = min(($this->offset / $maxEstimatedDocs) * $progressRange, $progressRange);
            $currentProgress = 50 + $docProgress;

            $this->updateProgress('fetching_documents', "Fetching Outline documents (batch starting at {$this->offset})...", (int) $currentProgress, [
                'current_offset' => $this->offset,
                'batch_size' => $this->limit,
            ]);
        }

        // Documents with pagination and optional collection filtering
        $documents = $this->fetchDocumentsWithFiltering($api);

        // Log the fetch results for debugging
        Log::info('Outline migration: fetched documents', [
            'integration_id' => $this->integration->id,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'documents_count' => count($documents),
            'context' => $this->context,
            'is_last_chunk' => count($documents) < $this->limit,
        ]);

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
                // Migration complete - update progress to 100% and mark complete
                $this->updateProgress('completed', 'Outline migration completed successfully', 100, [
                    'total_chunks' => ceil($this->offset / $this->limit) + 1,
                    'total_documents_processed' => $this->offset,
                    'context' => $context,
                ]);

                // Update integration status
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
            // Handle migration failure - update progress and unpause
            $this->updateProgress('failed', 'Outline migration failed: ' . $e->getMessage(), 0, [
                'error' => $e->getMessage(),
                'offset' => $this->offset,
                'integration_id' => $this->integration->id,
            ]);

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

        $params = [
            'offset' => $this->offset,
            'limit' => $this->limit,
            'sort' => 'createdAt',
            'direction' => 'ASC',
        ];

        // If we have a single collection to include, use the API's native collectionId filter
        if ($includeCollectionIds && is_array($includeCollectionIds) && count($includeCollectionIds) === 1) {
            $params['collectionId'] = $includeCollectionIds[0];

            return $api->listDocumentsPaginated($params);
        }

        // For multiple collections to include, we need to handle it differently
        if ($includeCollectionIds && is_array($includeCollectionIds) && count($includeCollectionIds) > 1) {
            return $this->fetchFromMultipleCollections($api, $includeCollectionIds);
        }

        // For exclusion filtering, we still need the custom logic
        if ($excludeCollectionIds && is_array($excludeCollectionIds)) {
            return $this->fetchAllDocumentsExcludingCollections($api, $excludeCollectionIds);
        }

        // Default case: fetch all documents without filtering
        return $api->listDocumentsPaginated($params);
    }

    /**
     * Fetch documents from specific collections with proper pagination
     *
     * Note: This is a simplified approach that fetches all documents from specified collections
     * and then applies pagination. For very large collections, this could be optimized further.
     */
    /**
     * Fetch documents from multiple specific collections
     * This method handles the complex case where we need documents from multiple collections
     */
    private function fetchFromMultipleCollections(OutlineApi $api, array $collectionIds): array
    {
        $allDocuments = [];

        // For multiple collections, we fetch all documents from each collection
        // up to a reasonable limit, then sort and paginate in memory
        foreach ($collectionIds as $collectionId) {
            $collectionDocuments = $api->listDocumentsPaginated([
                'collectionId' => $collectionId,
                'offset' => 0,
                'limit' => 100, // API limit
                'sort' => 'createdAt',
                'direction' => 'ASC',
            ]);

            $allDocuments = array_merge($allDocuments, $collectionDocuments);
        }

        // Sort combined results by createdAt to maintain consistency across collections
        usort($allDocuments, function ($a, $b) {
            return strcmp($a['createdAt'] ?? '', $b['createdAt'] ?? '');
        });

        // Apply pagination to the sorted combined results
        return array_slice($allDocuments, $this->offset, $this->limit);
    }

    /**
     * Fetch documents excluding specific collections, handling pagination properly
     */
    private function fetchAllDocumentsExcludingCollections(OutlineApi $api, array $excludeCollectionIds): array
    {
        $documents = [];
        $fetchOffset = $this->offset;
        $remainingNeeded = $this->limit;
        $maxAttempts = 5; // Prevent infinite loops
        $attempts = 0;

        while ($remainingNeeded > 0 && $attempts < $maxAttempts) {
            // Fetch more documents than needed to account for filtering
            $fetchLimit = min($remainingNeeded * 2, 100);

            $batch = $api->listDocumentsPaginated([
                'offset' => $fetchOffset,
                'limit' => $fetchLimit,
                'sort' => 'createdAt',
                'direction' => 'ASC',
            ]);

            if (empty($batch)) {
                // No more documents available
                break;
            }

            // Filter out excluded collections
            $filteredBatch = array_filter($batch, function ($doc) use ($excludeCollectionIds) {
                $collectionId = $doc['collectionId'] ?? null;

                return $collectionId === null || ! in_array($collectionId, $excludeCollectionIds);
            });

            $filteredBatch = array_values($filteredBatch);

            // Take only what we need
            $toTake = min(count($filteredBatch), $remainingNeeded);
            $documents = array_merge($documents, array_slice($filteredBatch, 0, $toTake));

            $remainingNeeded -= $toTake;
            $fetchOffset += count($batch); // Move forward by the number of documents we actually fetched
            $attempts++;

            // If we got fewer documents than requested from API, we've reached the end
            if (count($batch) < $fetchLimit) {
                break;
            }
        }

        return $documents;
    }

    /**
     * Update migration progress if we have a progress record
     */
    private function updateProgress(string $step, string $message, int $progress, array $details = []): void
    {
        if ($this->progressRecord) {
            $this->progressRecord->updateProgress($step, $message, $progress, $details);
        }
    }
}
