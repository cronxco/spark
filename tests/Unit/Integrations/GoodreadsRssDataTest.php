<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\Goodreads\GoodreadsRssData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodreadsRssDataTest extends TestCase
{
    use RefreshDatabase;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'goodreads',
            'auth_metadata' => [
                'rss_url' => 'https://www.goodreads.com/user/updates_rss/12345?key=test',
            ],
        ]);

        $this->integration = Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'goodreads',
            'name' => 'Goodreads RSS',
            'instance_type' => 'rss_feed',
            'configuration' => ['update_frequency_minutes' => 60],
        ]);
    }

    /** @test */
    public function it_parses_currently_reading_activity()
    {
        $items = [
            [
                'guid' => 'ReadStatus12345',
                'pubDate' => 'Sat, 29 Nov 2025 14:03:15 -0800',
                'title' => "Alice is currently reading 'The Chef Handbook'",
                'link' => 'https://www.goodreads.com/review/show/12345',
                'description' => $this->buildGoodreadsDescription(
                    'The Chef Handbook',
                    'John Smith',
                    'https://i.gr-assets.com/images/S/compressed.photo.goodreads.com/books/12345._SY75_.jpg',
                    '/book/show/12345-the-Chef-handbook',
                    '/author/show/67890.John_Smith'
                ),
            ],
        ];

        $job = new GoodreadsRssData($this->integration, ['items' => $items]);
        $job->handle();

        $this->assertDatabaseHas('events', [
            'service' => 'goodreads',
            'action' => 'is_reading',
            'domain' => 'media',
        ]);

        $event = Event::where('service', 'goodreads')->first();
        $this->assertEquals('is_reading', $event->action);
        $this->assertNull($event->value);

        // Check actor
        $this->assertEquals('goodreads_user', $event->actor->type);
        $this->assertEquals('Alice', $event->actor->title);

        // Check target (book)
        $this->assertEquals('goodreads_book', $event->target->type);
        $this->assertEquals('The Chef Handbook', $event->target->title);
        $this->assertStringContainsString('John Smith', $event->target->metadata['author']);

        // Check blocks
        $blocks = $event->blocks;
        $this->assertGreaterThan(0, $blocks->count());

        $coverBlock = $blocks->where('block_type', 'book_cover')->first();
        $this->assertNotNull($coverBlock);
        $this->assertStringContainsString('goodreads.com', $coverBlock->media_url);

        $authorBlock = $blocks->where('block_type', 'book_author')->first();
        $this->assertNotNull($authorBlock);
        $this->assertEquals('John Smith', $authorBlock->title);

        // Check tags
        $tags = $event->tags;
        $this->assertTrue($tags->contains('name', 'John Smith'));

        $authorTag = $tags->where('name', 'John Smith')->first();
        $this->assertNotNull($authorTag);
        $this->assertEquals('goodreads_author', $authorTag->type);
    }

    /** @test */
    public function it_parses_started_reading_activity()
    {
        $items = [
            [
                'guid' => 'ReadStatus67890',
                'pubDate' => 'Fri, 14 Nov 2025 23:35:33 -0800',
                'title' => "Bob started reading 'Mystery Novel'",
                'link' => 'https://www.goodreads.com/review/show/67890',
                'description' => $this->buildGoodreadsDescription(
                    'Mystery Novel',
                    'Jane Doe',
                    'https://i.gr-assets.com/images/S/compressed.photo.goodreads.com/books/67890._SY75_.jpg',
                    '/book/show/67890-mystery-novel',
                    '/author/show/11111.Jane_Doe'
                ),
            ],
        ];

        $job = new GoodreadsRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'goodreads')->first();
        $this->assertEquals('is_reading', $event->action);
        $this->assertEquals('Bob', $event->actor->title);
        $this->assertEquals('Mystery Novel', $event->target->title);
    }

    /** @test */
    public function it_parses_wants_to_read_activity()
    {
        $items = [
            [
                'guid' => 'ReadStatus11111',
                'pubDate' => 'Sun, 16 Nov 2025 12:58:09 -0800',
                'title' => "Charlie wants to read 'Future Book'",
                'link' => 'https://www.goodreads.com/review/show/11111',
                'description' => $this->buildGoodreadsDescription(
                    'Future Book',
                    'Author Name',
                    'https://i.gr-assets.com/images/S/compressed.photo.goodreads.com/books/11111._SY75_.jpg',
                    '/book/show/11111-future-book',
                    '/author/show/22222.Author_Name'
                ),
            ],
        ];

        $job = new GoodreadsRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'goodreads')->first();
        $this->assertEquals('wants_to_read', $event->action);
        $this->assertEquals('Future Book', $event->target->title);
    }

    /** @test */
    public function it_parses_reviewed_book_with_rating()
    {
        $items = [
            [
                'guid' => 'Review33333',
                'pubDate' => 'Sun, 23 Nov 2025 13:22:51 -0800',
                'title' => 'Diana gave 5 stars to Amazing Story',
                'link' => 'https://www.goodreads.com/review/show/33333',
                'description' => $this->buildGoodreadsDescription(
                    'Amazing Story',
                    'Best Author',
                    'https://i.gr-assets.com/images/S/compressed.photo.goodreads.com/books/33333._SY75_.jpg',
                    '/book/show/33333-amazing-story',
                    '/author/show/44444.Best_Author'
                ),
            ],
        ];

        $job = new GoodreadsRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'goodreads')->first();
        $this->assertEquals('reviewed', $event->action);
        $this->assertEquals(5, $event->value);
        $this->assertEquals('stars', $event->value_unit);
        $this->assertEquals('Diana', $event->actor->title);
        $this->assertEquals('Amazing Story', $event->target->title);
    }

    /** @test */
    public function it_handles_multiple_items_in_one_batch()
    {
        $items = [
            [
                'guid' => 'Item1',
                'pubDate' => 'Sat, 29 Nov 2025 14:03:15 -0800',
                'title' => "Eve is currently reading 'Book One'",
                'link' => 'https://www.goodreads.com/review/show/1',
                'description' => $this->buildGoodreadsDescription('Book One', 'Author A', '', '/book/show/1', '/author/show/1'),
            ],
            [
                'guid' => 'Item2',
                'pubDate' => 'Sat, 28 Nov 2025 10:00:00 -0800',
                'title' => "Eve wants to read 'Book Two'",
                'link' => 'https://www.goodreads.com/review/show/2',
                'description' => $this->buildGoodreadsDescription('Book Two', 'Author B', '', '/book/show/2', '/author/show/2'),
            ],
            [
                'guid' => 'Item3',
                'pubDate' => 'Sat, 27 Nov 2025 15:30:00 -0800',
                'title' => 'Eve gave 4 stars to Book Three',
                'link' => 'https://www.goodreads.com/review/show/3',
                'description' => $this->buildGoodreadsDescription('Book Three', 'Author C', '', '/book/show/3', '/author/show/3'),
            ],
        ];

        $job = new GoodreadsRssData($this->integration, ['items' => $items]);
        $job->handle();

        $events = Event::where('service', 'goodreads')->get();
        $this->assertCount(3, $events);

        $this->assertTrue($events->contains('action', 'is_reading'));
        $this->assertTrue($events->contains('action', 'wants_to_read'));
        $this->assertTrue($events->contains('action', 'reviewed'));

        $reviewedEvent = $events->where('action', 'reviewed')->first();
        $this->assertEquals(4, $reviewedEvent->value);
    }

    /** @test */
    public function it_uses_guid_for_idempotency()
    {
        $items = [
            [
                'guid' => 'UniqueGuid123',
                'pubDate' => 'Sat, 29 Nov 2025 14:03:15 -0800',
                'title' => "Frank is currently reading 'Test Book'",
                'link' => 'https://www.goodreads.com/review/show/123',
                'description' => $this->buildGoodreadsDescription('Test Book', 'Test Author', '', '/book/show/123', '/author/show/123'),
            ],
        ];

        // Run the job twice
        $job1 = new GoodreadsRssData($this->integration, ['items' => $items]);
        $job1->handle();

        $job2 = new GoodreadsRssData($this->integration, ['items' => $items]);
        $job2->handle();

        // Should only create one event due to source_id uniqueness
        $events = Event::where('service', 'goodreads')->get();
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertEquals('goodreads_' . md5('UniqueGuid123'), $event->source_id);
    }

    /** @test */
    public function it_strips_size_suffix_from_cover_urls()
    {
        $items = [
            [
                'guid' => 'CoverTest123',
                'pubDate' => 'Sat, 29 Nov 2025 14:03:15 -0800',
                'title' => "George is currently reading 'Book With Cover'",
                'link' => 'https://www.goodreads.com/review/show/999',
                'description' => $this->buildGoodreadsDescription(
                    'Book With Cover',
                    'Cover Author',
                    'https://i.gr-assets.com/images/S/compressed.photo.goodreads.com/books/1605735671l/55928896._SX98_.jpg',
                    '/book/show/999',
                    '/author/show/999'
                ),
            ],
        ];

        $job = new GoodreadsRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'goodreads')->first();
        $coverBlock = $event->blocks->where('block_type', 'book_cover')->first();

        $this->assertNotNull($coverBlock);
        // Should have stripped _SX98_ suffix
        $this->assertEquals(
            'https://i.gr-assets.com/images/S/compressed.photo.goodreads.com/books/1605735671l/55928896.jpg',
            $coverBlock->media_url
        );
        // Ensure it doesn't contain the size suffix
        $this->assertStringNotContainsString('_SX98_', $coverBlock->media_url);
    }

    /**
     * Build a mock Goodreads RSS description HTML
     */
    private function buildGoodreadsDescription(
        string $bookTitle,
        string $authorName,
        string $coverUrl = '',
        string $bookPath = '',
        string $authorPath = ''
    ): string {
        $cover = $coverUrl ? "<img src=\"{$coverUrl}\" />" : '';
        $bookLink = $bookPath ? "href=\"{$bookPath}\"" : '';
        $authorLink = $authorPath ? "href=\"{$authorPath}\"" : '';

        return <<<HTML
        <![CDATA[
        {$cover}
        <a class="bookTitle" {$bookLink}>{$bookTitle}</a>
        <span class="by">by</span>
        <a class="authorName" {$authorLink}>{$authorName}</a>
        ]]>
        HTML;
    }
}
