<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'integration_group_id' => IntegrationGroup::factory(),
            'service' => $this->faker->word(),
            'name' => $this->faker->word(),
            'instance_type' => null,
            'account_id' => $this->faker->uuid(),
            'configuration' => [
                'update_frequency_minutes' => 15,
            ],
            'last_triggered_at' => null,
            'last_successful_update_at' => null,
            'migration_batch_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
