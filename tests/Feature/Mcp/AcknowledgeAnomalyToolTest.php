<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\AcknowledgeAnomalyTool;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcknowledgeAnomalyToolTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function acknowledges_unacknowledged_anomaly(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $trend = MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_low',
            'detected_at' => Carbon::today(),
            'acknowledged_at' => null,
        ]);

        $response = SparkServer::actingAs($this->user)->tool(AcknowledgeAnomalyTool::class, [
            'metric' => 'oura.sleep_score',
            'date' => 'today',
            'note' => 'Was sick',
        ]);

        $response->assertOk();
        $response->assertSee('"anomalies_acknowledged": 1');
        $response->assertSee('"note": "Was sick"');

        // Verify in database
        $trend->refresh();
        $this->assertNotNull($trend->acknowledged_at);
        $this->assertEquals('Was sick', $trend->metadata['acknowledgement_note']);
    }

    #[Test]
    public function stores_suppress_until_in_metadata(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_low',
            'detected_at' => Carbon::today(),
            'acknowledged_at' => null,
        ]);

        $suppressDate = Carbon::today()->addDays(3)->toDateString();

        $response = SparkServer::actingAs($this->user)->tool(AcknowledgeAnomalyTool::class, [
            'metric' => 'oura.sleep_score',
            'date' => 'today',
            'suppress_until' => $suppressDate,
        ]);

        $response->assertOk();
        $response->assertSee('"suppress_until": "' . $suppressDate . '"');
    }

    #[Test]
    public function returns_error_for_unknown_metric(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(AcknowledgeAnomalyTool::class, [
            'metric' => 'unknown.metric',
            'date' => 'today',
        ]);

        $response->assertHasErrors(['Unknown metric identifier']);
    }

    #[Test]
    public function returns_error_when_no_anomalies_found(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $response = SparkServer::actingAs($this->user)->tool(AcknowledgeAnomalyTool::class, [
            'metric' => 'oura.sleep_score',
            'date' => 'today',
        ]);

        $response->assertHasErrors(['No unacknowledged anomalies found']);
    }

    #[Test]
    public function does_not_acknowledge_already_acknowledged_anomalies(): void
    {
        $stat = MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        MetricTrend::factory()->create([
            'metric_statistic_id' => $stat->id,
            'type' => 'anomaly_low',
            'detected_at' => Carbon::today(),
            'acknowledged_at' => now(),
        ]);

        $response = SparkServer::actingAs($this->user)->tool(AcknowledgeAnomalyTool::class, [
            'metric' => 'oura.sleep_score',
            'date' => 'today',
        ]);

        $response->assertHasErrors(['No unacknowledged anomalies found']);
    }
}
