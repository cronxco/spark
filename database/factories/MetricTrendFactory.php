<?php

namespace Database\Factories;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricTrend>
 */
class MetricTrendFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement([
            'anomaly_high',
            'anomaly_low',
            'trend_up_weekly',
            'trend_down_weekly',
            'trend_up_monthly',
            'trend_down_monthly',
        ]);

        $baselineValue = fake()->randomFloat(2, 50, 100);
        $percentChange = fake()->randomFloat(2, 0.15, 0.40); // 15-40% change
        $currentValue = str_contains($type, 'up') || $type === 'anomaly_high'
            ? $baselineValue * (1 + $percentChange)
            : $baselineValue * (1 - $percentChange);

        return [
            'metric_statistic_id' => MetricStatistic::factory(),
            'type' => $type,
            'detected_at' => now()->subDays(fake()->numberBetween(1, 7)),
            'start_date' => now()->subDays(14)->toDateString(),
            'end_date' => now()->toDateString(),
            'baseline_value' => $baselineValue,
            'current_value' => $currentValue,
            'deviation' => abs($currentValue - $baselineValue) / $baselineValue,
            'significance_score' => fake()->randomFloat(4, 0.5, 1.0),
            'metadata' => [],
            'acknowledged_at' => null,
        ];
    }
}
