<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UpToSpeedReadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'flint',
        ]);
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    #[Test]
    public function requires_ios_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', ['items' => []])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    #[Test]
    public function rejects_missing_items(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [])
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_unknown_type(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [['type' => 'invalid_type', 'id' => '00000000-0000-0000-0000-000000000001']],
        ])->assertStatus(422);
    }

    #[Test]
    public function rejects_non_uuid_id(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [['type' => 'flint_digest', 'id' => 'not-a-uuid']],
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // flint_digest
    // -------------------------------------------------------------------------

    #[Test]
    public function marks_flint_digest_as_caught_up(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [['type' => 'flint_digest', 'id' => $event->id]],
        ])->assertOk()->assertJsonPath('marked', 1);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'causer_id' => $this->user->id,
            'event' => 'caught_up',
        ]);
    }

    #[Test]
    public function marking_flint_digest_twice_is_idempotent(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $payload = ['items' => [['type' => 'flint_digest', 'id' => $event->id]]];

        $this->postJson('/api/v1/mobile/up-to-speed/read', $payload)->assertOk();
        $this->postJson('/api/v1/mobile/up-to-speed/read', $payload)->assertOk();

        $this->assertEquals(1, Activity::where('subject_id', $event->id)->where('event', 'caught_up')->count());
    }

    #[Test]
    public function silently_ignores_another_users_flint_digest(): void
    {
        $other = User::factory()->create();
        $otherGroup = IntegrationGroup::factory()->create(['user_id' => $other->id]);
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'integration_group_id' => $otherGroup->id,
            'service' => 'flint',
        ]);
        $event = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [['type' => 'flint_digest', 'id' => $event->id]],
        ])->assertOk()->assertJsonPath('marked', 0);

        $this->assertEquals(0, Activity::where('event', 'caught_up')->count());
    }

    // -------------------------------------------------------------------------
    // anomaly
    // -------------------------------------------------------------------------

    #[Test]
    public function marks_anomaly_as_caught_up(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [['type' => 'anomaly', 'id' => $anomaly->id]],
        ])->assertOk()->assertJsonPath('marked', 1);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => MetricTrend::class,
            'subject_id' => $anomaly->id,
            'causer_id' => $this->user->id,
            'event' => 'caught_up',
        ]);
    }

    #[Test]
    public function marking_anomaly_twice_is_idempotent(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $payload = ['items' => [['type' => 'anomaly', 'id' => $anomaly->id]]];

        $this->postJson('/api/v1/mobile/up-to-speed/read', $payload)->assertOk();
        $this->postJson('/api/v1/mobile/up-to-speed/read', $payload)->assertOk();

        $this->assertEquals(1, Activity::where('subject_id', $anomaly->id)->where('event', 'caught_up')->count());
    }

    #[Test]
    public function silently_ignores_another_users_anomaly(): void
    {
        $other = User::factory()->create();
        $stat = MetricStatistic::factory()->create(['user_id' => $other->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [['type' => 'anomaly', 'id' => $anomaly->id]],
        ])->assertOk()->assertJsonPath('marked', 0);

        $this->assertEquals(0, Activity::where('event', 'caught_up')->count());
    }

    // -------------------------------------------------------------------------
    // news_summary
    // -------------------------------------------------------------------------

    #[Test]
    public function marks_news_summary_as_caught_up(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'domain' => 'knowledge',
            'action' => 'bookmarked',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [['type' => 'news_summary', 'id' => $event->id]],
        ])->assertOk()->assertJsonPath('marked', 1);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'causer_id' => $this->user->id,
            'event' => 'caught_up',
        ]);
    }

    // -------------------------------------------------------------------------
    // Batch
    // -------------------------------------------------------------------------

    #[Test]
    public function handles_batch_of_mixed_types(): void
    {
        $digest = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
        ]);

        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
        ]);

        $news = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'domain' => 'knowledge',
            'action' => 'bookmarked',
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [
                ['type' => 'flint_digest', 'id' => $digest->id],
                ['type' => 'anomaly', 'id' => $anomaly->id],
                ['type' => 'news_summary', 'id' => $news->id],
            ],
        ])->assertOk()->assertJsonPath('marked', 3);

        $this->assertEquals(3, Activity::where('event', 'caught_up')->count());
    }

    #[Test]
    public function skips_nonexistent_ids_without_error(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [
                ['type' => 'flint_digest', 'id' => '00000000-0000-0000-0000-000000000001'],
            ],
        ])->assertOk()->assertJsonPath('marked', 0);
    }
}
