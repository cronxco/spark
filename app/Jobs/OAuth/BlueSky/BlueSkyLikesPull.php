<?php

namespace App\Jobs\OAuth\BlueSky;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\BlueSky\BlueSkyLikesData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Revolution\Bluesky\Facades\Bluesky;
use Throwable;

class BlueSkyLikesPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'bluesky';
    }

    protected function getJobType(): string
    {
        return 'likes';
    }

    protected function fetchData(): array
    {
        $cursor = $this->integration->configuration['bluesky']['likes_cursor'] ?? null;
        $limit = 50; // BlueSky API default limit

        Log::info('BlueSky: fetching likes', [
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

                return ['likes' => [], 'cursor' => null];
            }

            // Get the user's DID from the group
            $did = $group->account_id ?? $group->auth_metadata['did'] ?? null;

            if (! $did) {
                Log::error('BlueSky: missing DID', [
                    'integration_id' => $this->integration->id,
                ]);

                return ['likes' => [], 'cursor' => null];
            }

            // Use the BlueSky package to fetch likes
            $response = Bluesky::withToken(
                token: $group->access_token,
                refreshToken: $group->refresh_token
            )->getActorLikes(
                actor: $did,
                limit: $limit,
                cursor: $cursor
            );

            $feed = $response->json('feed', []);
            $nextCursor = $response->json('cursor');

            Log::info('BlueSky: likes fetched', [
                'integration_id' => $this->integration->id,
                'count' => count($feed),
                'next_cursor' => $nextCursor,
            ]);

            return [
                'likes' => $feed,
                'cursor' => $nextCursor,
            ];
        } catch (Throwable $e) {
            Log::error('BlueSky: likes fetch failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $likes = $rawData['likes'] ?? [];
        $cursor = $rawData['cursor'] ?? null;

        if (empty($likes)) {
            Log::info('BlueSky: no likes to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch data processing job
        BlueSkyLikesData::dispatch($this->integration, ['likes' => $likes]);

        // Persist pagination cursor for next run
        $config = $this->integration->configuration ?? [];
        Arr::set($config, 'bluesky.likes_cursor', $cursor);
        $this->integration->update(['configuration' => $config]);

        Log::info('BlueSky: likes processing dispatched', [
            'integration_id' => $this->integration->id,
            'likes_count' => count($likes),
            'cursor_updated' => $cursor,
        ]);
    }
}
