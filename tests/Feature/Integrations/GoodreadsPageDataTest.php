<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Goodreads\GoodreadsPageParser;
use App\Jobs\Data\Goodreads\GoodreadsPageData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Media\MediaDownloadHelper;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class GoodreadsPageDataTest extends TestCase
{
    private Integration $integration;

    private int $poll = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id, 'service' => 'goodreads', 'auth_metadata' => ['user_id' => '42'],
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'goodreads', 'instance_type' => 'shelf_read',
        ]);
        $this->mock(MediaDownloadHelper::class)->shouldReceive('downloadAndAttachMedia')->andReturnNull();
    }

    #[Test]
    public function imports_rereads_book_blocks_author_tags_and_series_idempotently(): void
    {
        $item = $this->shelfItem();
        $this->import('shelf', [$item]);
        $this->import('shelf', [$item]);

        $this->assertSame(2, Event::where('service', 'goodreads')->count());
        $this->assertSame(1, EventObject::where('type', 'goodreads_book')->count());
        $this->assertSame('Example Book', $this->book()->title);
        $this->assertSame(100, $this->book()->metadata['current_progress']);
        $this->assertSame(5, $this->book()->metadata['user_rating']);
        $this->assertSame(2, Relationship::where('type', 'part_of')->firstOrFail()->metadata['series_order'] + 0);
        foreach (Event::where('service', 'goodreads')->get() as $event) {
            $this->assertEquals(5, $event->value);
            $this->assertSame('/5', $event->value_unit);
            $this->assertSame('estimated', $event->event_metadata['time_precision']);
            $this->assertSame(1, $event->blocks()->where('block_type', 'book')->count());
            $this->assertSame(['A. Writer'], $event->tags()->where('type', 'goodreads_author')->pluck('name')->all());
            $this->assertTrue($event->time->eq($event->blocks()->first()->time));
        }
    }

    #[Test]
    public function a_date_only_shelf_entry_for_a_past_day_is_midday_in_the_users_timezone_bst_aware(): void
    {
        $this->integration->user->update(['settings' => ['timezone' => 'Europe/London']]);
        $item = $this->shelfItem('to-read');
        $item['user_date_added'] = '2026-10-19';
        $this->import('shelf', [$item]);

        $this->assertSame('2026-10-19 11:00:00', Event::firstOrFail()->time->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_date_only_shelf_entry_for_today_uses_when_it_was_first_seen_and_does_not_drift(): void
    {
        $this->integration->user->update(['settings' => ['timezone' => 'Europe/London']]);
        $item = $this->shelfItem('to-read');
        $item['user_date_added'] = '2026-11-06';

        $this->travelTo('2026-11-06 12:57:15');
        $this->import('shelf', [$item]);
        $first = Event::firstOrFail()->time->utc()->format('H:i');
        $this->assertSame('12:57', substr($first, 0, 5));

        $this->travelTo('2026-11-06 15:30:00');
        $this->import('shelf', [$item]);
        $this->assertSame('12:57', Event::firstOrFail()->time->utc()->format('H:i'));
    }

    #[Test]
    public function a_finished_book_is_dated_when_it_left_the_currently_reading_shelf_not_when_the_read_shelf_was_polled(): void
    {
        $this->integration->user->update(['settings' => ['timezone' => 'Europe/London']]);
        Integration::factory()->create([
            'user_id' => $this->integration->user_id, 'integration_group_id' => $this->integration->integration_group_id,
            'service' => 'goodreads', 'instance_type' => 'shelf_currently_reading', 'configuration' => ['update_frequency_minutes' => 60],
        ]);
        $current = $this->shelfItem('currently-reading');
        $current['reading_sessions'] = [['id' => 's1', 'started_at' => '2026-11-06', 'finished_at' => null]];
        $read = $this->shelfItem('read');
        $read['reading_sessions'] = [['id' => 's1', 'started_at' => '2026-11-06', 'finished_at' => '2026-11-07']];

        $this->travelTo('2026-11-07 13:28:10');
        $this->import('shelf', [$current]);
        $this->travelTo('2026-11-07 15:28:19');
        $this->import('shelf', [$read]);

        $finished = Event::where('action', 'finished_reading')->firstOrFail();
        $this->assertSame('14:28', $finished->time->utc()->format('H:i'));

        $this->travelTo('2026-11-07 17:28:00');
        $this->import('shelf', [$read]);
        $this->assertSame('14:28', $finished->fresh()->time->utc()->format('H:i'));
    }

    #[Test]
    public function an_existing_midnight_day_entry_for_today_is_corrected_to_when_it_was_created(): void
    {
        $this->integration->user->update(['settings' => ['timezone' => 'Europe/London']]);
        $item = $this->shelfItem('to-read');
        $item['user_date_added'] = '2026-11-06';
        $this->travelTo('2026-11-06 12:57:15');
        $this->import('shelf', [$item]);
        $event = Event::firstOrFail();
        $event->forceFill([
            'time' => '2026-11-06 08:00:00',
            'event_metadata' => [...$event->event_metadata, 'time_precision' => 'day'],
        ])->save();

        $this->travelTo('2026-11-06 15:30:00');
        $this->import('shelf', [$item]);

        $event->refresh();
        $this->assertSame('12:57', $event->time->utc()->format('H:i'));
        $this->assertSame('estimated', $event->event_metadata['time_precision']);
    }

    #[Test]
    public function reuses_legacy_review_events_without_changing_source_ids_or_second_precision(): void
    {
        $legacy = $this->legacyEvent('finished_reading', 'https://www.goodreads.com/review/show/9001?utm_medium=api&utm_source=rss', '2025-09-15T21:22:20+00:00', 5);
        $source = $legacy->source_id;
        $this->import('shelf', [$this->shelfItem()]);
        $this->import('shelf', [$this->shelfItem()]);

        $legacy->refresh();
        $this->assertSame(2, Event::count());
        $this->assertSame($source, $legacy->source_id);
        $this->assertSame('2025-09-15 21:22:20', $legacy->time->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($legacy->event_metadata['legacy_timestamp_preserved']);
        $this->assertTrue($this->book()->metadata['preserve_me']);
    }

    #[Test]
    public function does_not_reuse_an_old_start_event_for_a_new_reading_period(): void
    {
        $legacy = $this->legacyEvent('is_reading', 'https://www.goodreads.com/review/show/9001', '2025-09-13T18:01:22+00:00', 0);
        $this->import('shelf', [$this->shelfItem('currently-reading')]);
        $this->assertSame(2, Event::where('action', 'is_reading')->count());
        $this->assertSame('2025-09-13 18:01:22', $legacy->fresh()->time->utc()->format('Y-m-d H:i:s'));
        $this->assertStringStartsWith('2026-10-11', $this->book()->metadata['reading_started_at']);
    }

    #[Test]
    public function imports_want_to_read_without_a_rating_or_progress_value(): void
    {
        $this->import('shelf', [$this->shelfItem('to-read')]);
        $event = Event::firstOrFail();
        $this->assertSame('wants_to_read', $event->action);
        $this->assertNull($event->value);
        $this->assertNull($event->value_unit);
        $this->assertNull($this->book()->metadata['current_progress']);
    }

    #[Test]
    public function reading_progress_uses_book_ids_and_preserves_existing_rss_timestamps(): void
    {
        $legacy = $this->legacyEvent('is_reading', 'UserStatus103', '2026-10-17T22:06:22+00:00', 63);
        $this->import('progress', [$this->progress(63, '103')]);
        $this->assertSame(1, Event::count());
        $this->assertSame('2026-10-17 22:06:22', $legacy->fresh()->time->utc()->format('Y-m-d H:i:s'));
        $other = $this->progress(23, '999');
        $other['book_id'] = '456';
        $this->import('progress', [$other]);
        $this->assertSame(2, EventObject::where('type', 'goodreads_book')->count());
        $this->assertSame(2, Event::count());
        $this->assertNotEquals($legacy->target_id, Event::where('event_metadata->guid', 'UserStatus999')->firstOrFail()->target_id);
    }

    #[Test]
    public function older_progress_pages_do_not_roll_back_the_current_percentage(): void
    {
        $this->import('progress', [$this->progress(63, '103')]);
        $this->import('progress', [$this->progress(23, '101', '2026-10-12T21:00:00+00:00')]);
        $this->assertSame(63, $this->book()->metadata['current_progress']);
        $this->assertSame(2, Event::count());
    }

    #[Test]
    public function repeated_shelf_snapshots_do_not_reset_progress(): void
    {
        $this->import('shelf', [$this->shelfItem('currently-reading')]);
        $this->import('progress', [$this->progress(63, '103')]);
        $this->import('shelf', [$this->shelfItem('currently-reading')]);
        $this->assertSame(63, $this->book()->metadata['current_progress']);
        $this->assertSame(2, Event::count());
    }

    #[Test]
    public function completed_books_stay_complete_when_old_progress_is_backfilled(): void
    {
        $this->import('shelf', [$this->shelfItem()]);
        $this->import('progress', [$this->progress(63, '103')]);
        $this->assertSame(100, $this->book()->metadata['current_progress']);
        $this->assertSame('read', $this->book()->metadata['current_shelf']);
    }

    #[Test]
    public function start_statuses_upgrade_only_zero_percent_events_and_do_not_retime_progress(): void
    {
        $legacy = $this->legacyEvent('is_reading', 'UserStatus103', '2026-10-17T22:06:22+00:00', 63);
        $this->import('shelf', [$this->shelfItem('currently-reading')]);
        $activity = (new GoodreadsPageParser)->activity(file_get_contents(__DIR__ . '/../../Fixtures/Goodreads/activity.html'), '42', 'America/Los_Angeles');
        $this->import('progress', [$activity[1]]);

        $this->assertSame(2, Event::count());
        $this->assertSame('2026-10-17 22:06:22', $legacy->fresh()->time->utc()->format('Y-m-d H:i:s'));
        $start = Event::where('value', 0)->firstOrFail();
        $this->assertSame('2026-10-11 21:33:00', $start->time->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('minute', $start->event_metadata['time_precision']);
        $this->assertSame(63, $this->book()->metadata['current_progress']);
    }

    #[Test]
    public function rating_edits_update_existing_events_instead_of_duplicating_them(): void
    {
        $item = $this->shelfItem();
        $this->import('shelf', [$item]);
        $item['user_rating'] = null;
        $this->import('shelf', [$item]);
        $this->assertSame(2, Event::count());
        $this->assertNull(Event::firstOrFail()->value);
        $this->assertNull(Event::firstOrFail()->value_unit);
        $this->assertNull($this->book()->metadata['user_rating']);
    }

    #[Test]
    public function book_enrichment_updates_objects_and_blocks_without_creating_more_events(): void
    {
        $this->import('shelf', [$this->shelfItem()]);
        $book = (new GoodreadsPageParser)->book(file_get_contents(__DIR__ . '/../../Fixtures/Goodreads/book.html'), '123');
        $this->import('book', [[...$book, 'details_fetched_at' => '2026-11-01T19:00:00+00:00']]);
        $this->assertSame(2, Event::count());
        $this->assertSame(1, EventObject::where('type', 'goodreads_book')->count());
        $this->assertSame('A description with <b>formatting</b>.', $this->book()->content);
        $this->assertSame('https://images.example.com/large.jpg', $this->book()->media_url);
        $this->assertSame(400, Event::firstOrFail()->blocks()->firstOrFail()->metadata['num_pages']);
        $this->import('shelf', [$this->shelfItem()]);
        $this->assertSame('https://images.example.com/large.jpg', $this->book()->media_url);
    }

    #[Test]
    public function retains_the_five_percent_six_hour_filter_without_losing_latest_progress(): void
    {
        $this->import('progress', [$this->progress(23, '301', '2026-10-17T21:00:00+00:00')]);
        $this->import('progress', [$this->progress(24, '302', '2026-10-17T22:00:00+00:00')]);
        $this->assertSame(1, Event::count());
        $this->assertSame(24, $this->book()->metadata['current_progress']);
        $this->import('progress', [$this->progress(30, '303', '2026-10-18T05:00:00+00:00')]);
        $this->import('progress', [$this->progress(24, '302', '2026-10-17T22:00:00+00:00')]);
        $this->assertSame(2, Event::count());
        $this->assertSame(30, $this->book()->metadata['current_progress']);
    }

    #[Test]
    public function does_not_match_another_users_books_or_events(): void
    {
        $other = EventObject::factory()->create(['type' => 'goodreads_book', 'concept' => 'document', 'title' => 'Example Book', 'metadata' => ['book_id' => '123']]);
        $this->import('shelf', [$this->shelfItem()]);
        $this->assertNotEquals($other->id, $this->book()->id);
        $this->assertNull($other->fresh()->metadata['current_shelf'] ?? null);
    }

    #[Test]
    public function date_edits_update_scraped_sessions_without_creating_new_events(): void
    {
        $item = $this->shelfItem();
        $this->import('shelf', [$item]);
        $item['reading_sessions'][0]['finished_at'] = '2026-10-20';
        $this->import('shelf', [$item]);
        $event = Event::where('event_metadata->goodreads_session_id', 'amzn1grreading_sessionv1second')->firstOrFail();
        $this->assertSame(2, Event::count());
        $this->assertSame('2026-10-20', $event->time->timezone('America/Los_Angeles')->toDateString());
        $this->assertTrue($event->time->eq($event->blocks()->firstOrFail()->time));
    }

    #[Test]
    public function later_day_only_snapshots_do_not_downgrade_a_precise_activity_timestamp(): void
    {
        $activity = (new GoodreadsPageParser)->activity(file_get_contents(__DIR__ . '/../../Fixtures/Goodreads/activity.html'), '42', 'America/Los_Angeles');
        $this->import('shelf', [$this->shelfItem()], ['activity' => $activity]);
        $this->import('shelf', [$this->shelfItem()]);
        $event = Event::where('event_metadata->goodreads_session_id', 'amzn1grreading_sessionv1second')->firstOrFail();
        $this->assertSame('2026-10-19 21:25:00', $event->time->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('minute', $event->event_metadata['time_precision']);
    }

    #[Test]
    public function uses_activity_for_a_finished_session_without_a_finish_date_and_keeps_its_identity_when_the_date_is_filled(): void
    {
        $item = $this->shelfItem();
        $item['reading_sessions'][0]['finished_at'] = null;
        $activity = (new GoodreadsPageParser)->activity(file_get_contents(__DIR__ . '/../../Fixtures/Goodreads/activity.html'), '42', 'America/Los_Angeles');
        $this->import('shelf', [$item], ['activity' => $activity]);
        $event = Event::where('event_metadata->goodreads_session_id', 'amzn1grreading_sessionv1second')->firstOrFail();
        $this->assertSame('2026-10-19 21:25:00', $event->time->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('activity', $event->event_metadata['time_source']);
        $this->import('shelf', [$this->shelfItem()]);
        $this->assertSame(2, Event::count());
        $this->assertSame('2026-10-19 21:25:00', $event->fresh()->time->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function downloads_the_full_cover_once_instead_of_persisting_the_shelf_thumbnail(): void
    {
        $this->mock(MediaDownloadHelper::class)->shouldReceive('downloadAndAttachMedia')->once()
            ->with('https://images.example.com/large.jpg', Mockery::type(EventObject::class), 'downloaded_images', Mockery::type('array'))
            ->andReturn(new Media);
        $this->import('shelf', [$this->shelfItem()]);
        $details = (new GoodreadsPageParser)->book(file_get_contents(__DIR__ . '/../../Fixtures/Goodreads/book.html'), '123');
        $this->import('book', [[...$details, 'details_fetched_at' => now()->toIso8601String()]]);
        $this->import('shelf', [$this->shelfItem()]);
        $this->assertSame('https://images.example.com/large.jpg', $this->book()->metadata['cover_downloaded_url']);
    }

    #[Test]
    public function distinct_reading_sessions_on_the_same_day_remain_distinct(): void
    {
        $item = $this->shelfItem('currently-reading');
        $this->import('shelf', [$item]);
        $item['reading_sessions'][0]['id'] = 'amzn1grreading_sessionv1third';
        $this->import('shelf', [$item]);
        $this->assertSame(2, Event::where('action', 'is_reading')->count());
    }

    #[Test]
    public function edition_changes_reuse_review_and_status_identities(): void
    {
        $item = $this->shelfItem();
        $this->import('shelf', [$item]);
        $this->import('progress', [$this->progress(63, '103')]);
        $item['book_id'] = '456';
        $this->import('shelf', [$item]);
        $progress = $this->progress(63, '103');
        $progress['book_id'] = '456';
        $this->import('progress', [$progress]);
        $this->assertSame(3, Event::count());
        $newBook = EventObject::where('type', 'goodreads_book')->where('metadata->book_id', '456')->firstOrFail();
        $this->assertSame(3, Event::where('target_id', $newBook->id)->count());
    }

    private function shelfItem(string $shelf = 'read'): array
    {
        $html = file_get_contents(__DIR__ . '/../../Fixtures/Goodreads/shelf.html');
        $item = (new GoodreadsPageParser)->shelf($html, '42', $shelf, 1, 'America/Los_Angeles')['items'][0];
        if ($shelf === 'currently-reading') {
            $item['reading_sessions'][0]['finished_at'] = null;
        }

        return $item;
    }

    private function progress(int $value, string $id, string $date = '2026-10-17T22:06:00+00:00'): array
    {
        return [
            'guid' => 'UserStatus' . $id, 'status_id' => $id, 'type' => 'progress', 'book_id' => '123',
            'title' => 'Example Book (Example Series, #2)', 'user_name' => 'Alex', 'user_id' => '42',
            'progress_percentage' => $value, 'pubDate' => $date, 'source_date' => $date,
            'source_timezone' => 'America/Los_Angeles', 'time_precision' => 'minute',
        ];
    }

    private function import(string $kind, array $items, array $extra = []): void
    {
        (new GoodreadsPageData($this->integration, [
            'kind' => $kind, 'items' => $items, 'observed_at' => now()->addSeconds(++$this->poll)->toIso8601String(), ...$extra,
        ]))->handle();
    }

    private function book(): EventObject
    {
        return EventObject::where('user_id', $this->integration->user_id)->where('type', 'goodreads_book')->firstOrFail();
    }

    private function legacyEvent(string $action, string $guid, string $date, ?int $value = null): Event
    {
        $book = EventObject::firstOrCreate([
            'user_id' => $this->integration->user_id, 'concept' => 'document', 'type' => 'goodreads_book', 'title' => 'Example Book',
        ], ['metadata' => ['book_id' => '123', 'preserve_me' => true], 'time' => $date]);
        $actor = EventObject::firstOrCreate([
            'user_id' => $this->integration->user_id, 'concept' => 'user', 'type' => 'goodreads_user', 'title' => 'Alex',
        ], ['time' => $date]);

        return Event::create([
            'integration_id' => $this->integration->id, 'actor_id' => $actor->id, 'target_id' => $book->id,
            'source_id' => 'goodreads_' . ($action === 'is_reading' && str_starts_with($guid, 'UserStatus') ? $value : $action) . '_' . md5($guid),
            'service' => 'goodreads', 'domain' => 'media', 'action' => $action, 'time' => $date,
            'value' => $value, 'value_unit' => $action === 'is_reading' ? '%' : '/5', 'event_metadata' => ['guid' => $guid],
        ]);
    }
}
