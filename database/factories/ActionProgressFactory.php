<?php

namespace Database\Factories;

use App\Models\ActionProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionProgress>
 */
class ActionProgressFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ActionProgress::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $progress = $this->faker->numberBetween(0, 100);
        $step = $this->faker->randomElement(['starting', 'processing', 'completed', 'failed']);
        $message = $this->faker->sentence();

        return [
            'user_id' => User::factory(),
            'action_type' => $this->faker->randomElement(['deletion', 'migration', 'sync', 'backup']),
            'action_id' => $this->faker->uuid(),
            'step' => $step,
            'message' => $message,
            'progress' => $progress,
            'total' => 100,
            'details' => [
                'items_processed' => $this->faker->numberBetween(1, 100),
                'items_total' => $this->faker->numberBetween(100, 1000),
            ],
            'updates' => [[
                'timestamp' => now()->toIso8601String(),
                'step' => $step,
                'message' => $message,
                'percentage' => $progress,
            ]],
        ];
    }

    /**
     * Indicate that the action is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'step' => 'completed',
            'progress' => 100,
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the action has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'step' => 'failed',
            'progress' => 0,
            'failed_at' => now(),
            'error_message' => $this->faker->sentence(),
        ]);
    }

    /**
     * Indicate that the action is in progress.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'step' => 'processing',
            'progress' => $this->faker->numberBetween(1, 99),
            'completed_at' => null,
            'failed_at' => null,
        ]);
    }

    /**
     * Create a deletion progress record.
     */
    public function deletion(string $userId, string $groupId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'action_type' => 'deletion',
            'action_id' => $groupId,
        ]);
    }

    /**
     * Create a migration progress record.
     */
    public function migration(string $userId, string $migrationName): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
            'action_type' => 'migration',
            'action_id' => $migrationName,
        ]);
    }

    /**
     * Create a progress record with multiple update entries.
     */
    public function withMultipleUpdates(int $updateCount = 3): static
    {
        return $this->state(function (array $attributes) use ($updateCount) {
            $updates = [];
            $baseTime = now()->subMinutes($updateCount * 2);

            for ($i = 0; $i < $updateCount; $i++) {
                $progress = ($i + 1) * (100 / $updateCount);
                $step = match ($i) {
                    0 => 'starting',
                    $updateCount - 1 => 'completed',
                    default => 'processing'
                };

                $updates[] = [
                    'timestamp' => $baseTime->addMinutes($i * 2)->toIso8601String(),
                    'step' => $step,
                    'message' => "Progress update {({$i} + 1)}: {$step}",
                    'percentage' => (int) $progress,
                ];
            }

            return [
                'updates' => $updates,
                'progress' => (int) (100 / $updateCount * $updateCount), // Final progress
                'step' => $updates[count($updates) - 1]['step'],
                'message' => $updates[count($updates) - 1]['message'],
            ];
        });
    }
}
