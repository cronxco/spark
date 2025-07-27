<?php

namespace Database\Factories;

use App\Models\EventObject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EventObjectFactory extends Factory
{
    protected $model = EventObject::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'time' => $this->faker->dateTime(),
            'integration_id' => \App\Models\Integration::factory(),
            'concept' => $this->faker->word(),
            'type' => $this->faker->word(),
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
            'metadata' => [],
            'url' => $this->faker->url(),
            'media_url' => $this->faker->imageUrl(),
            'embeddings' => array_map(fn() => $this->faker->randomFloat(4, -1, 1), range(1, 3)),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
} 