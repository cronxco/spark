<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\Goodreads\GoodreadsShelfData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoodreadsShelfDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake storage for media tests
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::create([
            'user_id' => $this->user->id,
            'service' => 'goodreads',
            'auth_metadata' => [
                'user_id' => '128356509',
                'api_key' => 'test_key',
            ],
        ]);
        $this->integration = Integration::create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'goodreads',
            'name' => 'Read Shelf',
            'instance_type' => 'shelf_read',
            'configuration' => [],
        ]);
    }

    /**
     * @test
     */
    public function processes_book_from_read_shelf_with_rating()
    {
        $rawData = [
            'shelf' => 'read',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8039841250',
                    'pubDate' => 'Sun, 23 Nov 2025 13:22:20 -0800',
                    'title' => 'Kings Rising (Captive Prince, #3)',
                    'link' => 'https://www.goodreads.com/review/show/8039841250',
                    'book_id' => '25792894',
                    'book_large_image_url' => 'https://i.gr-assets.com/images/S/compressed.photo.goodreads.com/books/1726601626l/25792894._SY475_.jpg',
                    'book_description' => 'The epic conclusion...',
                    'num_pages' => 368,
                    'author_name' => 'C.S. Pacat',
                    'isbn' => '0698154320',
                    'user_name' => 'Will',
                    'user_rating' => 5,
                    'user_read_at' => 'Sun, 23 Nov 2025 00:00:00 +0000',
                    'user_date_added' => 'Sun, 23 Nov 2025 13:22:20 -0800',
                    'user_shelves' => 'read',
                    'average_rating' => 4.51,
                    'book_published' => '2016',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        // Check event was created
        $event = Event::where('service', 'goodreads')
            ->where('action', 'finished_reading')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(5, $event->value);
        $this->assertEquals('/5', $event->value_unit);

        // Check book object was created with clean title
        $book = EventObject::where('type', 'goodreads_book')
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertNotNull($book);
        $this->assertEquals('Kings Rising', $book->title);
        $this->assertEquals('25792894', $book->metadata['book_id']);
        $this->assertEquals('C.S. Pacat', $book->metadata['author']);
        $this->assertEquals(368, $book->metadata['num_pages']);
        $this->assertEquals('2016', $book->metadata['published_year']);
        $this->assertEquals(4.51, $book->metadata['average_rating']);
        $this->assertEquals(5, $book->metadata['user_rating']);
        $this->assertEquals('read', $book->metadata['current_shelf']);
        $this->assertEquals('Captive Prince', $book->metadata['series_name']);
        $this->assertEquals(3, $book->metadata['series_number']);
        $this->assertEquals(100, $book->metadata['current_progress']);
    }

    /**
     * @test
     */
    public function parses_series_information_correctly()
    {
        $rawData = [
            'shelf' => 'read',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/123',
                    'pubDate' => 'Sun, 23 Nov 2025 13:22:20 -0800',
                    'title' => 'Kings Rising (Captive Prince, #3)',
                    'link' => 'https://www.goodreads.com/book/show/25792894',
                    'book_id' => '25792894',
                    'user_name' => 'Will',
                    'user_rating' => 0,
                    'user_date_added' => 'Sun, 23 Nov 2025 13:22:20 -0800',
                    'user_shelves' => 'read',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        // Check series object was created
        $series = EventObject::where('type', 'goodreads_series')
            ->where('title', 'Captive Prince')
            ->first();

        $this->assertNotNull($series);
        $this->assertEquals('collection', $series->concept);

        // Check book object
        $book = EventObject::where('type', 'goodreads_book')->first();
        $this->assertEquals('Kings Rising', $book->title);

        // Check part_of relationship exists
        $relationship = Relationship::where('from_id', $book->id)
            ->where('to_id', $series->id)
            ->where('type', 'part_of')
            ->first();

        $this->assertNotNull($relationship);
        $this->assertEquals(3, $relationship->metadata['series_order']);
    }

    /**
     * @test
     */
    public function processes_currently_reading_shelf()
    {
        $this->integration->update(['instance_type' => 'shelf_currently_reading']);

        $rawData = [
            'shelf' => 'currently-reading',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8108362050',
                    'pubDate' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'title' => 'How Spies Think: Ten Lessons in Intelligence',
                    'link' => 'https://www.goodreads.com/review/show/8108362050',
                    'book_id' => '55928896',
                    'user_name' => 'Will',
                    'user_rating' => 0,
                    'user_date_added' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'user_shelves' => 'currently-reading',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        $event = Event::where('action', 'is_reading')->first();
        $this->assertNotNull($event);
        $this->assertEquals(0, $event->value);
        $this->assertEquals('%', $event->value_unit);

        $book = EventObject::where('type', 'goodreads_book')->first();
        $this->assertEquals('currently-reading', $book->metadata['current_shelf']);
        $this->assertEquals(0, $book->metadata['current_progress']);
    }

    /**
     * @test
     */
    public function processes_to_read_shelf()
    {
        $this->integration->update(['instance_type' => 'shelf_to_read']);

        $rawData = [
            'shelf' => 'to-read',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8116248116',
                    'pubDate' => 'Tue, 02 Dec 2025 10:45:17 -0800',
                    'title' => 'Damaged Like Us (Like Us, #1)',
                    'link' => 'https://www.goodreads.com/review/show/8116248116',
                    'book_id' => '21427834',
                    'user_name' => 'Will',
                    'user_rating' => 0,
                    'user_date_added' => 'Tue, 02 Dec 2025 10:45:17 -0800',
                    'user_shelves' => 'to-read',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        $event = Event::where('action', 'wants_to_read')->first();
        $this->assertNotNull($event);
        $this->assertNull($event->value);

        $book = EventObject::where('type', 'goodreads_book')->first();
        $this->assertEquals('to-read', $book->metadata['current_shelf']);
    }

    /**
     * @test
     */
    public function deduplicates_books_by_book_id()
    {
        // Create same book twice
        $rawData = [
            'shelf' => 'read',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/123',
                    'pubDate' => 'Sun, 23 Nov 2025 13:22:20 -0800',
                    'title' => 'Kings Rising',
                    'link' => 'https://www.goodreads.com/book/show/25792894',
                    'book_id' => '25792894',
                    'user_name' => 'Will',
                    'user_rating' => 4,
                    'user_date_added' => 'Sun, 23 Nov 2025 13:22:20 -0800',
                ],
            ],
        ];

        $job1 = new GoodreadsShelfData($this->integration, $rawData);
        $job1->handle();

        $rawData['items'][0]['guid'] = 'https://www.goodreads.com/review/show/456';
        $rawData['items'][0]['user_rating'] = 5;

        $job2 = new GoodreadsShelfData($this->integration, $rawData);
        $job2->handle();

        // Should only have one book object
        $bookCount = EventObject::where('type', 'goodreads_book')
            ->where('user_id', $this->user->id)
            ->count();

        $this->assertEquals(1, $bookCount);

        // But should have two events
        $eventCount = Event::where('service', 'goodreads')
            ->where('integration_id', $this->integration->id)
            ->count();

        $this->assertEquals(2, $eventCount);

        // Book should have latest metadata
        $book = EventObject::where('type', 'goodreads_book')->first();
        $this->assertEquals(5, $book->metadata['user_rating']);
    }

    /**
     * @test
     */
    public function tags_events_with_author_names()
    {
        $rawData = [
            'shelf' => 'read',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/123',
                    'pubDate' => 'Sun, 23 Nov 2025 13:22:20 -0800',
                    'title' => 'Test Book',
                    'link' => 'https://www.goodreads.com/book/show/123',
                    'book_id' => '123',
                    'author_name' => 'Test Author',
                    'user_name' => 'Will',
                    'user_rating' => 0,
                    'user_date_added' => 'Sun, 23 Nov 2025 13:22:20 -0800',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        $event = Event::first();
        $tags = $event->tags()->where('type', 'goodreads_author')->pluck('name');

        $this->assertTrue($tags->contains('Test Author'));
    }

    /**
     * @test
     */
    public function uses_stored_reading_started_at_for_is_reading_events()
    {
        $this->integration->update(['instance_type' => 'shelf_currently_reading']);

        // Create a book with stored reading_started_at
        $storedDate = '2025-11-29 22:03:15'; // UTC
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think: Ten Lessons in Intelligence',
            'metadata' => [
                'book_id' => '55928896',
                'reading_started_at' => $storedDate,
            ],
            'time' => now(),
        ]);

        $rawData = [
            'shelf' => 'currently-reading',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8108362050',
                    'pubDate' => 'Mon, 01 Dec 2025 14:08:32 -0800', // Wrong date (later)
                    'title' => 'How Spies Think: Ten Lessons in Intelligence',
                    'link' => 'https://www.goodreads.com/review/show/8108362050',
                    'book_id' => '55928896',
                    'user_name' => 'Will',
                    'user_rating' => 0,
                    'user_date_added' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'user_shelves' => 'currently-reading',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        // Check event was created with stored date, not shelf pubDate
        $event = Event::where('action', 'is_reading')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('2025-11-29', $event->time->toDateTimeString());
    }

    /**
     * @test
     */
    public function falls_back_to_shelf_pub_date_if_no_stored_date()
    {
        $this->integration->update(['instance_type' => 'shelf_currently_reading']);

        $rawData = [
            'shelf' => 'currently-reading',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8108362050',
                    'pubDate' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'title' => 'How Spies Think: Ten Lessons in Intelligence',
                    'link' => 'https://www.goodreads.com/review/show/8108362050',
                    'book_id' => '55928896',
                    'user_name' => 'Will',
                    'user_rating' => 0,
                    'user_date_added' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'user_shelves' => 'currently-reading',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        // Check event was created with shelf pubDate
        $event = Event::where('action', 'is_reading')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('2025-12-01', $event->time->toDateTimeString());
    }

    /**
     * @test
     */
    public function moves_reading_started_at_to_previously_started_at_when_book_moved_to_read_shelf()
    {
        // Create a book with reading_started_at
        $startedAt = '2025-11-29 22:03:15';
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think: Ten Lessons in Intelligence',
            'metadata' => [
                'book_id' => '55928896',
                'reading_started_at' => $startedAt,
            ],
            'time' => now(),
        ]);

        $rawData = [
            'shelf' => 'read',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8108362050',
                    'pubDate' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'title' => 'How Spies Think: Ten Lessons in Intelligence',
                    'link' => 'https://www.goodreads.com/review/show/8108362050',
                    'book_id' => '55928896',
                    'user_name' => 'Will',
                    'user_rating' => 5,
                    'user_date_added' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'user_shelves' => 'read',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        // Check metadata was moved
        $book->refresh();
        $this->assertArrayNotHasKey('reading_started_at', $book->metadata);
        $this->assertArrayHasKey('previously_started_at', $book->metadata);
        $this->assertEquals($startedAt, $book->metadata['previously_started_at']);
    }

    /**
     * @test
     */
    public function moves_reading_started_at_to_previously_started_at_when_book_moved_to_to_read_shelf()
    {
        $this->integration->update(['instance_type' => 'shelf_to_read']);

        // Create a book with reading_started_at
        $startedAt = '2025-11-29 22:03:15';
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'Damaged Like Us',
            'metadata' => [
                'book_id' => '21427834',
                'reading_started_at' => $startedAt,
            ],
            'time' => now(),
        ]);

        $rawData = [
            'shelf' => 'to-read',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8116248116',
                    'pubDate' => 'Tue, 02 Dec 2025 10:45:17 -0800',
                    'title' => 'Damaged Like Us (Like Us, #1)',
                    'link' => 'https://www.goodreads.com/review/show/8116248116',
                    'book_id' => '21427834',
                    'user_name' => 'Will',
                    'user_rating' => 0,
                    'user_date_added' => 'Tue, 02 Dec 2025 10:45:17 -0800',
                    'user_shelves' => 'to-read',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        // Check metadata was moved
        $book->refresh();
        $this->assertArrayNotHasKey('reading_started_at', $book->metadata);
        $this->assertArrayHasKey('previously_started_at', $book->metadata);
        $this->assertEquals($startedAt, $book->metadata['previously_started_at']);
    }

    /**
     * @test
     */
    public function does_not_move_date_for_currently_reading_shelf()
    {
        $this->integration->update(['instance_type' => 'shelf_currently_reading']);

        // Create a book with reading_started_at
        $startedAt = '2025-11-29 22:03:15';
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think: Ten Lessons in Intelligence',
            'metadata' => [
                'book_id' => '55928896',
                'reading_started_at' => $startedAt,
            ],
            'time' => now(),
        ]);

        $rawData = [
            'shelf' => 'currently-reading',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8108362050',
                    'pubDate' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'title' => 'How Spies Think: Ten Lessons in Intelligence',
                    'link' => 'https://www.goodreads.com/review/show/8108362050',
                    'book_id' => '55928896',
                    'user_name' => 'Will',
                    'user_rating' => 0,
                    'user_date_added' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'user_shelves' => 'currently-reading',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        // Check metadata was NOT moved
        $book->refresh();
        $this->assertArrayHasKey('reading_started_at', $book->metadata);
        $this->assertArrayNotHasKey('previously_started_at', $book->metadata);
        $this->assertEquals($startedAt, $book->metadata['reading_started_at']);
    }

    /**
     * @test
     */
    public function preserves_previously_started_at_when_book_already_has_one()
    {
        // Create a book with both reading_started_at and previously_started_at
        $previousDate = '2025-10-15 10:00:00';
        $currentDate = '2025-11-29 22:03:15';
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think: Ten Lessons in Intelligence',
            'metadata' => [
                'book_id' => '55928896',
                'reading_started_at' => $currentDate,
                'previously_started_at' => $previousDate, // From previous read
            ],
            'time' => now(),
        ]);

        $rawData = [
            'shelf' => 'read',
            'items' => [
                [
                    'guid' => 'https://www.goodreads.com/review/show/8108362050',
                    'pubDate' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'title' => 'How Spies Think: Ten Lessons in Intelligence',
                    'link' => 'https://www.goodreads.com/review/show/8108362050',
                    'book_id' => '55928896',
                    'user_name' => 'Will',
                    'user_rating' => 5,
                    'user_date_added' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'user_shelves' => 'read',
                ],
            ],
        ];

        $job = new GoodreadsShelfData($this->integration, $rawData);
        $job->handle();

        // Check metadata: should overwrite previously_started_at with most recent
        $book->refresh();
        $this->assertArrayNotHasKey('reading_started_at', $book->metadata);
        $this->assertArrayHasKey('previously_started_at', $book->metadata);
        $this->assertEquals($currentDate, $book->metadata['previously_started_at']); // Overwrites old one
    }
}
