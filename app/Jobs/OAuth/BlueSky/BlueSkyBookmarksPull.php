<?php

namespace App\Jobs\OAuth\BlueSky;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\BlueSky\BlueSkyBookmarksData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Revolution\Bluesky\Facades\Bluesky;
use Throwable;

class BlueSkyBookmarksPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'bluesky';
    }

    protected function getJobType(): string
    {
        return 'bookmarks';
    }

    protected function fetchData(): array
    {
        $cursor = $this->integration->configuration['bluesky']['bookmarks_cursor'] ?? null;
        $limit = 50; // BlueSky API default limit

        Log::info('BlueSky: fetching bookmarks', [
            'integration_id' => $this->integration->id,
            'cursor' => $cursor,
        ]);

        try {
            // Authenticate with stored tokens
            $group = $this->integration->group;

            if (! $group || ! $group->access_token) {
                Log::error('BlueSky: missing OAuth tokens', [
                    'integration_id' => $this->integration->id,
                ]);

                return ['bookmarks' => [], 'cursor' => null];
            }

            // Use the BlueSky package to fetch bookmarks
            $response = Bluesky::withToken(
                token: $group->access_token,
                refreshToken: $group->refresh_token
            )->getBookmarks(
                limit: $limit,
                cursor: $cursor
            );

            $bookmarks = $response->json('bookmarks', []);
            $nextCursor = $response->json('cursor');

            Log::info('BlueSky: bookmarks fetched', [
                'integration_id' => $this->integration->id,
                'count' => count($bookmarks),
                'next_cursor' => $nextCursor,
            ]);

            return [
                'bookmarks' => $bookmarks,
                'cursor' => $nextCursor,
            ];
        } catch (Throwable $e) {
            Log::error('BlueSky: bookmark fetch failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $bookmarks = $rawData['bookmarks'] ?? [];
        $cursor = $rawData['cursor'] ?? null;

        if (empty($bookmarks)) {
            Log::info('BlueSky: no bookmarks to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch data processing job
        BlueSkyBookmarksData::dispatch($this->integration, ['bookmarks' => $bookmarks]);

        // Persist pagination cursor for next run
        $config = $this->integration->configuration ?? [];
        Arr::set($config, 'bluesky.bookmarks_cursor', $cursor);
        $this->integration->update(['configuration' => $config]);

        Log::info('BlueSky: bookmark processing dispatched', [
            'integration_id' => $this->integration->id,
            'bookmark_count' => count($bookmarks),
            'cursor_updated' => $cursor,
        ]);
    }
}
