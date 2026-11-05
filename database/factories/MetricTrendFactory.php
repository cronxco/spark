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

        $isAnomaly = in_array($type, ['anomaly_high', 'anomaly_low'], true);

        // Anomalies and trends measure deviation differently, and the
        // difference matters: DetectMetricAnomaliesJob records standard
        // deviations from the mean, while trend detection records a
        // proportional change. Producing a proportion for an anomaly made
        // every factory-built anomaly look statistically trivial.
        $deviation = $isAnomaly
            ? fake()->randomFloat(2, 3.5, 5.0)
            : abs($currentValue - $baselineValue) / $baselineValue;

        return [
            'metric_statistic_id' => MetricStatistic::factory(),
            'type' => $type,
            'detected_at' => now()->subDays(fake()->numberBetween(1, 7)),
            'start_date' => now()->subDays(14)->toDateString(),
            'end_date' => now()->toDateString(),
            'baseline_value' => $baselineValue,
            'current_value' => $currentValue,
            'deviation' => $deviation,
            'significance_score' => fake()->randomFloat(4, 0.5, 1.0),
            'metadata' => [],
            'acknowledged_at' => null,
        ];
    }

    /**
     * An anomaly large enough that a single day's appearance is worth raising.
     */
    public function significant(): static
    {
        return $this->state(fn (): array => [
            'type' => 'anomaly_high',
            'deviation' => 4.0,
        ]);
    }

    /**
     * An anomaly that clears the detection bounds but is not, on its own,
     * large enough to interrupt someone for on day one.
     */
    public function marginal(): static
    {
        return $this->state(fn (): array => [
            'type' => 'anomaly_high',
            'deviation' => 2.2,
        ]);
    }
}
