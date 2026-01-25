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

    /**
     * @test
     */
    public function vapid_public_key_endpoint_returns_key(): void
    {
        config(['webpush.vapid.public_key' => 'test-public-key']);

        $response = $this->getJson('/push/vapid-public-key');

        $response->assertOk()
            ->assertJson(['publicKey' => 'test-public-key']);
    }

    /**
     * @test
     */
    public function subscribe_requires_authentication(): void
    {
        $response = $this->postJson('/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
            'keys' => [
                'p256dh' => 'test-p256dh-key',
                'auth' => 'test-auth-key',
            ],
        ]);

        // Web routes redirect to login, so expect redirect or unauthorized
        $response->assertStatus(401);
    }

    /**
     * @test
     */
    public function subscribe_creates_push_subscription(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/push/subscribe', [
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

    /**
     * @test
     */
    public function subscribe_updates_existing_subscription(): void
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
            ->postJson('/push/subscribe', [
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

    /**
     * @test
     */
    public function unsubscribe_removes_subscription(): void
    {
        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'test-p256dh-key',
            'test-auth-key',
            'aesgcm'
        );

        $response = $this->actingAs($this->user)
            ->postJson('/push/unsubscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-token',
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(0, $this->user->pushSubscriptions()->count());
    }

    /**
     * @test
     */
    public function unsubscribe_returns_404_for_unknown_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/push/unsubscribe', [
                'endpoint' => 'https://unknown-endpoint.com/test',
            ]);

        $response->assertNotFound();
    }

    /**
     * @test
     */
    public function status_returns_subscription_status(): void
    {
        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'test-p256dh-key',
            'test-auth-key',
            'aesgcm'
        );

        $response = $this->actingAs($this->user)
            ->getJson('/push/status?endpoint=' . urlencode('https://fcm.googleapis.com/fcm/send/test-token'));

        $response->assertOk()
            ->assertJson([
                'subscribed' => true,
                'subscriptionCount' => 1,
            ]);
    }

    /**
     * @test
     */
    public function list_returns_all_subscriptions(): void
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
            ->getJson('/push/subscriptions');

        $response->assertOk()
            ->assertJsonCount(2, 'subscriptions');
    }

    /**
     * @test
     */
    public function destroy_removes_specific_subscription(): void
    {
        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'test-p256dh-key',
            'test-auth-key',
            'aesgcm'
        );

        $subscription = $this->user->pushSubscriptions()->first();

        $response = $this->actingAs($this->user)
            ->deleteJson("/push/subscriptions/{$subscription->id}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(0, $this->user->pushSubscriptions()->count());
    }

    /**
     * @test
     */
    public function test_notification_requires_subscription(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/push/test');

        $response->assertBadRequest()
            ->assertJson(['message' => 'No push subscriptions found']);
    }

    /**
     * @test
     */
    public function test_notification_sends_notification(): void
    {
        Notification::fake();

        $this->user->updatePushSubscription(
            'https://fcm.googleapis.com/fcm/send/test-token',
            'test-p256dh-key',
            'test-auth-key',
            'aesgcm'
        );

        $response = $this->actingAs($this->user)
            ->postJson('/push/test');

        $response->assertOk()
            ->assertJson(['success' => true]);

        Notification::assertSentTo($this->user, TestPushNotification::class);
    }

    /**
     * @test
     */
    public function user_push_notification_preferences(): void
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
