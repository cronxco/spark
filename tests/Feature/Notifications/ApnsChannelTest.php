<?php

namespace Tests\Feature\Notifications;

use App\Models\Integration;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use App\Notifications\IntegrationAuthenticationFailed;
use App\Notifications\IntegrationFailed;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use NotificationChannels\Apn\ApnMessagePushType;
use PHPUnit\Framework\Attributes\Test;
use Pushok\Client;
use Pushok\Notification;
use Pushok\Notification as PushokNotification;
use Pushok\Response;
use Tests\TestCase;

class ApnsChannelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<int, Notification>
     */
    protected array $capturedNotifications = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->capturedNotifications = [];

        $client = Mockery::mock(Client::class);

        $client->shouldReceive('addNotification')
            ->andReturnUsing(function (PushokNotification $notification) {
                $this->capturedNotifications[] = $notification;
            });

        $client->shouldReceive('push')->andReturnUsing(function () {
            return array_map(
                fn () => $this->successfulResponse(),
                array_fill(0, count($this->capturedNotifications), null),
            );
        });

        $this->app->instance(Client::class, $client);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function it_sends_apns_payload_and_silent_companion_for_ios_subscription(): void
    {
        $user = User::factory()->create();

        $user->pushSubscriptions()->create([
            'endpoint' => str_repeat('a', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
            'app_environment' => 'sandbox',
            'bundle_id' => 'co.cronx.spark',
            'app_version' => '1.0.0',
            'os_version' => '18.0',
        ]);

        $user->notifyNow(new TestPushNotification('ios'));

        // Two Pushok notifications: the alert and the silent companion.
        $this->assertCount(2, $this->capturedNotifications);

        $alert = json_decode($this->capturedNotifications[0]->getPayload()->toJson(), true);

        $this->assertSame('Test Notification', $alert['aps']['alert']['title']);
        $this->assertSame('Push notifications are working correctly!', $alert['aps']['alert']['body']);
        $this->assertSame('default', $alert['aps']['sound']);
        $this->assertSame(1, $alert['aps']['badge']);
        // The category comes from NotificationCatalogue, which the client's
        // UNNotificationCategory registrations mirror exactly. Sending the raw
        // snake_case type — as this previously did — bound to nothing on the
        // client and left every action button inert.
        $this->assertSame('SYSTEM', $alert['aps']['category']);

        // thread-id is a client-agnostic grouping key and still carries the type.
        $this->assertSame('test_push', $alert['aps']['thread-id']);
        $this->assertSame(1, $alert['spark']['contract_version']);
        $this->assertSame('test_push', $alert['spark']['type']);
        $this->assertNotEmpty($alert['spark']['notification_id']);

        $silent = json_decode($this->capturedNotifications[1]->getPayload()->toJson(), true);

        $this->assertSame(1, $silent['aps']['content-available']);
        $this->assertSame(
            ApnMessagePushType::Background->value,
            $this->capturedNotifications[1]->getPayload()->getPushType(),
        );
        $this->assertSame(1, $silent['spark']['contract_version']);
        $this->assertSame('test_push', $silent['spark']['type']);
        $this->assertSame($alert['spark']['notification_id'], $silent['spark']['notification_id']);
    }

    #[Test]
    public function a_failure_notification_sends_the_category_the_client_registered(): void
    {
        $user = User::factory()->create();

        $user->pushSubscriptions()->create([
            'endpoint' => str_repeat('b', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
            'app_environment' => 'sandbox',
            'bundle_id' => 'co.cronx.spark',
            'app_version' => '1.0.0',
            'os_version' => '18.0',
        ]);

        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $user->notifyNow(new IntegrationFailed($integration, 'Provider returned 500'));

        $alert = json_decode($this->capturedNotifications[0]->getPayload()->toJson(), true);

        // SCREAMING_CASE, matching the UNNotificationCategory the client
        // registers. Category matching is case-sensitive, so the previous
        // snake_case `integration_failed` did not bind.
        $this->assertSame('INTEGRATION_STATUS', $alert['aps']['category']);
        // thread-id is a client-agnostic grouping key and still carries the type.
        $this->assertSame('integration_failed', $alert['aps']['thread-id']);
    }

    #[Test]
    public function a_reauthorization_notification_sends_the_attention_category(): void
    {
        $user = User::factory()->create();

        $user->pushSubscriptions()->create([
            'endpoint' => str_repeat('c', 64),
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
            'app_environment' => 'sandbox',
            'bundle_id' => 'co.cronx.spark',
            'app_version' => '1.0.0',
            'os_version' => '18.0',
        ]);

        $integration = Integration::factory()->create(['user_id' => $user->id]);

        $user->notifyNow(new IntegrationAuthenticationFailed($integration, 'Token expired'));

        $alert = json_decode($this->capturedNotifications[0]->getPayload()->toJson(), true);

        // Only this category carries the Reconnect action, which is the whole
        // point of separating it from the informational failures.
        $this->assertSame('INTEGRATION_ATTENTION', $alert['aps']['category']);
    }

    #[Test]
    public function it_does_nothing_when_user_has_no_ios_subscription(): void
    {
        $user = User::factory()->create();

        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/some-token',
            'device_type' => PushSubscription::DEVICE_TYPE_WEB,
        ]);

        $channel = app(ApnsChannel::class);

        $result = $channel->send($user, new TestPushNotification('ios'));

        $this->assertNull($result);
        $this->assertCount(0, $this->capturedNotifications);
    }

    protected function successfulResponse(): Response
    {
        return new Response(200, '', '');
    }
}
