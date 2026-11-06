<?php

namespace Tests\Feature\Mobile;

use App\Models\MetricBaseline;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Support\SparkAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetricBaselinesEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function baselines_carry_the_real_centre_spread_and_bounds(): void
    {
        $user = User::factory()->create();
        $statistic = MetricStatistic::factory()->create([
            'user_id' => $user->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
            'stddev_value' => 5,
            'normal_lower_bound' => 70,
            'normal_upper_bound' => 90,
            'event_count' => 60,
        ]);
        MetricBaseline::factory()->create([
            'metric_statistic_id' => $statistic->id,
            'window_days' => 30,
            'centre' => 82,
            'spread' => 4,
            'lower' => 74,
            'upper' => 90,
        ]);
        Sanctum::actingAs($user, SparkAbility::MOBILE_SESSION);

        $row = $this->getJson('/api/v1/mobile/metrics/baselines')->assertOk()->json('data.0');

        $this->assertEquals(80, $row['mean']);
        $this->assertEquals(5, $row['stddev']);
        $this->assertEquals(70, $row['lower_bound']);
        $this->assertEquals(90, $row['upper_bound']);
        $this->assertEquals(30, $row['baseline']['display']['window_days']);
        $this->assertEquals(78, $row['baseline']['display']['lower']);
        $this->assertEquals(86, $row['baseline']['display']['upper']);
    }

    #[Test]
    public function a_metric_without_enough_data_is_left_out_and_other_users_are_not_shown(): void
    {
        $user = User::factory()->create();
        MetricStatistic::factory()->create(['user_id' => $user->id, 'event_count' => 3]);
        MetricStatistic::factory()->create(['user_id' => User::factory()->create()->id]);
        Sanctum::actingAs($user, SparkAbility::MOBILE_SESSION);

        $this->getJson('/api/v1/mobile/metrics/baselines')->assertOk()->assertJsonCount(0, 'data');
    }
}
