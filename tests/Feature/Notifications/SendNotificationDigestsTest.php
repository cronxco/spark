<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\NotificationDigest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * NOTIF-05: "Daily Digest" promised one daily email but nothing ever sent it,
 * so a digest user received no email at all.
 */
class SendNotificationDigestsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->create(['settings' => ['timezone' => 'UTC']]);
        $this->user->updateNotificationPreferences([
            'delayed_sending' => ['mode' => 'daily_digest', 'digest_time' => '09:00'],
        ]);
    }

    #[Test]
    public function it_sends_one_email_with_the_days_notifications_after_the_digest_time(): void
    {
        $this->notification('integration_completed', 'Monzo synced', '2026-10-04 12:00');
        $this->notification('fetch_content_changed', 'Page changed', '2026-10-05 08:30');

        $this->travelTo(Carbon::parse('2026-10-05 09:05', 'UTC'));
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertSentToTimes($this->user, NotificationDigest::class, 1);
        Notification::assertSentTo($this->user, NotificationDigest::class, fn (NotificationDigest $digest) => array_column($digest->items, 'title') === ['Monzo synced', 'Page changed']);
    }

    #[Test]
    public function nothing_goes_out_before_the_digest_time(): void
    {
        $this->notification('integration_completed', 'Monzo synced', '2026-10-05 07:00');

        $this->travelTo(Carbon::parse('2026-10-05 08:55', 'UTC'));
        $this->artisan('notifications:send-digests')->assertSuccessful();

        Notification::assertNothingSent();
    }

    #[Test]
    public function a_second_run_the_same_day_does_not_send_again(): void
    {
        $this->notification('integration_completed', 'Monzo synced', '2026-10-05 07:00');

        $this->travelTo(Carbon::parse('2026-10-05 09:05', 'UTC'));
        $this->artisan('notifications:send-digests');
        $this->travelTo(Carbon::parse('2026-10-05 09:20', 'UTC'));
        $this->artisan('notifications:send-digests');

        Notification::assertSentToTimes($this->user, NotificationDigest::class, 1);
    }

    #[Test]
    public function items_outside_the_window_and_types_emailed_elsewhere_are_left_out(): void
    {
        $this->notification('integration_completed', 'Yesterday morning', '2026-10-04 08:00');
        $this->notification('integration_completed', 'After the cut-off', '2026-10-05 09:01');
        $this->notification('integration_authentication_failed', 'Already emailed', '2026-10-05 07:00');
        $this->notification('cookie_auto_refreshed', 'Email switched off', '2026-10-05 07:00');
        $this->notification('integration_failed', 'Included', '2026-10-05 07:00');
        $this->user->disableEmailNotifications('cookie_auto_refreshed');

        $this->travelTo(Carbon::parse('2026-10-05 09:05', 'UTC'));
        $this->artisan('notifications:send-digests');

        Notification::assertSentTo($this->user, NotificationDigest::class, fn (NotificationDigest $digest) => array_column($digest->items, 'title') === ['Included']);
    }

    #[Test]
    public function a_grouped_incident_counts_from_its_latest_occurrence(): void
    {
        $this->notification('integration_failed', 'Still failing', '2026-10-01 10:00', lastOccurredAt: '2026-10-05 06:00');

        $this->travelTo(Carbon::parse('2026-10-05 09:05', 'UTC'));
        $this->artisan('notifications:send-digests');

        Notification::assertSentTo($this->user, NotificationDigest::class, fn (NotificationDigest $digest) => array_column($digest->items, 'title') === ['Still failing']);
    }

    #[Test]
    public function the_digest_time_is_read_in_the_users_timezone(): void
    {
        $this->user->setTimezone('America/New_York');
        $this->notification('integration_completed', 'Monzo synced', '2026-10-05 10:00');

        // 09:05 UTC is 05:05 in New York: too early.
        $this->travelTo(Carbon::parse('2026-10-05 09:05', 'UTC'));
        $this->artisan('notifications:send-digests');
        Notification::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-10-05 13:05', 'UTC'));
        $this->artisan('notifications:send-digests');
        Notification::assertSentToTimes($this->user, NotificationDigest::class, 1);
    }

    #[Test]
    public function an_empty_day_sends_nothing_and_other_modes_are_ignored(): void
    {
        $immediate = User::factory()->create();
        $this->notification('integration_completed', 'Not a digest user', '2026-10-05 07:00', $immediate);

        $this->travelTo(Carbon::parse('2026-10-05 09:05', 'UTC'));
        $this->artisan('notifications:send-digests');

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_email_lists_each_item(): void
    {
        $mail = (new NotificationDigest([
            ['title' => 'Monzo synced', 'body' => '42 transactions'],
            ['title' => 'Page changed', 'body' => null],
        ]))->toMail($this->user);

        $this->assertSame('Your Spark digest: 2 notifications', $mail->subject);
        $this->assertContains('**Monzo synced** — 42 transactions', $mail->introLines);
        $this->assertContains('**Page changed**', $mail->introLines);
    }

    #[Test]
    public function a_failed_dispatch_can_be_retried_on_the_next_scheduler_run(): void
    {
        $this->notification('integration_completed', 'Monzo synced', '2026-10-05 07:00');
        $this->travelTo(Carbon::parse('2026-10-05 09:05', 'UTC'));
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue unavailable'));

        try {
            $this->artisan('notifications:send-digests');
            $this->fail('Expected the dispatch failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable', $exception->getMessage());
        }

        Notification::fake();
        $this->artisan('notifications:send-digests')->assertSuccessful();
        Notification::assertSentToTimes($this->user, NotificationDigest::class, 1);
    }

    private function notification(string $type, string $title, string $at, ?User $user = null, ?string $lastOccurredAt = null): DatabaseNotification
    {
        $user ??= $this->user;
        $createdAt = Carbon::parse($at, 'UTC');

        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => array_filter([
                'type' => $type,
                'title' => $title,
                'body' => null,
                'last_occurred_at' => $lastOccurredAt === null ? null : Carbon::parse($lastOccurredAt, 'UTC')->toJSON(),
            ]),
            'created_at' => $createdAt,
            'updated_at' => Carbon::parse($lastOccurredAt ?? $at, 'UTC'),
        ]);
    }
}
