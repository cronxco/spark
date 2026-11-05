<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetailPageOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $intruder;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    #[Test]
    public function event_detail_is_forbidden_for_non_owner(): void
    {
        $event = $this->ownedEvent();

        $this->actingAs($this->intruder)
            ->get(route('events.show', $event))
            ->assertForbidden();
    }

    #[Test]
    public function event_detail_is_visible_to_owner(): void
    {
        $event = $this->ownedEvent();

        $this->actingAs($this->owner)
            ->get(route('events.show', $event))
            ->assertOk();
    }

    #[Test]
    public function object_detail_is_forbidden_for_non_owner(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->intruder)
            ->get(route('objects.show', $object))
            ->assertForbidden();
    }

    #[Test]
    public function object_detail_is_visible_to_owner(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->get(route('objects.show', $object))
            ->assertOk();
    }

    #[Test]
    public function block_detail_is_forbidden_for_non_owner(): void
    {
        $block = Block::factory()->create(['event_id' => $this->ownedEvent()->id]);

        $this->actingAs($this->intruder)
            ->get(route('blocks.show', $block))
            ->assertForbidden();
    }

    #[Test]
    public function block_detail_is_visible_to_owner(): void
    {
        $block = Block::factory()->create(['event_id' => $this->ownedEvent()->id]);

        $this->actingAs($this->owner)
            ->get(route('blocks.show', $block))
            ->assertOk();
    }

    protected function ownedEvent(): Event
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->owner->id,
            'service' => 'monzo',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->owner->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
        ]);
    }
}
