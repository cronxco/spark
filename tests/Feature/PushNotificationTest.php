<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_vapid_public_key_endpoint_returns_key(): void
    {
        config(['webpush.vapid.public_key' => 'test-public-key']);

        $response = $this->getJson('/api/push/vapid-public-key');

        $response->assertOk()
            ->assertJson(['publicKey' => 'test-public-key']);
    }

    public function test_subscribe_requires_authentication(): void
    {
        $response = $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
            'keys' => [
                'p256dh' => 'test-p256dh-key',
                'auth' => 'test-auth-key',
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_subscribe_creates_push_subscription(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
                'keys' => [
                    'p256dh' => 'test-p256dh-key',
                    'auth' => 'test-auth-key',
                ],
                'contentEncoding' => 'aesgcm',
            ]);

        $response->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $this->user->id,
            'subscribable_type' => User::class,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
        ]);
    }

    public function test_subscribe_updates_existing_subscription(): void
    {
        // Create initial subscription
        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'old-p256dh-key',
            'old-auth-key',
            'aesgcm'
        );

        // Update with new keys
        $response = $this->actingAs($this->user)
            ->postJson('/api/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
                'keys' => [
                    'p256dh' => 'new-p256dh-key',
                    'auth' => 'new-auth-key',
                ],
            ]);

        $response->assertOk()
            ->assertJson(['success' => true, 'message' => 'Subscription updated']);

        // Should only have one subscription
        $this->assertEquals(1, $this->user->pushSubscriptions()->count());
    }

    public function test_unsubscribe_removes_subscription(): void
    {
        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'test-p256dh-key',
            'test-auth-key',
            'aesgcm'
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/push/unsubscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(0, $this->user->pushSubscriptions()->count());
    }

    public function test_unsubscribe_returns_404_for_unknown_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/push/unsubscribe', [
                'endpoint' => 'https://unknown-endpoint.com/test',
            ]);

        $response->assertNotFound();
    }

    public function test_status_returns_subscription_status(): void
    {
        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'test-p256dh-key',
            'test-auth-key',
            'aesgcm'
        );

        $response = $this->actingAs($this->user)
            ->getJson('/api/push/status?endpoint=' . urlencode('https://fcm.googleapis.com/fcm/send/test-token'));

        $response->assertOk()
            ->assertJson([
                'subscribed' => true,
                'subscriptionCount' => 1,
            ]);
    }

    public function test_list_returns_all_subscriptions(): void
    {
        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token-1',
            'key1',
            'auth1',
            'aesgcm'
        );
        $this->user->updatePushSubscription(
            'https://updates.push.services.mozilla.com/test-token-2',
            'key2',
            'auth2',
            'aesgcm'
        );

        $response = $this->actingAs($this->user)
            ->getJson('/api/push/subscriptions');

        $response->assertOk()
            ->assertJsonCount(2, 'subscriptions');
    }

    public function test_destroy_removes_specific_subscription(): void
    {
        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'test-p256dh-key',
            'test-auth-key',
            'aesgcm'
        );

        $subscription = $this->user->pushSubscriptions()->first();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/push/subscriptions/{$subscription->id}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(0, $this->user->pushSubscriptions()->count());
    }

    public function test_test_notification_requires_subscription(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/push/test');

        $response->assertBadRequest()
            ->assertJson(['message' => 'No push subscriptions found']);
    }

    public function test_test_notification_sends_notification(): void
    {
        Notification::fake();

        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'test-p256dh-key',
            'test-auth-key',
            'aesgcm'
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/push/test');

        $response->assertOk()
            ->assertJson(['success' => true]);

        Notification::assertSentTo($this->user, TestPushNotification::class);
    }

    public function test_user_push_notification_preferences(): void
    {
        // Test enabling push notifications globally
        $this->user->enablePushNotifications();
        $this->assertTrue($this->user->fresh()->hasPushNotificationsEnabled());

        // Test disabling push notifications globally
        $this->user->disablePushNotifications();
        $this->assertFalse($this->user->fresh()->hasPushNotificationsEnabled());

        // Test per-type preferences
        $this->user->enablePushNotifications();
        $this->user->disablePushNotificationsForType('integration_completed');
        $this->assertFalse($this->user->fresh()->hasPushNotificationsEnabledForType('integration_completed'));
        $this->assertTrue($this->user->fresh()->hasPushNotificationsEnabledForType('integration_failed'));

        // Global disable should override per-type
        $this->user->disablePushNotifications();
        $this->assertFalse($this->user->fresh()->hasPushNotificationsEnabledForType('integration_failed'));
    }
}
