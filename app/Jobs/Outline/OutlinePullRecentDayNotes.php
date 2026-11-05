<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Jobs\Base\BaseFetchJob;
use Carbon\CarbonImmutable;

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

        // Get day notes from current month, previous month, and next month
        $documents = $this->fetchDayNotesForMonths($api, $collectionId);

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

    private function fetchDayNotesForMonths(OutlineApi $api, string $collectionId): array
    {
        $now = CarbonImmutable::now('UTC');

        // Define the three months we want to fetch
        $months = [
            $now->subMonth(), // Previous month
            $now,             // Current month
            $now->addMonth(), // Next month
        ];

        $allDocuments = [];

        foreach ($months as $month) {
            $yearMonth = $month->format('Y-m'); // e.g., "2025-09"

            // Search for documents in this month within the Day Notes collection
            // Since we're filtering by collectionId, we should only get day notes
            // Use limited search to avoid unnecessary pagination
            $searchResults = $api->searchDocumentsLimited([
                'collectionId' => $collectionId,
                'query' => $yearMonth,
            ], 50); // Limit to 50 results per month (should be more than enough)

            // Extract documents from search results
            foreach ($searchResults as $searchResult) {
                $doc = $searchResult['document'] ?? $searchResult;

                // Verify this is actually a day note for this month
                $title = $doc['title'] ?? '';
                if (str_starts_with($title, $yearMonth)) {
                    $allDocuments[] = $doc;
                }
            }
        }

        // Remove duplicates (in case a document appears in multiple searches)
        $uniqueDocuments = [];
        $seenIds = [];

        foreach ($allDocuments as $doc) {
            $docId = $doc['id'] ?? null;
            if ($docId && ! in_array($docId, $seenIds)) {
                $uniqueDocuments[] = $doc;
                $seenIds[] = $docId;
            }
        }

        // Sort by updatedAt DESC to get most recent first
        usort($uniqueDocuments, function ($a, $b) {
            $updatedA = $a['updatedAt'] ?? '';
            $updatedB = $b['updatedAt'] ?? '';

            return strcmp($updatedB, $updatedA); // DESC order
        });

        return $uniqueDocuments;
    }

    private function getDayNotesCollectionId(): string
    {
        return (string) (($this->integration->configuration['daynotes_collection_id'] ?? null)
            ?: config('services.outline.daynotes_collection_id'));
    }
}
