<?php

namespace Tests\Feature\Notifications;

use App\Models\Integration;
use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use App\Notifications\IntegrationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use NotificationChannels\WebPush\WebPushChannel;
use PHPUnit\Framework\Attributes\Test;
use Pushok\Response;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryRecordTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_sent_email_is_recorded_on_the_in_app_notification(): void
    {
        [$user, $notification] = $this->storedFailure();

        event(new NotificationSent($user, $notification, 'mail', null));

        $delivery = $user->notifications()->first()->data['delivery'];
        $this->assertSame('sent', $delivery['mail']['status']);
        $this->assertArrayHasKey('at', $delivery['mail']);
    }

    #[Test]
    public function apns_outcomes_count_devices_and_failures(): void
    {
        [$user, $notification] = $this->storedFailure();

        event(new NotificationSent($user, $notification, ApnsChannel::class, [
            new Response(200, '', ''),
            new Response(410, '', '{"reason":"Unregistered"}'),
        ]));

        $apns = $user->notifications()->first()->data['delivery']['apns'];
        $this->assertSame('sent', $apns['status']);
        $this->assertSame(2, $apns['devices']);
        $this->assertSame(1, $apns['failed']);
        $this->assertSame('Unregistered', $apns['error']);
    }

    #[Test]
    public function apns_with_every_device_rejected_is_a_failure_and_no_devices_is_skipped(): void
    {
        [$user, $notification] = $this->storedFailure();

        event(new NotificationSent($user, $notification, ApnsChannel::class, [new Response(400, '', '{"reason":"BadDeviceToken"}')]));
        $this->assertSame('failed', $user->notifications()->first()->data['delivery']['apns']['status']);

        event(new NotificationSent($user, $notification, ApnsChannel::class, null));
        $this->assertSame('skipped', $user->notifications()->first()->data['delivery']['apns']['status']);
    }

    #[Test]
    public function each_channel_keeps_its_own_outcome(): void
    {
        [$user, $notification] = $this->storedFailure();

        event(new NotificationSent($user, $notification, 'mail', null));
        event(new NotificationSent($user, $notification, WebPushChannel::class, null));

        $delivery = $user->notifications()->first()->data['delivery'];
        $this->assertSame(['mail', 'web_push'], array_keys($delivery));
    }

    #[Test]
    public function a_queued_delivery_that_fails_is_recorded_without_secrets(): void
    {
        [$user, $notification] = $this->storedFailure();
        $notification->withDelay($user, 'mail');

        $notification->failed(new RuntimeException('SMTP refused password=hunter2'));

        $mail = $user->notifications()->first()->data['delivery']['mail'];
        $this->assertSame('failed', $mail['status']);
        $this->assertStringContainsString('RuntimeException: SMTP refused', $mail['error']);
        $this->assertStringNotContainsString('hunter2', $mail['error']);
    }

    #[Test]
    public function a_delivery_for_a_coalesced_repeat_lands_on_the_open_record_and_survives_the_next_repeat(): void
    {
        [$user, $notification] = $this->storedFailure();
        event(new NotificationSent($user, $notification, 'mail', null));

        $repeat = new IntegrationFailed($notification->integration, 'Again');
        $user->notifyNow($repeat, ['database']);
        $repeat->id = 'f5c1b0a2-0000-4000-8000-000000000000';
        event(new NotificationSent($user, $repeat, WebPushChannel::class, null));

        $stored = $user->notifications()->sole();
        $this->assertSame(2, $stored->data['occurrence_count']);
        $this->assertSame(['mail', 'web_push'], array_keys($stored->data['delivery']));
    }

    #[Test]
    public function the_database_channel_and_unknown_channels_record_nothing(): void
    {
        [$user, $notification] = $this->storedFailure();

        event(new NotificationSent($user, $notification, 'slack', null));

        $this->assertArrayNotHasKey('delivery', $user->notifications()->first()->data);
    }

    /**
     * @return array{0: User, 1: IntegrationFailed}
     */
    private function storedFailure(): array
    {
        $integration = Integration::factory()->create();
        $notification = new IntegrationFailed($integration, 'Failure');
        $integration->user->notifyNow($notification, ['database']);

        return [$integration->user, $notification];
    }
}
