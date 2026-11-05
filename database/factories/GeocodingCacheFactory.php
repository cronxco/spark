<?php

namespace Database\Factories;

use App\Models\GeocodingCache;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

class GeocodingCacheFactory extends Factory
{
    protected $model = GeocodingCache::class;

    public function definition(): array
    {
        $address = $this->faker->address();

        return [
            'address_hash' => GeocodingCache::hashAddress($address),
            'original_address' => $address,
            'formatted_address' => $address,
            'location' => Point::make(
                $this->faker->latitude(49, 61),
                $this->faker->longitude(-8, 2),
                4326
            ),
            'country_code' => $this->faker->countryCode(),
            'source' => 'geoapify',
            'hit_count' => $this->faker->numberBetween(1, 100),
            'last_used_at' => now(),
        ];
    }
}
