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
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aesgcm',
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
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aesgcm',
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

    #[Test]
    public function via_does_not_include_webpush_for_incomplete_web_subscription(): void
    {
        $user = User::factory()->create();
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://example.com/push',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => null,
        ]);

        $channels = (new TestFanoutNotification)->via($user);

        $this->assertNotContains(WebPushChannel::class, $channels);
    }

    #[Test]
    public function route_notification_for_webpush_returns_only_complete_web_subscriptions(): void
    {
        $user = User::factory()->create();
        $valid = $user->pushSubscriptions()->create([
            'endpoint' => 'https://example.com/push',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aesgcm',
        ]);
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://example.com/incomplete',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => null,
        ]);
        $user->pushSubscriptions()->create([
            'endpoint' => str_repeat('a', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
        ]);

        $subscriptions = $user->routeNotificationForWebPush();

        $this->assertCount(1, $subscriptions);
        $this->assertTrue($subscriptions->first()->is($valid));
    }

    #[Test]
    public function it_routes_every_channel_to_the_notifications_queue(): void
    {
        $notification = new TestFanoutNotification;

        $queues = $notification->viaQueues();

        $this->assertSame('notifications', $queues['database']);
        $this->assertSame('notifications', $queues['mail']);
        $this->assertSame('notifications', $queues[ApnsChannel::class]);
        $this->assertSame('notifications', $queues[WebPushChannel::class]);
    }
}
