<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\SendDigestNotificationJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $flintIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->flintIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function sends_digest_notification_when_digest_exists(): void
    {
        Notification::fake();

        // Create a day object to be the target
        $dayObject = EventObject::factory()->create([
            'concept' => 'day',
            'type' => 'day',
            'title' => now()->format('Y-m-d'),
            'time' => now()->startOfDay(),
        ]);

        // Create a flint event
        $flintEvent = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'target_id' => $dayObject->id,
            'service' => 'flint',
            'action' => 'had_analysis',
            'time' => now(),
        ]);

        // Create a digest block
        $digestBlock = Block::create([
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_digest',
            'time' => now(),
            'metadata' => [
                'headline' => 'Your Morning Digest',
                'summary' => 'Here is what happened today.',
                'top_insights' => [
                    [
                        'icon' => '💡',
                        'title' => 'Great sleep',
                        'description' => 'You got 8 hours of sleep.',
                    ],
                ],
                'wins' => ['Completed morning workout'],
                'watch_points' => [],
                'tomorrow_focus' => ['Focus on hydration'],
            ],
        ]);

        // Run the notification job (06:00 is morning)
        $job = new SendDigestNotificationJob($this->user, '06:00');
        $job->handle();

        // Assert notification was sent
        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class,
            function ($notification) use ($dayObject) {
                return $notification->digestObject->id === $dayObject->id
                    && $notification->period === 'morning';
            }
        );
    }

    #[Test]
    public function does_not_send_notification_when_no_digest_found(): void
    {
        Notification::fake();

        // Run the notification job without creating a digest
        $job = new SendDigestNotificationJob($this->user, '06:00');
        $job->handle();

        // Assert no notification was sent
        Notification::assertNothingSent();
    }

    #[Test]
    public function notification_contains_correct_digest_data(): void
    {
        Notification::fake();

        // Create a day object to be the target
        $dayObject = EventObject::factory()->create([
            'concept' => 'day',
            'type' => 'day',
            'title' => now()->format('Y-m-d'),
            'time' => now()->startOfDay(),
        ]);

        // Create a flint event
        $flintEvent = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'target_id' => $dayObject->id,
            'service' => 'flint',
            'action' => 'had_analysis',
            'time' => now(),
        ]);

        // Create a digest with specific data
        $digestBlock = Block::create([
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_digest',
            'time' => now(),
            'metadata' => [
                'headline' => 'Evening Digest',
                'summary' => 'Your evening summary.',
                'top_insights' => [
                    [
                        'icon' => '🏃',
                        'title' => 'Active day',
                        'description' => 'You walked 10,000 steps.',
                    ],
                ],
                'wins' => ['Hit step goal'],
                'watch_points' => ['Missed workout'],
                'tomorrow_focus' => ['Morning run'],
            ],
        ]);

        // Run the notification job (18:00 is evening)
        $job = new SendDigestNotificationJob($this->user, '18:00');
        $job->handle();

        // Assert notification was sent with correct data
        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class,
            function ($notification) use ($dayObject) {
                return $notification->digestObject->id === $dayObject->id
                    && $notification->period === 'evening'
                    && count($notification->blocks) > 0;
            }
        );
    }

    /**
     * The digest block lookup must use the user's effective-timezone local day,
     * not the server (UTC) day. A far-west user's digest is dated for their local
     * day but stored at that date's 00:00 UTC marker (see CreateFlintDigestTool);
     * once UTC has rolled past midnight, a server-tz `startOfDay()` bound would
     * wrongly exclude it. Reproduces and guards the far-west day-boundary bug.
     */
    #[Test]
    public function finds_digest_using_effective_timezone_local_day_for_far_west_user(): void
    {
        Notification::fake();

        $this->user->setTimezone('Pacific/Honolulu'); // UTC-10, no time_travel event => profile tz

        $localDate = '2026-06-14';

        // The user is late in their local day; UTC has already rolled to the 15th.
        Carbon::setTestNow(Carbon::parse("{$localDate} 22:00", 'Pacific/Honolulu')); // = 2026-06-15 08:00 UTC

        $dayObject = EventObject::factory()->create([
            'concept' => 'day',
            'type' => 'day',
            'title' => $localDate,
            'time' => Carbon::parse($localDate),
        ]);

        // Mirror production storage: the had_summary event's `time` is the
        // local_date anchored at 00:00 UTC (CreateFlintDigestTool:113), which is
        // BEFORE now()->startOfDay() (= 2026-06-15 00:00 UTC) at this moment.
        $flintEvent = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'target_id' => $dayObject->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => Carbon::parse($localDate), // 2026-06-14 00:00 UTC
        ]);

        Block::create([
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_digest',
            'time' => Carbon::parse($localDate),
            'metadata' => [
                'headline' => 'Evening Digest',
                'summary' => 'Far-west summary.',
            ],
        ]);

        $job = new SendDigestNotificationJob($this->user, '18:00');
        $job->handle();

        Notification::assertSentTo(
            [$this->user],
            DailyDigestReady::class,
            fn ($notification) => $notification->digestObject->id === $dayObject->id
                && $notification->period === 'evening'
        );
    }
}
