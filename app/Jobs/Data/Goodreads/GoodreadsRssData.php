<?php

namespace App\Jobs\Data\Goodreads;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;

class GoodreadsRssData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'rss';
    }

    protected function process(): void
    {
        $items = $this->rawData['items'] ?? [];
        $events = [];

        foreach ($items as $item) {
            $guid = $item['guid'] ?? null;
            $title = $item['title'] ?? '';
            $description = $item['description'] ?? '';
            $link = $item['link'] ?? '';
            $pubDate = $item['pubDate'] ?? '';

            if (! $guid) {
                continue;
            }

            // Parse action and extract data from title
            $parsedData = $this->parseTitle($title);

            if (! $parsedData) {
                continue;
            }

            // Parse HTML description to extract detailed information
            $descriptionData = $this->parseDescription($description);

            // Extract user name from title (e.g., "Will is currently reading...")
            $userName = $this->extractUserName($title);

            // Build actor (Goodreads user)
            $actor = [
                'concept' => 'user',
                'type' => 'goodreads_user',
                'title' => $userName ?: 'Goodreads User',
                'content' => null,
                'metadata' => [],
                'url' => null,
                'image_url' => null,
                'time' => now(),
            ];

            // Build target (book)
            $bookTitle = $descriptionData['bookTitle'] ?? $parsedData['bookTitle'] ?? 'Unknown Book';
            $bookUrl = $descriptionData['bookUrl'] ?? $link;
            $bookId = $this->extractBookId($bookUrl);

            $target = [
                'concept' => 'document',
                'type' => 'goodreads_book',
                'title' => $bookTitle,
                'content' => null,
                'metadata' => [
                    'book_id' => $bookId,
                    'author' => $descriptionData['authorName'] ?? null,
                    'author_url' => $descriptionData['authorUrl'] ?? null,
                ],
                'url' => $bookUrl,
                'image_url' => $descriptionData['coverUrl'] ?? null,
                'time' => $pubDate ? Carbon::parse($pubDate) : now(),
            ];

            // Build blocks
            $blocks = [];

            // Add book cover block if we have a cover URL
            if (! empty($descriptionData['coverUrl'])) {
                $blocks[] = [
                    'block_type' => 'book_cover',
                    'title' => 'Book Cover',
                    'metadata' => [],
                    'url' => null,
                    'media_url' => $descriptionData['coverUrl'],
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => $pubDate ? Carbon::parse($pubDate) : now(),
                ];
            }

            // Add author block if we have author info
            if (! empty($descriptionData['authorName'])) {
                $blocks[] = [
                    'block_type' => 'book_author',
                    'title' => $descriptionData['authorName'],
                    'metadata' => [
                        'author_url' => $descriptionData['authorUrl'] ?? null,
                    ],
                    'url' => $descriptionData['authorUrl'] ?? null,
                    'media_url' => null,
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => $pubDate ? Carbon::parse($pubDate) : now(),
                ];
            }

            // Build source ID
            $sourceId = 'goodreads_' . md5($guid);

            // Build event
            $events[] = [
                'source_id' => $sourceId,
                'time' => $pubDate ? Carbon::parse($pubDate) : now(),
                'domain' => 'media',
                'action' => $parsedData['action'],
                'value' => $parsedData['rating'] ?? null,
                'value_multiplier' => 1,
                'value_unit' => isset($parsedData['rating']) ? 'stars' : null,
                'event_metadata' => [
                    'guid' => $guid,
                    'link' => $link,
                    '__tags' => ! empty($descriptionData['authorName']) ? [$descriptionData['authorName']] : [],
                ],
                'actor' => $actor,
                'target' => $target,
                'blocks' => $blocks,
            ];
        }

        // Create events
        $created = $this->createEventsPayload($events);

        // Tag events with author names
        foreach ($created as $event) {
            $tags = $event->event_metadata['__tags'] ?? [];
            foreach ($tags as $tag) {
                $event->attachTag($tag, 'goodreads_author');
            }
        }
    }

    /**
     * Override createOrUpdateObject to handle book deduplication by book_id
     */
    protected function createOrUpdateObject(array $objectData): EventObject
    {
        // For Goodreads books, check if object with same book_id already exists
        if ($objectData['type'] === 'goodreads_book' && isset($objectData['metadata']['book_id'])) {
            $bookId = $objectData['metadata']['book_id'];

            // Find existing book by book_id in metadata
            $existingBook = EventObject::where('user_id', $this->integration->user_id)
                ->where('concept', $objectData['concept'])
                ->where('type', $objectData['type'])
                ->whereJsonContains('metadata->book_id', $bookId)
                ->first();

            if ($existingBook) {
                // Keep the longer title (handles truncated titles from RSS feed)
                $newTitle = $objectData['title'];
                $existingTitle = $existingBook->title;
                $titleToKeep = mb_strlen($newTitle) > mb_strlen($existingTitle) ? $newTitle : $existingTitle;

                // Update the existing book with new data
                $existingBook->update([
                    'time' => $objectData['time'] ?? now(),
                    'title' => $titleToKeep,
                    'content' => $objectData['content'] ?? null,
                    'metadata' => array_merge($existingBook->metadata ?? [], $objectData['metadata'] ?? []),
                    'url' => $objectData['url'] ?? $existingBook->url,
                    'media_url' => $objectData['image_url'] ?? $existingBook->media_url,
                    'embeddings' => $objectData['embeddings'] ?? $existingBook->embeddings,
                ]);

                return $existingBook;
            }
        }

        // Fall back to parent method for other object types
        return parent::createOrUpdateObject($objectData);
    }

    /**
     * Parse the RSS item title to extract action type and book information
     */
    private function parseTitle(string $title): ?array
    {
        // "Will is currently reading 'Book Title'" OR "Will started reading 'Book Title'"
        if (preg_match('/(is currently reading|started reading) [\'"](.+?)[\'"]/', $title, $matches)) {
            return [
                'action' => 'is_reading',
                'bookTitle' => $matches[2],
            ];
        }

        // "Will gave 5 stars to Book Title"
        if (preg_match('/gave (\d+) stars? to (.+)/', $title, $matches)) {
            return [
                'action' => 'reviewed',
                'rating' => (int) $matches[1],
                'bookTitle' => trim($matches[2]),
            ];
        }

        // "Will wants to read 'Book Title'"
        if (preg_match('/wants to read [\'"](.+?)[\'"]/', $title, $matches)) {
            return [
                'action' => 'wants_to_read',
                'bookTitle' => $matches[1],
            ];
        }

        // "Will added 'Book Title'" (typically after finishing)
        if (preg_match('/added [\'"](.+?)[\'"]/', $title, $matches)) {
            // Check if it mentions a rating in the title - if so, it's a review
            if (str_contains($title, 'gave') && str_contains($title, 'star')) {
                return null; // Will be caught by the rating regex above
            }

            return [
                'action' => 'finished_reading',
                'bookTitle' => $matches[1],
            ];
        }

        return null;
    }

    /**
     * Parse HTML description to extract book details
     */
    private function parseDescription(string $description): array
    {
        $data = [
            'coverUrl' => null,
            'bookTitle' => null,
            'bookUrl' => null,
            'authorName' => null,
            'authorUrl' => null,
        ];

        if (empty($description)) {
            return $data;
        }

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $description, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($dom);

        // Extract cover image
        $images = $xpath->query('//img');
        if ($images->length > 0) {
            $coverUrl = $images->item(0)->getAttribute('src');
            // Strip size suffix to get full-sized image (e.g., _SX98_, _SY475_)
            $data['coverUrl'] = $this->getFullSizeCoverUrl($coverUrl);
        }

        // Extract book link and title
        $bookLinks = $xpath->query('//a[@class="bookTitle"]');
        if ($bookLinks->length > 0) {
            $bookLink = $bookLinks->item(0);
            $data['bookTitle'] = trim($bookLink->textContent);
            $href = $bookLink->getAttribute('href');
            // Convert relative URL to absolute
            if ($href && ! str_starts_with($href, 'http')) {
                $data['bookUrl'] = 'https://www.goodreads.com' . $href;
            } else {
                $data['bookUrl'] = $href;
            }
        }

        // Extract author
        $authorLinks = $xpath->query('//a[@class="authorName"]');
        if ($authorLinks->length > 0) {
            $authorLink = $authorLinks->item(0);
            $data['authorName'] = trim($authorLink->textContent);
            $href = $authorLink->getAttribute('href');
            // Convert relative URL to absolute
            if ($href && ! str_starts_with($href, 'http')) {
                $data['authorUrl'] = 'https://www.goodreads.com' . $href;
            } else {
                $data['authorUrl'] = $href;
            }
        }

        return $data;
    }

    /**
     * Extract user name from title
     */
    private function extractUserName(string $title): ?string
    {
        // Try to extract name before action verbs
        if (preg_match('/^(.+?) (?:is currently reading|started reading|gave \d+ stars?|wants to read|added)/', $title, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Convert payloads to BaseProcessingJob::createEvents structure
     */
    private function createEventsPayload(array $entries)
    {
        $payloads = [];
        foreach ($entries as $entry) {
            $payloads[] = [
                'source_id' => $entry['source_id'],
                'time' => $entry['time'],
                'domain' => $entry['domain'],
                'action' => $entry['action'],
                'value' => $entry['value'],
                'value_multiplier' => $entry['value_multiplier'],
                'value_unit' => $entry['value_unit'],
                'event_metadata' => $entry['event_metadata'] ?? [],
                'actor' => $entry['actor'],
                'target' => $entry['target'],
                'blocks' => array_map(function ($b) use ($entry) {
                    return [
                        'time' => $b['time'] ?? $entry['time'],
                        'block_type' => $b['block_type'],
                        'title' => $b['title'],
                        'metadata' => $b['metadata'] ?? [],
                        'url' => $b['url'] ?? null,
                        'media_url' => $b['media_url'] ?? null,
                        'value' => $b['value'] ?? null,
                        'value_multiplier' => $b['value_multiplier'] ?? 1,
                        'value_unit' => $b['value_unit'] ?? null,
                    ];
                }, $entry['blocks'] ?? []),
            ];
        }

        return $this->createEvents($payloads);
    }

    /**
     * Strip size suffix from Goodreads cover URL to get full-sized image
     * Example: https://i.gr-assets.com/.../55928896._SX98_.jpg -> https://i.gr-assets.com/.../55928896.jpg
     */
    private function getFullSizeCoverUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        // Remove size suffixes like _SX98_, _SY475_, etc.
        return preg_replace('/\._[A-Z]{2}\d+_\./', '.', $url);
    }

    /**
     * Extract book ID from Goodreads book URL
     * Example: https://www.goodreads.com/book/show/25792894-kings-rising -> 25792894
     */
    private function extractBookId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        // Match pattern: /book/show/{book_id}-{slug} or /book/show/{book_id}
        if (preg_match('/\/book\/show\/(\d+)(?:-|$)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
