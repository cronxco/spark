<?php

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\WebPushChannel;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Notifications\Stubs\TestFanoutNotification;
use Tests\TestCase;

class SparkNotificationFanoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function via_includes_apns_channel_when_user_has_ios_device(): void
    {
        $user = User::factory()->create();
        $user->pushSubscriptions()->create([
            'endpoint' => str_repeat('a', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
        ]);

        $notification = new TestFanoutNotification;

        $channels = $notification->via($user);

        $this->assertContains(ApnsChannel::class, $channels);
        $this->assertNotContains(WebPushChannel::class, $channels);
    }

    #[Test]
    public function via_includes_webpush_channel_when_user_has_web_device(): void
    {
        $user = User::factory()->create();
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://example.com/push',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
        ]);

        $notification = new TestFanoutNotification;

        $channels = $notification->via($user);

        $this->assertContains(WebPushChannel::class, $channels);
        $this->assertNotContains(ApnsChannel::class, $channels);
    }

    #[Test]
    public function via_includes_both_channels_when_user_has_both_devices(): void
    {
        $user = User::factory()->create();
        $user->pushSubscriptions()->create([
            'endpoint' => str_repeat('a', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
        ]);
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://example.com/push',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
        ]);

        $channels = (new TestFanoutNotification)->via($user);

        $this->assertContains(ApnsChannel::class, $channels);
        $this->assertContains(WebPushChannel::class, $channels);
    }

    #[Test]
    public function via_has_no_push_channels_when_user_has_no_devices(): void
    {
        $user = User::factory()->create();

        $channels = (new TestFanoutNotification)->via($user);

        $this->assertNotContains(ApnsChannel::class, $channels);
        $this->assertNotContains(WebPushChannel::class, $channels);
    }
}
