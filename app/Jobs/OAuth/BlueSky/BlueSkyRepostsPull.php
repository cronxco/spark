<?php

namespace App\Jobs\OAuth\BlueSky;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\BlueSky\BlueSkyRepostsData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Revolution\Bluesky\Facades\Bluesky;
use Throwable;

class BlueSkyRepostsPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'bluesky';
    }

    protected function getJobType(): string
    {
        return 'reposts';
    }

    protected function fetchData(): array
    {
        $cursor = $this->integration->configuration['bluesky']['reposts_cursor'] ?? null;
        $limit = 50; // BlueSky API default limit

        Log::info('BlueSky: fetching reposts', [
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

                return ['reposts' => [], 'cursor' => null];
            }

            // Get the user's DID from the group
            $did = $group->account_id ?? $group->auth_metadata['did'] ?? null;

            if (! $did) {
                Log::error('BlueSky: missing DID', [
                    'integration_id' => $this->integration->id,
                ]);

                return ['reposts' => [], 'cursor' => null];
            }

            // Use listRecords to get reposts from the user's repository
            $response = Bluesky::withToken(
                token: $group->access_token,
                refreshToken: $group->refresh_token
            )->listRecords(
                repo: $did,
                collection: 'app.bsky.feed.repost',
                limit: $limit,
                cursor: $cursor
            );

            $records = $response->json('records', []);
            $nextCursor = $response->json('cursor');

            // Enrich repost data with full post information
            $enrichedReposts = [];
            if (! empty($records)) {
                $postUris = array_map(function ($record) {
                    return $record['value']['subject']['uri'] ?? null;
                }, $records);

                // Filter out null URIs
                $postUris = array_filter($postUris);

                if (! empty($postUris)) {
                    // Fetch full post data for all reposted posts
                    $postsResponse = Bluesky::withToken(
                        token: $group->access_token,
                        refreshToken: $group->refresh_token
                    )->getPosts(uris: array_values($postUris));

                    $posts = $postsResponse->json('posts', []);

                    // Create a map of URI to post data
                    $postMap = [];
                    foreach ($posts as $post) {
                        $postMap[$post['uri']] = $post;
                    }

                    // Combine repost metadata with full post data
                    foreach ($records as $record) {
                        $postUri = $record['value']['subject']['uri'] ?? null;
                        if ($postUri && isset($postMap[$postUri])) {
                            $enrichedReposts[] = [
                                'repost' => $record,
                                'post' => $postMap[$postUri],
                            ];
                        }
                    }
                }
            }

            Log::info('BlueSky: reposts fetched', [
                'integration_id' => $this->integration->id,
                'count' => count($enrichedReposts),
                'next_cursor' => $nextCursor,
            ]);

            return [
                'reposts' => $enrichedReposts,
                'cursor' => $nextCursor,
            ];
        } catch (Throwable $e) {
            Log::error('BlueSky: reposts fetch failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $reposts = $rawData['reposts'] ?? [];
        $cursor = $rawData['cursor'] ?? null;

        if (empty($reposts)) {
            Log::info('BlueSky: no reposts to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch data processing job
        BlueSkyRepostsData::dispatch($this->integration, ['reposts' => $reposts]);

        // Persist pagination cursor for next run
        $config = $this->integration->configuration ?? [];
        Arr::set($config, 'bluesky.reposts_cursor', $cursor);
        $this->integration->update(['configuration' => $config]);

        Log::info('BlueSky: reposts processing dispatched', [
            'integration_id' => $this->integration->id,
            'reposts_count' => count($reposts),
            'cursor_updated' => $cursor,
        ]);
    }
}
