<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\SendDigestNotificationJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    /**
     * @test
     */
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

    /**
     * @test
     */
    public function does_not_send_notification_when_no_digest_found(): void
    {
        Notification::fake();

        // Run the notification job without creating a digest
        $job = new SendDigestNotificationJob($this->user, '06:00');
        $job->handle();

        // Assert no notification was sent
        Notification::assertNothingSent();
    }

    /**
     * @test
     */
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
}
