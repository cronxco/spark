<?php

namespace Tests\Feature\Mcp;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\DaySummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetDaySummaryToolTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
        ]);
    }

    #[Test]
    public function generates_summary_with_health_section(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $sleepEvent = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'value' => 85,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::today()->setHour(8),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        Block::factory()->create([
            'event_id' => $sleepEvent->id,
            'block_type' => 'contributor',
            'title' => 'Deep Sleep',
            'value' => 80,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::today(),
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertArrayHasKey('sections', $summary);
        $this->assertArrayHasKey('health', $summary['sections']);
        $this->assertArrayHasKey('sleep_score', $summary['sections']['health']);
        $this->assertEquals($sleepEvent->id, $summary['sections']['health']['sleep_score']['event_id']);
        $this->assertEquals(85, $summary['sections']['health']['sleep_score']['score']);
        $this->assertArrayHasKey('contributors', $summary['sections']['health']['sleep_score']);
    }

    #[Test]
    public function generates_summary_with_activity_section(): void
    {
        $ahGroup = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'apple_health',
        ]);

        $ahIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $ahGroup->id,
            'service' => 'apple_health',
        ]);

        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $stepsEvent = Event::factory()->create([
            'integration_id' => $ahIntegration->id,
            'service' => 'apple_health',
            'domain' => 'health',
            'action' => 'had_step_count',
            'value' => 10500,
            'value_multiplier' => 1,
            'value_unit' => 'count',
            'time' => Carbon::today()->setHour(18),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertArrayHasKey('activity', $summary['sections']);
        $this->assertArrayHasKey('steps', $summary['sections']['activity']);
        $this->assertEquals($stepsEvent->id, $summary['sections']['activity']['steps']['event_id']);
        $this->assertEquals(10500, $summary['sections']['activity']['steps']['value']);
    }

    #[Test]
    public function includes_baseline_comparison_when_statistics_exist(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'value' => 90,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::today()->setHour(8),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
            'stddev_value' => 5,
            'normal_lower_bound' => 70,
            'normal_upper_bound' => 90,
            'event_count' => 100,
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $sleepScore = $summary['sections']['health']['sleep_score'];
        $this->assertArrayHasKey('vs_baseline_pct', $sleepScore);
        $this->assertEquals(12.5, $sleepScore['vs_baseline_pct']);
        $this->assertFalse($sleepScore['is_anomaly']);
    }

    #[Test]
    public function detects_anomalies_outside_normal_bounds(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'value' => 55,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::today()->setHour(8),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
            'stddev_value' => 5,
            'normal_lower_bound' => 70,
            'normal_upper_bound' => 90,
            'event_count' => 100,
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $sleepScore = $summary['sections']['health']['sleep_score'];
        $this->assertTrue($sleepScore['is_anomaly']);
    }

    #[Test]
    public function generates_money_section_with_total_spend(): void
    {
        $monzoGroup = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);

        $monzoIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $monzoGroup->id,
            'service' => 'monzo',
        ]);

        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $merchant = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Tesco',
        ]);

        Event::factory()->create([
            'integration_id' => $monzoIntegration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 2550,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(12),
            'actor_id' => $actor->id,
            'target_id' => $merchant->id,
        ]);

        Event::factory()->create([
            'integration_id' => $monzoIntegration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 450,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(14),
            'actor_id' => $actor->id,
            'target_id' => $merchant->id,
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertArrayHasKey('money', $summary['sections']);
        $this->assertCount(2, $summary['sections']['money']['transactions']);
        $this->assertArrayHasKey('event_id', $summary['sections']['money']['transactions'][0]);
        $this->assertEquals(30.0, $summary['sections']['money']['total_spend']);
    }

    #[Test]
    public function generates_media_section_with_spotify_sessions(): void
    {
        $spotifyGroup = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'spotify',
        ]);

        $spotifyIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $spotifyGroup->id,
            'service' => 'spotify',
        ]);

        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);

        foreach (range(0, 4) as $i) {
            $target = EventObject::factory()->create([
                'user_id' => $this->user->id,
                'title' => "Track {$i}",
            ]);

            $event = Event::factory()->create([
                'integration_id' => $spotifyIntegration->id,
                'service' => 'spotify',
                'domain' => 'media',
                'action' => 'listened_to',
                'value' => null,
                'value_multiplier' => null,
                'value_unit' => null,
                'time' => Carbon::today()->setHour(14)->addMinutes($i * 5),
                'actor_id' => $actor->id,
                'target_id' => $target->id,
            ]);

            Block::factory()->create([
                'event_id' => $event->id,
                'block_type' => 'artist',
                'title' => 'Test Artist',
                'time' => Carbon::today(),
            ]);
        }

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertArrayHasKey('media', $summary['sections']);
        $this->assertArrayHasKey('listening_sessions', $summary['sections']['media']);
        $this->assertCount(1, $summary['sections']['media']['listening_sessions']);
        $this->assertArrayHasKey('first_event_id', $summary['sections']['media']['listening_sessions'][0]);
        $this->assertArrayHasKey('last_event_id', $summary['sections']['media']['listening_sessions'][0]);
        $this->assertEquals(5, $summary['sections']['media']['listening_sessions'][0]['track_count']);
    }

    #[Test]
    public function filters_by_domain(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'value' => 85,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::today()->setHour(8),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today(), ['money']);

        $this->assertArrayNotHasKey('health', $summary['sections']);
        $this->assertArrayNotHasKey('activity', $summary['sections']);
        $this->assertArrayHasKey('money', $summary['sections']);
    }

    #[Test]
    public function builds_sync_status_per_service(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'value' => 85,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::today()->setHour(8),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertArrayHasKey('sync_status', $summary);
        $this->assertArrayHasKey('oura', $summary['sync_status']);
        $this->assertEquals(1, $summary['sync_status']['oura']['event_count']);
    }

    #[Test]
    public function returns_empty_sections_for_day_with_no_events(): void
    {
        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertEquals(Carbon::today()->toDateString(), $summary['date']);
        $this->assertEmpty($summary['sync_status']);
        $this->assertEmpty($summary['sections']['health']);
        $this->assertEmpty($summary['anomalies']);
    }

    #[Test]
    public function includes_unacknowledged_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
        ]);

        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_low',
            'detected_at' => Carbon::today(),
            'current_value' => 55,
            'baseline_value' => 80,
            'deviation' => 0.3125,
            'acknowledged_at' => null,
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertNotEmpty($summary['anomalies']);
        $this->assertEquals('anomaly_low', $summary['anomalies'][0]['type']);
        $this->assertEquals('down', $summary['anomalies'][0]['direction']);
    }

    #[Test]
    public function excludes_acknowledged_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
        ]);

        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_low',
            'detected_at' => Carbon::today(),
            'current_value' => 55,
            'baseline_value' => 80,
            'deviation' => 0.3125,
            'acknowledged_at' => now(),
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertEmpty($summary['anomalies']);
    }

    #[Test]
    public function excludes_suppressed_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
        ]);

        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_low',
            'detected_at' => Carbon::today(),
            'current_value' => 55,
            'baseline_value' => 80,
            'deviation' => 0.3125,
            'acknowledged_at' => null,
            'metadata' => ['suppress_until' => Carbon::tomorrow()->toDateString()],
        ]);

        $service = app(DaySummaryService::class);
        $summary = $service->generateSummary($this->user, Carbon::today());

        $this->assertEmpty($summary['anomalies']);
    }
}
