<?php

namespace Database\Factories;

use App\Models\EventObject;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventObjectFactory extends Factory
{
    protected $model = EventObject::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'time' => $this->faker->dateTime(),
            'user_id' => User::factory(),
            'concept' => $this->faker->word(),
            'type' => $this->faker->word(),
            'title' => $this->faker->sentence(),
            'content' => $this->faker->paragraph(),
            'metadata' => [],
            'url' => $this->faker->url(),
            'media_url' => $this->faker->imageUrl(),
            'embeddings' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the object should have valid embeddings (1536 dimensions)
     */
    public function withEmbeddings(): static
    {
        return $this->state(fn (array $attributes) => [
            'embeddings' => array_map(fn () => $this->faker->randomFloat(4, -1, 1), range(1, 1536)),
        ]);
    }

    /**
     * Indicate that the object should have a location
     */
    public function withLocation(): static
    {
        return $this->state(fn (array $attributes) => [
            'location' => Point::makeGeodetic(
                $this->faker->latitude(49, 61),
                $this->faker->longitude(-8, 2)
            ),
            'location_address' => $this->faker->address(),
            'location_geocoded_at' => now(),
            'location_source' => 'test',
        ]);
    }
}
