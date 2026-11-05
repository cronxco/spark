<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TaskExecution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskExecutionFactory extends Factory
{
    protected $model = TaskExecution::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'entity_type' => 'event',
            'entity_id' => Event::factory(),
            'task_key' => $this->faker->slug(2),
            'task_name' => $this->faker->words(3, true),
            'status' => $this->faker->randomElement(['pending', 'running', 'success', 'failed']),
            'attempts' => 1,
            'triggered_by' => 'created',
            'started_at' => now(),
            'completed_at' => null,
            'queue' => 'tasks',
            'queue_connection' => null,
            'job_id' => null,
            'error' => null,
            'waiting_for' => null,
            'blocked_by' => null,
            'changed_fields' => null,
            'history' => [],
            'last_success' => null,
        ];
    }
}
