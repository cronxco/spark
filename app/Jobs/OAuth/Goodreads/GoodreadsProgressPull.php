<?php

namespace App\Jobs\OAuth\Goodreads;

use App\Integrations\Fetch\FetchEngineManager;
use App\Integrations\Goodreads\GoodreadsPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Goodreads\GoodreadsProgressData;
use Exception;
use Illuminate\Support\Facades\Log;

class GoodreadsProgressPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'progress';
    }

    protected function fetchData(): array
    {
        $group = $this->integration->group;
        $userId = $group->auth_metadata['user_id'] ?? null;
        $apiKey = $group->auth_metadata['api_key'] ?? null;

        if (! $userId || ! $apiKey) {
            throw new Exception('User ID and API key not configured in integration group settings');
        }

        $rssUrl = GoodreadsPlugin::buildUpdatesUrl($userId, $apiKey);

        // Use FetchEngineManager to fetch RSS
        $fetchManager = app(FetchEngineManager::class);
        $result = $fetchManager->fetch($rssUrl, $group);

        if ($result['error']) {
            throw new Exception('RSS fetch failed: ' . $result['error']);
        }

        // Parse XML
        $xml = simplexml_load_string($result['html']);

        if ($xml === false) {
            throw new Exception('Failed to parse RSS XML');
        }

        $items = [];

        foreach ($xml->channel->item as $item) {
            $guid = (string) $item->guid;

            Log::info('Processing Goodreads RSS item', ['guid' => $guid]);

            $title = (string) $item->title;
            $pubDate = (string) $item->pubDate;

            // Handle UserStatus items (reading progress)
            if (str_starts_with($guid, 'UserStatus')) {
                Log::info('Item title', ['title' => $title]);

                // Parse progress from title: "Will is 23% done with The Book"
                if (preg_match('/^\s*.+?\s+is (\d+)% done with (.+)$/m', $title, $matches)) {
                    $items[] = [
                        'guid' => $guid,
                        'pubDate' => $pubDate,
                        'title' => $title,
                        'type' => 'progress',
                        'progress_percentage' => (int) $matches[1],
                        'book_title' => trim($matches[2]),
                    ];
                    Log::info('Parsed progress update', [
                        'guid' => $guid,
                        'progress' => (int) $matches[1],
                        'book_title' => trim($matches[2]),
                    ]);
                } else {
                    Log::warning('Failed to parse progress from title', ['title' => $title]);
                }
            }

            // Handle ReadStatus items (start reading events)
            if (str_starts_with($guid, 'ReadStatus')) {
                Log::info('Item title', ['title' => $title]);

                // Parse "is currently reading" from title
                // Match: "Will is currently reading 'How Spies Think: Ten Lessons in Intelligence'"
                if (preg_match('/^\s*.+?\s+is currently reading [\'"](.+?)[\'"]$/m', $title, $matches)) {
                    $items[] = [
                        'guid' => $guid,
                        'pubDate' => $pubDate,
                        'title' => $title,
                        'type' => 'start_reading',
                        'book_title' => trim($matches[1]),
                    ];
                    Log::info('Parsed start reading status', [
                        'guid' => $guid,
                        'book_title' => trim($matches[1]),
                    ]);
                } else {
                    Log::info('Skipping non-start-reading ReadStatus item', ['title' => $title]);
                }
            }
        }

        return ['items' => $items];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $items = $rawData['items'] ?? [];

        if (empty($items)) {
            return;
        }

        GoodreadsProgressData::dispatch($this->integration, [
            'items' => $items,
        ]);
    }
}
