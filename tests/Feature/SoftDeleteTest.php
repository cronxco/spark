<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Integration;
use App\Models\EventObject;
use App\Models\Event;
use App\Models\Block;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_soft_delete()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        
        $integrationId = $integration->id;
        $integration->delete();
        
        // Should not find the integration in normal queries
        $this->assertNull(Integration::find($integrationId));
        
        // Should find it with withTrashed()
        $deletedIntegration = Integration::withTrashed()->find($integrationId);
        $this->assertNotNull($deletedIntegration);
        $this->assertNotNull($deletedIntegration->deleted_at);
    }

    public function test_event_object_soft_delete()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $object = EventObject::factory()->create(['user_id' => $user->id]);
        
        $objectId = $object->id;
        $object->delete();
        
        // Should not find the object in normal queries
        $this->assertNull(EventObject::find($objectId));
        
        // Should find it with withTrashed()
        $deletedObject = EventObject::withTrashed()->find($objectId);
        $this->assertNotNull($deletedObject);
        $this->assertNotNull($deletedObject->deleted_at);
    }

    public function test_event_soft_delete()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);
        
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
        
        $eventId = $event->id;
        $event->delete();
        
        // Should not find the event in normal queries
        $this->assertNull(Event::find($eventId));
        
        // Should find it with withTrashed()
        $deletedEvent = Event::withTrashed()->find($eventId);
        $this->assertNotNull($deletedEvent);
        $this->assertNotNull($deletedEvent->deleted_at);
    }

    public function test_block_soft_delete()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
        
        $block = Block::factory()->create([
            'event_id' => $event->id,
            'integration_id' => $integration->id,
        ]);
        
        $blockId = $block->id;
        $block->delete();
        
        // Should not find the block in normal queries
        $this->assertNull(Block::find($blockId));
        
        // Should find it with withTrashed()
        $deletedBlock = Block::withTrashed()->find($blockId);
        $this->assertNotNull($deletedBlock);
        $this->assertNotNull($deletedBlock->deleted_at);
    }

    public function test_relationships_with_soft_deleted_models()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);
        
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
        
        // Soft delete the integration
        $integration->delete();
        
        // The event should still be able to access the soft deleted integration
        $event->refresh();
        $this->assertNotNull($event->integration);
        $this->assertNotNull($event->integration->deleted_at);
    }

    public function test_restore_functionality()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        
        $integrationId = $integration->id;
        $integration->delete();
        
        // Should not find the integration in normal queries
        $this->assertNull(Integration::find($integrationId));
        
        // Restore the integration
        $deletedIntegration = Integration::withTrashed()->find($integrationId);
        $deletedIntegration->restore();
        
        // Should find the integration in normal queries again
        $restoredIntegration = Integration::find($integrationId);
        $this->assertNotNull($restoredIntegration);
        $this->assertNull($restoredIntegration->deleted_at);
    }

    public function test_force_delete_functionality()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        
        $integrationId = $integration->id;
        $integration->delete(); // Soft delete
        
        // Should not find the integration in normal queries
        $this->assertNull(Integration::find($integrationId));
        
        // Force delete the integration
        $deletedIntegration = Integration::withTrashed()->find($integrationId);
        $deletedIntegration->forceDelete();
        
        // Should not find the integration even with withTrashed()
        $this->assertNull(Integration::withTrashed()->find($integrationId));
    }

    public function test_only_trashed_scope()
    {
        $user = User::factory()->create();
        
        // Create active integrations
        $activeIntegration1 = Integration::factory()->create(['user_id' => $user->id]);
        $activeIntegration2 = Integration::factory()->create(['user_id' => $user->id]);
        
        // Create and soft delete some integrations
        $deletedIntegration1 = Integration::factory()->create(['user_id' => $user->id]);
        $deletedIntegration2 = Integration::factory()->create(['user_id' => $user->id]);
        $deletedIntegration1->delete();
        $deletedIntegration2->delete();
        
        // Test onlyTrashed scope
        $deletedIntegrations = Integration::onlyTrashed()->get();
        $this->assertCount(2, $deletedIntegrations);
        $this->assertTrue($deletedIntegrations->contains($deletedIntegration1));
        $this->assertTrue($deletedIntegrations->contains($deletedIntegration2));
        
        // Test normal queries exclude soft deleted
        $activeIntegrations = Integration::all();
        $this->assertCount(2, $activeIntegrations);
        $this->assertTrue($activeIntegrations->contains($activeIntegration1));
        $this->assertTrue($activeIntegrations->contains($activeIntegration2));
    }

    public function test_with_trashed_scope()
    {
        $user = User::factory()->create();
        
        // Create active integrations
        $activeIntegration = Integration::factory()->create(['user_id' => $user->id]);
        
        // Create and soft delete an integration
        $deletedIntegration = Integration::factory()->create(['user_id' => $user->id]);
        $deletedIntegration->delete();
        
        // Test withTrashed scope
        $allIntegrations = Integration::withTrashed()->get();
        $this->assertCount(2, $allIntegrations);
        $this->assertTrue($allIntegrations->contains($activeIntegration));
        $this->assertTrue($allIntegrations->contains($deletedIntegration));
    }

    public function test_trashed_helper_method()
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        
        // Should not be trashed initially
        $this->assertFalse($integration->trashed());
        
        // Soft delete the integration
        $integration->delete();
        
        // Should be trashed after deletion
        $this->assertTrue($integration->trashed());
        
        // Restore the integration
        $integration->restore();
        
        // Should not be trashed after restoration
        $this->assertFalse($integration->trashed());
    }
}
