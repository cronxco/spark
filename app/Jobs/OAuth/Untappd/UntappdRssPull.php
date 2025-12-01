<?php

namespace App\Jobs\OAuth\Untappd;

use App\Integrations\Fetch\FetchEngineManager;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Untappd\UntappdRssData;
use Exception;

class UntappdRssPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'untappd';
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

        $xmlContent = $result['html'];

        // Extract XML from <pre> tags if wrapped in HTML
        if (str_starts_with(trim($xmlContent), '<!DOCTYPE') || str_starts_with(trim($xmlContent), '<html')) {
            // Try to extract content from <pre> tags
            if (preg_match('/<pre[^>]*>(.*?)<\/pre>/is', $xmlContent, $matches)) {
                $xmlContent = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            } else {
                throw new Exception('Received HTML instead of XML and could not extract from <pre> tags. Response starts with: ' . substr($xmlContent, 0, 200));
            }
        }

        // Parse XML with error handling
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMessages = array_map(fn ($error) => trim($error->message), $errors);
            libxml_clear_errors();

            throw new Exception('Failed to parse RSS XML: ' . implode(', ', $errorMessages) . '. Content preview: ' . substr($xmlContent, 0, 500));
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

        UntappdRssData::dispatch($this->integration, [
            'items' => $items,
        ]);
    }
}
