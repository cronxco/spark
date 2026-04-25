<?php

namespace Database\Factories;

use App\Models\Place;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Place>
 */
class PlaceFactory extends Factory
{
    protected $model = Place::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'time' => $this->faker->dateTime(),
            'user_id' => User::factory(),
            'concept' => 'place',
            'type' => 'discovered_place',
            'title' => $this->faker->company(),
            'content' => null,
            'metadata' => [
                'visit_count' => 1,
                'first_visit_at' => now()->toIso8601String(),
                'last_visit_at' => now()->toIso8601String(),
                'category' => null,
                'detection_radius_meters' => 50,
                'is_favorite' => false,
            ],
            'url' => null,
            'media_url' => null,
            'embeddings' => null,
            'location' => Point::makeGeodetic(
                $this->faker->latitude(49, 61),
                $this->faker->longitude(-8, 2)
            ),
            'location_address' => $this->faker->address(),
            'location_geocoded_at' => now(),
            'location_source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
