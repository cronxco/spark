<?php

namespace App\Jobs\OAuth\Karakeep;

use App\Integrations\Karakeep\KarakeepPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Karakeep\KarakeepBookmarksData;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

class KarakeepBookmarksPull extends BaseFetchJob
{
    public function getIntegration()
    {
        return $this->integration;
    }

    protected function getServiceName(): string
    {
        return 'karakeep';
    }

    protected function getJobType(): string
    {
        return 'bookmarks';
    }

    protected function fetchData(): array
    {
        $group = $this->integration->group;
        if (! $group) {
            throw new Exception('Integration group not found');
        }

        $apiUrl = $group->auth_metadata['api_url'] ?? config('services.karakeep.url');
        $accessToken = $group->access_token ?? config('services.karakeep.access_token');

        if (! $apiUrl || ! $accessToken) {
            throw new Exception('Karakeep API URL or access token not configured');
        }

        $baseUrl = rtrim($apiUrl, '/');
        $config = $this->integration->configuration ?? [];
        $fetchLimit = $config['fetch_limit'] ?? 50;
        $syncHighlights = $config['sync_highlights'] ?? true;

        Log::info('Karakeep Bookmarks Pull: Starting fetch', [
            'integration_id' => $this->integration->id,
            'api_url' => $baseUrl,
            'fetch_limit' => $fetchLimit,
        ]);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $plugin = new KarakeepPlugin;

        // Fetch user info
        $plugin->logApiRequest('GET', '/api/v1/users/me', ['Authorization' => '[REDACTED]'], [], (string) $this->integration->id);
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET /api/v1/users/me'));
        $userResponse = Http::withToken($accessToken)
            ->get($baseUrl . '/api/v1/users/me');
        $span?->finish();
        $plugin->logApiResponse('GET', '/api/v1/users/me', $userResponse->status(), $userResponse->body(), $userResponse->headers(), (string) $this->integration->id);

        if (! $userResponse->successful()) {
            throw new Exception('Failed to fetch Karakeep user info: ' . $userResponse->body());
        }

        $userData = $userResponse->json();

        // Fetch bookmarks
        $bookmarksQuery = [
            'limit' => $fetchLimit,
            'sort' => 'updatedAt',
            'order' => 'desc',
        ];
        $plugin->logApiRequest('GET', '/api/v1/bookmarks', ['Authorization' => '[REDACTED]'], $bookmarksQuery, (string) $this->integration->id);
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET /api/v1/bookmarks'));
        $bookmarksResponse = Http::withToken($accessToken)
            ->get($baseUrl . '/api/v1/bookmarks', $bookmarksQuery);
        $span?->finish();
        $plugin->logApiResponse('GET', '/api/v1/bookmarks', $bookmarksResponse->status(), $bookmarksResponse->body(), $bookmarksResponse->headers(), (string) $this->integration->id);

        if (! $bookmarksResponse->successful()) {
            throw new Exception('Failed to fetch Karakeep bookmarks: ' . $bookmarksResponse->body());
        }

        $bookmarksData = $bookmarksResponse->json();

        // Fetch tags
        $plugin->logApiRequest('GET', '/api/v1/tags', ['Authorization' => '[REDACTED]'], [], (string) $this->integration->id);
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET /api/v1/tags'));
        $tagsResponse = Http::withToken($accessToken)
            ->get($baseUrl . '/api/v1/tags');
        $span?->finish();
        $plugin->logApiResponse('GET', '/api/v1/tags', $tagsResponse->status(), $tagsResponse->body(), $tagsResponse->headers(), (string) $this->integration->id);

        if (! $tagsResponse->successful()) {
            Log::warning('Failed to fetch Karakeep tags, continuing without tags', [
                'integration_id' => $this->integration->id,
                'error' => $tagsResponse->body(),
            ]);
            $tagsData = ['tags' => []];
        } else {
            $tagsData = $tagsResponse->json();
        }

        // Fetch lists
        $plugin->logApiRequest('GET', '/api/v1/lists', ['Authorization' => '[REDACTED]'], [], (string) $this->integration->id);
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET /api/v1/lists'));
        $listsResponse = Http::withToken($accessToken)
            ->get($baseUrl . '/api/v1/lists');
        $span?->finish();
        $plugin->logApiResponse('GET', '/api/v1/lists', $listsResponse->status(), $listsResponse->body(), $listsResponse->headers(), (string) $this->integration->id);

        if (! $listsResponse->successful()) {
            Log::warning('Failed to fetch Karakeep lists, continuing without lists', [
                'integration_id' => $this->integration->id,
                'error' => $listsResponse->body(),
            ]);
            $listsData = ['lists' => []];
        } else {
            $listsData = $listsResponse->json();
        }

        // Fetch highlights if enabled
        $highlightsData = [];
        if ($syncHighlights) {
            $plugin->logApiRequest('GET', '/api/v1/highlights', ['Authorization' => '[REDACTED]'], [], (string) $this->integration->id);
            $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('GET /api/v1/highlights'));
            $highlightsResponse = Http::withToken($accessToken)
                ->get($baseUrl . '/api/v1/highlights');
            $span?->finish();
            $plugin->logApiResponse('GET', '/api/v1/highlights', $highlightsResponse->status(), $highlightsResponse->body(), $highlightsResponse->headers(), (string) $this->integration->id);

            if ($highlightsResponse->successful()) {
                $highlightsData = $highlightsResponse->json();
            } else {
                Log::warning('Failed to fetch Karakeep highlights, continuing without highlights', [
                    'integration_id' => $this->integration->id,
                    'error' => $highlightsResponse->body(),
                ]);
            }
        }

        Log::info('Karakeep Bookmarks Pull: Fetch completed', [
            'integration_id' => $this->integration->id,
            'bookmarks_count' => count($bookmarksData['bookmarks'] ?? []),
            'tags_count' => count($tagsData['tags'] ?? []),
            'lists_count' => count($listsData['lists'] ?? []),
            'highlights_count' => count($highlightsData['highlights'] ?? []),
        ]);

        return [
            'user' => $userData,
            'bookmarks' => $bookmarksData['bookmarks'] ?? [],
            'tags' => $tagsData['tags'] ?? [],
            'lists' => $listsData['lists'] ?? [],
            'highlights' => $highlightsData['highlights'] ?? [],
            'fetched_at' => now()->toISOString(),
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['bookmarks'])) {
            Log::info('Karakeep Bookmarks Pull: No bookmarks to process', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Dispatch data processing job
        KarakeepBookmarksData::dispatch($this->integration, $rawData);
    }
}
