<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'source_id' => $this->faker->uuid(),
            'time' => $this->faker->dateTime(),
            'integration_id' => Integration::factory(),
            'actor_id' => fn (array $attributes) => EventObject::factory()->state([
                'user_id' => Integration::findOrFail($attributes['integration_id'])->user_id,
            ]),
            'actor_metadata' => [],
            'service' => $this->faker->word(),
            'domain' => $this->faker->word(),
            'action' => $this->faker->word(),
            'value' => $this->faker->randomNumber(),
            'value_multiplier' => $this->faker->randomDigit(),
            'value_unit' => $this->faker->word(),
            'event_metadata' => [],
            'target_id' => fn (array $attributes) => EventObject::factory()->state([
                'user_id' => Integration::findOrFail($attributes['integration_id'])->user_id,
            ]),
            'target_metadata' => [],
            'embeddings' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the event should have valid embeddings (1536 dimensions)
     */
    public function withEmbeddings(): static
    {
        return $this->state(fn (array $attributes) => [
            'embeddings' => array_map(fn () => $this->faker->randomFloat(4, -1, 1), range(1, 1536)),
        ]);
    }

    /**
     * Indicate that the event should have a location
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
