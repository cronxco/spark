<?php

namespace Database\Factories;

use App\Models\MetricBaseline;
use App\Models\MetricStatistic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricBaseline>
 */
class MetricBaselineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $centre = fake()->randomFloat(2, 50, 100);
        $spread = fake()->randomFloat(2, 5, 15);

        return [
            'metric_statistic_id' => MetricStatistic::factory(),
            'subject_id' => null,
            'window_days' => 90,
            'day_type' => MetricBaseline::DAY_TYPE_ALL,
            'method' => 'mean_sd',
            'centre' => $centre,
            'spread' => $spread,
            'lower' => $centre - 2 * $spread,
            'upper' => $centre + 2 * $spread,
            'sample_count' => 60,
            'effect_significance' => null,
            'algorithm_version' => MetricBaseline::ALGORITHM_VERSION,
            'computed_at' => now(),
        ];
    }
}
