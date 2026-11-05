<?php

namespace Tests\Feature\EventsObjectsBlocks;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;

trait EntityFixtures
{
    /** @return array{Integration, Event, Block} */
    private function ownedGraph(User $user): array
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $integration = Integration::factory()->create(['user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'monzo']);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create(['integration_id' => $integration->id, 'actor_id' => $actor->id, 'service' => 'monzo']);
        $block = Block::factory()->create(['event_id' => $event->id]);

        return [$integration, $event, $block];
    }
}
