<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Goodreads\GoodreadsShelfData;
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
}
