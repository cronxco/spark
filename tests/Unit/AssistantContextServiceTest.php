<?php

namespace Tests\Unit;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\Relationship;
use App\Models\User;
use App\Services\AssistantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssistantContextServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generates_context_for_yesterday_today_tomorrow(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        // Create test events for yesterday
        $monzoIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        Event::factory()->count(5)->create([
            'integration_id' => $monzoIntegration->id,
            'time' => now()->subDay(),
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $this->assertArrayHasKey('yesterday', $context);
        $this->assertArrayHasKey('today', $context);
        $this->assertArrayHasKey('tomorrow', $context);

        $this->assertArrayHasKey('date', $context['yesterday']);
        $this->assertArrayHasKey('timezone', $context['yesterday']);
        $this->assertArrayHasKey('event_count', $context['yesterday']);
        $this->assertArrayHasKey('group_count', $context['yesterday']);
        $this->assertArrayHasKey('service_breakdown', $context['yesterday']);
        $this->assertArrayHasKey('groups', $context['yesterday']);
        $this->assertArrayHasKey('relationships', $context['yesterday']);

        $this->assertNotEmpty($context['yesterday']['groups']);
        $this->assertEquals(5, $context['yesterday']['event_count']);
    }

    #[Test]
    public function groups_events_like_day_view_by_service_action_and_hour(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $spotifyIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'spotify']);
        $target = EventObject::factory()->create(['user_id' => $user->id, 'type' => 'track']);

        // Create 5 spotify listens in same hour
        foreach (range(0, 4) as $i) {
            Event::factory()->create([
                'integration_id' => $spotifyIntegration->id,
                'service' => 'spotify',
                'action' => 'listened_to',
                'target_id' => $target->id,
                'time' => now()->setHour(17)->setMinute($i * 10),
            ]);
        }

        $service = app(AssistantContextService::class);
        $context = $service->generateTimeframeContext($user, 'today', now(), $flintIntegration);

        $this->assertCount(1, $context['groups']);
        $this->assertEquals(5, $context['groups'][0]['count']);
        $this->assertTrue($context['groups'][0]['is_condensed']);

        $this->assertArrayHasKey('service', $context['groups'][0]);
        $this->assertArrayHasKey('action', $context['groups'][0]);
        $this->assertArrayHasKey('hour', $context['groups'][0]);
        $this->assertArrayHasKey('timezone_hour', $context['groups'][0]);
        $this->assertArrayHasKey('count', $context['groups'][0]);
        $this->assertArrayHasKey('object_type_plural', $context['groups'][0]);
        $this->assertArrayHasKey('summary', $context['groups'][0]);
        $this->assertArrayHasKey('is_condensed', $context['groups'][0]);
        $this->assertArrayHasKey('formatted_action', $context['groups'][0]);
        $this->assertArrayHasKey('first_event', $context['groups'][0]);
        $this->assertArrayHasKey('all_events', $context['groups'][0]);
    }

    #[Test]
    public function includes_id_but_excludes_embeddings_and_internal_fields_from_events(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $monzoIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        Event::factory()->create(['integration_id' => $monzoIntegration->id, 'time' => now()]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $group = $context['today']['groups'][0];

        // Should include the event ID so AI can reference events
        $this->assertArrayHasKey('id', $group['first_event']);

        // Should exclude these internal fields
        $this->assertArrayNotHasKey('embeddings', $group['first_event']);
        $this->assertArrayNotHasKey('integration_id', $group['first_event']);
        $this->assertArrayNotHasKey('created_at', $group['first_event']);
        $this->assertArrayNotHasKey('deleted_at', $group['first_event']);

        // Should have updated_at for temporal context
        $this->assertArrayHasKey('updated_at', $group['first_event']);
    }

    #[Test]
    public function excludes_raw_blocks_by_default(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id, 'time' => now()]);

        // Create both raw and non-raw blocks
        Block::factory()->create(['event_id' => $event->id, 'block_type' => 'summary_raw']);
        Block::factory()->create(['event_id' => $event->id, 'block_type' => 'summary_paragraph']);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertCount(1, $transformedEvent['blocks']);
        $this->assertEquals('summary_paragraph', $transformedEvent['blocks'][0]['type']);
    }

    #[Test]
    public function respects_custom_excluded_block_types(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user, [
            'excluded_block_types' => ['summary_paragraph', 'summary_tweet'],
        ]);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id, 'time' => now()]);

        Block::factory()->create(['event_id' => $event->id, 'block_type' => 'summary_paragraph']);
        Block::factory()->create(['event_id' => $event->id, 'block_type' => 'summary_tweet']);
        Block::factory()->create(['event_id' => $event->id, 'block_type' => 'key_takeaways']);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertCount(1, $transformedEvent['blocks']);
        $this->assertEquals('key_takeaways', $transformedEvent['blocks'][0]['type']);
    }

    #[Test]
    public function truncates_long_block_content_to_500_chars(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id, 'time' => now()]);

        $longContent = str_repeat('A', 600);
        Block::factory()->create([
            'event_id' => $event->id,
            'block_type' => 'summary_paragraph',
            'metadata' => ['content' => $longContent],
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertLessThanOrEqual(503, strlen($transformedEvent['blocks'][0]['content'])); // 500 + '...'
        $this->assertStringEndsWith('...', $transformedEvent['blocks'][0]['content']);
    }

    #[Test]
    public function includes_relationships_when_enabled(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event1 = Event::factory()->create(['integration_id' => $integration->id, 'time' => now()]);
        $event2 = Event::factory()->create(['integration_id' => $integration->id, 'time' => now()->addMinutes(5)]);

        Relationship::create([
            'user_id' => $user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $this->assertCount(1, $context['today']['relationships']);
        $this->assertArrayHasKey('type', $context['today']['relationships'][0]);
        $this->assertArrayHasKey('from_event', $context['today']['relationships'][0]);
        $this->assertArrayHasKey('to_event', $context['today']['relationships'][0]);
    }

    #[Test]
    public function excludes_relationships_when_disabled(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user, ['include_relationships' => false]);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event1 = Event::factory()->create(['integration_id' => $integration->id, 'time' => now()]);
        $event2 = Event::factory()->create(['integration_id' => $integration->id, 'time' => now()->addMinutes(5)]);

        Relationship::create([
            'user_id' => $user->id,
            'from_type' => Event::class,
            'from_id' => $event1->id,
            'to_type' => Event::class,
            'to_id' => $event2->id,
            'type' => 'linked_to',
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $this->assertEmpty($context['today']['relationships']);
    }

    #[Test]
    public function respects_max_events_limit(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user, ['max_events_per_timeframe' => 10]);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        Event::factory()->count(25)->create([
            'integration_id' => $integration->id,
            'time' => now(),
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $this->assertLessThanOrEqual(10, $context['today']['event_count']);
    }

    #[Test]
    public function filters_by_enabled_services(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user, [
            'today_services' => ['monzo'], // Only Monzo enabled
        ]);

        $monzoIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $spotifyIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'spotify']);

        Event::factory()->create(['integration_id' => $monzoIntegration->id, 'service' => 'monzo', 'time' => now()]);
        Event::factory()->create(['integration_id' => $spotifyIntegration->id, 'service' => 'spotify', 'time' => now()]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $this->assertEquals(1, $context['today']['event_count']);
        $this->assertEquals('monzo', $context['today']['groups'][0]['service']);
    }

    #[Test]
    public function filters_by_enabled_integration_instances(): void
    {
        $user = User::factory()->create();

        $monzoIntegration1 = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $monzoIntegration2 = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);

        $flintIntegration = $this->createFlintIntegration($user, [
            'today_integrations' => [$monzoIntegration1->id], // Only first integration enabled
        ]);

        Event::factory()->create(['integration_id' => $monzoIntegration1->id, 'time' => now()]);
        Event::factory()->create(['integration_id' => $monzoIntegration2->id, 'time' => now()]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $this->assertEquals(1, $context['today']['event_count']);
    }

    #[Test]
    public function returns_empty_context_when_timeframe_is_disabled(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user, ['today_enabled' => false]);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        Event::factory()->count(5)->create([
            'integration_id' => $integration->id,
            'time' => now(),
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $this->assertEquals(0, $context['today']['event_count']);
        $this->assertEmpty($context['today']['groups']);
    }

    #[Test]
    public function includes_tags_in_event_transformation(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id, 'time' => now()]);

        $event->attachTag('test-tag');
        $event->attachTag('another-tag');

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertArrayHasKey('tags', $transformedEvent);
        $this->assertContains('test-tag', $transformedEvent['tags']);
        $this->assertContains('another-tag', $transformedEvent['tags']);
    }

    #[Test]
    public function includes_formatted_value_with_unit(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => now(),
            'value' => 5000,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertEquals(50.0, $transformedEvent['value']);
        $this->assertEquals('GBP', $transformedEvent['unit']);
    }

    #[Test]
    public function includes_actor_and_target_objects(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id, 'title' => 'Current Account']);
        $target = EventObject::factory()->create(['user_id' => $user->id, 'title' => 'Starbucks']);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => now(),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertArrayHasKey('title', $transformedEvent['actor']);
        $this->assertArrayHasKey('concept', $transformedEvent['actor']);
        $this->assertArrayHasKey('type', $transformedEvent['actor']);
        $this->assertEquals('Current Account', $transformedEvent['actor']['title']);
        $this->assertEquals('Starbucks', $transformedEvent['target']['title']);
    }

    #[Test]
    public function service_breakdown_counts_events_by_service(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $monzoIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $spotifyIntegration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'spotify']);

        Event::factory()->count(3)->create(['integration_id' => $monzoIntegration->id, 'service' => 'monzo', 'time' => now()]);
        Event::factory()->count(7)->create(['integration_id' => $spotifyIntegration->id, 'service' => 'spotify', 'time' => now()]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $this->assertArrayHasKey('monzo', $context['today']['service_breakdown']);
        $this->assertArrayHasKey('spotify', $context['today']['service_breakdown']);
        $this->assertEquals(3, $context['today']['service_breakdown']['monzo']);
        $this->assertEquals(7, $context['today']['service_breakdown']['spotify']);
    }

    #[Test]
    public function embeds_metrics_in_events_when_available(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'oura']);

        // Create metric statistic for HRV
        $statistic = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'logged_hrv',
            'value_unit' => 'ms',
            'event_count' => 30,
            'min_value' => 30.0,
            'max_value' => 80.0,
            'mean_value' => 55.0,
            'stddev_value' => 10.0,
            'normal_lower_bound' => 35.0,
            'normal_upper_bound' => 75.0,
        ]);

        // Create event with HRV value
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'logged_hrv',
            'time' => now(),
            'value' => 50.0,
            'value_unit' => 'ms',
            'value_multiplier' => 1,
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertArrayHasKey('metrics', $transformedEvent);
        $this->assertArrayHasKey('baseline', $transformedEvent['metrics']);
        $this->assertArrayHasKey('normal_bounds', $transformedEvent['metrics']);
        $this->assertArrayHasKey('vs_baseline', $transformedEvent['metrics']);
        $this->assertArrayHasKey('vs_baseline_pct', $transformedEvent['metrics']);
        $this->assertArrayHasKey('is_anomaly', $transformedEvent['metrics']);
        $this->assertArrayHasKey('recent_trends', $transformedEvent['metrics']);

        $this->assertEquals(55.0, $transformedEvent['metrics']['baseline']['mean']);
        $this->assertEquals(30.0, $transformedEvent['metrics']['baseline']['min']);
        $this->assertEquals(80.0, $transformedEvent['metrics']['baseline']['max']);
        $this->assertEquals(10.0, $transformedEvent['metrics']['baseline']['stddev']);
    }

    #[Test]
    public function does_not_embed_metrics_when_no_statistics_available(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);

        // Create event without any metric statistics
        Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => now(),
            'value' => 100.0,
            'value_unit' => 'bpm',
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertArrayNotHasKey('metrics', $transformedEvent);
    }

    #[Test]
    public function calculates_vs_baseline_percentage_correctly(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'oura']);

        MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'logged_sleep',
            'value_unit' => 'hours',
            'mean_value' => 7.5,
            'normal_lower_bound' => 6.3,
            'normal_upper_bound' => 8.7,
        ]);

        // Create event with sleep duration 20% below baseline
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'logged_sleep',
            'time' => now(),
            'value' => 6.0,
            'value_unit' => 'hours',
            'value_multiplier' => 1,
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertEquals(-1.5, $transformedEvent['metrics']['vs_baseline']);
        $this->assertEquals(-20.0, $transformedEvent['metrics']['vs_baseline_pct']);
    }

    #[Test]
    public function detects_anomalies_correctly(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'oura']);

        MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'logged_hrv',
            'value_unit' => 'ms',
            'mean_value' => 55.0,
            'normal_lower_bound' => 35.0,
            'normal_upper_bound' => 75.0,
        ]);

        // Create event with HRV value outside normal bounds (anomaly)
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'logged_hrv',
            'time' => now(),
            'value' => 85.0, // Above upper bound
            'value_unit' => 'ms',
            'value_multiplier' => 1,
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertTrue($transformedEvent['metrics']['is_anomaly']);
    }

    #[Test]
    public function includes_recent_trends_when_available(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'oura']);

        $statistic = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'logged_sleep',
            'value_unit' => 'hours',
            'mean_value' => 7.5,
        ]);

        // Create recent trend
        MetricTrend::factory()->create([
            'metric_statistic_id' => $statistic->id,
            'type' => 'trend_down_weekly',
            'detected_at' => now()->subDays(2),
            'deviation' => -0.8,
            'significance_score' => 0.85,
            'acknowledged_at' => null, // Unacknowledged
        ]);

        // Create old trend (should not be included)
        MetricTrend::factory()->create([
            'metric_statistic_id' => $statistic->id,
            'type' => 'trend_up_monthly',
            'detected_at' => now()->subDays(10),
            'acknowledged_at' => null,
        ]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'logged_sleep',
            'time' => now(),
            'value' => 7.0,
            'value_unit' => 'hours',
            'value_multiplier' => 1,
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertCount(1, $transformedEvent['metrics']['recent_trends']);
        $this->assertEquals('trend_down_weekly', $transformedEvent['metrics']['recent_trends'][0]['type']);
        $this->assertEquals(-0.8, $transformedEvent['metrics']['recent_trends'][0]['deviation']);
        $this->assertEquals(0.85, $transformedEvent['metrics']['recent_trends'][0]['significance']);
    }

    #[Test]
    public function excludes_acknowledged_trends(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'oura']);

        $statistic = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'logged_hrv',
            'value_unit' => 'ms',
            'mean_value' => 55.0,
        ]);

        // Create acknowledged trend (should not be included)
        MetricTrend::factory()->create([
            'metric_statistic_id' => $statistic->id,
            'type' => 'anomaly_high',
            'detected_at' => now()->subDay(),
            'acknowledged_at' => now()->subHours(12), // Already acknowledged
        ]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'oura',
            'action' => 'logged_hrv',
            'time' => now(),
            'value' => 50.0,
            'value_unit' => 'ms',
            'value_multiplier' => 1,
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertEmpty($transformedEvent['metrics']['recent_trends']);
    }

    #[Test]
    public function does_not_embed_metrics_for_events_without_values(): void
    {
        $user = User::factory()->create();
        $flintIntegration = $this->createFlintIntegration($user);

        $integration = Integration::factory()->create(['user_id' => $user->id]);

        // Create event without value (e.g., qualitative event)
        Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => now(),
            'value' => null,
            'value_unit' => null,
        ]);

        $service = app(AssistantContextService::class);
        $context = $service->generateContext($user, now(), $flintIntegration);

        $transformedEvent = $context['today']['groups'][0]['first_event'];

        $this->assertArrayNotHasKey('metrics', $transformedEvent);
    }

    protected function createFlintIntegration(User $user, array $config = []): Integration
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'flint',
        ]);

        return Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'flint',
            'instance_type' => 'assistant',
            'configuration' => array_merge([
                'yesterday_enabled' => true,
                'today_enabled' => true,
                'tomorrow_enabled' => true,
                'include_relationships' => true,
            ], $config),
        ]);
    }
}
