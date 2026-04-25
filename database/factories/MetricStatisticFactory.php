<?php

namespace Database\Factories;

use App\Models\MetricStatistic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricStatistic>
 */
class MetricStatisticFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $mean = fake()->randomFloat(2, 50, 100);
        $stddev = fake()->randomFloat(2, 5, 15);

        return [
            'user_id' => User::factory(),
            'service' => fake()->randomElement(['oura', 'monzo', 'spotify', 'github']),
            'action' => fake()->randomElement(['had_readiness_score', 'had_activity_score', 'listened_to']),
            'value_unit' => fake()->randomElement(['percent', 'bpm', 'kcal', 'minutes']),
            'event_count' => fake()->numberBetween(30, 500),
            'first_event_at' => now()->subDays(60),
            'last_event_at' => now(),
            'min_value' => $mean - ($stddev * 3),
            'max_value' => $mean + ($stddev * 3),
            'mean_value' => $mean,
            'stddev_value' => $stddev,
            'normal_lower_bound' => $mean - (2 * $stddev),
            'normal_upper_bound' => $mean + (2 * $stddev),
            'last_calculated_at' => now(),
        ];
    }
}
