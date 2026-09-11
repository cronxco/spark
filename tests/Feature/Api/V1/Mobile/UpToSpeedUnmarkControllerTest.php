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

/**
 * Unmark is the recovery path for something dismissed by accident. Without it
 * a swipe past a card is irreversible: the feed has no other way to resurface
 * an item once its `caught_up` row exists, and no way at all to resurface an
 * acknowledged anomaly.
 */
class UpToSpeedUnmarkControllerTest extends TestCase
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
    // Auth and validation
    // -------------------------------------------------------------------------

    #[Test]
    public function requires_ios_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', ['items' => []])
            ->assertStatus(403);
    }

    #[Test]
    public function rejects_missing_items(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [])
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_check_in_type(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [
            'items' => [['type' => 'check_in', 'id' => '00000000-0000-4000-8000-000000000000']],
        ])->assertStatus(422);
    }

    #[Test]
    public function rejects_non_uuid_id(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [
            'items' => [['type' => 'flint_digest', 'id' => 'not-a-uuid']],
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Digests and news
    // -------------------------------------------------------------------------

    #[Test]
    public function unmarking_a_digest_removes_its_caught_up_row(): void
    {
        $event = $this->digest();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $payload = ['items' => [['type' => 'flint_digest', 'id' => $event->id]]];
        $this->postJson('/api/v1/mobile/up-to-speed/read', $payload)->assertOk();

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', $payload)
            ->assertOk()
            ->assertJsonPath('unmarked', 1);

        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'event' => 'caught_up',
        ]);
    }

    #[Test]
    public function an_unmarked_digest_returns_to_the_feed_as_unread(): void
    {
        $event = $this->digest();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $payload = ['items' => [['type' => 'flint_digest', 'id' => $event->id]]];
        $this->postJson('/api/v1/mobile/up-to-speed/read', $payload)->assertOk();

        $read = $this->getJson('/api/v1/mobile/up-to-speed')->assertOk();
        $this->assertNotNull($this->itemById($read->json('items'), $event->id)['caught_up_at']);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', $payload)->assertOk();

        $unread = $this->getJson('/api/v1/mobile/up-to-speed')->assertOk();
        $this->assertNull($this->itemById($unread->json('items'), $event->id)['caught_up_at']);
    }

    #[Test]
    public function unmarking_something_already_unread_is_a_no_op(): void
    {
        $event = $this->digest();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [
            'items' => [['type' => 'flint_digest', 'id' => $event->id]],
        ])->assertOk()->assertJsonPath('unmarked', 0);
    }

    #[Test]
    public function unmarking_is_idempotent(): void
    {
        $event = $this->digest();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $payload = ['items' => [['type' => 'flint_digest', 'id' => $event->id]]];
        $this->postJson('/api/v1/mobile/up-to-speed/read', $payload)->assertOk();

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', $payload)->assertJsonPath('unmarked', 1);
        $this->postJson('/api/v1/mobile/up-to-speed/unmark', $payload)->assertJsonPath('unmarked', 0);
    }

    // -------------------------------------------------------------------------
    // Anomalies — acknowledgement, not the activity row, is what evicts them
    // -------------------------------------------------------------------------

    #[Test]
    public function unmarking_an_anomaly_clears_its_acknowledgement(): void
    {
        $anomaly = $this->anomaly();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/anomalies/{$anomaly->id}/acknowledge", [])->assertOk();
        $this->assertNotNull($anomaly->fresh()->acknowledged_at);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [
            'items' => [['type' => 'anomaly', 'id' => $anomaly->id]],
        ])->assertOk()->assertJsonPath('unmarked', 1);

        $this->assertNull($anomaly->fresh()->acknowledged_at);
    }

    #[Test]
    public function unmarking_an_anomaly_lifts_its_suppression_window(): void
    {
        $anomaly = $this->anomaly();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/anomalies/{$anomaly->id}/acknowledge", [
            'suppress_until' => now()->addDays(7)->toDateString(),
        ])->assertOk();

        $this->assertNotNull($anomaly->metricStatistic->fresh()->anomaly_high_suppressed_until);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [
            'items' => [['type' => 'anomaly', 'id' => $anomaly->id]],
        ])->assertOk()->assertJsonPath('unmarked', 1);

        $fresh = $anomaly->fresh();
        $this->assertNull($fresh->acknowledged_at);
        $this->assertArrayNotHasKey('suppress_until', $fresh->metadata ?? []);
        $this->assertNull($anomaly->metricStatistic->fresh()->anomaly_high_suppressed_until);
    }

    #[Test]
    public function unmarking_an_anomaly_retains_another_acknowledged_suppression_window(): void
    {
        $first = $this->anomaly();
        $second = MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $first->metric_statistic_id,
            'detected_at' => now(),
            'acknowledged_at' => null,
        ]);
        $remainingSuppression = now()->addDays(7)->endOfDay();

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/anomalies/{$first->id}/acknowledge", [
            'suppress_until' => $remainingSuppression->toDateString(),
        ])->assertOk();
        $this->postJson("/api/v1/mobile/anomalies/{$second->id}/acknowledge", [
            'suppress_until' => now()->addDays(14)->toDateString(),
        ])->assertOk();

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [
            'items' => [['type' => 'anomaly', 'id' => $second->id]],
        ])->assertOk()->assertJsonPath('unmarked', 1);

        $this->assertTrue(
            $first->metricStatistic->fresh()->anomaly_high_suppressed_until->equalTo($remainingSuppression)
        );
    }

    #[Test]
    public function a_recovered_anomaly_returns_to_the_unread_feed(): void
    {
        $anomaly = $this->anomaly();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson("/api/v1/mobile/anomalies/{$anomaly->id}/acknowledge", [])->assertOk();

        $afterAck = $this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items');
        $this->assertNull($this->findById($afterAck, $anomaly->id), 'acknowledged anomaly should be gone');

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [
            'items' => [['type' => 'anomaly', 'id' => $anomaly->id]],
        ])->assertOk();

        $afterUnmark = $this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items');
        $this->assertNotNull($this->findById($afterUnmark, $anomaly->id), 'anomaly should be back');
    }

    // -------------------------------------------------------------------------
    // Tenancy
    // -------------------------------------------------------------------------

    #[Test]
    public function cannot_unmark_another_users_digest(): void
    {
        $otherUser = User::factory()->create();
        $otherGroup = IntegrationGroup::factory()->create(['user_id' => $otherUser->id]);
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'integration_group_id' => $otherGroup->id,
            'service' => 'flint',
        ]);
        $otherEvent = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
        ]);

        activity('changelog')
            ->performedOn($otherEvent)
            ->causedBy($otherUser)
            ->event('caught_up')
            ->log('caught_up');

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/unmark', [
            'items' => [['type' => 'flint_digest', 'id' => $otherEvent->id]],
        ])->assertOk()->assertJsonPath('unmarked', 0);

        $this->assertEquals(
            1,
            Activity::where('subject_id', $otherEvent->id)->where('event', 'caught_up')->count()
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function digest(): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now(),
        ]);
    }

    private function anomaly(): MetricTrend
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);

        // significant() sets the deviation as well as the type, so the anomaly
        // clears the feed's noise gate and can actually be seen to come back.
        return MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'acknowledged_at' => null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function findById(array $items, string $id): ?array
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function itemById(array $items, string $id): array
    {
        $item = $this->findById($items, $id);
        $this->assertNotNull($item, "item {$id} not present in feed");

        return $item;
    }
}
