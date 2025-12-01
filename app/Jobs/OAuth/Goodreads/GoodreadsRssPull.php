<?php

namespace App\Jobs\OAuth\Goodreads;

use App\Integrations\Fetch\FetchEngineManager;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Goodreads\GoodreadsRssData;
use Exception;

class GoodreadsRssPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'rss';
    }

    protected function fetchData(): array
    {
        $group = $this->integration->group;
        $rssUrl = $group->auth_metadata['rss_url'] ?? null;

        if (! $rssUrl) {
            throw new Exception('RSS URL not configured in integration group settings');
        }

        // Use FetchEngineManager to fetch via Playwright (supports authenticated feeds)
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
            $items[] = [
                'guid' => (string) $item->guid,
                'pubDate' => (string) $item->pubDate,
                'title' => (string) $item->title,
                'link' => (string) $item->link,
                'description' => (string) $item->description,
            ];
        }

        return ['items' => $items];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $items = $rawData['items'] ?? [];

        if (empty($items)) {
            return;
        }

        GoodreadsRssData::dispatch($this->integration, [
            'items' => $items,
        ]);
    }
}
