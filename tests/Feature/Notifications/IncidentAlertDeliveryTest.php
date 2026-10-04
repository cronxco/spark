<?php

namespace Tests\Feature\Notifications;

use App\Models\Integration;
use App\Notifications\IntegrationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IncidentAlertDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_first_failure_of_an_incident_is_emailed(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 12:00', 'UTC'));
        $integration = Integration::factory()->create();

        $channels = (new IntegrationFailed($integration, 'Failure'))->via($integration->user);

        $this->assertSame(['database', 'mail'], $channels);
    }

    #[Test]
    public function a_repeat_failure_while_the_incident_is_open_only_updates_the_in_app_record(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 12:00', 'UTC'));
        $integration = Integration::factory()->create();
        $user = $integration->user;
        $user->notifyNow(new IntegrationFailed($integration, 'Failure'), ['database']);

        $channels = (new IntegrationFailed($integration, 'Failure again'))->via($user);

        $this->assertSame(['database'], $channels);
    }

    #[Test]
    public function a_failure_after_the_incident_resolved_is_emailed_again(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 12:00', 'UTC'));
        $integration = Integration::factory()->create();
        $user = $integration->user;
        $user->notifyNow(new IntegrationFailed($integration, 'Failure'), ['database']);
        $user->notifications()->update(['archived_at' => now()]);

        $channels = (new IntegrationFailed($integration, 'New failure'))->via($user);

        $this->assertSame(['database', 'mail'], $channels);
    }

    #[Test]
    public function an_overnight_failure_holds_email_until_seven_local_time(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 23:30', 'Europe/London'));
        $integration = Integration::factory()->create();
        $user = $integration->user;
        $user->setTimezone('Europe/London');
        $notification = new IntegrationFailed($integration, 'Failure');

        $delay = $notification->withDelay($user, 'mail');

        $this->assertTrue($delay->equalTo(Carbon::parse('2026-06-16 07:00', 'Europe/London')));
        $this->assertTrue($notification->heldOvernight);
        $this->assertNull($notification->withDelay($user, 'database'));
    }

    #[Test]
    public function a_daytime_failure_is_not_held(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 07:00', 'Europe/London'));
        $integration = Integration::factory()->create();
        $user = $integration->user;
        $user->setTimezone('Europe/London');
        $notification = new IntegrationFailed($integration, 'Failure');

        $this->assertNull($notification->withDelay($user, 'mail'));
        $this->assertFalse($notification->heldOvernight);
    }

    #[Test]
    public function an_overnight_hold_waits_for_work_hours_when_they_open_later(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 02:00', 'Europe/London'));
        $integration = Integration::factory()->create();
        $user = $integration->user;
        $user->setTimezone('Europe/London');
        $preferences = $user->getNotificationPreferences();
        $preferences['work_hours'] = ['enabled' => true, 'timezone' => 'Europe/London', 'start' => '09:00', 'end' => '17:00'];
        $preferences['delayed_sending'] = ['mode' => 'work_hours'];
        $user->settings = array_merge($user->settings ?? [], ['notifications' => $preferences]);
        $user->save();

        $delay = (new IntegrationFailed($integration, 'Failure'))->withDelay($user, 'mail');

        $this->assertTrue($delay->equalTo(Carbon::parse('2026-06-15 09:00', 'Europe/London')));
    }

    #[Test]
    public function a_held_alert_is_dropped_if_the_incident_resolved_before_morning(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 23:30', 'UTC'));
        $integration = Integration::factory()->create();
        $user = $integration->user;
        $notification = new IntegrationFailed($integration, 'Failure');
        $user->notifyNow($notification, ['database']);
        $notification->withDelay($user, 'mail');

        $this->assertTrue($notification->shouldSend($user, 'mail'));

        $user->notifications()->update(['archived_at' => now()]);

        $this->assertFalse($notification->shouldSend($user, 'mail'));
    }

    #[Test]
    public function queued_overnight_email_is_dispatched_with_the_morning_delay(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-15 23:30', 'UTC'));
        $integration = Integration::factory()->create();

        $integration->user->notify(new IntegrationFailed($integration, 'Failure'));

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->channels === ['mail']
            && $job->notification->heldOvernight
            && Carbon::parse('2026-06-16 07:00', 'UTC')->equalTo($job->delay));
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->channels === ['database']
            && $job->delay === null);
    }
}
