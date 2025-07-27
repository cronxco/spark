<?php

namespace Database\Factories;

use App\Models\Block;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BlockFactory extends Factory
{
    protected $model = Block::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'event_id' => $this->faker->uuid(),
            'time' => $this->faker->dateTime(),
            'integration_id' => $this->faker->uuid(),
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
            'url' => $this->faker->url(),
            'media_url' => $this->faker->imageUrl(),
            'value' => $this->faker->randomNumber(),
            'value_multiplier' => $this->faker->randomDigit(),
            'value_unit' => $this->faker->word(),
            'embeddings' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
} 