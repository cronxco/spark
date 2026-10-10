<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Goodreads\GoodreadsShelfData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoodreadsShelfTimestampTest extends TestCase
{
    #[Test]
    public function normalizes_book_block_and_event_times_to_the_same_utc_instant(): void
    {
        $integration = new Integration;
        $integration->service = 'goodreads';
        $job = new class($integration, ['shelf' => 'read', 'items' => [['guid' => 'review-123', 'book_id' => '123', 'title' => 'Example Book', 'user_rating' => 4, 'pubDate' => 'Sun, 27 Sep 2026 23:08:03 -0700', 'book_large_image_url' => 'https://example.com/cover.jpg']]]) extends GoodreadsShelfData
        {
            public array $captured = [];

            public function processForTest(): void
            {
                $this->process();
            }

            protected function createEvents(array $eventData): Collection
            {
                $this->captured = $eventData;

                return collect();
            }
        };

        $job->processForTest();

        $event = $job->captured[0];
        foreach ([$event['time'], $event['target']['time'], $event['blocks'][0]['time']] as $time) {
            $this->assertSame('2026-09-28 06:08:03', $time->format('Y-m-d H:i:s'));
            $this->assertSame(0, $time->getOffset());
        }
    }

    #[Test]
    public function follow_up_work_uses_the_matching_input_when_earlier_events_already_exist(): void
    {
        $integration = new Integration;
        $integration->service = 'goodreads';
        $items = [
            ['guid' => 'review-old', 'book_id' => '1', 'title' => 'Old Book', 'user_rating' => 0, 'pubDate' => 'Sun, 27 Sep 2026 10:00:00 +0000', 'book_large_image_url' => 'https://example.com/old.jpg'],
            ['guid' => 'review-new', 'book_id' => '2', 'title' => 'New Book', 'user_rating' => 0, 'pubDate' => 'Mon, 28 Sep 2026 10:00:00 +0000', 'book_large_image_url' => 'https://example.com/new.jpg'],
        ];
        $job = new class($integration, ['shelf' => 'currently-reading', 'items' => $items]) extends GoodreadsShelfData
        {
            public array $covers = [];

            public function processForTest(): void
            {
                $this->process();
            }

            protected function createEvents(array $eventData): Collection
            {
                // The first event already exists, so only the second one is returned
                $event = new Event(['source_id' => $eventData[1]['source_id'], 'event_metadata' => []]);
                $event->setRelation('target', new EventObject(['title' => 'New Book']));

                return collect([$event]);
            }

            protected function downloadBookCover(EventObject $book, string $coverUrl): void
            {
                $this->covers[] = [$book->title, $coverUrl];
            }
        };

        $job->processForTest();

        $this->assertSame([['New Book', 'https://example.com/new.jpg']], $job->covers);
    }
}
