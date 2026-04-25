<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Events\Mobile\LiveActivityUpdate;
use App\Models\LiveActivityToken;
use App\Models\User;
use App\Services\ApnsLiveActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LiveActivitiesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();

        $mock = Mockery::mock(ApnsLiveActivityService::class);
        $mock->shouldReceive('startOrUpdate')->byDefault()->andReturnNull();
        $mock->shouldReceive('end')->byDefault()->andReturnNull();
        $this->app->instance(ApnsLiveActivityService::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function start_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/live-activities', [
            'activity_id' => '00000000-0000-4000-8000-000000000001',
            'activity_type' => 'sleep',
            'push_token' => str_repeat('a', 64),
        ])->assertStatus(403);
    }

    #[Test]
    public function start_creates_token_row_and_broadcasts(): void
    {
        Event::fake([LiveActivityUpdate::class]);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $activityId = '00000000-0000-4000-8000-000000000001';

        $this->postJson('/api/v1/mobile/live-activities', [
            'activity_id' => $activityId,
            'activity_type' => 'sleep',
            'push_token' => str_repeat('a', 64),
            'content_state' => ['phase' => 'deep'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('activity_id', $activityId)
            ->assertJsonPath('activity_type', 'sleep');

        $this->assertDatabaseHas('live_activity_tokens', [
            'user_id' => $this->user->id,
            'activity_id' => $activityId,
            'activity_type' => 'sleep',
        ]);

        Event::assertDispatched(LiveActivityUpdate::class, fn ($e) => $e->activityId === $activityId && $e->event === 'start');
    }

    #[Test]
    public function update_respects_rate_limit(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $token = LiveActivityToken::create([
            'user_id' => $this->user->id,
            'activity_id' => '00000000-0000-4000-8000-000000000002',
            'activity_type' => 'run',
            'push_token' => str_repeat('b', 64),
            'starts_at' => now(),
        ]);

        // 16 updates should pass, 17th should 429.
        for ($i = 0; $i < 16; $i++) {
            $this->patchJson('/api/v1/mobile/live-activities/' . $token->activity_id, [
                'content_state' => ['distance' => $i],
            ])->assertOk();
        }

        $this->patchJson('/api/v1/mobile/live-activities/' . $token->activity_id, [
            'content_state' => ['distance' => 17],
        ])->assertStatus(429);
    }

    #[Test]
    public function end_marks_activity_as_ended(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $token = LiveActivityToken::create([
            'user_id' => $this->user->id,
            'activity_id' => '00000000-0000-4000-8000-000000000003',
            'activity_type' => 'run',
            'push_token' => str_repeat('c', 64),
            'starts_at' => now(),
        ]);

        $this->deleteJson('/api/v1/mobile/live-activities/' . $token->activity_id)
            ->assertStatus(204);

        $this->assertNotNull($token->fresh()->ends_at);
    }

    #[Test]
    public function register_token_rotates_push_token(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $token = LiveActivityToken::create([
            'user_id' => $this->user->id,
            'activity_id' => '00000000-0000-4000-8000-000000000004',
            'activity_type' => 'run',
            'push_token' => str_repeat('d', 64),
            'starts_at' => now(),
        ]);

        $newToken = str_repeat('e', 64);
        $this->postJson('/api/v1/mobile/live-activities/' . $token->activity_id . '/tokens', [
            'push_token' => $newToken,
        ])->assertOk();

        $this->assertEquals($newToken, $token->fresh()->push_token);
    }

    #[Test]
    public function update_returns_404_for_other_users_activity(): void
    {
        $other = User::factory()->create();
        $token = LiveActivityToken::create([
            'user_id' => $other->id,
            'activity_id' => '00000000-0000-4000-8000-000000000005',
            'activity_type' => 'sleep',
            'push_token' => str_repeat('f', 64),
            'starts_at' => now(),
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->patchJson('/api/v1/mobile/live-activities/' . $token->activity_id, [
            'content_state' => ['foo' => 'bar'],
        ])->assertStatus(404);
    }
}
