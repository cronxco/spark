<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class UpToSpeedControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $flintIntegration;

    protected Integration $knowledgeIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();

        $flintGroup = IntegrationGroup::factory()->create(['user_id' => $this->user->id]);
        $this->flintIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $flintGroup->id,
            'service' => 'flint',
        ]);

        $knowledgeGroup = IntegrationGroup::factory()->create(['user_id' => $this->user->id]);
        $this->knowledgeIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $knowledgeGroup->id,
            'service' => 'fetch',
        ]);
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/up-to-speed')
            ->assertStatus(401);
    }

    #[Test]
    public function requires_ios_read_ability(): void
    {
        Sanctum::actingAs($this->user, []);

        $this->getJson('/api/v1/mobile/up-to-speed')
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Basic response structure
    // -------------------------------------------------------------------------

    #[Test]
    public function returns_empty_items_when_nothing_exists(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $response = $this->getJson('/api/v1/mobile/up-to-speed')
            ->assertOk()
            ->assertJsonStructure(['items']);

        // Check-ins are always included (both morning and afternoon)
        $items = $response->json('items');
        $this->assertCount(2, $items);
        $this->assertEquals('check_in', $items[0]['type']);
        $this->assertEquals('check_in', $items[1]['type']);
    }

    // -------------------------------------------------------------------------
    // flint_digest items
    // -------------------------------------------------------------------------

    #[Test]
    public function includes_todays_flint_digests(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now(),
            'event_metadata' => ['period' => 'morning', 'title' => 'Morning Digest', 'summary' => 'Summary text'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $response = $this->getJson('/api/v1/mobile/up-to-speed')->assertOk();
        $items = collect($response->json('items'));

        $digest = $items->firstWhere('type', 'flint_digest');
        $this->assertNotNull($digest);
        $this->assertEquals($event->id, $digest['id']);
        $this->assertNull($digest['caught_up_at']);
        $this->assertEquals('morning', $digest['payload']['period']);
        $this->assertEquals('Morning Digest', $digest['payload']['title']);
    }

    #[Test]
    public function excludes_flint_digests_from_other_days(): void
    {
        Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now()->subDays(2),
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'flint_digest'));
    }

    #[Test]
    public function flint_digest_caught_up_at_is_set_when_marked(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now(),
        ]);

        Activity::create([
            'log_name' => 'changelog',
            'description' => 'caught_up',
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'caught_up',
            'properties' => [],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $digest = $items->firstWhere('type', 'flint_digest');
        $this->assertNotNull($digest['caught_up_at']);
    }

    // -------------------------------------------------------------------------
    // check_in items
    // -------------------------------------------------------------------------

    #[Test]
    public function includes_both_check_in_periods(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $checkIns = $items->where('type', 'check_in')->values();

        $this->assertCount(2, $checkIns);
        $this->assertEquals('morning', $checkIns[0]['payload']['period']);
        $this->assertEquals('afternoon', $checkIns[1]['payload']['period']);
    }

    #[Test]
    public function check_in_uses_synthetic_id_format(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $today = Carbon::today('UTC')->toDateString();
        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $checkIns = $items->where('type', 'check_in')->values();

        $this->assertEquals("morning:{$today}", $checkIns[0]['id']);
        $this->assertEquals("afternoon:{$today}", $checkIns[1]['id']);
    }

    #[Test]
    public function check_in_caught_up_at_is_set_when_completed(): void
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id]);
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'daily_checkin',
        ]);

        $today = Carbon::today('UTC')->toDateString();
        $checkinTime = now()->setTimeFromTimeString('08:00:00');

        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'daily_checkin',
            'action' => 'had_morning_checkin',
            'source_id' => 'daily_checkin_morning_' . $today,
            'time' => $checkinTime,
            'event_metadata' => ['date' => $today],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $morning = $items->first(fn ($i) => $i['type'] === 'check_in' && $i['payload']['period'] === 'morning');

        $this->assertNotNull($morning);
        $this->assertTrue($morning['payload']['completed']);
        $this->assertNotNull($morning['caught_up_at']);
    }

    #[Test]
    public function incomplete_check_in_has_null_caught_up_at(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $morning = $items->first(fn ($i) => $i['type'] === 'check_in' && $i['payload']['period'] === 'morning');

        $this->assertNotNull($morning);
        $this->assertFalse($morning['payload']['completed']);
        $this->assertNull($morning['caught_up_at']);
    }

    // -------------------------------------------------------------------------
    // anomaly items
    // -------------------------------------------------------------------------

    #[Test]
    public function includes_todays_unacknowledged_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
            'detected_at' => now(),
            'acknowledged_at' => null,
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $anomalyItem = $items->firstWhere('type', 'anomaly');

        $this->assertNotNull($anomalyItem);
        $this->assertEquals($anomaly->id, $anomalyItem['id']);
        $this->assertNull($anomalyItem['caught_up_at']);
        $this->assertArrayHasKey('metric', $anomalyItem['payload']);
        $this->assertArrayHasKey('direction', $anomalyItem['payload']);
    }

    #[Test]
    public function excludes_acknowledged_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
            'detected_at' => now(),
            'acknowledged_at' => now(),
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'anomaly'));
    }

    #[Test]
    public function excludes_suppressed_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
            'detected_at' => now(),
            'acknowledged_at' => null,
            'metadata' => ['suppress_until' => now()->addDay()->toDateString()],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'anomaly'));
    }

    #[Test]
    public function anomaly_caught_up_at_is_set_when_marked(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $anomaly = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
            'detected_at' => now(),
            'acknowledged_at' => null,
        ]);

        Activity::create([
            'log_name' => 'changelog',
            'description' => 'caught_up',
            'subject_type' => MetricTrend::class,
            'subject_id' => $anomaly->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'caught_up',
            'properties' => [],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $anomalyItem = $items->firstWhere('type', 'anomaly');
        $this->assertNotNull($anomalyItem['caught_up_at']);
    }

    // -------------------------------------------------------------------------
    // news_summary items
    // -------------------------------------------------------------------------

    #[Test]
    public function includes_bookmarks_with_summary_blocks_in_48h_window(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->knowledgeIntegration->id,
            'domain' => 'knowledge',
            'service' => 'fetch',
            'action' => 'bookmarked',
            'time' => now()->subHours(12),
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_tldr',
            'metadata' => ['text' => 'Short summary'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $newsItem = $items->firstWhere('type', 'news_summary');

        $this->assertNotNull($newsItem);
        $this->assertEquals($event->id, $newsItem['id']);
        $this->assertEquals('fetch', $newsItem['payload']['source']);
    }

    #[Test]
    public function excludes_bookmarks_without_summary_blocks(): void
    {
        Event::factory()->create([
            'integration_id' => $this->knowledgeIntegration->id,
            'domain' => 'knowledge',
            'action' => 'bookmarked',
            'time' => now()->subHours(12),
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'news_summary'));
    }

    #[Test]
    public function excludes_news_older_than_48_hours(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->knowledgeIntegration->id,
            'domain' => 'knowledge',
            'action' => 'bookmarked',
            'time' => now()->subHours(49),
        ]);
        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_tldr',
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'news_summary'));
    }

    #[Test]
    public function news_caught_up_at_is_set_when_marked(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->knowledgeIntegration->id,
            'domain' => 'knowledge',
            'action' => 'bookmarked',
            'time' => now()->subHours(12),
        ]);
        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_summary_paragraph',
        ]);

        Activity::create([
            'log_name' => 'changelog',
            'description' => 'caught_up',
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'caught_up',
            'properties' => [],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $newsItem = $items->firstWhere('type', 'news_summary');
        $this->assertNotNull($newsItem['caught_up_at']);
    }

    // -------------------------------------------------------------------------
    // Ordering
    // -------------------------------------------------------------------------

    #[Test]
    public function items_are_ordered_correctly(): void
    {
        // Create one of each type
        Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now(),
        ]);

        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_high',
            'detected_at' => now(),
            'acknowledged_at' => null,
        ]);

        $newsEvent = Event::factory()->create([
            'integration_id' => $this->knowledgeIntegration->id,
            'domain' => 'knowledge',
            'action' => 'bookmarked',
            'time' => now()->subHours(1),
        ]);
        Block::factory()->create(['event_id' => $newsEvent->id, 'block_type' => 'fetch_tldr']);

        Sanctum::actingAs($this->user, ['ios:read']);

        $types = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'))
            ->pluck('type')
            ->all();

        $this->assertEquals(['flint_digest', 'check_in', 'check_in', 'anomaly', 'news_summary'], $types);
    }

    // -------------------------------------------------------------------------
    // Data isolation
    // -------------------------------------------------------------------------

    #[Test]
    public function does_not_include_other_users_items(): void
    {
        $other = User::factory()->create();
        $otherGroup = IntegrationGroup::factory()->create(['user_id' => $other->id]);
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'integration_group_id' => $otherGroup->id,
            'service' => 'flint',
        ]);

        Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now(),
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'flint_digest'));
    }
}
