<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\Goodreads\GoodreadsProgressData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodreadsProgressDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

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
            'name' => 'Reading Progress',
            'instance_type' => 'updates_progress',
            'configuration' => [],
        ]);
    }

    /**
     * @test
     */
    public function processes_progress_update()
    {
        // Create a book first
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think',
            'metadata' => [
                'book_id' => '55928896',
                'current_progress' => 0,
            ],
            'time' => now(),
        ]);

        $rawData = [
            'items' => [
                [
                    'guid' => 'UserStatus1168855100',
                    'pubDate' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'title' => 'Will is 23% done with How Spies Think',
                    'progress_percentage' => 23,
                    'book_title' => 'How Spies Think',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Check event was created
        $event = Event::where('action', 'is_reading')
            ->where('value', 23)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(23, $event->value);
        $this->assertEquals('%', $event->value_unit);

        // Check book metadata was updated
        $book->refresh();
        $this->assertEquals(23, $book->metadata['current_progress']);
    }

    /**
     * @test
     */
    public function skips_progress_update_if_less_than_5_percent_within_6_hours()
    {
        // Create a book
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think',
            'metadata' => [
                'book_id' => '55928896',
                'current_progress' => 23,
            ],
            'time' => now(),
        ]);

        // Create a user object
        $userObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'user',
            'type' => 'goodreads_user',
            'title' => 'Test User',
            'time' => now(),
        ]);

        // Create an existing progress event (23% done, 2 hours ago)
        $event = Event::create([
            'integration_id' => $this->integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $book->id,
            'service' => 'goodreads',
            'domain' => 'media',
            'action' => 'is_reading',
            'value' => 23,
            'value_unit' => '%',
            'time' => now()->subHours(2),
            'source_id' => 'test_previous',
            'event_metadata' => [],
        ]);

        // Try to add 24% progress (only 1% increase)
        $rawData = [
            'items' => [
                [
                    'guid' => 'UserStatus1234567890',
                    'pubDate' => now()->toRfc2822String(),
                    'title' => 'Will is 24% done with How Spies Think',
                    'progress_percentage' => 24,
                    'book_title' => 'How Spies Think',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Should NOT create new event (only 1% increase)
        $eventCount = Event::where('action', 'is_reading')
            ->where('integration_id', $this->integration->id)
            ->where('value', 24)
            ->count();

        $this->assertEquals(0, $eventCount);
    }

    /**
     * @test
     */
    public function allows_progress_update_if_5_percent_or_more_increase()
    {
        // Create a book
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think',
            'metadata' => [
                'book_id' => '55928896',
                'current_progress' => 23,
            ],
            'time' => now(),
        ]);

        // Create a user object
        $userObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'user',
            'type' => 'goodreads_user',
            'title' => 'Test User',
            'time' => now(),
        ]);

        // Create an existing progress event (23% done, 2 hours ago)
        $event = Event::create([
            'integration_id' => $this->integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $book->id,
            'service' => 'goodreads',
            'domain' => 'media',
            'action' => 'is_reading',
            'value' => 23,
            'value_unit' => '%',
            'time' => now()->subHours(2),
            'source_id' => 'test_previous',
            'event_metadata' => [],
        ]);

        // Try to add 28% progress (5% increase)
        $rawData = [
            'items' => [
                [
                    'guid' => 'UserStatus1234567890',
                    'pubDate' => now()->toRfc2822String(),
                    'title' => 'Will is 28% done with How Spies Think',
                    'progress_percentage' => 28,
                    'book_title' => 'How Spies Think',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // SHOULD create new event (5% increase)
        $event = Event::where('action', 'is_reading')
            ->where('value', 28)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(28, $event->value);
    }

    /**
     * @test
     */
    public function allows_progress_update_if_more_than_6_hours_since_last()
    {
        // Create a book
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think',
            'metadata' => [
                'book_id' => '55928896',
                'current_progress' => 23,
            ],
            'time' => now(),
        ]);

        // Create a user object
        $userObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'user',
            'type' => 'goodreads_user',
            'title' => 'Test User',
            'time' => now(),
        ]);

        // Create an existing progress event (23% done, 7 hours ago)
        $event = Event::create([
            'integration_id' => $this->integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $book->id,
            'service' => 'goodreads',
            'domain' => 'media',
            'action' => 'is_reading',
            'value' => 23,
            'value_unit' => '%',
            'time' => now()->subHours(7),
            'source_id' => 'test_previous',
            'event_metadata' => [],
        ]);

        // Try to add 24% progress (only 1% increase, but more than 6 hours)
        $rawData = [
            'items' => [
                [
                    'guid' => 'UserStatus1234567890',
                    'pubDate' => now()->toRfc2822String(),
                    'title' => 'Will is 24% done with How Spies Think',
                    'progress_percentage' => 24,
                    'book_title' => 'How Spies Think',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // SHOULD create new event (more than 6 hours ago)
        $event = Event::where('action', 'is_reading')
            ->where('value', 24)
            ->first();

        $this->assertNotNull($event);
    }

    /**
     * @test
     */
    public function skips_progress_update_if_book_not_found()
    {
        $rawData = [
            'items' => [
                [
                    'guid' => 'UserStatus1234567890',
                    'pubDate' => now()->toRfc2822String(),
                    'title' => 'Will is 50% done with Nonexistent Book',
                    'progress_percentage' => 50,
                    'book_title' => 'Nonexistent Book',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Should not create any events
        $eventCount = Event::where('service', 'goodreads')
            ->where('integration_id', $this->integration->id)
            ->count();

        $this->assertEquals(0, $eventCount);
    }

    /**
     * @test
     */
    public function finds_book_by_full_title_in_metadata()
    {
        // Create a book with series info
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'Kings Rising',
            'metadata' => [
                'book_id' => '25792894',
                'full_title' => 'Kings Rising (Captive Prince, #3)',
                'current_progress' => 0,
            ],
            'time' => now(),
        ]);

        // Progress update uses full title
        $rawData = [
            'items' => [
                [
                    'guid' => 'UserStatus1234567890',
                    'pubDate' => now()->toRfc2822String(),
                    'title' => 'Will is 50% done with Kings Rising (Captive Prince, #3)',
                    'progress_percentage' => 50,
                    'book_title' => 'Kings Rising (Captive Prince, #3)',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Should find book and create event
        $event = Event::where('action', 'is_reading')
            ->where('value', 50)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals($book->id, $event->target->id);
    }

    /**
     * @test
     */
    public function stores_reading_started_at_from_start_reading_item()
    {
        // Create a book first
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think: Ten Lessons in Intelligence',
            'metadata' => [
                'book_id' => '55928896',
            ],
            'time' => now(),
        ]);

        $startDate = '2025-11-29 14:03:15';
        $rawData = [
            'items' => [
                [
                    'guid' => 'ReadStatus10191389246',
                    'pubDate' => 'Sat, 29 Nov 2025 14:03:15 -0800',
                    'title' => "Will is currently reading 'How Spies Think: Ten Lessons in Intelligence'",
                    'type' => 'start_reading',
                    'book_title' => 'How Spies Think: Ten Lessons in Intelligence',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Check metadata was updated with start date
        $book->refresh();
        $this->assertArrayHasKey('reading_started_at', $book->metadata);
        $this->assertStringContainsString('2025-11-29', $book->metadata['reading_started_at']);
    }

    /**
     * @test
     */
    public function does_not_overwrite_existing_reading_started_at()
    {
        // Create a book with existing reading_started_at
        $originalDate = '2025-11-25 10:00:00';
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think: Ten Lessons in Intelligence',
            'metadata' => [
                'book_id' => '55928896',
                'reading_started_at' => $originalDate,
            ],
            'time' => now(),
        ]);

        $rawData = [
            'items' => [
                [
                    'guid' => 'ReadStatus10191389246',
                    'pubDate' => 'Sat, 29 Nov 2025 14:03:15 -0800', // Later date
                    'title' => "Will is currently reading 'How Spies Think: Ten Lessons in Intelligence'",
                    'type' => 'start_reading',
                    'book_title' => 'How Spies Think: Ten Lessons in Intelligence',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Should NOT overwrite existing date
        $book->refresh();
        $this->assertEquals($originalDate, $book->metadata['reading_started_at']);
    }

    /**
     * @test
     */
    public function corrects_existing_is_reading_event_time()
    {
        // Create a book
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think: Ten Lessons in Intelligence',
            'metadata' => [
                'book_id' => '55928896',
            ],
            'time' => now(),
        ]);

        // Create a user object
        $userObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'user',
            'type' => 'goodreads_user',
            'title' => 'Test User',
            'time' => now(),
        ]);

        // Create an existing is_reading event with wrong date (from shelf feed)
        $wrongDate = now()->subDays(2);
        $event = Event::create([
            'integration_id' => $this->integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $book->id,
            'service' => 'goodreads',
            'domain' => 'media',
            'action' => 'is_reading',
            'value' => 0,
            'value_unit' => '%',
            'time' => $wrongDate,
            'source_id' => 'goodreads_is_reading_test',
            'event_metadata' => [],
        ]);

        // Process start_reading item with correct date
        $correctDate = '2025-11-29 14:03:15';
        $rawData = [
            'items' => [
                [
                    'guid' => 'ReadStatus10191389246',
                    'pubDate' => 'Sat, 29 Nov 2025 14:03:15 -0800',
                    'title' => "Will is currently reading 'How Spies Think: Ten Lessons in Intelligence'",
                    'type' => 'start_reading',
                    'book_title' => 'How Spies Think: Ten Lessons in Intelligence',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Event time should be corrected
        $event->refresh();
        $this->assertStringContainsString('2025-11-29', $event->time->toDateTimeString());
    }

    /**
     * @test
     */
    public function does_not_correct_event_if_times_already_match()
    {
        // Create a book with correct start date
        $correctDate = '2025-11-29 22:03:15'; // UTC conversion of PST -0800
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think: Ten Lessons in Intelligence',
            'metadata' => [
                'book_id' => '55928896',
                'reading_started_at' => $correctDate,
            ],
            'time' => now(),
        ]);

        // Create a user object
        $userObject = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'user',
            'type' => 'goodreads_user',
            'title' => 'Test User',
            'time' => now(),
        ]);

        // Create an event with correct date already
        $event = Event::create([
            'integration_id' => $this->integration->id,
            'actor_id' => $userObject->id,
            'target_id' => $book->id,
            'service' => 'goodreads',
            'domain' => 'media',
            'action' => 'is_reading',
            'value' => 0,
            'value_unit' => '%',
            'time' => $correctDate,
            'source_id' => 'goodreads_is_reading_test',
            'event_metadata' => [],
        ]);

        $originalUpdatedAt = $event->updated_at;

        // Process start_reading item with same date
        $rawData = [
            'items' => [
                [
                    'guid' => 'ReadStatus10191389246',
                    'pubDate' => 'Sat, 29 Nov 2025 14:03:15 -0800',
                    'title' => "Will is currently reading 'How Spies Think: Ten Lessons in Intelligence'",
                    'type' => 'start_reading',
                    'book_title' => 'How Spies Think: Ten Lessons in Intelligence',
                ],
            ],
        ];

        // Small delay to ensure timestamps would differ if updated
        sleep(1);

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Event should NOT be updated (updated_at should be same)
        $event->refresh();
        $this->assertEquals($originalUpdatedAt->timestamp, $event->updated_at->timestamp);
    }

    /**
     * @test
     */
    public function existing_progress_update_logic_still_works_with_type_field()
    {
        // Create a book
        $book = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'document',
            'type' => 'goodreads_book',
            'title' => 'How Spies Think',
            'metadata' => [
                'book_id' => '55928896',
                'current_progress' => 0,
            ],
            'time' => now(),
        ]);

        $rawData = [
            'items' => [
                [
                    'guid' => 'UserStatus1168855100',
                    'pubDate' => 'Mon, 01 Dec 2025 14:08:32 -0800',
                    'title' => 'Will is 23% done with How Spies Think',
                    'type' => 'progress', // Explicit type
                    'progress_percentage' => 23,
                    'book_title' => 'How Spies Think',
                ],
            ],
        ];

        $job = new GoodreadsProgressData($this->integration, $rawData);
        $job->handle();

        // Check event was created
        $event = Event::where('action', 'is_reading')
            ->where('value', 23)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(23, $event->value);

        // Check book metadata was updated
        $book->refresh();
        $this->assertEquals(23, $book->metadata['current_progress']);
    }
}
