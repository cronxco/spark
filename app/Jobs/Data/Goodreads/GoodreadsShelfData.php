<?php

namespace App\Jobs\Data\Goodreads;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Services\Media\MediaDownloadHelper;
use Carbon\Carbon;
use Exception;

class GoodreadsShelfData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'shelf';
    }

    protected function process(): void
    {
        $items = $this->rawData['items'] ?? [];
        $shelf = $this->rawData['shelf'] ?? 'unknown';
        $events = [];

        foreach ($items as $item) {
            $guid = $item['guid'] ?? null;
            $bookId = $item['book_id'] ?? null;

            if (! $guid || ! $bookId) {
                continue;
            }

            // Parse series info from title
            $fullTitle = $item['title'] ?? 'Unknown Book';
            $seriesInfo = $this->parseSeriesInfo($fullTitle);

            // Extract username from user_name field
            $userName = $item['user_name'] ?? 'Goodreads User';

            // Check for stored start date from status feed (for currently-reading shelf only)
            $storedStartDate = null;
            if ($shelf === 'currently-reading') {
                // Check if book exists and has stored start date
                $existingBook = EventObject::where('user_id', $this->integration->user_id)
                    ->where('type', 'goodreads_book')
                    ->whereJsonContains('metadata->book_id', $bookId)
                    ->first();

                if ($existingBook && isset($existingBook->metadata['reading_started_at'])) {
                    $storedStartDate = Carbon::parse($existingBook->metadata['reading_started_at']);
                    logger()->info('Using stored start date for is_reading event', [
                        'book_id' => $bookId,
                        'stored_date' => $storedStartDate->toDateTimeString(),
                        'shelf_pubDate' => $item['pubDate'],
                    ]);
                }
            }

            // Build actor (Goodreads user)
            $actor = [
                'concept' => 'user',
                'type' => 'goodreads_user',
                'title' => $userName,
                'content' => null,
                'metadata' => [],
                'url' => null,
                'image_url' => null,
                'time' => now(),
            ];

            // Build target (book) - use stored start date if available
            $target = [
                'concept' => 'document',
                'type' => 'goodreads_book',
                'title' => $seriesInfo['clean_title'],
                'content' => $item['book_description'] ?? null,
                'metadata' => [
                    'book_id' => $bookId,
                    'author' => $item['author_name'] ?? null,
                    'author_url' => null, // Not available in shelf RSS
                    'isbn' => $item['isbn'] ?? null,
                    'num_pages' => $item['num_pages'] ?? null,
                    'published_year' => $item['book_published'] ?? null,
                    'average_rating' => $item['average_rating'] ?? null,
                    'user_rating' => $item['user_rating'] ?: null,
                    'date_read' => isset($item['user_read_at']) && $item['user_read_at'] ? Carbon::parse($item['user_read_at'])->toDateString() : null,
                    'date_added' => isset($item['user_date_added']) && $item['user_date_added'] ? Carbon::parse($item['user_date_added'])->toDateString() : null,
                    'current_shelf' => $shelf,
                    'series_name' => $seriesInfo['series_name'],
                    'series_number' => $seriesInfo['series_number'],
                    'current_progress' => $shelf === 'read' ? 100 : ($shelf === 'currently-reading' ? 0 : null),
                    'full_title' => $fullTitle,
                ],
                'url' => $item['link'] ?? null,
                'image_url' => $item['book_large_image_url'] ?? null,
                'time' => $storedStartDate ?? ($item['pubDate'] ? Carbon::parse($item['pubDate']) : now()),
            ];

            // Determine action and value based on shelf
            $action = $this->getActionForShelf($shelf);
            $value = null;
            $valueUnit = null;

            if ($action === 'is_reading') {
                $value = 0; // Starting to read
                $valueUnit = '%';
            } elseif ($action === 'finished_reading' && $item['user_rating'] > 0) {
                $value = $item['user_rating'];
                $valueUnit = '/5';
            }

            // Build blocks
            $blocks = [];

            // Single book block with metadata
            if (! empty($item['book_large_image_url'])) {
                $blocks[] = [
                    'block_type' => 'book',
                    'title' => $seriesInfo['clean_title'],
                    'metadata' => [
                        'isbn' => $item['isbn'] ?? null,
                        'num_pages' => $item['num_pages'] ?? null,
                        'published_year' => $item['book_published'] ?? null,
                    ],
                    'url' => $item['link'] ?? null,
                    'media_url' => $item['book_large_image_url'],
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => $item['pubDate'] ? Carbon::parse($item['pubDate']) : now(),
                ];
            }

            // Build source ID
            $sourceId = 'goodreads_'.$action.'_'.md5($guid);

            // Build event - use stored start date if available
            $events[] = [
                'source_id' => $sourceId,
                'time' => $storedStartDate ? $storedStartDate->UTC() : ($item['pubDate'] ? Carbon::parse($item['pubDate'])->UTC() : now()->UTC()),
                'domain' => 'media',
                'action' => $action,
                'value' => $value,
                'value_multiplier' => 1,
                'value_unit' => $valueUnit,
                'event_metadata' => [
                    'guid' => $guid,
                    'link' => $item['link'] ?? null,
                    '__tags' => ! empty($item['author_name']) ? [$item['author_name']] : [],
                ],
                'actor' => $actor,
                'target' => $target,
                'blocks' => $blocks,
                'series_info' => $seriesInfo, // Pass series info for relationship creation
            ];
        }

        // Create events
        $created = $this->createEventsPayload($events);

        // Tag events with author names and create series relationships
        foreach ($created as $index => $event) {
            // Tag with author
            $tags = $event->event_metadata['__tags'] ?? [];
            foreach ($tags as $tag) {
                $event->attachTag($tag, 'goodreads_author');
            }

            // Create series relationship if applicable
            $seriesInfo = $events[$index]['series_info'] ?? null;
            if ($seriesInfo && $seriesInfo['series_name']) {
                $this->createSeriesRelationship($event->target, $seriesInfo);
            }

            // Download book cover to Media Library
            $bookCoverUrl = $events[$index]['target']['image_url'] ?? null;
            if ($bookCoverUrl && $event->target) {
                $this->downloadBookCover($event->target, $bookCoverUrl);
            }

            // Move reading_started_at to previously_started_at when book moves to completed or to-read shelf
            if (in_array($shelf, ['read', 'to-read'])) {
                if ($event->target && isset($event->target->metadata['reading_started_at'])) {
                    $metadata = $event->target->metadata;

                    // Preserve the start date as previously_started_at
                    $metadata['previously_started_at'] = $metadata['reading_started_at'];
                    unset($metadata['reading_started_at']);

                    $event->target->update(['metadata' => $metadata]);

                    logger()->info('Moved reading_started_at to previously_started_at on shelf change', [
                        'book_id' => $event->target->id,
                        'new_shelf' => $shelf,
                        'date' => $metadata['previously_started_at'],
                    ]);
                }
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
                // Merge metadata, keeping the most complete data
                $mergedMetadata = array_merge(
                    $existingBook->metadata ?? [],
                    array_filter($objectData['metadata'] ?? [], fn ($v) => $v !== null)
                );

                // Update the existing book with new data
                $existingBook->update([
                    'time' => $objectData['time'] ?? now(),
                    'title' => $objectData['title'] ?? $existingBook->title,
                    'content' => $objectData['content'] ?? $existingBook->content,
                    'metadata' => $mergedMetadata,
                    'url' => $objectData['url'] ?? $existingBook->url,
                    'media_url' => $objectData['image_url'] ?? $existingBook->media_url,
                ]);

                return $existingBook;
            }
        }

        // Fall back to parent method for other object types
        return parent::createOrUpdateObject($objectData);
    }

    /**
     * Parse series information from book title
     * Example: "Kings Rising (Captive Prince, #3)" -> ["Kings Rising", "Captive Prince", 3]
     */
    private function parseSeriesInfo(string $title): array
    {
        // Regex to match: "Book Title (Series Name, #Number)"
        if (preg_match('/^(.+?)\s*\((.+?),\s*#(\d+)\)$/', $title, $matches)) {
            return [
                'clean_title' => trim($matches[1]),
                'series_name' => trim($matches[2]),
                'series_number' => (int) $matches[3],
            ];
        }

        // No series info found
        return [
            'clean_title' => $title,
            'series_name' => null,
            'series_number' => null,
        ];
    }

    /**
     * Get action type based on shelf name
     */
    private function getActionForShelf(string $shelf): string
    {
        return match ($shelf) {
            'currently-reading' => 'is_reading',
            'read' => 'finished_reading',
            'to-read' => 'wants_to_read',
            default => 'is_reading',
        };
    }

    /**
     * Create series relationship for a book
     */
    private function createSeriesRelationship(EventObject $book, array $seriesInfo): void
    {
        if (! $seriesInfo['series_name']) {
            return;
        }

        // Create or get series object
        $series = EventObject::firstOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'collection',
                'type' => 'goodreads_series',
                'title' => $seriesInfo['series_name'],
            ],
            [
                'content' => null,
                'metadata' => [
                    'total_books' => null, // Unknown from RSS data
                ],
                'url' => null,
                'media_url' => null,
                'time' => now(),
            ]
        );

        // Create part_of relationship
        Relationship::createRelationship([
            'user_id' => $this->integration->user_id,
            'from_type' => EventObject::class,
            'from_id' => $book->id,
            'to_type' => EventObject::class,
            'to_id' => $series->id,
            'type' => 'part_of',
            'metadata' => [
                'series_order' => $seriesInfo['series_number'],
            ],
        ]);
    }

    /**
     * Download book cover to Media Library
     */
    private function downloadBookCover(EventObject $book, string $coverUrl): void
    {
        try {
            $helper = app(MediaDownloadHelper::class);
            $helper->downloadAndAttachMedia(
                $coverUrl,
                $book,
                'downloaded_images',
                ['alt' => $book->title]
            );
        } catch (Exception $e) {
            // Log but don't fail the entire job
            logger()->warning('Failed to download book cover', [
                'book_id' => $book->id,
                'cover_url' => $coverUrl,
                'error' => $e->getMessage(),
            ]);
        }
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
}
