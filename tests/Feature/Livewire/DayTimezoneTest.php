<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Day;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A past day must render in the timezone that was acknowledged on that date,
     * not the user's current one (B4 / CRX-725). The user travelled to Tokyo on
     * the 9th and returned to UTC on the 13th; viewing the 10th must use Tokyo,
     * so an event at 00:30 Tokyo (15:30 UTC on the 9th) belongs to that day even
     * though it falls on the previous UTC calendar day.
     */
    #[Test]
    public function past_day_uses_the_timezone_acknowledged_on_that_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00', 'UTC'));

        $user = User::factory()->create(['settings' => ['timezone' => 'UTC']]);

        $checkin = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'daily_checkin',
        ]);

        // Travelled to Tokyo before the target day, then back to UTC after it.
        $this->makeTimezoneEvent($checkin, 'Asia/Tokyo', '2026-06-09T08:00:00.000000Z');
        $this->makeTimezoneEvent($checkin, 'UTC', '2026-06-13T08:00:00.000000Z');

        // 00:30 on the 10th in Tokyo == 15:30 on the 9th in UTC.
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'monzo',
        ]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'action' => 'card_payment',
            'time' => Carbon::parse('2026-06-09 15:30', 'UTC'),
        ]);

        $component = Livewire::actingAs($user, 'web')
            ->test(Day::class)
            ->set('coreEventsLoaded', false)
            ->set('date', '2026-06-10')
            ->call('loadCoreEvents');

        $loaded = $component->get('allEvents');

        $this->assertTrue(
            $loaded->contains(fn ($e) => $e->id === $event->id),
            'Expected the event to be included via the historical Tokyo timezone bounds.',
        );
    }

    #[Test]
    public function internal_flint_task_events_are_hidden_from_the_day_feed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00', 'UTC'));

        $user = User::factory()->create(['settings' => ['timezone' => 'UTC']]);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'flint']);

        $visible = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'flint',
            'action' => 'created_digest',
            'time' => Carbon::parse('2026-06-15 09:00', 'UTC'),
        ]);

        $hidden = collect(['created_task', 'task_created', 'task_incident_reported', 'task_incident_resolved'])
            ->map(fn (string $action) => Event::factory()->create([
                'integration_id' => $integration->id,
                'service' => 'flint',
                'action' => $action,
                'time' => Carbon::parse('2026-06-15 09:00', 'UTC'),
                'event_metadata' => ['internal' => true],
            ]));

        $loaded = Livewire::actingAs($user, 'web')
            ->test(Day::class)
            ->set('coreEventsLoaded', false)
            ->set('date', '2026-06-15')
            ->call('loadCoreEvents')
            ->get('filteredEvents');

        $this->assertTrue($loaded->contains(fn ($e) => $e->id === $visible->id));
        $hidden->each(fn (Event $event) => $this->assertFalse($loaded->contains(fn ($e) => $e->id === $event->id), $event->action));
    }

    private function makeTimezoneEvent(Integration $integration, string $timezone, string $acknowledgedAt): Event
    {
        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'daily_checkin',
            'action' => 'time_travel',
            'event_metadata' => [
                'timezone' => $timezone,
                'acknowledged_at' => $acknowledgedAt,
            ],
        ]);
    }
}
