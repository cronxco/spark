<?php

namespace Tests\Feature\Mobile;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\ApnsChannel;
use App\Services\ApnsLiveActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Mobile\Stubs\Phase0SmokeNotification;
use Tests\TestCase;

/**
 * End-to-end smoke test that walks through the Phase 0 iOS surface:
 *   1. Register a device.
 *   2. Fire a push notification through SparkNotification (multi-channel).
 *   3. Acknowledge an anomaly through the mobile endpoint.
 *   4. Start a Live Activity and assert the APN HTTP/2 call.
 *
 * Purpose: catch regressions where routes are silently unwired or the
 * fanout swap breaks iOS delivery.
 */
class Phase0SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function phase0_end_to_end_flow(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        // 1. Device registration.
        $this->postJson('/api/v1/mobile/devices', [
            'apns_token' => str_repeat('a', 64),
            'app_environment' => 'sandbox',
            'bundle_id' => 'co.cronx.spark',
            'app_version' => '1.0.0',
            'os_version' => '18.1',
        ])->assertStatus(201);

        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $this->user->id,
            'device_type' => PushSubscription::DEVICE_TYPE_IOS,
        ]);

        // 2. Multi-channel fanout: a priority SparkNotification should now include ApnsChannel.
        Notification::fake();
        $notification = new Phase0SmokeNotification;
        $this->user->notify($notification);
        Notification::assertSentTo($this->user, Phase0SmokeNotification::class, function ($n, $channels) {
            return in_array(ApnsChannel::class, $channels, true);
        });

        // 3. Anomaly acknowledge.
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
            'acknowledged_at' => null,
        ]);

        $this->postJson('/api/v1/mobile/anomalies/' . $anomaly->id . '/acknowledge')->assertOk();
        $this->assertNotNull($anomaly->fresh()->acknowledged_at);

        // 4. Live Activity start → APN HTTP/2 hit (ApnsLiveActivityService is mocked since
        // there's no real APN key in CI). We assert the service was called.
        $mock = Mockery::mock(ApnsLiveActivityService::class);
        $mock->shouldReceive('startOrUpdate')->once()->andReturnNull();
        $this->app->instance(ApnsLiveActivityService::class, $mock);

        Http::fake();

        $this->postJson('/api/v1/mobile/live-activities', [
            'activity_id' => '00000000-0000-4000-8000-00000000aaaa',
            'activity_type' => 'sleep',
            'push_token' => str_repeat('b', 64),
            'content_state' => ['phase' => 'deep'],
        ])->assertStatus(201);

        $this->assertDatabaseHas('live_activity_tokens', [
            'user_id' => $this->user->id,
            'activity_id' => '00000000-0000-4000-8000-00000000aaaa',
            'activity_type' => 'sleep',
        ]);
    }
}
