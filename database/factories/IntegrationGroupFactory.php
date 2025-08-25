<?php

namespace Database\Factories;

use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IntegrationGroupFactory extends Factory
{
    protected $model = IntegrationGroup::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'service' => $this->faker->word(),
            'account_id' => null,
            'webhook_secret' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
            'auth_metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}