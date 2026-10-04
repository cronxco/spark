<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventFactoryTest extends TestCase
{
    #[Test]
    public function overridden_relationships_create_no_unused_rows(): void
    {
        $integration = Integration::factory()->create();
        $actor = EventObject::factory()->create(['user_id' => $integration->user_id]);
        $counts = $this->relatedCounts();

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => null,
        ]);

        $this->assertSame($counts, $this->relatedCounts());
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertNull($event->target_id);
    }

    #[Test]
    public function default_objects_belong_to_the_selected_integration_owner(): void
    {
        $integration = Integration::factory()->create();
        $integrationCount = Integration::count();
        $events = Event::factory()->count(2)->for($integration)->create();

        $this->assertSame($integrationCount, Integration::count());
        foreach ($events as $event) {
            $this->assertSame($integration->user_id, $event->actor->user_id);
            $this->assertSame($integration->user_id, $event->target->user_id);
        }
    }

    #[Test]
    public function defaults_create_an_integration_and_objects_for_its_owner(): void
    {
        $event = Event::factory()->create();

        $this->assertSame($event->integration->user_id, $event->actor->user_id);
        $this->assertSame($event->integration->user_id, $event->target->user_id);
    }

    private function relatedCounts(): array
    {
        return [User::count(), IntegrationGroup::count(), Integration::count(), EventObject::count()];
    }
}
