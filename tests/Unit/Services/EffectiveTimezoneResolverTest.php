<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\EffectiveTimezoneResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EffectiveTimezoneResolverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function falls_back_to_profile_timezone_when_no_event(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);

        $this->assertSame('Europe/London', app(EffectiveTimezoneResolver::class)->timezoneFor($user));
    }

    #[Test]
    public function returns_utc_when_no_user(): void
    {
        $this->assertSame('UTC', app(EffectiveTimezoneResolver::class)->timezoneFor(null));
    }

    #[Test]
    public function uses_latest_acknowledged_timezone(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $this->makeTimezoneEvent($user, 'America/New_York', '2026-06-14T10:00:00.000000Z');

        $this->assertSame('America/New_York', app(EffectiveTimezoneResolver::class)->timezoneFor($user));
    }

    #[Test]
    public function helpers_reflect_effective_timezone(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $this->makeTimezoneEvent($user, 'America/New_York', '2026-06-14T10:00:00.000000Z');

        $this->assertSame('America/New_York', user_now($user)->getTimezone()->getName());
        $this->assertSame('America/New_York', user_today($user)->getTimezone()->getName());
    }

    #[Test]
    public function forget_busts_the_memo(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $resolver = app(EffectiveTimezoneResolver::class);

        $this->assertSame('Europe/London', $resolver->timezoneFor($user));

        $this->makeTimezoneEvent($user, 'Asia/Tokyo', '2026-06-14T10:00:00.000000Z');

        // Still memoized as the profile timezone until we forget it.
        $this->assertSame('Europe/London', $resolver->timezoneFor($user));

        $resolver->forget($user);
        $this->assertSame('Asia/Tokyo', $resolver->timezoneFor($user));
    }

    #[Test]
    public function point_in_time_returns_timezone_acknowledged_at_or_before_instant(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $this->makeTimezoneEvent($user, 'Asia/Tokyo', '2026-06-10T10:00:00.000000Z');

        $resolver = app(EffectiveTimezoneResolver::class);

        // An instant after the acknowledgement sees the travel timezone.
        $this->assertSame('Asia/Tokyo', $resolver->timezoneForAt($user, Carbon::parse('2026-06-12 12:00', 'UTC')));

        // An instant before the acknowledgement still sees the profile timezone.
        $this->assertSame('Europe/London', $resolver->timezoneForAt($user, Carbon::parse('2026-06-08 12:00', 'UTC')));
    }

    #[Test]
    public function point_in_time_picks_the_latest_acknowledgement_before_the_instant(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $this->makeTimezoneEvent($user, 'Asia/Tokyo', '2026-06-10T10:00:00.000000Z');
        $this->makeTimezoneEvent($user, 'America/New_York', '2026-06-15T10:00:00.000000Z');

        $resolver = app(EffectiveTimezoneResolver::class);

        $this->assertSame('Asia/Tokyo', $resolver->timezoneForAt($user, Carbon::parse('2026-06-12 12:00', 'UTC')));
        $this->assertSame('America/New_York', $resolver->timezoneForAt($user, Carbon::parse('2026-06-20 12:00', 'UTC')));
    }

    #[Test]
    public function point_in_time_falls_back_to_time_for_legacy_event_without_acknowledged_at(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $this->makeLegacyTimezoneEvent($user, 'Asia/Tokyo', Carbon::parse('2026-06-10 10:00', 'UTC'));

        $resolver = app(EffectiveTimezoneResolver::class);

        $this->assertSame('Asia/Tokyo', $resolver->timezoneForAt($user, Carbon::parse('2026-06-12 12:00', 'UTC')));
        $this->assertSame('Europe/London', $resolver->timezoneForAt($user, Carbon::parse('2026-06-08 12:00', 'UTC')));
    }

    #[Test]
    public function point_in_time_at_now_matches_the_live_effective_timezone(): void
    {
        $resolver = app(EffectiveTimezoneResolver::class);

        // No acknowledgement: both resolve to the profile timezone.
        $noEvents = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $this->assertSame(
            $resolver->timezoneFor($noEvents),
            $resolver->timezoneForAt($noEvents, Carbon::now()),
        );

        // With a past acknowledgement: both resolve to the acknowledged timezone.
        $travelled = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $this->makeTimezoneEvent($travelled, 'Asia/Tokyo', Carbon::now()->subDay()->format('Y-m-d\TH:i:s.u\Z'));
        $this->assertSame(
            $resolver->timezoneFor($travelled),
            $resolver->timezoneForAt($travelled, Carbon::now()),
        );
    }

    #[Test]
    public function point_in_time_returns_utc_when_no_user(): void
    {
        $this->assertSame('UTC', app(EffectiveTimezoneResolver::class)->timezoneForAt(null, Carbon::now()));
    }

    private function makeTimezoneEvent(User $user, string $timezone, string $acknowledgedAt): Event
    {
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'daily_checkin',
        ]);

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

    private function makeLegacyTimezoneEvent(User $user, string $timezone, Carbon $time): Event
    {
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'daily_checkin',
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'daily_checkin',
            'action' => 'time_travel',
            'time' => $time,
            'event_metadata' => [
                'timezone' => $timezone,
            ],
        ]);
    }
}
