<?php

namespace Tests\Feature\Settings;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionsTest extends TestCase
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
    public function sessions_page_is_displayed(): void
    {
        $this->get('/settings/sessions')->assertOk();
    }

    #[Test]
    public function mobile_devices_are_loaded_on_mount(): void
    {
        $this->user->pushSubscriptions()->create([
            'endpoint' => str_repeat('a', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
            'app_environment' => 'production',
            'app_version' => '1.2.3',
            'os_version' => 'iOS 18.1',
        ]);

        $component = Volt::test('settings.sessions');

        $devices = $component->get('mobileDevices');

        $this->assertCount(1, $devices);
        $this->assertEquals('iOS 18.1', $devices[0]['os_version']);
        $this->assertEquals('1.2.3', $devices[0]['app_version']);
        $this->assertEquals('production', $devices[0]['app_environment']);
    }

    #[Test]
    public function web_push_subscriptions_are_not_shown_as_mobile_devices(): void
    {
        $this->user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
        ]);

        $component = Volt::test('settings.sessions');

        $this->assertCount(0, $component->get('mobileDevices'));
    }

    #[Test]
    public function revoke_mobile_device_removes_subscription(): void
    {
        $subscription = $this->user->pushSubscriptions()->create([
            'endpoint' => str_repeat('b', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
        ]);

        Volt::test('settings.sessions')
            ->call('revokeMobileDevice', $subscription->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    #[Test]
    public function revoke_mobile_device_cannot_remove_another_users_device(): void
    {
        $other = User::factory()->create();
        $subscription = $other->pushSubscriptions()->create([
            'endpoint' => str_repeat('c', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
        ]);

        Volt::test('settings.sessions')
            ->call('revokeMobileDevice', $subscription->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }
}
