<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        // Create actor and target objects with the same integration
        $integration = Integration::factory()->create();
        $actor = EventObject::factory()->create(['user_id' => $integration->user_id]);
        $target = EventObject::factory()->create(['user_id' => $integration->user_id]);

        return [
            'id' => $this->faker->uuid(),
            'source_id' => $this->faker->uuid(),
            'time' => $this->faker->dateTime(),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'actor_metadata' => [],
            'service' => $this->faker->word(),
            'domain' => $this->faker->word(),
            'action' => $this->faker->word(),
            'value' => $this->faker->randomNumber(),
            'value_multiplier' => $this->faker->randomDigit(),
            'value_unit' => $this->faker->word(),
            'event_metadata' => [],
            'target_id' => $target->id,
            'target_metadata' => [],
            'embeddings' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
