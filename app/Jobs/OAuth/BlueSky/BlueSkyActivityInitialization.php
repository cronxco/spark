<?php

namespace App\Jobs\OAuth\BlueSky;

use App\Jobs\Base\BaseInitializationJob;
use App\Jobs\Data\BlueSky\BlueSkyBookmarksData;
use App\Jobs\Data\BlueSky\BlueSkyLikesData;
use App\Jobs\Data\BlueSky\BlueSkyRepostsData;
use Illuminate\Support\Facades\Log;
use Revolution\Bluesky\Facades\Bluesky;
use Throwable;

class BlueSkyActivityInitialization extends BaseInitializationJob
{
    protected function getServiceName(): string
    {
        return 'bluesky';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function initialize(): void
    {
        Log::info('BlueSky: starting historical data migration', [
            'integration_id' => $this->integration->id,
        ]);

        $group = $this->integration->group;

        if (! $group || ! $group->access_token) {
            Log::error('BlueSky: missing OAuth tokens for migration', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        $config = $this->integration->configuration;

        // Fetch historical bookmarks if enabled
        if ($config['track_bookmarks'] ?? true) {
            $this->fetchHistoricalBookmarks($group);
        }

        // Fetch historical likes if enabled
        if ($config['track_likes'] ?? true) {
            $this->fetchHistoricalLikes($group);
        }

        // Fetch historical reposts if enabled
        if ($config['track_reposts'] ?? true) {
            $this->fetchHistoricalReposts($group);
        }

        Log::info('BlueSky: completed historical data migration', [
            'integration_id' => $this->integration->id,
        ]);
    }

    protected function fetchHistoricalBookmarks($group): void
    {
        $allBookmarks = [];
        $cursor = null;
        $pageCount = 0;
        $maxPages = 100; // Limit to prevent infinite loops

        do {
            try {
                $response = Bluesky::withToken(
                    token: $group->access_token,
                    refreshToken: $group->refresh_token
                )->getBookmarks(
                    limit: 100,
                    cursor: $cursor
                );

                $bookmarks = $response->json('bookmarks', []);
                $cursor = $response->json('cursor');

                $allBookmarks = array_merge($allBookmarks, $bookmarks);
                $pageCount++;

                Log::info('BlueSky: fetched bookmarks page', [
                    'integration_id' => $this->integration->id,
                    'page' => $pageCount,
                    'count' => count($bookmarks),
                    'total' => count($allBookmarks),
                ]);

                // Dispatch processing in batches of 50
                if (count($allBookmarks) >= 50) {
                    $batch = array_splice($allBookmarks, 0, 50);
                    BlueSkyBookmarksData::dispatch($this->integration, ['bookmarks' => $batch]);
                }

            } catch (Throwable $e) {
                Log::error('BlueSky: bookmark fetch failed during migration', [
                    'integration_id' => $this->integration->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

        } while ($cursor && $pageCount < $maxPages);

        // Process remaining bookmarks
        if (! empty($allBookmarks)) {
            BlueSkyBookmarksData::dispatch($this->integration, ['bookmarks' => $allBookmarks]);
        }

        Log::info('BlueSky: historical bookmarks fetched', [
            'integration_id' => $this->integration->id,
            'total_pages' => $pageCount,
        ]);
    }

    protected function fetchHistoricalLikes($group): void
    {
        $allLikes = [];
        $cursor = null;
        $pageCount = 0;
        $maxPages = 100;

        $did = $group->account_id ?? $group->auth_metadata['did'] ?? null;

        if (! $did) {
            Log::error('BlueSky: missing DID for likes migration', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        do {
            try {
                $response = Bluesky::withToken(
                    token: $group->access_token,
                    refreshToken: $group->refresh_token
                )->getActorLikes(
                    actor: $did,
                    limit: 100,
                    cursor: $cursor
                );

                $likes = $response->json('feed', []);
                $cursor = $response->json('cursor');

                $allLikes = array_merge($allLikes, $likes);
                $pageCount++;

                Log::info('BlueSky: fetched likes page', [
                    'integration_id' => $this->integration->id,
                    'page' => $pageCount,
                    'count' => count($likes),
                    'total' => count($allLikes),
                ]);

                // Dispatch processing in batches of 50
                if (count($allLikes) >= 50) {
                    $batch = array_splice($allLikes, 0, 50);
                    BlueSkyLikesData::dispatch($this->integration, ['likes' => $batch]);
                }

            } catch (Throwable $e) {
                Log::error('BlueSky: likes fetch failed during migration', [
                    'integration_id' => $this->integration->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

        } while ($cursor && $pageCount < $maxPages);

        // Process remaining likes
        if (! empty($allLikes)) {
            BlueSkyLikesData::dispatch($this->integration, ['likes' => $allLikes]);
        }

        Log::info('BlueSky: historical likes fetched', [
            'integration_id' => $this->integration->id,
            'total_pages' => $pageCount,
        ]);
    }

    protected function fetchHistoricalReposts($group): void
    {
        $allReposts = [];
        $cursor = null;
        $pageCount = 0;
        $maxPages = 100;

        $did = $group->account_id ?? $group->auth_metadata['did'] ?? null;

        if (! $did) {
            Log::error('BlueSky: missing DID for reposts migration', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        do {
            try {
                $response = Bluesky::withToken(
                    token: $group->access_token,
                    refreshToken: $group->refresh_token
                )->listRecords(
                    repo: $did,
                    collection: 'app.bsky.feed.repost',
                    limit: 100,
                    cursor: $cursor
                );

                $records = $response->json('records', []);
                $cursor = $response->json('cursor');

                // Enrich with post data
                if (! empty($records)) {
                    $postUris = array_map(function ($record) {
                        return $record['value']['subject']['uri'] ?? null;
                    }, $records);

                    $postUris = array_filter($postUris);

                    if (! empty($postUris)) {
                        $postsResponse = Bluesky::withToken(
                            token: $group->access_token,
                            refreshToken: $group->refresh_token
                        )->getPosts(uris: array_values($postUris));

                        $posts = $postsResponse->json('posts', []);
                        $postMap = [];
                        foreach ($posts as $post) {
                            $postMap[$post['uri']] = $post;
                        }

                        foreach ($records as $record) {
                            $postUri = $record['value']['subject']['uri'] ?? null;
                            if ($postUri && isset($postMap[$postUri])) {
                                $allReposts[] = [
                                    'repost' => $record,
                                    'post' => $postMap[$postUri],
                                ];
                            }
                        }
                    }
                }

                $pageCount++;

                Log::info('BlueSky: fetched reposts page', [
                    'integration_id' => $this->integration->id,
                    'page' => $pageCount,
                    'count' => count($records),
                    'total' => count($allReposts),
                ]);

                // Dispatch processing in batches of 50
                if (count($allReposts) >= 50) {
                    $batch = array_splice($allReposts, 0, 50);
                    BlueSkyRepostsData::dispatch($this->integration, ['reposts' => $batch]);
                }

            } catch (Throwable $e) {
                Log::error('BlueSky: reposts fetch failed during migration', [
                    'integration_id' => $this->integration->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

        } while ($cursor && $pageCount < $maxPages);

        // Process remaining reposts
        if (! empty($allReposts)) {
            BlueSkyRepostsData::dispatch($this->integration, ['reposts' => $allReposts]);
        }

        Log::info('BlueSky: historical reposts fetched', [
            'integration_id' => $this->integration->id,
            'total_pages' => $pageCount,
        ]);
    }
}
