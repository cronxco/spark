<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Support\SparkAbility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BriefingAheadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        Carbon::setTestNow('2026-10-10 19:00:00 UTC');
        $this->user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function today_and_past_days_carry_no_ahead_section(): void
    {
        $this->getJson('/api/v1/mobile/briefing/today')
            ->assertOk()
            ->assertJsonMissingPath('ahead');

        $this->getJson('/api/v1/mobile/briefing/today?date=yesterday')
            ->assertOk()
            ->assertJsonMissingPath('ahead');
    }

    #[Test]
    public function an_empty_tomorrow_says_nothing_is_on(): void
    {
        $this->getJson('/api/v1/mobile/briefing/today?date=tomorrow')
            ->assertOk()
            ->assertJsonPath('date', '2026-10-11')
            ->assertJsonPath('ahead.date', '2026-10-11')
            ->assertJsonPath('ahead.headline', 'Nothing on the calendar.')
            ->assertJsonPath('ahead.calendar', [])
            ->assertJsonPath('ahead.plan', [])
            ->assertJsonPath('ahead.weather', null)
            ->assertJsonPath('ahead.first_commitment', null)
            ->assertJsonPath('ahead.sleep_target', null);
    }

    #[Test]
    public function synced_calendar_events_form_the_schedule_without_a_digest(): void
    {
        $calendar = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'google_calendar']);
        $this->calendarEvent($calendar, 'Lunch with Sam', '2026-10-11 12:00:00', 90, 'Peckham');
        $this->calendarEvent($calendar, 'parkrun', '2026-10-11 08:00:00', 30);
        $this->calendarEvent($calendar, "Dan's birthday", '2026-10-11 00:00:00', null);
        $this->calendarEvent($calendar, 'Next week', '2026-10-13 09:00:00', 30);

        $response = $this->getJson('/api/v1/mobile/briefing/today?date=2026-10-11')->assertOk();

        $response->assertJsonCount(2, 'ahead.calendar')
            ->assertJsonPath('ahead.calendar.0.title', 'parkrun')
            ->assertJsonPath('ahead.calendar.0.start', '2026-10-11T09:00:00+01:00')
            ->assertJsonPath('ahead.calendar.0.end', '2026-10-11T09:30:00+01:00')
            ->assertJsonPath('ahead.calendar.0.person', null)
            ->assertJsonPath('ahead.calendar.1.title', 'Lunch with Sam')
            ->assertJsonPath('ahead.calendar.1.location', 'Peckham')
            ->assertJsonPath('ahead.birthdays.0.title', "Dan's birthday")
            ->assertJsonPath('ahead.first_commitment.title', 'parkrun')
            ->assertJsonPath('ahead.headline', "2 things on, first at 09:00. Dan's birthday.");
    }

    #[Test]
    public function the_evening_digest_supplies_attribution_weather_and_birthdays(): void
    {
        $calendar = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'google_calendar']);
        $synced = $this->calendarEvent($calendar, 'Office', '2026-10-11 08:00:00', 480, 'Kings Cross');

        $this->dayContext('2026-10-11', [
            'calendar' => [
                ['title' => 'Will · Office', 'all_day' => false, 'start' => '2026-10-11T09:00:00+01:00', 'person' => 'will'],
                ['title' => 'Dan · Office', 'all_day' => false, 'start' => '2026-10-11T09:00:00+01:00', 'person' => 'dan'],
                ['title' => 'Climbing', 'all_day' => false, 'start' => '2026-10-11T19:00:00+01:00', 'person' => 'dan'],
            ],
            'birthdays' => [['title' => "Sam's birthday"]],
            'weather' => ['location' => 'London', 'condition' => 'Overcast', 'temp_high_c' => 14.4, 'rain_probability_pct' => 60],
        ]);

        $this->getJson('/api/v1/mobile/briefing/today?date=tomorrow')
            ->assertOk()
            ->assertJsonCount(2, 'ahead.calendar')
            ->assertJsonPath('ahead.calendar.0.title', 'Office')
            ->assertJsonPath('ahead.calendar.0.person', 'both')
            ->assertJsonPath('ahead.calendar.0.location', 'Kings Cross')
            ->assertJsonPath('ahead.calendar.0.event_id', (string) $synced->id)
            ->assertJsonPath('ahead.calendar.1.title', 'Climbing')
            ->assertJsonPath('ahead.calendar.1.person', 'dan')
            ->assertJsonPath('ahead.birthdays', [['title' => "Sam's birthday"]])
            ->assertJsonPath('ahead.weather.condition', 'Overcast')
            ->assertJsonPath('ahead.weather.rain_probability_pct', 60)
            ->assertJsonPath('ahead.headline', "2 things on, first at 09:00. Sam's birthday. Overcast, 14°, 60% chance of rain.");
    }

    #[Test]
    public function the_plan_joins_day_note_tasks_topic_tasks_and_reviews(): void
    {
        $outline = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'outline']);
        $dayNote = Event::factory()->create([
            'integration_id' => $outline->id,
            'service' => 'outline',
            'action' => 'had_day_note',
            'time' => '2026-10-11 00:00:00',
            'target_id' => EventObject::factory()->create(['user_id' => $this->user->id, 'url' => 'https://docs.example/day'])->id,
        ]);
        Block::factory()->create(['event_id' => $dayNote->id, 'block_type' => 'day_task', 'title' => 'Second', 'metadata' => ['line_number' => 9, 'checked' => true]]);
        Block::factory()->create(['event_id' => $dayNote->id, 'block_type' => 'day_task', 'title' => 'First', 'metadata' => ['line_number' => 3, 'checked' => false]]);
        Block::factory()->create(['event_id' => $dayNote->id, 'block_type' => 'day_task', 'title' => 'Gone', 'metadata' => ['line_number' => 4, 'removed' => true]]);

        $flint = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint']);
        $topic = EventObject::factory()->create([
            'user_id' => $this->user->id, 'concept' => 'flint', 'type' => 'topic', 'title' => 'Marathon base',
            'metadata' => ['status' => 'active', 'next_review_at' => '2026-10-11'],
        ]);
        EventObject::factory()->create([
            'user_id' => $this->user->id, 'concept' => 'flint', 'type' => 'topic', 'title' => 'Retired',
            'metadata' => ['status' => 'retired', 'next_review_at' => '2026-10-11'],
        ]);
        $taskEvent = Event::factory()->create([
            'integration_id' => $flint->id, 'service' => 'flint', 'action' => 'had_topic_task',
            'target_id' => $topic->id, 'event_metadata' => ['internal' => true],
        ]);
        Block::factory()->create(['event_id' => $taskEvent->id, 'block_type' => 'flint_topic_task', 'title' => 'Book physio', 'metadata' => ['due_on' => '2026-10-11', 'completed_at' => null]]);
        Block::factory()->create(['event_id' => $taskEvent->id, 'block_type' => 'flint_topic_task', 'title' => 'Later', 'metadata' => ['due_on' => '2026-10-20', 'completed_at' => null]]);

        $this->getJson('/api/v1/mobile/briefing/today?date=tomorrow')
            ->assertOk()
            ->assertJsonCount(4, 'ahead.plan')
            ->assertJsonPath('ahead.plan.0.title', 'First')
            ->assertJsonPath('ahead.plan.0.kind', 'day_note')
            ->assertJsonPath('ahead.plan.0.done', false)
            ->assertJsonPath('ahead.plan.1.title', 'Second')
            ->assertJsonPath('ahead.plan.1.done', true)
            ->assertJsonPath('ahead.plan.2.title', 'Book physio')
            ->assertJsonPath('ahead.plan.2.kind', 'topic_task')
            ->assertJsonPath('ahead.plan.2.topic_title', 'Marathon base')
            ->assertJsonPath('ahead.plan.3.kind', 'topic_review')
            ->assertJsonPath('ahead.plan.3.title', 'Marathon base')
            ->assertJsonPath('ahead.day_note_url', 'https://docs.example/day');
    }

    #[Test]
    public function an_early_start_produces_a_bedtime_target(): void
    {
        $oura = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'oura']);
        foreach (range(1, 7) as $daysAgo) {
            $bedtime = Carbon::parse('2026-10-10 22:30:00', 'Europe/London')->subDays($daysAgo);
            Event::factory()->create([
                'integration_id' => $oura->id, 'service' => 'oura', 'action' => 'slept_for',
                'time' => $bedtime->copy()->utc(), 'value' => 8 * 3600, 'value_multiplier' => 1,
                'event_metadata' => ['end' => $bedtime->copy()->addHours(8)->addMinutes(30)->toIso8601String()],
            ]);
        }
        $calendar = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'google_calendar']);
        $this->calendarEvent($calendar, 'Flight', '2026-10-11 05:30:00', 120);

        $this->getJson('/api/v1/mobile/briefing/today?date=tomorrow')
            ->assertOk()
            ->assertJsonPath('ahead.sleep_target.wake_by', '2026-10-11T05:30:00+01:00')
            ->assertJsonPath('ahead.sleep_target.bed_by', '2026-10-10T21:10:00+01:00')
            ->assertJsonPath('ahead.sleep_target.sleep_need_minutes', 480)
            ->assertJsonPath('ahead.sleep_target.typical_wake', '07:00')
            ->assertJsonPath('ahead.sleep_target.early_start', true);
    }

    #[Test]
    public function a_usual_start_with_steady_readiness_has_no_bedtime_target(): void
    {
        $oura = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'oura']);
        foreach (range(1, 7) as $daysAgo) {
            $bedtime = Carbon::parse('2026-10-10 22:30:00', 'Europe/London')->subDays($daysAgo);
            Event::factory()->create([
                'integration_id' => $oura->id, 'service' => 'oura', 'action' => 'slept_for',
                'time' => $bedtime->copy()->utc(), 'value' => 8 * 3600, 'value_multiplier' => 1,
                'event_metadata' => ['end' => $bedtime->copy()->addHours(8)->addMinutes(30)->toIso8601String()],
            ]);
        }
        $calendar = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'google_calendar']);
        $this->calendarEvent($calendar, 'Brunch', '2026-10-11 10:00:00', 60);

        $this->getJson('/api/v1/mobile/briefing/today?date=tomorrow')
            ->assertOk()
            ->assertJsonPath('ahead.sleep_target', null);
    }

    private function calendarEvent(Integration $integration, string $title, string $utcStart, ?int $minutes, ?string $location = null): Event
    {
        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'google_calendar',
            'domain' => 'knowledge',
            'action' => $minutes === null ? 'had_all_day_event' : 'had_event',
            'time' => $utcStart,
            'value' => $minutes,
            'value_multiplier' => 1,
            'event_metadata' => array_filter(['location' => $location]),
            'target_id' => EventObject::factory()->create([
                'user_id' => $this->user->id, 'concept' => 'event', 'type' => 'calendar_event', 'title' => $title,
            ])->id,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function dayContext(string $date, array $context): void
    {
        $flint = Integration::factory()->create(['user_id' => $this->user->id, 'service' => 'flint', 'instance_type' => 'digest']);
        $digest = Event::factory()->create([
            'integration_id' => $flint->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => '2026-10-10 18:00:00',
            'event_metadata' => ['period' => 'evening', 'title' => 'Evening digest'],
        ]);
        Block::factory()->create([
            'event_id' => $digest->id,
            'block_type' => 'flint_day_context',
            'title' => 'Tomorrow at a glance',
            'metadata' => ['day_context' => array_merge(['date' => $date], $context)],
        ]);
    }
}
