<?php

namespace App\Jobs\Data\Goodreads;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Carbon\Carbon;

class GoodreadsProgressData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'progress';
    }

    protected function process(): void
    {
        $items = $this->rawData['items'] ?? [];
        $events = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? 'progress'; // Default to progress for backwards compatibility

            if ($type === 'start_reading') {
                // Handle "is currently reading" ReadStatus item
                $this->handleStartReading($item);

                continue;
            }

            // Handle progress updates (existing logic)
            $guid = $item['guid'] ?? null;
            $progressPercentage = $item['progress_percentage'] ?? null;
            $bookTitle = $item['book_title'] ?? null;

            if (! $guid || $progressPercentage === null || ! $bookTitle) {
                continue;
            }

            // Find matching book object
            $book = $this->findBookByTitle($bookTitle);

            if (! $book) {
                // Can't create progress event without book context
                logger()->debug('No matching book found for progress update', [
                    'book_title' => $bookTitle,
                    'progress' => $progressPercentage,
                ]);

                continue;
            }

            // Check deduplication: skip if recent progress < 5% increase within 6 hours
            if ($this->shouldSkipProgressUpdate($book, $progressPercentage)) {
                logger()->debug('Skipping progress update due to deduplication', [
                    'book_id' => $book->id,
                    'book_title' => $bookTitle,
                    'progress' => $progressPercentage,
                ]);

                continue;
            }

            // Build actor (we'll reuse the user object)
            $actor = [
                'concept' => 'user',
                'type' => 'goodreads_user',
                'title' => 'Goodreads User',
                'content' => null,
                'metadata' => [],
                'url' => null,
                'image_url' => null,
                'time' => now(),
            ];

            // Build source ID
            $sourceId = 'goodreads_'.$progressPercentage.'_'.md5($guid);

            // Build event
            $events[] = [
                'source_id' => $sourceId,
                'time' => (! empty($item['pubDate']))
                    ? Carbon::parse($item['pubDate'])->utc()
                    : Carbon::now()->utc(),
                'domain' => 'media',
                'action' => 'is_reading',
                'value' => $progressPercentage,
                'value_multiplier' => 1,
                'value_unit' => '%',
                'event_metadata' => [
                    'guid' => $guid,
                ],
                'actor' => $actor,
                'target' => [
                    'concept' => 'document',
                    'type' => 'goodreads_book',
                    'title' => $book->title,
                    'content' => $book->content,
                    'metadata' => array_merge($book->metadata ?? [], [
                        'current_progress' => $progressPercentage,
                    ]),
                    'url' => $book->url,
                    'image_url' => $book->media_url,
                    'time' => $book->time,
                ],
                'blocks' => [], // No separate block for progress, stored in object metadata
                'book_object' => $book, // Pass book for metadata update
            ];
        }

        // Create events
        $created = $this->createEventsPayload($events);

        // Update book object metadata with current progress
        foreach ($created as $index => $event) {
            $bookObject = $events[$index]['book_object'] ?? null;
            $progressPercentage = $events[$index]['value'] ?? null;

            if ($bookObject && $progressPercentage !== null) {
                $metadata = $bookObject->metadata ?? [];
                $metadata['current_progress'] = $progressPercentage;
                $bookObject->update(['metadata' => $metadata]);
            }
        }
    }

    /**
     * Find book object by title (exact match or full_title match)
     */
    private function findBookByTitle(string $bookTitle): ?EventObject
    {
        // Try exact title match first
        $book = EventObject::where('user_id', $this->integration->user_id)
            ->where('type', 'goodreads_book')
            ->where('title', $bookTitle)
            ->first();

        if ($book) {
            return $book;
        }

        // Try matching against full_title in metadata
        $book = EventObject::where('user_id', $this->integration->user_id)
            ->where('type', 'goodreads_book')
            ->whereJsonContains('metadata->full_title', $bookTitle)
            ->first();

        if ($book) {
            return $book;
        }

        // Try partial match (book title might be truncated in updates feed)
        $book = EventObject::where('user_id', $this->integration->user_id)
            ->where('type', 'goodreads_book')
            ->where('title', 'like', $bookTitle.'%')
            ->first();

        return $book;
    }

    /**
     * Check if we should skip this progress update based on deduplication rules
     * Skip if: less than 6 hours since last update AND progress increase < 5%
     */
    private function shouldSkipProgressUpdate(EventObject $book, int $newProgress): bool
    {
        // Get last is_reading event for this book in the last 6 hours
        $lastEvent = Event::where('service', 'goodreads')
            ->where('action', 'is_reading')
            ->whereHas('target', function ($query) use ($book) {
                $query->where('id', $book->id);
            })
            ->where('time', '>=', now()->subHours(6))
            ->orderBy('time', 'desc')
            ->first();

        if (! $lastEvent) {
            // No recent event, don't skip
            return false;
        }

        $lastProgress = $lastEvent->value ?? 0;
        $progressIncrease = $newProgress - $lastProgress;

        // Skip if progress increase is less than 5%
        return $progressIncrease < 5;
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
                'blocks' => [],
            ];
        }

        return $this->createEvents($payloads);
    }

    /**
     * Handle "is currently reading" ReadStatus items
     * Stores the correct start date in metadata and corrects existing events
     */
    private function handleStartReading(array $item): void
    {
        $bookTitle = $item['book_title'] ?? null;
        $pubDate = $item['pubDate'] ?? null;

        if (! $bookTitle || ! $pubDate) {
            return;
        }

        // Find matching book object
        $book = $this->findBookByTitle($bookTitle);

        if (! $book) {
            logger()->debug('No matching book found for start reading status', [
                'book_title' => $bookTitle,
            ]);

            return;
        }

        $correctStartDate = Carbon::parse($pubDate);

        // Store start date in metadata if not already set
        $metadata = $book->metadata ?? [];
        if (! isset($metadata['reading_started_at'])) {
            $metadata['reading_started_at'] = $correctStartDate->toDateTimeString();
            $book->update(['metadata' => $metadata]);

            logger()->info('Stored reading start date from status feed', [
                'book_id' => $book->id,
                'reading_started_at' => $correctStartDate,
            ]);
        }

        // Find existing is_reading event for this book (from shelf feed)
        $existingEvent = Event::where('service', 'goodreads')
            ->where('action', 'is_reading')
            ->whereHas('target', function ($query) use ($book) {
                $query->where('id', $book->id);
            })
            ->first();

        if ($existingEvent && ! $existingEvent->time->eq($correctStartDate)) {
            // Update event time to correct date
            $oldTime = $existingEvent->time->toDateTimeString();
            $existingEvent->update(['time' => $correctStartDate]);

            logger()->info('Corrected is_reading event time', [
                'event_id' => $existingEvent->id,
                'old_time' => $oldTime,
                'new_time' => $correctStartDate->toDateTimeString(),
            ]);
        }
    }
}
