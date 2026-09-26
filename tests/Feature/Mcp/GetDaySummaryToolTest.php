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
use App\Services\EffectiveTimezoneResolver;
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
        $this->assertSame('provisional', $summary['sections']['health']['sleep_score']['state']);
        $this->assertSame($sleepEvent->updated_at->toIso8601String(), $summary['sections']['health']['sleep_score']['updated_at']);
        $this->assertArrayHasKey('contributors', $summary['sections']['health']['sleep_score']);
    }

    #[Test]
    public function fetched_content_uses_the_revision_snapshot_and_its_own_enrichments(): void
    {
        $fetchGroup = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
        ]);
        $fetchIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $fetchGroup->id,
            'service' => 'fetch',
        ]);
        $webpage = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Later mutable title',
            'url' => 'https://example.com/live',
        ]);
        $event = Event::factory()->create([
            'integration_id' => $fetchIntegration->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'fetched',
            'time' => Carbon::today()->setHour(12),
            'target_id' => $webpage->id,
            'target_metadata' => [
                'title' => 'Noon revision title',
                'url' => 'https://example.com/revision',
            ],
        ]);
        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'fetch_summary_paragraph',
            'title' => 'Paragraph Summary',
            'metadata' => ['content' => 'Noon revision summary'],
        ]);

        $summary = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today());
        $fetched = $summary['sections']['knowledge']['fetched_content'][0];

        $this->assertSame('Noon revision title', $fetched['title']);
        $this->assertSame('https://example.com/revision', $fetched['url']);
        $this->assertSame('Noon revision summary', $fetched['summary']);
    }

    #[Test]
    public function fetch_bookmarks_use_the_once_revision_title_and_url(): void
    {
        $fetchGroup = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
        ]);
        $fetchIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $fetchGroup->id,
            'service' => 'fetch',
        ]);
        $webpage = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Later mutable title',
            'url' => 'https://example.com/live',
        ]);
        Event::factory()->create([
            'integration_id' => $fetchIntegration->id,
            'service' => 'fetch',
            'domain' => 'knowledge',
            'action' => 'bookmarked',
            'time' => Carbon::today()->setHour(12),
            'target_id' => $webpage->id,
            'target_metadata' => [
                'title' => 'Saved revision title',
                'url' => 'https://example.com/saved-revision',
            ],
        ]);

        $summary = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today());
        $bookmark = $summary['sections']['knowledge']['bookmarks'][0];

        $this->assertSame('Saved revision title', $bookmark['title']);
        $this->assertSame('https://example.com/saved-revision', $bookmark['url']);
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

        $actor = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Personal',
        ]);
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
            'event_metadata' => ['notes' => 'Weekly shop'],
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

        // Service orders by time DESC, so 14:00 event comes first
        $firstTx = $summary['sections']['money']['transactions'][0];
        $this->assertEquals('Personal', $firstTx['account']);
        $this->assertArrayNotHasKey('reference', $firstTx);

        $secondTx = $summary['sections']['money']['transactions'][1];
        $this->assertEquals('Personal', $secondTx['account']);
        $this->assertEquals('Weekly shop', $secondTx['reference']);
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
        // A service the user has connected but which has nothing to report
        // today still appears, with its own freshness judgement — that is
        // different from a service that's behind.
        $this->assertArrayHasKey('oura', $summary['sync_status']);
        $this->assertEquals(0, $summary['sync_status']['oura']['event_count']);
        $this->assertTrue($summary['sync_status']['oura']['stale']);
        $this->assertNull($summary['sync_status']['oura']['as_of']);
        $this->assertEmpty($summary['sections']['health']);
        $this->assertEmpty($summary['anomalies']);
    }

    #[Test]
    public function summary_queries_the_users_local_day_across_utc_boundaries(): void
    {
        $this->user->settings = array_merge($this->user->settings ?? [], ['timezone' => 'Europe/London']);
        $this->user->save();
        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'had_sleep_score',
            'value' => 80,
            'value_multiplier' => 1,
            'value_unit' => 'percent',
            'time' => Carbon::parse('2026-07-01 00:30:00', 'Europe/London')->utc(),
        ]);

        $summary = app(DaySummaryService::class)->generateSummary($this->user, Carbon::parse('2026-07-01'));

        $this->assertSame('2026-07-01', $summary['date']);
        $this->assertSame('Europe/London', $summary['timezone']);
        $this->assertArrayHasKey('sleep_score', $summary['sections']['health']);
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
            'anomaly_low_suppressed_until' => Carbon::tomorrow()->endOfDay(),
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

        $this->assertEmpty($summary['anomalies']);
    }

    #[Test]
    public function sleep_is_attributed_to_the_day_it_ends_on(): void
    {
        $tz = $this->timezone();
        $today = Carbon::today($tz);

        // Last night: bedtime yesterday evening, woke this morning. This is
        // the night today's sleep score describes.
        $lastNight = $this->sleepRecord($today->copy()->subDay()->setTime(21, 40), $today->copy()->setTime(6, 46), 25110, 69);
        // Tonight: starts today, ends tomorrow — tomorrow's sleep.
        $tonight = $this->sleepRecord($today->copy()->setTime(22, 30), $today->copy()->addDay()->setTime(6, 30), 27000, 88);

        $service = app(DaySummaryService::class);

        $todays = $service->generateSummary($this->user, $today)['sections']['health']['sleep_duration'];
        $this->assertEquals($lastNight->id, $todays['event_id']);
        $this->assertEquals(25110, $todays['duration_seconds']);
        // Oura's measured efficiency, a real percentage.
        $this->assertSame(69, $todays['efficiency_pct']);

        $yesterdays = $service->generateSummary($this->user, $today->copy()->subDay())['sections']['health'];
        $this->assertArrayNotHasKey('sleep_duration', $yesterdays);

        $tomorrows = $service->generateSummary($this->user, $today->copy()->addDay())['sections']['health']['sleep_duration'];
        $this->assertEquals($tonight->id, $tomorrows['event_id']);
    }

    #[Test]
    public function the_main_sleep_wins_over_a_nap_on_the_same_day(): void
    {
        $today = Carbon::today($this->timezone());

        $night = $this->sleepRecord($today->copy()->subDay()->setTime(23, 0), $today->copy()->setTime(7, 0), 26000, 90);
        $this->sleepRecord($today->copy()->setTime(14, 0), $today->copy()->setTime(15, 0), 3600, 50);

        $summary = app(DaySummaryService::class)->generateSummary($this->user, $today);

        $this->assertEquals($night->id, $summary['sections']['health']['sleep_duration']['event_id']);
    }

    #[Test]
    public function a_running_daily_total_is_not_low_until_the_day_is_over(): void
    {
        $health = $this->appleHealthIntegration();
        $tz = $this->timezone();

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'apple_health',
            'action' => 'had_apple_stand_hour',
            'value_unit' => 'hours',
            'mean_value' => 9,
            'stddev_value' => 1.5,
            'normal_lower_bound' => 6,
            'normal_upper_bound' => 12,
            'event_count' => 100,
        ]);

        foreach ([Carbon::today($tz), Carbon::yesterday($tz)] as $day) {
            $this->appleHealthEvent($health, 'had_apple_stand_hour', 3, 'hours', $day);
        }

        $service = app(DaySummaryService::class);

        // Three stand hours so far today is where most days are at 8pm.
        $today = $service->generateSummary($this->user, Carbon::today($tz));
        $this->assertFalse($today['sections']['activity']['stand_hours']['is_anomaly']);

        // A finished day with three is genuinely low.
        $yesterday = $service->generateSummary($this->user, Carbon::yesterday($tz));
        $this->assertTrue($yesterday['sections']['activity']['stand_hours']['is_anomaly']);
    }

    #[Test]
    public function a_suppressed_anomaly_direction_is_not_flagged(): void
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
            'anomaly_low_suppressed_until' => now()->addDays(3),
        ]);

        $summary = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today());

        $this->assertFalse($summary['sections']['health']['sleep_score']['is_anomaly']);
    }

    #[Test]
    public function a_day_with_no_spend_has_no_percentage_against_baseline(): void
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo']);
        $monzo = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);
        $actor = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Current Account']);
        $merchant = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Tesco']);

        $transaction = fn (string $action, int $pence, Carbon $time) => Event::factory()->create([
            'integration_id' => $monzo->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => $action,
            'value' => $pence,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => $time,
            'actor_id' => $actor->id,
            'target_id' => $merchant->id,
        ]);

        // Enough spending history for a baseline to exist.
        foreach (range(1, 6) as $daysAgo) {
            $transaction('card_payment_to', 2000, Carbon::today()->subDays($daysAgo)->setHour(12));
        }
        // Today: only money moved into a pot.
        $transaction('pot_transfer_to', 534, Carbon::today()->setHour(3));

        $money = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today())['sections']['money'];

        $this->assertEquals(0, $money['total_spend']);
        // Zero against any baseline is "-100%", which says nothing the
        // zero doesn't.
        $this->assertArrayNotHasKey('total_spend_vs_baseline_pct', $money);
        $this->assertSame('no_spend', $money['total_spend_baseline_unavailable_reason']);
    }

    #[Test]
    public function a_pushed_service_is_as_fresh_as_what_it_last_sent(): void
    {
        // Apple Health is pushed to Spark; nothing polls it, so nothing had
        // ever set `last_successful_update_at` and it read as permanently
        // stale.
        $health = $this->appleHealthIntegration();
        $this->appleHealthEvent($health, 'had_step_count', 8064, 'steps', Carbon::today($this->timezone()));

        $status = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today())['sync_status']['apple_health'];

        $this->assertFalse($status['stale']);
        $this->assertNotNull($status['as_of']);
    }

    #[Test]
    public function a_pushed_service_goes_stale_when_nothing_has_arrived_for_a_day(): void
    {
        $health = $this->appleHealthIntegration(['last_successful_update_at' => now()->subHours(30)]);
        $this->appleHealthEvent($health, 'had_step_count', 41, 'steps', Carbon::today($this->timezone()));

        $status = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today())['sync_status']['apple_health'];

        $this->assertTrue($status['stale']);
    }

    #[Test]
    public function apple_health_coverage_follows_the_last_push_not_the_newest_sample(): void
    {
        $this->travelTo(Carbon::today($this->timezone())->setTime(19, 36));

        // The last push was two and a half hours ago…
        $health = $this->appleHealthIntegration(['last_successful_update_at' => now()->subMinutes(150)]);
        $this->appleHealthEvent($health, 'had_step_count', 5109, 'steps', Carbon::today($this->timezone()), now()->subMinutes(150));
        // …but a heart-rate sample from it was touched moments ago.
        $this->appleHealthEvent($health, 'had_heart_rate', 72, 'bpm', Carbon::today($this->timezone()), now()->subMinutes(2));

        $status = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today())['sync_status']['apple_health'];

        $this->assertSame('partial', $status['coverage']);
        $this->assertSame('Last updated 2h ago — data may be incomplete.', $status['coverage_note']);
    }

    #[Test]
    public function apple_health_coverage_ignores_a_workouts_push(): void
    {
        $this->travelTo(Carbon::today($this->timezone())->setTime(19, 36));

        $metrics = $this->appleHealthIntegration(['last_successful_update_at' => now()->subHours(3)]);
        // A workout arrived just now; the day's totals are still three hours old.
        $this->appleHealthIntegration([
            'instance_type' => 'workouts',
            'last_successful_update_at' => now()->subMinutes(5),
        ]);
        $this->appleHealthEvent($metrics, 'had_step_count', 5109, 'steps', Carbon::today($this->timezone()), now()->subHours(3));

        $status = app(DaySummaryService::class)->generateSummary($this->user, Carbon::today())['sync_status']['apple_health'];

        $this->assertSame('partial', $status['coverage']);
    }

    private function timezone(): string
    {
        return app(EffectiveTimezoneResolver::class)->timezoneFor($this->user);
    }

    private function sleepRecord(Carbon $bedtime, Carbon $wake, int $seconds, int $efficiency): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'domain' => 'health',
            'action' => 'slept_for',
            'value' => $seconds,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'time' => $bedtime,
            'event_metadata' => ['end' => $wake->toIso8601String(), 'efficiency' => $efficiency],
            'actor_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
            'target_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
        ]);
    }

    private function appleHealthIntegration(array $attributes = []): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'apple_health']);

        return Integration::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'apple_health',
            'instance_type' => 'metrics',
            'last_successful_update_at' => null,
        ], $attributes));
    }

    private function appleHealthEvent(Integration $integration, string $action, float $value, string $unit, Carbon $day, ?Carbon $updatedAt = null): Event
    {
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'apple_health',
            'domain' => 'health',
            'action' => $action,
            'value' => $value,
            'value_multiplier' => 1,
            'value_unit' => $unit,
            'time' => $day->copy()->startOfDay(),
            'actor_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
            'target_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
        ]);

        if ($updatedAt !== null) {
            Event::whereKey($event->id)->update(['updated_at' => $updatedAt]);
        }

        return $event;
    }
}
