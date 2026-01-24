<?php

namespace App\Jobs\OAuth\Goodreads;

use App\Integrations\Fetch\FetchEngineManager;
use App\Integrations\Goodreads\GoodreadsPlugin;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Goodreads\GoodreadsShelfData;
use Exception;

class GoodreadsShelfPull extends BaseFetchJob
{
    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'shelf';
    }

    protected function fetchData(): array
    {
        $group = $this->integration->group;
        $userId = $group->auth_metadata['user_id'] ?? null;
        $apiKey = $group->auth_metadata['api_key'] ?? null;

        if (! $userId || ! $apiKey) {
            throw new Exception('User ID and API key not configured in integration group settings');
        }

        $shelf = GoodreadsPlugin::getShelfFromInstanceType($this->integration->instance_type);

        if (! $shelf) {
            throw new Exception('Invalid instance type for shelf feed: '.$this->integration->instance_type);
        }

        $rssUrl = GoodreadsPlugin::buildShelfUrl($userId, $apiKey, $shelf);

        // Use FetchEngineManager to fetch RSS
        $fetchManager = app(FetchEngineManager::class);
        $result = $fetchManager->fetch($rssUrl, $group);

        if ($result['error']) {
            throw new Exception('RSS fetch failed: '.$result['error']);
        }

        // Parse XML
        $xml = simplexml_load_string($result['html']);

        if ($xml === false) {
            throw new Exception('Failed to parse RSS XML');
        }

        $items = [];

        foreach ($xml->channel->item as $item) {
            // Extract all available fields from shelf RSS feed
            $bookNode = $item->book;

            $items[] = [
                'guid' => (string) $item->guid,
                'pubDate' => (string) $item->pubDate,
                'title' => (string) $item->title,
                'link' => (string) $item->link,
                'book_id' => (string) $item->book_id,
                'book_image_url' => (string) $item->book_image_url,
                'book_small_image_url' => (string) $item->book_small_image_url,
                'book_medium_image_url' => (string) $item->book_medium_image_url,
                'book_large_image_url' => (string) $item->book_large_image_url,
                'book_description' => (string) $item->book_description,
                'num_pages' => $bookNode ? (int) $bookNode->num_pages : null,
                'author_name' => (string) $item->author_name,
                'isbn' => (string) $item->isbn,
                'user_name' => (string) $item->user_name,
                'user_rating' => (int) $item->user_rating,
                'user_read_at' => (string) $item->user_read_at,
                'user_date_added' => (string) $item->user_date_added,
                'user_date_created' => (string) $item->user_date_created,
                'user_shelves' => (string) $item->user_shelves,
                'average_rating' => (float) $item->average_rating,
                'book_published' => (string) $item->book_published,
                'description' => (string) $item->description,
            ];
        }

        return [
            'items' => $items,
            'shelf' => $shelf,
        ];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        $items = $rawData['items'] ?? [];

        if (empty($items)) {
            return;
        }

        GoodreadsShelfData::dispatch($this->integration, [
            'items' => $items,
            'shelf' => $rawData['shelf'],
        ]);
    }
}
