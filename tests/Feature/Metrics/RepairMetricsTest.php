<?php

namespace Tests\Feature\Metrics;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairMetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $unlisted = $this->statistic('untappd', 'drank');
        $this->trend($unlisted, 'anomaly_high');
        $this->trend($unlisted, 'trend_up_weekly');
        $flagged = $this->statistic('apple_health', 'had_respiratory_rate', ['baseline_reset_suggested_at' => now()]);

        $this->artisan('metrics:repair', ['--user' => $this->user->id])->assertSuccessful();

        $this->assertSame(2, MetricTrend::count());
        $this->assertNotNull($flagged->fresh()->baseline_reset_suggested_at);
    }

    #[Test]
    public function applying_removes_stale_anomalies_retired_trends_and_review_flags(): void
    {
        $unlisted = $this->statistic('untappd', 'drank');
        $this->trend($unlisted, 'anomaly_high');

        $readiness = $this->statistic('oura', 'had_readiness_score', ['baseline_reset_suggested_at' => now()]);
        $this->trend($readiness, 'anomaly_high'); // the good direction for readiness
        $kept = $this->trend($readiness, 'anomaly_low');
        $this->trend($readiness, 'trend_down_monthly');

        $this->artisan('metrics:repair', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();

        $this->assertSame([$kept->id], MetricTrend::pluck('id')->all());
        $this->assertNull($readiness->fresh()->baseline_reset_suggested_at);
    }

    #[Test]
    public function acknowledged_rows_are_never_touched(): void
    {
        $unlisted = $this->statistic('untappd', 'drank');
        $acknowledged = $this->trend($unlisted, 'anomaly_high', ['acknowledged_at' => now()]);
        $retired = $this->trend($unlisted, 'trend_up_weekly', ['acknowledged_at' => now()]);

        $this->artisan('metrics:repair', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing([$acknowledged->id, $retired->id], MetricTrend::pluck('id')->all());
    }

    #[Test]
    public function another_users_rows_are_left_alone(): void
    {
        $other = User::factory()->create();
        $theirs = MetricStatistic::factory()->create(['user_id' => $other->id, 'service' => 'untappd', 'action' => 'drank']);
        $this->trend($theirs, 'anomaly_high');

        $this->artisan('metrics:repair', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();

        $this->assertSame(1, MetricTrend::count());
    }

    #[Test]
    public function alias_services_are_merged_only_when_asked(): void
    {
        $alias = $this->statistic('google_calendar', 'had_event', ['value_unit' => 'minutes']);

        $this->artisan('metrics:repair', ['--user' => $this->user->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('google_calendar', $alias->fresh()->service);

        $this->artisan('metrics:repair', ['--user' => $this->user->id, '--apply' => true, '--merge-services' => true])->assertSuccessful();
        $this->assertSame('google-calendar', $alias->fresh()->service);
    }

    #[Test]
    public function an_unknown_user_is_refused(): void
    {
        $this->artisan('metrics:repair', ['--user' => '00000000-0000-0000-0000-000000000000'])->assertFailed();
    }

    private function statistic(string $service, string $action, array $overrides = []): MetricStatistic
    {
        return MetricStatistic::factory()->create($overrides + [
            'user_id' => $this->user->id,
            'service' => $service,
            'action' => $action,
        ]);
    }

    private function trend(MetricStatistic $statistic, string $type, array $overrides = []): MetricTrend
    {
        return MetricTrend::factory()->create($overrides + [
            'metric_statistic_id' => $statistic->id,
            'type' => $type,
        ]);
    }
}
