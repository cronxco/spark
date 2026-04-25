<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DevicesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function register_requires_authentication(): void
    {
        $this->postJson('/api/v1/mobile/devices', $this->payload())->assertStatus(401);
    }

    #[Test]
    public function register_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/devices', $this->payload())
            ->assertStatus(403);
    }

    #[Test]
    public function register_creates_ios_push_subscription(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $token = str_repeat('b', 64);

        $this->postJson('/api/v1/mobile/devices', $this->payload($token))
            ->assertStatus(201)
            ->assertJsonPath('device_type', PushSubscription::DEVICE_TYPE_IOS)
            ->assertJsonPath('endpoint', $token)
            ->assertJsonPath('app_environment', 'sandbox');

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $token,
            'device_type' => 'ios',
            'subscribable_id' => $this->user->id,
        ]);
    }

    #[Test]
    public function register_upserts_on_duplicate_token(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $token = str_repeat('c', 64);

        $this->postJson('/api/v1/mobile/devices', $this->payload($token))->assertStatus(201);
        $this->postJson('/api/v1/mobile/devices', array_merge($this->payload($token), ['app_version' => '1.0.1']))
            ->assertStatus(201)
            ->assertJsonPath('endpoint', $token);

        $this->assertEquals(1, PushSubscription::where('endpoint', $token)->count());
        $this->assertEquals('1.0.1', PushSubscription::where('endpoint', $token)->first()->app_version);
    }

    #[Test]
    public function register_rejects_invalid_environment(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/devices', array_merge($this->payload(), ['app_environment' => 'staging']))
            ->assertStatus(422);
    }

    #[Test]
    public function destroy_removes_subscription(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $subscription = $this->user->pushSubscriptions()->create([
            'endpoint' => str_repeat('d', 64),
            'device_type' => 'ios',
        ]);

        $this->deleteJson("/api/v1/mobile/devices/{$subscription->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    #[Test]
    public function destroy_denies_another_users_device(): void
    {
        $other = User::factory()->create();
        $subscription = $other->pushSubscriptions()->create([
            'endpoint' => str_repeat('e', 64),
            'device_type' => 'ios',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->deleteJson("/api/v1/mobile/devices/{$subscription->id}")
            ->assertStatus(404);
    }

    protected function payload(?string $token = null): array
    {
        return [
            'apns_token' => $token ?? str_repeat('a', 64),
            'app_environment' => 'sandbox',
            'bundle_id' => 'co.cronx.spark',
            'app_version' => '1.0.0',
            'os_version' => '18.1',
            'device_name' => 'iPhone 15 Pro',
        ];
    }
}
