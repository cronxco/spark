<?php

namespace App\Jobs\Data\Karakeep;

use App\Jobs\Base\BaseProcessingJob;
use Illuminate\Support\Facades\Log;

class KarakeepBookmarksData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'karakeep';
    }

    protected function getJobType(): string
    {
        return 'bookmarks';
    }

    protected function process(): void
    {
        $bookmarks = $this->rawData['bookmarks'] ?? [];
        $tagsData = $this->rawData['tags'] ?? [];
        $listsData = $this->rawData['lists'] ?? [];
        $highlightsData = $this->rawData['highlights'] ?? [];

        if (empty($bookmarks)) {
            Log::info('Karakeep Bookmarks Data: No bookmarks to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        Log::info('KarakeepBookmarksData: Dispatching bookmark jobs', [
            'integration_id' => $this->integration->id,
            'bookmark_count' => count($bookmarks),
            'tags_count' => count($tagsData),
            'lists_count' => count($listsData),
            'highlights_count' => count($highlightsData),
        ]);

        // Create maps for easy lookup
        $tagsMap = [];
        foreach ($tagsData as $tag) {
            if (isset($tag['id'])) {
                $tagsMap[$tag['id']] = $tag;
            }
        }

        $listsMap = [];
        foreach ($listsData as $list) {
            if (isset($list['id'])) {
                $listsMap[$list['id']] = $list;
            }
        }

        // Prepare context data to pass to individual bookmark jobs
        $contextData = [
            'tags' => $tagsMap,
            'lists' => $listsMap,
            'highlights' => $highlightsData,
        ];

        // Dispatch a separate job for each bookmark
        foreach ($bookmarks as $bookmark) {
            $bookmarkId = $bookmark['id'] ?? null;
            if (! $bookmarkId) {
                Log::warning('Skipping bookmark without ID', [
                    'integration_id' => $this->integration->id,
                    'bookmark' => $bookmark,
                ]);

                continue;
            }

            // Dispatch individual bookmark processing job
            KarakeepBookmarkData::dispatch($this->integration, $bookmark, $contextData);
        }

        Log::info('KarakeepBookmarksData: Dispatched all bookmark jobs', [
            'integration_id' => $this->integration->id,
            'jobs_dispatched' => count($bookmarks),
        ]);
    }
}
