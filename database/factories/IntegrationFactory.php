<?php

namespace Database\Factories;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'user_id' => \App\Models\User::factory(),
            'service' => $this->faker->word(),
            'name' => $this->faker->word(),
            'account_id' => $this->faker->uuid(),
            'access_token' => $this->faker->sha256(),
            'refresh_token' => $this->faker->sha256(),
            'expiry' => $this->faker->dateTime(),
            'refresh_expiry' => $this->faker->dateTime(),
            'configuration' => [],
            'update_frequency_minutes' => 15,
            'last_triggered_at' => null,
            'last_successful_update_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
} 