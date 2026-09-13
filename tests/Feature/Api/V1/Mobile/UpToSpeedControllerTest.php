<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
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
        $anomaly = MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
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
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
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
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
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
        $anomaly = MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
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

    /**
     * The Economist's World in Brief is a monitored page, not a bookmark, so it
     * arrives as "fetched". Only "bookmarked" used to qualify, and it had
     * therefore never once reached Up to Speed.
     */
    #[Test]
    public function includes_monitored_fetch_pages(): void
    {
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'World in Brief',
        ]);

        $event = Event::factory()->create([
            'integration_id' => $this->knowledgeIntegration->id,
            'domain' => 'knowledge',
            'service' => 'fetch',
            'action' => 'fetched',
            'target_id' => $target->id,
            'target_metadata' => [
                'title' => 'World in Brief: snapshot',
                'url' => 'https://example.test/world-in-brief-snapshot',
            ],
            'time' => now()->subHours(2),
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_tldr',
            'metadata' => ['content' => 'Oil disruption dominates the headlines.'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $newsItem = $items->firstWhere('type', 'news_summary');

        $this->assertNotNull($newsItem);
        $this->assertEquals($event->id, $newsItem['id']);
        $this->assertEquals('World in Brief: snapshot', $newsItem['payload']['title']);
        $this->assertEquals('https://example.test/world-in-brief-snapshot', $newsItem['payload']['url']);
        $this->assertEquals('Oil disruption dominates the headlines.', $newsItem['payload']['tldr']);
    }

    /**
     * A busy monitored page must not prevent older, distinct reading from
     * filling the requested queue just because its repeated events span more
     * than one candidate batch.
     */
    #[Test]
    public function fills_the_news_limit_after_duplicate_fetches_exceed_a_candidate_batch(): void
    {
        $repeatedTarget = EventObject::factory()->create(['user_id' => $this->user->id]);
        $otherTarget = EventObject::factory()->create(['user_id' => $this->user->id]);

        foreach (range(1, 11) as $minutesAgo) {
            $event = Event::factory()->create([
                'integration_id' => $this->knowledgeIntegration->id,
                'domain' => 'knowledge',
                'service' => 'fetch',
                'action' => 'fetched',
                'target_id' => $repeatedTarget->id,
                'time' => now()->subMinutes($minutesAgo),
            ]);

            Block::factory()->create(['event_id' => $event->id, 'block_type' => 'fetch_tldr']);
        }

        $otherEvent = Event::factory()->create([
            'integration_id' => $this->knowledgeIntegration->id,
            'domain' => 'knowledge',
            'service' => 'fetch',
            'action' => 'fetched',
            'target_id' => $otherTarget->id,
            'time' => now()->subHours(1),
        ]);
        Block::factory()->create(['event_id' => $otherEvent->id, 'block_type' => 'fetch_tldr']);

        Sanctum::actingAs($this->user, ['ios:read']);

        $newsItems = collect($this->getJson('/api/v1/mobile/up-to-speed?news_limit=2')
            ->assertOk()
            ->json('items'))
            ->where('type', 'news_summary')
            ->values();

        $this->assertCount(2, $newsItems);
        $this->assertEquals($otherEvent->id, $newsItems[1]['id']);
    }

    /**
     * A monitored page is re-fetched through the day, each fetch its own event
     * with its own summary. Without collapsing them the 48h window puts four
     * near-identical World in Brief cards in the queue.
     */
    #[Test]
    public function collapses_repeated_fetches_of_the_same_page_keeping_the_newest(): void
    {
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'World in Brief',
        ]);

        $newest = null;
        foreach ([26, 14, 6, 1] as $index => $hoursAgo) {
            $event = Event::factory()->create([
                'integration_id' => $this->knowledgeIntegration->id,
                'domain' => 'knowledge',
                'service' => 'fetch',
                'action' => $index === 1 ? 'updated' : 'fetched',
                'target_id' => $target->id,
                'time' => now()->subHours($hoursAgo),
            ]);

            Block::factory()->create([
                'event_id' => $event->id,
                'block_type' => 'fetch_tldr',
                'metadata' => ['content' => "Summary from {$hoursAgo}h ago."],
            ]);

            $newest = $event;
        }

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $newsItems = $items->where('type', 'news_summary')->values();

        $this->assertCount(1, $newsItems);
        $this->assertEquals($newest->id, $newsItems[0]['id']);
        $this->assertEquals('Summary from 1h ago.', $newsItems[0]['payload']['tldr']);
    }

    /**
     * Karakeep bookmarks are knowledge-domain "bookmarked" events on a different
     * service, so the monitored-page clause must not be scoped in a way that
     * drops them.
     */
    #[Test]
    public function still_includes_bookmarks_from_other_services(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->knowledgeIntegration->id,
            'domain' => 'knowledge',
            'service' => 'karakeep',
            'action' => 'bookmarked',
            'time' => now()->subHours(3),
        ]);

        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_tldr',
            'metadata' => ['content' => 'A saved article.'],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));

        $this->assertNotNull($items->firstWhere('id', $event->id));
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
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
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
    // Anomaly presentation
    // -------------------------------------------------------------------------

    /**
     * The payload carried only a metric identifier string, so the client had no
     * way to tell a bank balance from a sleep score — which is why every
     * anomaly, money included, was filed under "Your body".
     */
    #[Test]
    public function anomaly_payload_carries_its_domain_and_unit(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_cardiovascular_age',
            'value_unit' => 'years',
        ]);
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'current_value' => 44,
            'baseline_value' => 38.87,
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $payload = $this->firstAnomalyPayload();

        $this->assertSame('health', $payload['domain']);
        $this->assertSame('oura', $payload['service']);
        $this->assertSame('years', $payload['unit']);
        $this->assertSame('Cardiovascular Age', $payload['display_name']);
    }

    #[Test]
    public function anomaly_payload_carries_preformatted_values(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
        ]);
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'current_value' => 78,
            'baseline_value' => 85,
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $payload = $this->firstAnomalyPayload();

        $this->assertSame('78%', $payload['current_display']);
        $this->assertSame('85%', $payload['baseline_display']);
        $this->assertStringNotContainsString('<', $payload['current_display']);
    }

    /**
     * Direction is not valence, and the shipped UI had only direction — so it
     * tinted a rising number as a warning whatever the number meant.
     */
    #[Test]
    public function anomaly_payload_distinguishes_valence_from_direction(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_cardiovascular_age',
            'value_unit' => 'years',
        ]);
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'current_value' => 44,
            'baseline_value' => 38.87,
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $payload = $this->firstAnomalyPayload();

        $this->assertSame('up', $payload['direction']);
        $this->assertSame('bad', $payload['valence']);
    }

    #[Test]
    public function anomaly_payload_flags_ordinal_metrics(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_resilience_score',
            'value_unit' => 'resilience_level',
        ]);
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_low',
            'detected_at' => now(),
            'current_value' => 2,
            'baseline_value' => 3.2,
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $payload = $this->firstAnomalyPayload();

        $this->assertTrue($payload['is_ordinal']);
        $this->assertSame('Adequate', $payload['current_display']);
    }

    // -------------------------------------------------------------------------
    // Noise gating — the briefing styleguide, applied
    // -------------------------------------------------------------------------

    /**
     * "A single-day movement should usually be treated as noise unless the
     * deviation is genuinely large."
     */
    #[Test]
    public function excludes_a_marginal_single_day_anomaly(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
        ]);
        MetricTrend::factory()->marginal()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'anomaly'));
    }

    #[Test]
    public function includes_a_large_single_day_anomaly(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
        ]);
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertCount(1, $items->where('type', 'anomaly'));
    }

    /**
     * A metric anomalous every day for a fortnight is not surprising — its
     * baseline has drifted. The card was announcing "I've seen this 14 days
     * running, so I'm raising it", which is backwards.
     */
    #[Test]
    public function excludes_an_anomaly_that_has_run_for_a_week(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
        ]);

        foreach (range(0, 8) as $daysAgo) {
            MetricTrend::factory()->significant()->create([
                'metric_statistic_id' => $stat->id,
                'detected_at' => now()->subDays($daysAgo),
            ]);
        }

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'anomaly'));
    }

    /**
     * The plugin marks account balances as exclude_from_flint, and Flint was
     * leading the anomaly chapter with one anyway.
     */
    #[Test]
    public function excludes_metrics_the_plugin_keeps_out_of_flint(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'gocardless',
            'action' => 'had_balance',
            'value_unit' => 'GBP',
        ]);
        MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'current_value' => 2082.23,
            'baseline_value' => 241.68,
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertEmpty($items->where('type', 'anomaly'));
    }

    // -------------------------------------------------------------------------
    // Read state is exposed, never enforced
    // -------------------------------------------------------------------------

    /**
     * The client builds its "already seen today" recap out of the caught-up
     * items in this response, so they must keep being returned after they are
     * marked. Filtering them here would make an accidental dismissal
     * unrecoverable.
     */
    #[Test]
    public function caught_up_items_are_still_returned(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now(),
        ]);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/up-to-speed/read', [
            'items' => [['type' => 'flint_digest', 'id' => $event->id]],
        ])->assertOk();

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $digest = $items->firstWhere('id', $event->id);

        $this->assertNotNull($digest, 'a caught-up digest must still appear in the feed');
        $this->assertNotNull($digest['caught_up_at']);
    }

    // -------------------------------------------------------------------------
    // include_acknowledged
    // -------------------------------------------------------------------------

    #[Test]
    public function acknowledged_anomalies_are_excluded_by_default(): void
    {
        $anomaly = $this->acknowledgedAnomaly();

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertNull($items->firstWhere('id', $anomaly->id));
    }

    #[Test]
    public function include_acknowledged_returns_dismissed_anomalies(): void
    {
        $anomaly = $this->acknowledgedAnomaly();

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect(
            $this->getJson('/api/v1/mobile/up-to-speed?include_acknowledged=1')->assertOk()->json('items')
        );

        $found = $items->firstWhere('id', $anomaly->id);
        $this->assertNotNull($found, 'a dismissed anomaly must be recoverable');
        $this->assertNotNull($found['payload']['acknowledged_at']);
    }

    #[Test]
    public function include_acknowledged_bypasses_noise_only_for_acknowledged_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $acknowledged = MetricTrend::factory()->marginal()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'acknowledged_at' => now(),
        ]);
        $unacknowledged = MetricTrend::factory()->marginal()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'acknowledged_at' => null,
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect(
            $this->getJson('/api/v1/mobile/up-to-speed?include_acknowledged=1')->assertOk()->json('items')
        );

        $this->assertNotNull($items->firstWhere('id', $acknowledged->id));
        $this->assertNull($items->firstWhere('id', $unacknowledged->id));
    }

    #[Test]
    public function acknowledged_balance_anomaly_uses_its_account_direction(): void
    {
        $account = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'metadata' => ['account_type' => 'credit_card'],
        ]);
        $event = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'actor_id' => $account->id,
            'service' => 'gocardless',
            'domain' => 'money',
            'action' => 'had_balance',
            'value_unit' => 'GBP',
        ]);
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'gocardless',
            'action' => 'had_balance',
            'value_unit' => 'GBP',
        ]);
        $anomaly = MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'acknowledged_at' => now(),
            'metadata' => ['event_id' => $event->id],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = collect(
            $this->getJson('/api/v1/mobile/up-to-speed?include_acknowledged=1')->assertOk()->json('items')
        );

        $this->assertSame('bad', $items->firstWhere('id', $anomaly->id)['payload']['valence']);
    }

    #[Test]
    public function include_acknowledged_returns_suppressed_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);
        $anomaly = MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'acknowledged_at' => null,
            'metadata' => ['suppress_until' => now()->addDays(7)->toDateString()],
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $default = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $this->assertNull($default->firstWhere('id', $anomaly->id));

        $included = collect(
            $this->getJson('/api/v1/mobile/up-to-speed?include_acknowledged=1')->assertOk()->json('items')
        );
        $this->assertNotNull($included->firstWhere('id', $anomaly->id));
    }

    #[Test]
    public function internal_subject_keys_are_not_exposed(): void
    {
        Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now(),
        ]);

        Sanctum::actingAs($this->user, ['ios:read']);

        $items = $this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items');

        foreach ($items as $item) {
            $this->assertArrayNotHasKey('_subject_id', $item);
            $this->assertArrayNotHasKey('_subject_key', $item);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function firstAnomalyPayload(): array
    {
        $items = collect($this->getJson('/api/v1/mobile/up-to-speed')->assertOk()->json('items'));
        $anomaly = $items->firstWhere('type', 'anomaly');

        $this->assertNotNull($anomaly, 'expected an anomaly item in the feed');

        return $anomaly['payload'];
    }

    private function acknowledgedAnomaly(): MetricTrend
    {
        $stat = MetricStatistic::factory()->create(['user_id' => $this->user->id]);

        // significant() sets the deviation as well as the type: the factory
        // definition picks its deviation scale from the type it generated, so
        // overriding type alone can leave a trend-scale value behind.
        return MetricTrend::factory()->significant()->create([
            'metric_statistic_id' => $stat->id,
            'detected_at' => now(),
            'acknowledged_at' => now(),
        ]);
    }
}
