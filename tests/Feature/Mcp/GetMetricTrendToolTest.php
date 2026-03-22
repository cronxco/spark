<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\GetMetricTrendTool;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetMetricTrendToolTest extends TestCase
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
    public function returns_daily_values_over_date_range(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        foreach ([3, 2, 1] as $daysAgo) {
            Event::factory()->create([
                'integration_id' => $this->integration->id,
                'service' => 'oura',
                'action' => 'had_sleep_score',
                'value' => 80 + $daysAgo,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
                'time' => Carbon::today()->subDays($daysAgo)->setHour(8),
                'actor_id' => $actor->id,
                'target_id' => $target->id,
            ]);
        }

        $response = SparkServer::actingAs($this->user)->tool(GetMetricTrendTool::class, [
            'metric' => 'oura.sleep_score',
            'from' => '7_days_ago',
            'to' => 'today',
        ]);

        $response->assertOk();
        $response->assertSee('"metric": "oura.had_sleep_score.percent"');
        $response->assertSee('"mean"');
    }

    #[Test]
    public function includes_baseline_when_statistics_exist(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value' => 85,
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

        $response = SparkServer::actingAs($this->user)->tool(GetMetricTrendTool::class, [
            'metric' => 'oura.sleep_score',
            'from' => 'today',
            'to' => 'today',
        ]);

        $response->assertOk();
        $response->assertSee('"baseline"');
        $response->assertSee('"mean": 80');
    }

    #[Test]
    public function rejects_unknown_metric_identifier(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(GetMetricTrendTool::class, [
            'metric' => 'unknown.metric',
        ]);

        $response->assertHasErrors(['Unknown metric identifier']);
    }

    #[Test]
    public function calculates_trend_direction(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        // Create ascending trend
        foreach (range(0, 5) as $i) {
            Event::factory()->create([
                'integration_id' => $this->integration->id,
                'service' => 'oura',
                'action' => 'had_sleep_score',
                'value' => 70 + ($i * 5),
                'value_multiplier' => 1,
                'value_unit' => 'percent',
                'time' => Carbon::today()->subDays(5 - $i)->setHour(8),
                'actor_id' => $actor->id,
                'target_id' => $target->id,
            ]);
        }

        $response = SparkServer::actingAs($this->user)->tool(GetMetricTrendTool::class, [
            'metric' => 'oura.sleep_score',
            'from' => '7_days_ago',
            'to' => 'today',
        ]);

        $response->assertOk();
        $response->assertSee('"trend_direction": "up"');
    }
}
