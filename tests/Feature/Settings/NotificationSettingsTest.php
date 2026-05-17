<?php

namespace Tests\Feature\Settings;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    #[Test]
    public function notifications_page_is_displayed(): void
    {
        $this->get('/settings/notifications')->assertOk();
    }

    #[Test]
    public function send_test_notification_fails_without_any_subscription(): void
    {
        Notification::fake();

        Volt::test('settings.notifications')
            ->call('sendTestNotification')
            ->assertHasNoErrors();

        Notification::assertNothingSent();
    }

    #[Test]
    public function send_test_notification_sends_to_web_device(): void
    {
        Notification::fake();

        $this->user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aesgcm',
        ]);

        Volt::test('settings.notifications')
            ->call('sendTestNotification')
            ->assertHasNoErrors();

        Notification::assertSentTo($this->user, TestPushNotification::class);
    }

    #[Test]
    public function send_test_ios_notification_fails_without_ios_subscription(): void
    {
        Notification::fake();

        $this->user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
        ]);

        Volt::test('settings.notifications')
            ->call('sendTestIosNotification')
            ->assertHasNoErrors();

        Notification::assertNothingSent();
    }

    #[Test]
    public function send_test_ios_notification_sends_to_ios_device(): void
    {
        Notification::fake();

        $this->user->pushSubscriptions()->create([
            'endpoint' => str_repeat('a', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
        ]);

        Volt::test('settings.notifications')
            ->call('sendTestIosNotification')
            ->assertHasNoErrors();

        Notification::assertSentTo(
            $this->user,
            TestPushNotification::class,
            fn (TestPushNotification $notification) => $notification->platform === 'ios',
        );
    }

    #[Test]
    public function push_subscriptions_are_loaded_with_correct_labels(): void
    {
        $this->user->pushSubscriptions()->create([
            'endpoint' => str_repeat('a', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
        ]);
        $this->user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
        ]);

        $component = Volt::test('settings.notifications');

        $subscriptions = $component->get('pushSubscriptions');

        $this->assertCount(2, $subscriptions);

        $ios = collect($subscriptions)->firstWhere('device_type', 'ios');
        $this->assertEquals('iOS App', $ios['label']);

        $web = collect($subscriptions)->firstWhere('device_type', 'web');
        $this->assertEquals('Chrome/Android', $web['label']);
    }
}
