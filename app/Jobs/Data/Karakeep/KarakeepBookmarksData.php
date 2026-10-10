<?php

namespace App\Jobs\Data\Karakeep;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
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
            'user' => $this->rawData['user'] ?? null,
            'tags' => $tagsMap,
            'lists' => $listsMap,
            'highlights' => $highlightsData,
        ];

        // The fetch is a full scan, so only dispatch bookmarks that are new or have changed
        $bookmarks = $this->withoutUnchangedBookmarks($bookmarks);

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

    /**
     * Drop bookmarks whose event already exists, whose modifiedAt matches the stored object,
     * and whose list memberships all have events, so a full scan doesn't queue a job per bookmark.
     */
    protected function withoutUnchangedBookmarks(array $bookmarks): array
    {
        $storedModifiedAt = EventObject::where('user_id', $this->integration->user_id)
            ->where('concept', 'bookmark')
            ->where('type', 'karakeep_bookmark')
            ->get(['metadata'])
            ->mapWithKeys(fn (EventObject $object) => [
                (string) ($object->metadata['karakeep_id'] ?? '') => $object->metadata['updated_at'] ?? null,
            ]);

        $existingSourceIds = Event::where('integration_id', $this->integration->id)
            ->where(fn ($query) => $query->where('source_id', 'like', 'karakeep_bookmark_%')
                ->orWhere('source_id', 'like', 'karakeep_added_to_list_%'))
            ->pluck('source_id')
            ->flip();

        $changed = array_values(array_filter($bookmarks, function (array $bookmark) use ($storedModifiedAt, $existingSourceIds) {
            $bookmarkId = $bookmark['id'] ?? null;
            if (! $bookmarkId || ! $existingSourceIds->has("karakeep_bookmark_{$bookmarkId}")) {
                return true;
            }

            if (! $storedModifiedAt->has($bookmarkId) || $storedModifiedAt->get($bookmarkId) !== ($bookmark['modifiedAt'] ?? null)) {
                return true;
            }

            foreach ($bookmark['lists'] ?? [] as $listId) {
                if (! $existingSourceIds->has("karakeep_added_to_list_{$bookmarkId}_{$listId}")) {
                    return true;
                }
            }

            return false;
        }));

        Log::info('KarakeepBookmarksData: Skipped unchanged bookmarks', [
            'integration_id' => $this->integration->id,
            'skipped' => count($bookmarks) - count($changed),
        ]);

        return $changed;
    }
}
